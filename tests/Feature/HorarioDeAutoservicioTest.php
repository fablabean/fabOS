<?php

namespace Tests\Feature;

use App\Filament\Pages\BloqueoDeReservas as Pantalla;
use App\Models\Area;
use App\Models\Asset;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use App\Support\HorarioDeAutoservicio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class HorarioDeAutoservicioTest extends TestCase
{
    use RefreshDatabase;

    private function hora(string $cuando): Carbon
    {
        return Carbon::parse($cuando, config('fabos.lab.timezone'));
    }

    public function test_apagado_no_limita_nada(): void
    {
        $this->assertTrue(HorarioDeAutoservicio::permite('maquinas', $this->hora('2026-10-05 08:00'), $this->hora('2026-10-05 22:00')));
    }

    public function test_la_reserva_entera_tiene_que_caber_en_el_horario(): void
    {
        HorarioDeAutoservicio::guardar(['maquinas'], '13:00', '18:00');

        $this->assertTrue(HorarioDeAutoservicio::permite('maquinas', $this->hora('2026-10-05 13:00'), $this->hora('2026-10-05 18:00')));
        $this->assertTrue(HorarioDeAutoservicio::permite('maquinas', $this->hora('2026-10-05 15:30'), $this->hora('2026-10-05 16:30')));

        $this->assertFalse(HorarioDeAutoservicio::permite('maquinas', $this->hora('2026-10-05 12:45'), $this->hora('2026-10-05 13:45')));
        $this->assertFalse(HorarioDeAutoservicio::permite('maquinas', $this->hora('2026-10-05 17:00'), $this->hora('2026-10-05 18:30')));
        $this->assertFalse(HorarioDeAutoservicio::permite('maquinas', $this->hora('2026-10-05 09:00'), $this->hora('2026-10-05 10:00')));

        // Lo que no se marcó, sigue libre.
        $this->assertTrue(HorarioDeAutoservicio::permite('asesorias', $this->hora('2026-10-05 09:00'), $this->hora('2026-10-05 09:45')));
    }

    public function test_el_interruptor_viejo_se_lee_como_maquinas(): void
    {
        \App\Models\Setting::put(HorarioDeAutoservicio::CLAVE, ['activo' => true, 'desde' => '13:00', 'hasta' => '18:00']);

        $this->assertTrue(HorarioDeAutoservicio::activo('maquinas'));
        $this->assertFalse(HorarioDeAutoservicio::activo('asesorias'));
    }

    public function test_desde_su_cuenta_no_se_reserva_fuera_del_horario(): void
    {
        HorarioDeAutoservicio::guardar(['maquinas'], '13:00', '18:00');

        $area = Area::create(['slug' => 'corte', 'name' => 'Corte láser']);
        $equipo = Asset::create([
            'area_id' => $area->id, 'name' => 'Cortadora', 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true,
            'min_minutes' => 30, 'autonomous_minutes' => 60, 'max_minutes' => 720,
        ]);
        $ana = User::create(['name' => 'Ana', 'email' => 'ana@test.co', 'status' => 'activo']);

        $this->actingAs($ana)
            ->post(route('reservas.store', $equipo), [
                'fecha' => now(config('fabos.lab.timezone'))->addDay()->format('Y-m-d'),
                'inicio' => '09:00',
                'duracion' => 60,
            ])
            ->assertSessionHasErrors(['fecha' => 'Por ahora las máquinas se reservan de 13:00 a 18:00: tiene que empezar y terminar dentro de ese horario. Si necesitas otra hora, pídela al equipo del laboratorio.']);

        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_se_administra_desde_el_panel(): void
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create(['name' => 'Coordinación', 'email' => 'coord@test.co', 'status' => 'activo']);
        $u->assignRole(User::ROL_ADMINISTRADOR);
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        Livewire::test(Pantalla::class)
            ->set('horario.aplica', ['maquinas', 'asesorias'])
            ->set('horario.desde', '18:00')
            ->set('horario.hasta', '13:00')
            ->call('guardarHorario')
            ->assertHasErrors('hasta');

        $this->assertFalse(HorarioDeAutoservicio::activo());

        Livewire::test(Pantalla::class)
            ->set('horario.aplica', ['maquinas', 'asesorias'])
            ->set('horario.desde', '13:00')
            ->set('horario.hasta', '18:00')
            ->call('guardarHorario');

        $this->assertTrue(HorarioDeAutoservicio::activo('asesorias'));
        $this->assertFalse(HorarioDeAutoservicio::activo('espacios'));
        $this->assertSame('de 13:00 a 18:00', HorarioDeAutoservicio::legible());
    }
}
