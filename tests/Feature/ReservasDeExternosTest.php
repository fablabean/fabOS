<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\WorkSchedule;
use App\Services\Booking\BookingService;
use App\Services\Booking\EspacioBookingService;
use App\Support\ReservaConAprobacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Lo que reserva por su cuenta alguien de fuera lo confirma la coordinación.
 *
 * Un externo pidió un recorrido para quince personas y quedó confirmado solo,
 * porque había alguien del equipo en jornada.
 */
class ReservasDeExternosTest extends TestCase
{
    use RefreshDatabase;

    private Space $laboratorio;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $area = Area::create(['slug' => 'taller', 'name' => 'Taller']);
        $this->laboratorio = Space::create(['slug' => 'taller', 'name' => 'Taller', 'capacity' => 12, 'is_reservable' => true]);
        $this->laboratorio->areas()->attach($area);

        // Alguien en jornada presencial: lo de dentro de la jornada se confirmaría solo.
        WorkSchedule::create([
            'user_id' => User::factory()->create(['status' => 'activo'])->id, 'weekday' => 1,
            'starts_at' => '08:00', 'ends_at' => '18:00', 'break_minutes' => 60,
            'modalidad' => WorkSchedule::PRESENCIAL, 'effective_from' => '2026-01-01',
        ]);
    }

    private function lunes(string $hhmm): Carbon
    {
        return Carbon::now(config('fabos.lab.timezone'))->next(Carbon::MONDAY)->setTimeFromTimeString($hhmm);
    }

    private function persona(string $slug, bool $requiereAprobacion): User
    {
        $categoria = UserCategory::firstOrCreate(['slug' => $slug], [
            'name' => ucfirst($slug), 'can_reserve' => true, 'rate_factor' => 1,
            'is_institutional' => ! $requiereAprobacion, 'requires_approval' => $requiereAprobacion,
        ]);

        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
            'user_category_id' => $categoria->id,
        ])->fresh();
    }

    public function test_el_recorrido_que_pide_un_externo_queda_pendiente(): void
    {
        $leandro = $this->persona('externo', true);
        $this->actingAs($leandro);

        $r = app(EspacioBookingService::class)->reservar(
            $leandro, $this->laboratorio, $this->lunes('09:00'), $this->lunes('11:00'), 15,
            proposito: 'Recorrido de presentación de tecnologías 4.0', modalidad: EspacioBookingService::RECORRIDO,
        );

        $this->assertSame('solicitada', $r->status);
        $this->assertSame(Reservation::MODO_RECORRIDO, $r->mode);
        $this->assertStringContainsString(ReservaConAprobacion::MOTIVO, $r->status_reason);

        // Usar la sala, también.
        $sala = app(EspacioBookingService::class)->reservar($leandro, $this->laboratorio, $this->lunes('14:00'), $this->lunes('15:00'), 3);
        $this->assertSame('solicitada', $sala->status);
    }

    public function test_quien_es_de_la_institucion_sigue_confirmando_solo(): void
    {
        $estudiante = $this->persona('estudiante', false);
        $this->actingAs($estudiante);

        $r = app(EspacioBookingService::class)->reservar($estudiante, $this->laboratorio, $this->lunes('09:00'), $this->lunes('10:00'), 3);

        $this->assertSame('confirmada', $r->status);
    }

    public function test_lo_que_el_equipo_le_reserva_a_un_externo_desde_el_panel_no_espera(): void
    {
        $leandro = $this->persona('externo', true);
        Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web');
        $jefa = $this->persona('colaborador', false);
        $jefa->assignRole(User::ROL_ADMINISTRADOR);

        // Quien reserva es del equipo: ya decidió.
        $this->actingAs($jefa);

        $r = app(EspacioBookingService::class)->reservar($leandro, $this->laboratorio, $this->lunes('09:00'), $this->lunes('10:00'), 3);

        $this->assertSame('confirmada', $r->status);
    }

    public function test_un_equipo_que_reserva_un_externo_tambien_queda_pendiente(): void
    {
        $leandro = $this->persona('externo', true);
        $area = $this->laboratorio->areas->first();
        $familia = \App\Models\RiskFamily::create(['area_id' => $area->id, 'slug' => 'fdm', 'name' => 'FDM']);
        $impresora = Asset::create([
            'area_id' => $area->id, 'risk_family_id' => $familia->id, 'name' => 'Impresora', 'kind' => 'fijo', 'status' => 'operativo',
            'is_reservable' => true, 'min_minutes' => 30, 'autonomous_minutes' => 240, 'max_minutes' => 480,
        ]);

        // Habilitado como cualquiera: lo que cuenta es quién reserva.
        \App\Models\Certifab::create(['user_id' => $leandro->id, 'risk_family_id' => $familia->id, 'level' => 'byte']);
        $this->actingAs($leandro);

        $r = app(BookingService::class)->reservar($leandro, $impresora, $this->lunes('09:00'), $this->lunes('10:00'));

        $this->assertSame('solicitada', $r->status);
        $this->assertSame('con_aprobacion', $r->mode);
        $this->assertSame(ReservaConAprobacion::MOTIVO, $r->status_reason);
    }
}
