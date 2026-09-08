<?php

namespace Tests\Feature;

use App\Filament\Resources\WorkSchedules\Pages\CreateWorkSchedule;
use App\Models\Area;
use App\Models\Asset;
use App\Models\AssetAdvisor;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Auth\TwoFactorService;
use App\Services\Booking\AsesoriaService;
use App\Services\Booking\BookingService;
use App\Services\Staffing\CopiaDeJornadas;
use App\Services\Staffing\CoverageService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A que hora es el descanso de cada jornada (§5).
 *
 * La jornada sabia cuantos minutos de descanso tenia, pero no cuando: el
 * almuerzo se seguia ofreciendo para asesorias y se le seguian asignando
 * acompanamientos. Con la hora, esa franja queda fuera.
 */
class DescansoEnLaJornadaTest extends TestCase
{
    use RefreshDatabase;

    /** El 1 de septiembre de 2026 es martes. */
    private const MARTES = '2026-09-01';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-31 07:00', config('fabos.lab.timezone')));

        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }
    }

    private function colaborador(string $nombre, ?string $descansoDesde = '12:00', int $minutos = 60): User
    {
        $u = User::create(['name' => $nombre, 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(User::ROL_CONSULTOR);

        WorkSchedule::create([
            'user_id' => $u->id, 'weekday' => 2,
            'starts_at' => '08:00', 'ends_at' => '18:00',
            'break_minutes' => $minutos, 'break_starts_at' => $descansoDesde,
            'modalidad' => WorkSchedule::PRESENCIAL, 'effective_from' => '2026-01-01',
        ]);

        return $u->fresh();
    }

    private function franja(string $desde, string $hasta): array
    {
        $tz = config('fabos.lab.timezone');

        return [Carbon::parse(self::MARTES . ' ' . $desde, $tz), Carbon::parse(self::MARTES . ' ' . $hasta, $tz)];
    }

    public function test_el_descanso_saca_a_la_persona_de_esa_franja(): void
    {
        $ana = $this->colaborador('Ana');
        $c = app(CoverageService::class);

        [$d, $h] = $this->franja('12:15', '12:45');
        $this->assertFalse($c->enJornada($d, $h)->contains('id', $ana->id));

        // Pisarlo por un rato también cuenta.
        [$d, $h] = $this->franja('11:30', '12:15');
        $this->assertFalse($c->enJornada($d, $h)->contains('id', $ana->id));

        // Antes y después, sí. Tocarse por el borde no es pisarse.
        [$d, $h] = $this->franja('11:00', '12:00');
        $this->assertTrue($c->enJornada($d, $h)->contains('id', $ana->id));

        [$d, $h] = $this->franja('13:00', '14:00');
        $this->assertTrue($c->enJornada($d, $h)->contains('id', $ana->id));

        // Y la franja atendida del día no se encoge por el almuerzo.
        $this->assertSame(['08:00:00', '18:00:00'], $c->franjaAtendida(Carbon::parse(self::MARTES)));
    }

    /** Sin hora, el descanso solo descuenta horas: nada cambia. */
    public function test_sin_hora_el_descanso_no_bloquea_nada(): void
    {
        $ana = $this->colaborador('Ana', descansoDesde: null);

        [$d, $h] = $this->franja('12:15', '12:45');
        $this->assertTrue(app(CoverageService::class)->enJornada($d, $h)->contains('id', $ana->id));
        $this->assertNull($ana->workSchedules()->first()->descanso());
        $this->assertSame('60 min', $ana->workSchedules()->first()->descansoTexto());
    }

    public function test_las_asesorias_no_ofrecen_la_hora_del_almuerzo(): void
    {
        $ana = $this->colaborador('Ana');
        $area = Area::create(['name' => 'Prototipado', 'slug' => 'prototipado']);
        $equipo = Asset::create(['name' => 'Láser', 'area_id' => $area->id, 'kind' => 'fijo', 'status' => 'operativo', 'is_reservable' => true]);
        AssetAdvisor::create(['user_id' => $ana->id, 'asset_id' => $equipo->id]);

        [$d, $h] = $this->franja('12:00', '12:45');
        $this->assertEmpty(app(AsesoriaService::class)->disponiblesPara($equipo, $d, $h));

        [$d, $h] = $this->franja('13:00', '13:45');
        $this->assertNotEmpty(app(AsesoriaService::class)->disponiblesPara($equipo, $d, $h));
    }

    /** A quien la elige a mano se le dice por qué no, con la hora. */
    public function test_quien_la_elige_a_mano_ve_el_descanso(): void
    {
        $ana = $this->colaborador('Ana');

        [$d, $h] = $this->franja('12:15', '12:45');
        $this->assertSame(
            'Ana está en su descanso a esa hora (de 12:00 a 13:00).',
            app(BookingService::class)->porQueNoEstaLibre($ana, $d, $h),
        );

        [$d, $h] = $this->franja('14:00', '15:00');
        $this->assertNull(app(BookingService::class)->porQueNoEstaLibre($ana, $d, $h));
    }

    /** Desde el panel: una hora para todos los días marcados, en el mismo formulario. */
    public function test_se_guarda_desde_el_formulario_de_jornadas(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $admin->assignRole(User::ROL_SUPERADMIN);
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($admin);
        $servicio->confirmar($admin, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($admin->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        $persona = User::create(['name' => 'Beto', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $persona->assignRole(User::ROL_PRACTICANTE);

        Livewire::test(CreateWorkSchedule::class)
            ->fillForm([
                'user_id'         => $persona->id,
                'weekdays'        => [1, 2, 3],
                'starts_at'       => '08:00',
                'ends_at'         => '17:30',
                'break_minutes'   => 60,
                'break_starts_at' => '12:30',
                'modalidad'       => WorkSchedule::PRESENCIAL,
                'effective_from'  => '2026-08-24',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $jornadas = WorkSchedule::where('user_id', $persona->id)->get();

        $this->assertCount(3, $jornadas);
        $this->assertTrue($jornadas->every(fn (WorkSchedule $j) => str_starts_with((string) $j->break_starts_at, '12:30')));
        $this->assertSame('60 min, de 12:30 a 13:30', $jornadas->first()->descansoTexto());

        // Y al copiar el patrón a otra persona, la hora viaja con él.
        $otra = User::create(['name' => 'Caro', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        app(CopiaDeJornadas::class)->copiar($persona->id, $otra->id, Carbon::parse('2026-08-24'));

        $this->assertTrue(WorkSchedule::where('user_id', $otra->id)->get()->every(
            fn (WorkSchedule $j) => str_starts_with((string) $j->break_starts_at, '12:30'),
        ));
    }
}
