<?php

namespace Tests\Feature;

use App\Filament\Pages\Personal\MiSemana;
use App\Filament\Pages\Personal\SemanaDelEquipo;
use App\Models\Area;
use App\Models\Asset;
use App\Models\AssetAdvisor;
use App\Models\JornadaDelDia;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\WorkSchedule;
use App\Services\Auth\TwoFactorService;
use App\Services\Booking\AsesoriaService;
use App\Services\Booking\BookingException;
use App\Services\Staffing\CoverageService;
use App\Services\Staffing\Programacion;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Modalidad de trabajo (§5): normal, híbrida y flexible; cambios por fecha,
 * el mínimo presencial por área y las asesorías virtuales.
 */
class ModalidadDeTrabajoTest extends TestCase
{
    use RefreshDatabase;

    private Area $area;

    private Asset $equipo;

    /** Martes 25 de agosto de 2026: el día que se cambia en las pruebas. */
    private Carbon $martes;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $tz = config('fabos.lab.timezone');
        $this->travelTo(Carbon::parse('2026-08-24 07:00', $tz));
        $this->martes = Carbon::parse('2026-08-25', $tz);

        UserCategory::firstOrCreate(
            ['slug' => 'invitado'],
            ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1, 'client_kind' => 'externo'],
        );

        $this->area = Area::create(['slug' => 'impresion-3d', 'name' => 'Impresión 3D']);
        $this->equipo = Asset::create([
            'area_id' => $this->area->id, 'name' => 'Prusa', 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true, 'is_public' => true,
            'min_minutes' => 30, 'autonomous_minutes' => 60, 'max_minutes' => 720,
        ]);
    }

    /** Del equipo, de lunes a viernes de 8:00 a 17:30 en el laboratorio, asesora la Prusa. */
    private function colaborador(string $nombre, string $modalidad = User::TRABAJO_NORMAL, bool $asesora = true): User
    {
        $u = User::create(['name' => $nombre, 'email' => uniqid() . '@lab.co', 'status' => 'activo', 'modalidad_trabajo' => $modalidad]);
        $u->assignRole(Role::findOrCreate(User::ROL_PRACTICANTE, 'web'));

        foreach (range(1, 5) as $dia) {
            WorkSchedule::create([
                'user_id' => $u->id, 'weekday' => $dia, 'starts_at' => '08:00', 'ends_at' => '17:30',
                'break_minutes' => 0, 'modalidad' => WorkSchedule::PRESENCIAL, 'effective_from' => '2026-01-01',
            ]);
        }

        if ($asesora) {
            AssetAdvisor::create(['user_id' => $u->id, 'asset_id' => $this->equipo->id, 'es_responsable' => false]);
        }

        return $u->fresh();
    }

    private function coordinacion(): User
    {
        $u = User::create(['name' => 'Coordinación', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_SUPERADMIN, 'web'));

        return $u->fresh();
    }

    private function entrar(User $u): void
    {
        if ($u->segundoFactorObligatorio()) {
            $servicio = app(TwoFactorService::class);
            $secreto = $servicio->generarSecreto($u);
            $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));
        }

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);
    }

    private function hora(string $hora): Carbon
    {
        return $this->martes->copy()->setTimeFromTimeString($hora);
    }

    public function test_la_modalidad_flexible_es_el_contrato_flexible_de_la_nomina(): void
    {
        $u = $this->colaborador('Flexi', User::TRABAJO_FLEXIBLE);
        $this->assertTrue($u->tieneContratoFlexible());

        $u->update(['modalidad_trabajo' => User::TRABAJO_HIBRIDO]);
        $this->assertFalse($u->fresh()->tieneContratoFlexible());
    }

    public function test_hibrida_marca_la_tarde_remota_y_deja_de_contar_como_cobertura_esas_horas(): void
    {
        $ana = $this->colaborador('Ana', User::TRABAJO_HIBRIDO);
        $this->colaborador('Beto'); // otro presencial del área

        app(Programacion::class)->guardarDia($ana, $this->martes, [
            ['inicio' => '08:00', 'fin' => '12:00', 'modalidad' => 'presencial'],
            ['inicio' => '13:00', 'fin' => '17:30', 'modalidad' => 'remota'],
        ], $ana);

        $cobertura = app(CoverageService::class);
        $this->assertTrue($cobertura->enJornada($this->hora('09:00'), $this->hora('10:00'))->contains('id', $ana->id));
        $this->assertFalse($cobertura->enJornada($this->hora('14:00'), $this->hora('15:00'))->contains('id', $ana->id));
        $this->assertTrue($cobertura->enJornada($this->hora('14:00'), $this->hora('15:00'), incluirRemota: true)->contains('id', $ana->id));

        // El miércoles sigue con su horario de siempre.
        $miercoles = $this->martes->copy()->addDay();
        $this->assertTrue($cobertura->enJornada($miercoles->copy()->setTime(14, 0), $miercoles->copy()->setTime(15, 0))->contains('id', $ana->id));
    }

    public function test_hibrida_no_mueve_el_horario_y_normal_no_cambia_nada(): void
    {
        $this->colaborador('Beto');
        $ana = $this->colaborador('Ana', User::TRABAJO_HIBRIDO);
        $nora = $this->colaborador('Nora');

        try {
            app(Programacion::class)->guardarDia($ana, $this->martes, [['inicio' => '14:00', 'fin' => '22:00', 'modalidad' => 'presencial']], $ana);
            $this->fail('La híbrida no debía poder mover el horario.');
        } catch (BookingException $e) {
            $this->assertStringContainsString('flexible', $e->getMessage());
        }

        $this->expectException(BookingException::class);
        app(Programacion::class)->guardarDia($nora, $this->martes, [['inicio' => '08:00', 'fin' => '17:30', 'modalidad' => 'remota']], $nora);
    }

    public function test_flexible_mueve_el_horario_y_el_laboratorio_queda_atendido_hasta_tarde(): void
    {
        $this->colaborador('Beto');
        $flor = $this->colaborador('Flor', User::TRABAJO_FLEXIBLE);

        app(Programacion::class)->guardarDia($flor, $this->martes, [['inicio' => '14:00', 'fin' => '22:00', 'modalidad' => 'presencial']], $flor);

        $cobertura = app(CoverageService::class);
        $this->assertSame(['08:00:00', '22:00:00'], array_map(fn ($h) => strlen($h) === 5 ? $h . ':00' : $h, $cobertura->franjaAtendida($this->martes)));
        $this->assertFalse($cobertura->enJornada($this->hora('09:00'), $this->hora('10:00'))->contains('id', $flor->id));
        $this->assertTrue($cobertura->enJornada($this->hora('20:00'), $this->hora('21:00'))->contains('id', $flor->id));
    }

    public function test_no_deja_un_area_sin_nadie_presencial_salvo_que_la_coordinacion_lo_fuerce(): void
    {
        $ana = $this->colaborador('Ana', User::TRABAJO_HIBRIDO);
        $remoto = [['inicio' => '08:00', 'fin' => '17:30', 'modalidad' => 'remota']];

        try {
            app(Programacion::class)->guardarDia($ana, $this->martes, $remoto, $ana);
            $this->fail('Ana es la única presencial de Impresión 3D: no debía poder irse remota.');
        } catch (BookingException $e) {
            $this->assertStringContainsString('Impresión 3D', $e->getMessage());
        }

        $this->assertSame(0, JornadaDelDia::count());

        // Con otra persona del área presencial, sí.
        $beto = $this->colaborador('Beto');
        app(Programacion::class)->guardarDia($ana, $this->martes, $remoto, $ana);
        $this->assertSame(1, JornadaDelDia::count());

        // Y Beto ya no puede irse también.
        $beto->update(['modalidad_trabajo' => User::TRABAJO_HIBRIDO]);
        $this->expectException(BookingException::class);
        app(Programacion::class)->guardarDia($beto->fresh(), $this->martes, $remoto, $beto);
    }

    public function test_la_coordinacion_puede_forzarlo_y_queda_anotado(): void
    {
        $ana = $this->colaborador('Ana', User::TRABAJO_NORMAL);

        app(Programacion::class)->guardarDia($ana, $this->martes, [['inicio' => '08:00', 'fin' => '17:30', 'modalidad' => 'remota']], $this->coordinacion(), forzar: true);

        $this->assertTrue(JornadaDelDia::sole()->forzado);
    }

    public function test_con_el_asesor_remoto_la_asesoria_se_ofrece_virtual_y_queda_dicho(): void
    {
        $this->colaborador('Beto', asesora: false); // cubre el área presencial sin asesorar la Prusa
        $ana = $this->colaborador('Ana', User::TRABAJO_HIBRIDO);
        $ana->update(['enlace_reunion' => 'https://meet.example.com/ana']);
        AssetAdvisor::create(['user_id' => $ana->id, 'asset_id' => Asset::create([
            'area_id' => $this->area->id, 'name' => 'Otra', 'kind' => 'fijo', 'status' => 'operativo',
        ])->id, 'es_responsable' => false]);
        // Beto también asesora en el área (otra máquina), así Ana puede irse remota.
        AssetAdvisor::create(['user_id' => User::where('name', 'Beto')->value('id'), 'asset_id' => Asset::where('name', 'Otra')->value('id'), 'es_responsable' => false]);

        app(Programacion::class)->guardarDia($ana, $this->martes, [['inicio' => '08:00', 'fin' => '17:30', 'modalidad' => 'remota']], $ana);

        $servicio = app(AsesoriaService::class);
        $delMartes = fn ($franjas) => $franjas->filter(fn ($f) => $f['inicio']->isSameDay($this->martes));

        // Sin admitir lo virtual (las prácticas), ese día no hay horas.
        $this->assertCount(0, $delMartes($servicio->franjasDisponibles($this->equipo, null, 3)));

        // Admitiéndolo, sí, y dicho.
        $franjas = $delMartes($servicio->franjasDisponibles($this->equipo, null, 3, permitirVirtual: true));
        $this->assertNotEmpty($franjas);
        $this->assertSame('virtual', $franjas->first()['modalidad']);

        $quien = User::create(['name' => 'Quien pide', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $reserva = $servicio->agendar($quien, $this->equipo, $this->hora('10:00'), $this->hora('10:45'), null, permitirVirtual: true);

        $this->assertSame('virtual', $reserva->modalidad_asesoria);
        $this->assertTrue($reserva->reservable->is($ana));
        $this->assertStringContainsString('https://meet.example.com/ana', AsesoriaService::donde($reserva));
    }

    public function test_con_el_asesor_presencial_la_asesoria_es_presencial(): void
    {
        $this->colaborador('Ana');
        $quien = User::create(['name' => 'Quien pide', 'email' => uniqid() . '@test.co', 'status' => 'activo']);

        $reserva = app(AsesoriaService::class)->agendar($quien, $this->equipo, $this->hora('10:00'), $this->hora('10:45'), null, permitirVirtual: true);

        $this->assertSame('presencial', $reserva->modalidad_asesoria);
        $this->assertSame('Presencial, en el laboratorio.', AsesoriaService::donde($reserva));
    }

    public function test_mi_semana_deja_cambiar_un_dia_y_la_semana_del_equipo_lo_muestra(): void
    {
        $this->colaborador('Beto');
        $ana = $this->colaborador('Ana', User::TRABAJO_HIBRIDO);
        $this->entrar($ana);

        Livewire::test(MiSemana::class)
            ->assertOk()
            ->assertSee('híbrida')
            ->callAction('editarDia', [
                'bloques' => [['inicio' => '08:00', 'fin' => '17:30', 'modalidad' => 'remota']],
            ], ['fecha' => '2026-08-25'])
            ->assertHasNoActionErrors();

        $this->assertSame('remota', JornadaDelDia::where('user_id', $ana->id)->sole()->modalidad);

        Livewire::test(SemanaDelEquipo::class)
            ->assertOk()
            ->assertSee('Ana')
            ->assertSee('Beto')
            ->assertSee('Remoto')
            ->assertSee('Presencial por área')
            ->assertSee('Cambios recientes');
    }

    public function test_nadie_cambia_la_semana_de_otra_persona_salvo_la_coordinacion(): void
    {
        $ana = $this->colaborador('Ana', User::TRABAJO_FLEXIBLE);
        $beto = $this->colaborador('Beto', User::TRABAJO_FLEXIBLE);

        $this->expectException(BookingException::class);
        app(Programacion::class)->guardarDia($ana, $this->martes, [['inicio' => '09:00', 'fin' => '18:00', 'modalidad' => 'presencial']], $beto);
    }
}
