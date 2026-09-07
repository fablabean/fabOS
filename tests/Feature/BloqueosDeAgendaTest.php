<?php

namespace Tests\Feature;

use App\Filament\Resources\ScheduleExceptions\Pages\CreateScheduleException;
use App\Models\Area;
use App\Models\Asset;
use App\Models\AssetAdvisor;
use App\Models\ScheduleException;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Auth\TwoFactorService;
use App\Services\Booking\AsesoriaService;
use App\Services\Booking\BookingService;
use App\Services\Staffing\CoverageService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Bloqueos de agenda: una franja del día, puntual o cada semana (§5).
 *
 * Una ausencia era de días enteros. Pero la agenda también se rompe por
 * horas: la clase de inglés de los jueves de cuatro a cinco hasta diciembre,
 * una cita el martes a las diez. Sin poder decirlo, esa hora se seguía
 * ofreciendo, y el choque aparecía con la persona ya en camino a su clase.
 */
class BloqueosDeAgendaTest extends TestCase
{
    use RefreshDatabase;

    /** El 3 de septiembre de 2026 es jueves. */
    private const JUEVES = '2026-09-03';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-01 07:00', config('fabos.lab.timezone')));
    }

    private function servicio(): CoverageService
    {
        return app(CoverageService::class);
    }

    /** En jornada presencial de lunes a viernes, de 8 a 18. */
    private function colaborador(string $nombre): User
    {
        $u = User::create(['name' => $nombre, 'email' => uniqid() . '@test.co', 'status' => 'activo']);

        foreach ([1, 2, 3, 4, 5] as $dia) {
            WorkSchedule::create([
                'user_id' => $u->id, 'weekday' => $dia,
                'starts_at' => '08:00', 'ends_at' => '18:00',
                'break_minutes' => 60, 'modalidad' => WorkSchedule::PRESENCIAL,
                'effective_from' => '2026-01-01',
            ]);
        }

        return $u;
    }

    private function franja(string $fecha, string $desde, string $hasta): array
    {
        $tz = config('fabos.lab.timezone');

        return [Carbon::parse($fecha . ' ' . $desde, $tz), Carbon::parse($fecha . ' ' . $hasta, $tz)];
    }

    /** La clase de inglés: cada jueves de 16:00 a 17:00, hasta el 30 de noviembre. */
    private function claseDeIngles(User $u, ?string $hasta = '2026-11-30'): ScheduleException
    {
        return ScheduleException::create([
            'user_id' => $u->id, 'kind' => 'bloqueo', 'note' => 'Clase de inglés',
            'starts_on' => '2026-09-01', 'ends_on' => $hasta,
            'starts_time' => '16:00', 'ends_time' => '17:00', 'weekday' => 4,
        ]);
    }

    // -------------------------------------------------------------- semanal

    public function test_un_bloqueo_semanal_saca_a_la_persona_de_esa_franja(): void
    {
        $ana = $this->colaborador('Ana');
        $this->claseDeIngles($ana);

        [$d, $h] = $this->franja(self::JUEVES, '16:00', '17:00');
        $this->assertFalse($this->servicio()->enJornada($d, $h)->contains('id', $ana->id));

        // Un rato que la pisa, también.
        [$d, $h] = $this->franja(self::JUEVES, '15:30', '16:15');
        $this->assertFalse($this->servicio()->enJornada($d, $h)->contains('id', $ana->id));
    }

    public function test_pero_sigue_el_resto_del_dia_y_los_otros_dias(): void
    {
        $ana = $this->colaborador('Ana');
        $this->claseDeIngles($ana);

        // Antes y después, ese mismo jueves. Tocarse por el borde no es solaparse.
        [$d, $h] = $this->franja(self::JUEVES, '15:00', '16:00');
        $this->assertTrue($this->servicio()->enJornada($d, $h)->contains('id', $ana->id));

        [$d, $h] = $this->franja(self::JUEVES, '17:00', '18:00');
        $this->assertTrue($this->servicio()->enJornada($d, $h)->contains('id', $ana->id));

        // El viernes a esa hora, sí está.
        [$d, $h] = $this->franja('2026-09-04', '16:00', '17:00');
        $this->assertTrue($this->servicio()->enJornada($d, $h)->contains('id', $ana->id));

        // Y la franja atendida del jueves no se encoge: abre y cierra igual.
        $this->assertSame(['08:00:00', '18:00:00'], $this->servicio()->franjaAtendida(Carbon::parse(self::JUEVES)));
    }

    public function test_el_bloqueo_semanal_termina_en_su_fecha(): void
    {
        $ana = $this->colaborador('Ana');
        $this->claseDeIngles($ana, hasta: '2026-11-30');

        // El jueves 26 de noviembre todavía tiene clase; el 3 de diciembre ya no.
        [$d, $h] = $this->franja('2026-11-26', '16:00', '17:00');
        $this->assertFalse($this->servicio()->enJornada($d, $h)->contains('id', $ana->id));

        [$d, $h] = $this->franja('2026-12-03', '16:00', '17:00');
        $this->assertTrue($this->servicio()->enJornada($d, $h)->contains('id', $ana->id));
    }

    public function test_sin_fecha_de_fin_es_hasta_nuevo_aviso(): void
    {
        $ana = $this->colaborador('Ana');
        $this->claseDeIngles($ana, hasta: null);

        [$d, $h] = $this->franja('2027-03-04', '16:00', '17:00'); // un jueves lejano
        $this->assertFalse($this->servicio()->enJornada($d, $h)->contains('id', $ana->id));
    }

    // -------------------------------------------------------------- puntual

    public function test_un_bloqueo_puntual_vale_solo_ese_dia(): void
    {
        $ana = $this->colaborador('Ana');

        ScheduleException::create([
            'user_id' => $ana->id, 'kind' => 'permiso', 'note' => 'Cita médica',
            'starts_on' => '2026-09-02', 'ends_on' => '2026-09-02',
            'starts_time' => '10:00', 'ends_time' => '11:30',
        ]);

        [$d, $h] = $this->franja('2026-09-02', '10:30', '11:00');
        $this->assertFalse($this->servicio()->enJornada($d, $h)->contains('id', $ana->id));

        [$d, $h] = $this->franja('2026-09-03', '10:30', '11:00');
        $this->assertTrue($this->servicio()->enJornada($d, $h)->contains('id', $ana->id));
    }

    /** También vale para quien está por turno programado, no solo por patrón. */
    public function test_tambien_saca_a_quien_esta_por_turno(): void
    {
        $tz = config('fabos.lab.timezone');
        $beto = User::create(['name' => 'Beto', 'email' => uniqid() . '@test.co', 'status' => 'activo']);

        ShiftAssignment::create([
            'user_id' => $beto->id,
            'starts_at' => Carbon::parse('2026-09-05 08:00', $tz), // sábado
            'ends_at' => Carbon::parse('2026-09-05 14:00', $tz),
            'reason' => 'Apertura del sábado',
        ]);

        [$d, $h] = $this->franja('2026-09-05', '10:00', '11:00');
        $this->assertTrue($this->servicio()->enJornada($d, $h)->contains('id', $beto->id));

        ScheduleException::create([
            'user_id' => $beto->id, 'kind' => 'bloqueo',
            'starts_on' => '2026-09-05', 'ends_on' => '2026-09-05',
            'starts_time' => '10:00', 'ends_time' => '11:00',
        ]);

        $this->assertFalse($this->servicio()->enJornada($d, $h)->contains('id', $beto->id));
    }

    /** Sin persona, es todo el equipo: la reunión de los lunes. */
    public function test_un_bloqueo_general_deja_esa_franja_sin_nadie(): void
    {
        $this->colaborador('Ana');
        $this->colaborador('Beto');

        ScheduleException::create([
            'kind' => 'bloqueo', 'note' => 'Reunión de equipo',
            'starts_on' => '2026-09-01', 'ends_on' => null,
            'starts_time' => '09:00', 'ends_time' => '10:00', 'weekday' => 1,
        ]);

        [$d, $h] = $this->franja('2026-09-07', '09:00', '10:00'); // lunes
        $this->assertFalse($this->servicio()->hayCobertura($d, $h));

        [$d, $h] = $this->franja('2026-09-07', '10:00', '11:00');
        $this->assertTrue($this->servicio()->hayCobertura($d, $h));
    }

    /** Lo de días enteros sigue igual: sin horas, es la ausencia de siempre. */
    public function test_una_ausencia_de_dias_enteros_sigue_encogiendo_la_franja(): void
    {
        $ana = $this->colaborador('Ana');

        ScheduleException::create([
            'user_id' => $ana->id, 'kind' => 'vacaciones',
            'starts_on' => '2026-09-01', 'ends_on' => '2026-09-05',
        ]);

        $this->assertNull($this->servicio()->franjaAtendida(Carbon::parse(self::JUEVES)));
    }

    // ------------------------------------------------- donde de verdad importa

    /** La asesoría no se ofrece en la hora de la clase. */
    public function test_no_se_ofrecen_asesorias_en_la_hora_bloqueada(): void
    {
        $ana = $this->colaborador('Ana');
        $this->claseDeIngles($ana);

        $area = Area::create(['name' => 'Prototipado', 'slug' => 'prototipado']);
        $equipo = Asset::create([
            'name' => 'Cortadora láser', 'slug' => 'laser', 'area_id' => $area->id,
            'status' => 'operativo', 'is_reservable' => true,
        ]);
        AssetAdvisor::create(['user_id' => $ana->id, 'asset_id' => $equipo->id]);

        [$d, $h] = $this->franja(self::JUEVES, '16:00', '16:45');
        $this->assertEmpty(app(AsesoriaService::class)->disponiblesPara($equipo, $d, $h));

        [$d, $h] = $this->franja(self::JUEVES, '17:00', '17:45');
        $this->assertNotEmpty(app(AsesoriaService::class)->disponiblesPara($equipo, $d, $h));
    }

    /** Y a quien la elige a mano se le dice por qué no, con el motivo. */
    public function test_quien_la_elige_a_mano_ve_el_motivo(): void
    {
        $ana = $this->colaborador('Ana');
        $this->claseDeIngles($ana);

        [$d, $h] = $this->franja(self::JUEVES, '16:00', '17:00');

        $this->assertSame(
            'Ana tiene esa hora bloqueada (Clase de inglés).',
            app(BookingService::class)->porQueNoEstaLibre($ana, $d, $h),
        );

        [$d, $h] = $this->franja(self::JUEVES, '14:00', '15:00');
        $this->assertNull(app(BookingService::class)->porQueNoEstaLibre($ana, $d, $h));
    }

    // ---------------------------------------------------------------- panel

    /** Desde Jornadas → Ausencias y bloqueos, con el formulario que pregunta el alcance. */
    public function test_se_crea_desde_el_panel(): void
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $admin = User::create(['name' => 'Admin', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $admin->assignRole(User::ROL_SUPERADMIN);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($admin);
        $servicio->confirmar($admin, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($admin->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        $ana = $this->colaborador('Ana');

        Livewire::test(CreateScheduleException::class)
            ->fillForm([
                'user_id'     => $ana->id,
                'kind'        => 'bloqueo',
                'alcance'     => 'franja',
                'starts_time' => '16:00',
                'ends_time'   => '17:00',
                'weekday'     => 4,
                'starts_on'   => '2026-09-01',
                'ends_on'     => '2026-11-30',
                'note'        => 'Clase de inglés',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $bloqueo = ScheduleException::where('user_id', $ana->id)->firstOrFail();

        $this->assertTrue($bloqueo->seRepite());
        $this->assertSame(4, (int) $bloqueo->weekday);
        $this->assertStringStartsWith('16:00', $bloqueo->starts_time);
        $this->assertSame('Cada jueves de 16:00 a 17:00, desde el 01/09/2026 hasta el 30/11/2026', $bloqueo->cuando());

        [$d, $h] = $this->franja(self::JUEVES, '16:00', '17:00');
        $this->assertFalse($this->servicio()->enJornada($d, $h)->contains('id', $ana->id));
    }

    /**
     * La lista carga, con lo vigente por defecto y lo vencido a un filtro.
     *
     * Produccion devolvio un 500 aqui: el filtro llamaba `$q` al parametro y
     * Filament, que inyecta por nombre, le paso un constructor de consultas
     * sin modelo. Ninguna prueba abria esta lista.
     */
    public function test_la_lista_del_panel_carga_y_filtra_lo_vigente(): void
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $admin = User::create(['name' => 'Admin', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $admin->assignRole(User::ROL_SUPERADMIN);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($admin);
        $servicio->confirmar($admin, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($admin->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        $ana = $this->colaborador('Ana');
        $vigente = $this->claseDeIngles($ana);
        $vencida = ScheduleException::create([
            'user_id' => $ana->id, 'kind' => 'vacaciones',
            'starts_on' => '2026-01-05', 'ends_on' => '2026-01-16',
        ]);

        $this->get('/admin/schedule-exceptions')->assertOk()->assertSee('Clase de inglés');

        Livewire::test(\App\Filament\Resources\ScheduleExceptions\Pages\ListScheduleExceptions::class)
            ->assertCanSeeTableRecords([$vigente])
            ->assertCanNotSeeTableRecords([$vencida])
            ->sortTable('cuando')
            ->removeTableFilter('vigentes')
            ->assertCanSeeTableRecords([$vigente, $vencida]);
    }

    /** Y una ausencia de días enteros se guarda sin horas, como siempre. */
    public function test_una_ausencia_de_dias_se_guarda_sin_horas(): void
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $admin = User::create(['name' => 'Admin', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $admin->assignRole(User::ROL_SUPERADMIN);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($admin);
        $servicio->confirmar($admin, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($admin->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        $ana = $this->colaborador('Ana');

        Livewire::test(CreateScheduleException::class)
            ->fillForm([
                'user_id'   => $ana->id,
                'kind'      => 'vacaciones',
                'alcance'   => 'dia',
                'starts_on' => '2026-09-14',
                'ends_on'   => '2026-09-18',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $ausencia = ScheduleException::where('user_id', $ana->id)->firstOrFail();

        $this->assertFalse($ausencia->esDeFranja());
        $this->assertNull($ausencia->weekday);
        $this->assertSame('Del 14/09/2026 al 18/09/2026', $ausencia->cuando());
    }
}
