<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Booking\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Estado de los equipos en el catálogo público (§10). */
class DisponibilidadPublicaTest extends TestCase
{
    use RefreshDatabase;

    private function servicio(): AvailabilityService
    {
        return app(AvailabilityService::class);
    }

    private function equipo(array $attrs = []): Asset
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Área']);

        return Asset::create(array_merge([
            'area_id' => $area->id, 'name' => 'Equipo ' . uniqid(), 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true, 'is_public' => true,
            'min_minutes' => 30, 'autonomous_minutes' => 60, 'max_minutes' => 720,
        ], $attrs));
    }

    private function persona(): User
    {
        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
    }

    /** Deja a alguien en jornada ahora, para que el laboratorio esté abierto. */
    private function abrirElLaboratorio(): void
    {
        $u = $this->persona();

        foreach (range(1, 7) as $dia) {
            WorkSchedule::create([
                'user_id' => $u->id, 'weekday' => $dia,
                'starts_at' => '00:00', 'ends_at' => '23:59',
                'break_minutes' => 0, 'effective_from' => '2020-01-01',
            ]);
        }
    }

    private function estadoDe(Asset $a): array
    {
        return $this->servicio()->estadoAhora(collect([$a]))[$a->id];
    }

    public function test_un_equipo_sin_reservas_esta_libre(): void
    {
        $this->abrirElLaboratorio();

        $this->assertSame(AvailabilityService::LIBRE, $this->estadoDe($this->equipo())['estado']);
    }

    public function test_un_equipo_en_uso_aparece_ocupado_y_dice_hasta_cuando(): void
    {
        $this->abrirElLaboratorio();
        $a = $this->equipo();

        $fin = now()->addHour();
        Reservation::create([
            'reservable_type' => Asset::class, 'reservable_id' => $a->id,
            'user_id' => $this->persona()->id, 'status' => 'en_curso', 'mode' => 'directa',
            'starts_at' => now()->subMinutes(30), 'ends_at' => $fin,
        ]);

        $estado = $this->estadoDe($a);

        $this->assertSame(AvailabilityService::OCUPADO, $estado['estado']);
        $this->assertStringContainsString(
            $fin->timezone(config('fabos.lab.timezone'))->format('H:i'),
            $estado['etiqueta']
        );
    }

    public function test_una_reserva_futura_no_lo_ocupa_ahora(): void
    {
        $this->abrirElLaboratorio();
        $a = $this->equipo();

        Reservation::create([
            'reservable_type' => Asset::class, 'reservable_id' => $a->id,
            'user_id' => $this->persona()->id, 'status' => 'confirmada', 'mode' => 'directa',
            'starts_at' => now()->addHours(3), 'ends_at' => now()->addHours(4),
        ]);

        $this->assertSame(AvailabilityService::LIBRE, $this->estadoDe($a)['estado']);
    }

    public function test_un_equipo_en_mantenimiento_lo_dice(): void
    {
        $this->abrirElLaboratorio();

        $estado = $this->estadoDe($this->equipo(['status' => 'mantenimiento']));

        $this->assertSame(AvailabilityService::NO_OPERATIVO, $estado['estado']);
        $this->assertSame('En mantenimiento', $estado['etiqueta']);
    }

    public function test_sin_nadie_en_jornada_el_laboratorio_aparece_cerrado(): void
    {
        // Sin jornadas: nadie atiende.
        $this->assertSame(AvailabilityService::CERRADO, $this->estadoDe($this->equipo())['estado']);
    }

    public function test_lo_desatendido_sigue_libre_aunque_este_cerrado(): void
    {
        // Una impresión de doce horas no necesita a nadie presente (§7).
        $estado = $this->estadoDe($this->equipo(['unattended_use' => true]));

        $this->assertSame(AvailabilityService::LIBRE, $estado['estado']);
    }

    public function test_el_catalogo_publico_muestra_el_estado(): void
    {
        $this->abrirElLaboratorio();
        $a = $this->equipo();

        Reservation::create([
            'reservable_type' => Asset::class, 'reservable_id' => $a->id,
            'user_id' => $this->persona()->id, 'status' => 'en_curso', 'mode' => 'directa',
            'starts_at' => now()->subMinutes(10), 'ends_at' => now()->addHour(),
        ]);

        // Sin sesión: es la vitrina pública. Dentro del área, que es donde
        // aparecen las máquinas desde que la portada pregunta primero cómo se
        // quiere usar el laboratorio.
        $this->get(route('publico.reservas', [
            'modo' => 'asesoria', 'area' => $a->area?->slug, 'maquina' => 1,
        ]))
            ->assertOk()
            ->assertSee('Ocupado hasta las');
    }

    public function test_cuenta_los_libres_en_una_sola_pasada(): void
    {
        $this->abrirElLaboratorio();
        $libre = $this->equipo();
        $roto  = $this->equipo(['status' => 'fuera_de_servicio']);

        $this->assertSame(1, $this->servicio()->contarLibres(collect([$libre, $roto])));
    }
}
