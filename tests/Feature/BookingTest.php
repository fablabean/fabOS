<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Models\Reservation;
use App\Models\RiskFamily;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\WorkSchedule;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/** Creación de reservas sobre activos (§10). */
class BookingTest extends TestCase
{
    use RefreshDatabase;

    private function servicio(): BookingService
    {
        return app(BookingService::class);
    }

    private function usuario(): User
    {
        $cat = UserCategory::create([
            'slug' => 'cat-' . uniqid(), 'name' => 'Estudiante', 'can_reserve' => true,
        ]);

        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    private function activo(array $attrs = [], array $familia = [], ?RiskFamily $rf = null): Asset
    {
        if (! $rf) {
            $area = Area::create(['slug' => 'area-' . uniqid(), 'name' => 'Área']);
            $rf = RiskFamily::create(array_merge([
                'area_id' => $area->id, 'slug' => 'fam-' . uniqid(), 'name' => 'FDM',
                'required_course_level' => 'byte', 'requires_companion' => false,
            ], $familia));
        }

        return Asset::create(array_merge([
            'area_id' => $rf->area_id, 'risk_family_id' => $rf->id,
            'name' => 'Equipo ' . uniqid(), 'kind' => 'fijo', 'status' => 'operativo',
            'is_reservable' => true, 'min_minutes' => 30,
            'autonomous_minutes' => 120, 'max_minutes' => 720,
        ], $attrs));
    }

    private function certificar(User $u, Asset $a, array $attrs = []): Certifab
    {
        return Certifab::create(array_merge([
            'user_id' => $u->id, 'risk_family_id' => $a->risk_family_id, 'level' => 'byte',
        ], $attrs));
    }


    /** Colaborador en jornada el dia de las pruebas y certificado en ese equipo. */
    private function colaboradorEnJornada(Asset $asset): User
    {
        $u = User::create([
            'name' => 'Colaborador ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo',
        ]);

        WorkSchedule::create([
            'user_id' => $u->id,
            'weekday' => Carbon::parse('2026-09-01')->isoWeekday(),
            'starts_at' => '07:00', 'ends_at' => '20:00',
            'break_minutes' => 60, 'effective_from' => '2026-01-01',
        ]);

        Certifab::create([
            'user_id' => $u->id, 'risk_family_id' => $asset->risk_family_id, 'level' => 'mega',
        ]);

        return $u;
    }

    private function franja(int $desdeHora = 14, int $horas = 2): array
    {
        $d = Carbon::parse('2026-09-01 00:00:00')->addHours($desdeHora);

        return [$d, $d->copy()->addHours($horas)];
    }

    // ------------------------------------------------------------------ feliz

    public function test_crea_la_reserva_confirmada_cuando_es_autonomo(): void
    {
        $u = $this->usuario();
        $a = $this->activo();
        $this->certificar($u, $a);
        [$d, $h] = $this->franja();

        $r = $this->servicio()->reservar($u, $a, $d, $h, 'Prototipo de clase');

        $this->assertSame('confirmada', $r->status);
        $this->assertSame('directa', $r->mode);
        $this->assertSame($a->id, $r->reservable_id);
        $this->assertSame('Prototipo de clase', $r->purpose);
    }

    public function test_queda_solicitada_cuando_solo_falta_el_visto_bueno(): void
    {
        $u = $this->usuario();
        $a = $this->activo(['autonomous_minutes' => 60]);
        $this->certificar($u, $a);
        [$d, $h] = $this->franja(14, 3);          // 3 h supera la autonomia

        $r = $this->servicio()->reservar($u, $a, $d, $h);

        // No bloquea el equipo: espera visto bueno del responsable.
        $this->assertSame('solicitada', $r->status);
        $this->assertSame('con_aprobacion', $r->mode);
        $this->assertNull($r->supervisor_id, 'aqui no hace falta nadie presente');
    }

    public function test_sin_colaborador_en_jornada_no_deja_reservar_lo_que_exige_compania(): void
    {
        $u = $this->usuario();
        $a = $this->activo(familia: ['requires_companion' => true]);
        $this->certificar($u, $a);
        [$d, $h] = $this->franja();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/en jornada/');

        $this->servicio()->reservar($u, $a, $d, $h);
    }

    public function test_asigna_acompanante_y_reserva_tambien_su_tiempo(): void
    {
        $u = $this->usuario();
        $a = $this->activo(familia: ['requires_companion' => true]);
        $this->certificar($u, $a);

        $colaborador = $this->colaboradorEnJornada($a);
        [$d, $h] = $this->franja();

        $r = $this->servicio()->reservar($u, $a, $d, $h);

        $this->assertSame('confirmada', $r->status);
        $this->assertSame($colaborador->id, $r->supervisor_id);

        // El tiempo del colaborador queda comprometido: no puede estar en dos
        // acompanamientos a la vez.
        $this->assertDatabaseHas('reservations', [
            'reservable_type' => User::class,
            'reservable_id'   => $colaborador->id,
            'status'          => 'confirmada',
        ]);
    }

    public function test_no_asigna_dos_veces_al_mismo_colaborador(): void
    {
        $a1 = $this->activo(familia: ['requires_companion' => true]);
        $colaborador = $this->colaboradorEnJornada($a1);

        // Segundo equipo de la MISMA familia: el mismo colaborador lo cubriria.
        $a2 = $this->activo(rf: $a1->riskFamily);

        $u1 = $this->usuario();
        $u2 = $this->usuario();
        $this->certificar($u1, $a1);
        $this->certificar($u2, $a2);

        [$d, $h] = $this->franja();

        $this->servicio()->reservar($u1, $a1, $d, $h);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/en jornada/');

        $this->servicio()->reservar($u2, $a2, $d, $h);
    }

    // ------------------------------------------------------------- conflictos

    public function test_rechaza_el_traslape_con_un_mensaje_claro(): void
    {
        $a = $this->activo();
        $u1 = $this->usuario();
        $u2 = $this->usuario();
        $this->certificar($u1, $a);
        $this->certificar($u2, $a);

        [$d, $h] = $this->franja(14, 2);                 // 14:00 - 16:00
        $this->servicio()->reservar($u1, $a, $d, $h);

        [$d2, $h2] = $this->franja(15, 2);               // 15:00 - 17:00
        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/ya está tomado/');

        $this->servicio()->reservar($u2, $a, $d2, $h2);
    }

    public function test_acepta_reservas_contiguas(): void
    {
        $a = $this->activo();
        $u = $this->usuario();
        $this->certificar($u, $a);

        [$d, $h]   = $this->franja(14, 2);               // 14:00 - 16:00
        [$d2, $h2] = $this->franja(16, 2);               // 16:00 - 18:00

        $this->servicio()->reservar($u, $a, $d, $h);
        $r = $this->servicio()->reservar($u, $a, $d2, $h2);

        $this->assertSame('confirmada', $r->status);
        $this->assertSame(2, Reservation::count());
    }

    public function test_no_deja_reservar_sin_certifab(): void
    {
        [$d, $h] = $this->franja();

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/certifab/i');

        $this->servicio()->reservar($this->usuario(), $this->activo(), $d, $h);
    }

    public function test_exige_que_el_fin_sea_posterior_al_inicio(): void
    {
        $u = $this->usuario();
        $a = $this->activo();
        $this->certificar($u, $a);
        [$d, $h] = $this->franja();

        $this->expectException(BookingException::class);
        $this->servicio()->reservar($u, $a, $h, $d);
    }

    // ------------------------------------------------------------------ pools

    public function test_asigna_una_unidad_libre_del_grupo(): void
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Impresión 3D']);
        $rf = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'fdm-' . uniqid(), 'name' => 'FDM',
            'required_course_level' => 'byte', 'requires_companion' => false,
        ]);

        $u1 = $this->usuario();
        $u2 = $this->usuario();
        $unidad1 = $this->activo(['pool_key' => 'creality'], rf: $rf);
        $unidad2 = $this->activo(['pool_key' => 'creality'], rf: $rf);
        $this->certificar($u1, $unidad1);
        $this->certificar($u2, $unidad1);

        [$d, $h] = $this->franja();

        $r1 = $this->servicio()->reservar($u1, $unidad1, $d, $h);
        // Pide "una Creality" a la misma hora: debe caer en la otra unidad.
        $r2 = $this->servicio()->reservar($u2, $unidad1, $d, $h);

        $this->assertSame($unidad1->id, $r1->reservable_id);
        $this->assertSame($unidad2->id, $r2->reservable_id);
    }

    public function test_avisa_cuando_el_grupo_esta_completo(): void
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Impresión 3D']);
        $rf = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'fdm-' . uniqid(), 'name' => 'FDM',
            'required_course_level' => 'byte', 'requires_companion' => false,
        ]);

        $u = $this->usuario();
        $unica = $this->activo(['pool_key' => 'ender'], rf: $rf);
        $this->certificar($u, $unica);
        [$d, $h] = $this->franja();

        $this->servicio()->reservar($u, $unica, $d, $h);

        $this->assertCount(0, $this->servicio()->unidadesLibres($unica, $d, $h));

        $this->expectException(BookingException::class);
        $this->servicio()->reservar($u, $unica, $d, $h);
    }
}
