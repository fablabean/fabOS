<?php

namespace Tests\Feature;

use App\Filament\Resources\CourseEditions\Pages\EditCourseEdition;
use App\Filament\Resources\CourseEditions\RelationManagers\EnrollmentsRelationManager;
use App\Models\Area;
use App\Models\Asset;
use App\Models\AssetAdvisor;
use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\Enrollment;
use App\Models\NotificationLog;
use App\Models\Reservation;
use App\Models\RiskFamily;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\WorkSchedule;
use App\Services\Auth\TwoFactorService;
use App\Services\Training\PracticaService;
use App\Services\Training\TrainingException;
use App\Services\Training\TrainingService;
use App\Support\FactoresDeSesion;
use Database\Seeders\NotificationTemplateSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La prueba practica de un curso, agendada (§9).
 *
 * Quien aprobaba el examen teorico se quedaba con una frase —«falta la
 * evaluacion presencial»— y sin forma de pedirla. Ahora la pide como una
 * asesoria: las horas en que alguien del area puede verla, y el sistema
 * reserva el tiempo de esa persona. Al firmarla, sale el certifab.
 */
class PruebaPracticaTest extends TestCase
{
    use RefreshDatabase;

    private Course $curso;

    private CourseEdition $edicion;

    private Area $area;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(NotificationTemplateSeeder::class);

        // Lunes por la mañana: quienes evalúan trabajan los lunes.
        $this->travelTo(Carbon::parse('2026-08-24 07:00', config('fabos.lab.timezone')));

        UserCategory::firstOrCreate(
            ['slug' => 'invitado'],
            ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1, 'client_kind' => 'externo'],
        );

        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $this->area = Area::create(['slug' => 'impresion-3d', 'name' => 'Impresión 3D']);

        $this->curso = Course::create([
            'slug' => 'creality-hi', 'name' => 'Creality Hi · kilo', 'area_id' => $this->area->id,
            'level' => 'kilo', 'summary' => 'Uso autónomo', 'hours' => 4, 'passing_score' => 80,
            'requires_practical' => true, 'is_active' => true, 'is_public' => true,
        ]);

        $familia = RiskFamily::create(['area_id' => $this->area->id, 'slug' => 'fdm', 'name' => 'Impresión FDM']);
        $this->curso->riskFamilies()->attach($familia->id);

        foreach (range(1, 5) as $i) {
            $this->curso->questions()->create([
                'position' => $i, 'prompt' => 'Pregunta ' . $i,
                'options' => ['Mal', 'Bien'], 'correct' => 1,
            ]);
        }

        $this->edicion = CourseEdition::create([
            'course_id' => $this->curso->id, 'code' => 'ED-1',
            'capacity' => 10, 'status' => 'abierta', 'is_self_paced' => true,
        ]);
    }

    private function hora(string $hhmm): Carbon
    {
        return Carbon::parse('2026-08-24 ' . $hhmm, config('fabos.lab.timezone'));
    }

    /** Alguien del equipo que asesora el área, en jornada presencial el lunes. */
    private function evaluador(string $nombre, string $rol = User::ROL_CONSULTOR): User
    {
        $u = User::create(['name' => $nombre, 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $u->assignRole($rol);

        WorkSchedule::create([
            'user_id' => $u->id, 'weekday' => 1,
            'starts_at' => '08:00', 'ends_at' => '18:00',
            'break_minutes' => 60, 'modalidad' => WorkSchedule::PRESENCIAL,
            'effective_from' => '2026-01-01',
        ]);

        $maquina = Asset::firstOrCreate(
            ['name' => 'Creality Hi'],
            ['area_id' => $this->area->id, 'kind' => 'fijo', 'status' => 'operativo', 'is_reservable' => true],
        );
        AssetAdvisor::firstOrCreate(['user_id' => $u->id, 'asset_id' => $maquina->id]);

        return $u->fresh();
    }

    private function inscrito(): Enrollment
    {
        $u = User::create(['name' => 'Estudiante', 'email' => uniqid() . '@test.co', 'status' => 'activo']);

        return Enrollment::create([
            'course_edition_id' => $this->edicion->id, 'user_id' => $u->id,
            'status' => 'inscrito', 'enrolled_at' => now(),
        ]);
    }

    /** Con el examen aprobado. */
    private function conTeoria(): Enrollment
    {
        $i = $this->inscrito();
        $respuestas = $this->curso->questions->mapWithKeys(fn ($p) => [$p->id => 1])->all();
        app(TrainingService::class)->calificarExamen($i, $respuestas);

        return $i->fresh();
    }

    private function entra(User $u): void
    {
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);
    }

    // ------------------------------------------------------ pedir la hora

    public function test_con_la_teoria_aprobada_hay_horas_con_alguien_del_area(): void
    {
        $this->evaluador('Michael');
        $i = $this->conTeoria();

        $franjas = app(PracticaService::class)->franjas($i);

        $this->assertNotEmpty($franjas);
        $this->assertSame(60, (int) $franjas->first()['inicio']->diffInMinutes($franjas->first()['fin']), 'dura lo que dice la configuración');
    }

    public function test_sin_examen_aprobado_no_se_agenda(): void
    {
        $this->evaluador('Michael');
        $i = $this->inscrito();

        $this->expectException(TrainingException::class);
        $this->expectExceptionMessage('examen teórico');

        app(PracticaService::class)->agendar($i, $this->hora('10:00'));
    }

    public function test_al_agendar_se_reserva_el_tiempo_de_quien_evalua_y_se_avisa_a_los_dos(): void
    {
        $michael = $this->evaluador('Michael');
        $i = $this->conTeoria();

        $reserva = app(PracticaService::class)->agendar($i, $this->hora('10:00'));

        $this->assertSame('practica', $reserva->mode);
        $this->assertSame('confirmada', $reserva->status);
        $this->assertSame($michael->id, $reserva->reservable_id);
        $this->assertSame($i->id, $reserva->enrollment_id);
        $this->assertSame($i->user_id, $reserva->user_id);
        $this->assertSame('Creality Hi · kilo', $reserva->sobreQue());

        $this->assertTrue(NotificationLog::where('key', 'practica.agendada')->where('user_id', $i->user_id)->where('status', 'enviado')->exists());
        $this->assertTrue(NotificationLog::where('key', 'practica.asignada')->where('user_id', $michael->id)->where('status', 'enviado')->exists());

        // Y ya no puede pedir otra mientras esa esté en pie.
        $this->assertFalse($i->fresh()->puedeAgendarPractica());
        $this->assertSame($reserva->id, $i->fresh()->practicaAgendada()->id);
    }

    /** Quien evalúa no queda libre para una asesoría a esa hora: es la misma persona. */
    public function test_la_practica_ocupa_a_quien_evalua(): void
    {
        $michael = $this->evaluador('Michael');
        $i = $this->conTeoria();

        app(PracticaService::class)->agendar($i, $this->hora('10:00'));

        $this->assertNotNull(app(\App\Services\Booking\BookingService::class)->porQueNoEstaLibre($michael, $this->hora('10:00'), $this->hora('11:00')));
    }

    // -------------------------------------------------------- las pantallas

    public function test_desde_mi_cuenta_se_pide_y_se_ve(): void
    {
        $this->evaluador('Michael');
        $i = $this->conTeoria();

        $this->actingAs($i->user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Agendar la prueba práctica');

        $this->actingAs($i->user)
            ->get(route('formacion.practica', $i))
            ->assertOk()
            ->assertSee('Prueba práctica')
            ->assertSee('Agendar la práctica');

        $this->actingAs($i->user)
            ->post(route('formacion.practica.agendar', $i), ['inicio' => $this->hora('10:00')->format('Y-m-d H:i:s')])
            ->assertRedirect(route('home'))
            ->assertSessionHas('status', fn (string $m) => str_contains($m, 'Michael'));

        $this->actingAs($i->user)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Práctica agendada')
            ->assertSee('Michael')
            ->assertDontSee('Agendar la prueba práctica');
    }

    public function test_la_practica_de_otra_persona_no_se_agenda(): void
    {
        $this->evaluador('Michael');
        $i = $this->conTeoria();

        $this->actingAs($this->inscrito()->user)
            ->get(route('formacion.practica', $i))
            ->assertForbidden();
    }

    /** Quien evalúa la ve en su cuenta, marcada como práctica. */
    public function test_quien_evalua_la_ve_en_su_cuenta(): void
    {
        $michael = $this->evaluador('Michael');
        $i = $this->conTeoria();

        app(PracticaService::class)->agendar($i, $this->hora('10:00'));

        $this->actingAs($michael)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Asesorías que voy a atender', false)
            ->assertSee('Práctica')
            ->assertSee('Creality Hi · kilo')
            ->assertSee('Estudiante');
    }

    /** Y la persona puede cancelarla y pedir otra hora. */
    public function test_se_puede_cancelar_y_volver_a_pedir(): void
    {
        $this->evaluador('Michael');
        $i = $this->conTeoria();

        $reserva = app(PracticaService::class)->agendar($i, $this->hora('10:00'));

        $this->actingAs($i->user)
            ->post(route('reservas.cancel', $reserva))
            ->assertRedirect();

        $this->assertSame('cancelada', $reserva->fresh()->status);
        $this->assertTrue($i->fresh()->puedeAgendarPractica());
    }

    // --------------------------------------------------------------- panel

    /** El panel enseña el examen y la práctica de cada inscrito. */
    public function test_el_panel_ensena_el_resultado_del_examen_y_la_practica(): void
    {
        $michael = $this->evaluador('Michael');
        $admin = $this->evaluador('Admin', User::ROL_ADMINISTRADOR);
        $i = $this->conTeoria();
        app(PracticaService::class)->agendar($i, $this->hora('10:00'));

        $this->entra($admin);

        Livewire::test(EnrollmentsRelationManager::class, [
            'ownerRecord' => $this->edicion,
            'pageClass'   => EditCourseEdition::class,
        ])
            ->assertCanSeeTableRecords([$i])
            ->assertTableColumnStateSet('theory_score', '100%', $i)
            ->assertTableColumnStateSet('practica', 'Agendada 24/08 10:00', $i)
            ->assertSee('1 intento');
    }

    /** Firmar da el certifab en el mismo acto, y cierra la hora reservada. */
    public function test_firmar_la_practica_otorga_el_certifab_y_cierra_la_reserva(): void
    {
        $this->evaluador('Michael');
        $admin = $this->evaluador('Admin', User::ROL_ADMINISTRADOR);
        $i = $this->conTeoria();
        $reserva = app(PracticaService::class)->agendar($i, $this->hora('10:00'));

        $this->entra($admin);

        Livewire::test(EnrollmentsRelationManager::class, [
            'ownerRecord' => $this->edicion,
            'pageClass'   => EditCourseEdition::class,
        ])
            ->callAction(TestAction::make('practica')->table($i), ['notas' => 'Niveló la cama sin ayuda.'])
            ->assertHasNoActionErrors();

        $i->refresh();

        $this->assertTrue($i->practicaAprobada());
        $this->assertSame($admin->id, $i->practical_by);
        $this->assertSame('aprobado', $i->status);
        $this->assertNotNull($i->certificate_code);
        $this->assertDatabaseHas('certifabs', ['user_id' => $i->user_id, 'level' => 'kilo']);
        $this->assertSame('completada', $reserva->fresh()->status);
    }

    /** En un curso con práctica, «Aprobar» sobra hasta que se firma: firmar ya aprueba. */
    public function test_en_un_curso_con_practica_aprobar_no_se_ofrece_antes_de_firmar(): void
    {
        $admin = $this->evaluador('Admin', User::ROL_ADMINISTRADOR);
        $i = $this->conTeoria();

        $this->entra($admin);

        Livewire::test(EnrollmentsRelationManager::class, [
            'ownerRecord' => $this->edicion,
            'pageClass'   => EditCourseEdition::class,
        ])
            ->assertActionVisible(TestAction::make('practica')->table($i))
            ->assertActionHidden(TestAction::make('aprobar')->table($i));
    }

    /** Por ahora firman administradores y superadmin: un consultor no ve el botón. */
    public function test_un_consultor_no_puede_firmar(): void
    {
        $consultor = $this->evaluador('Consultor');
        $i = $this->conTeoria();

        $this->entra($consultor);

        Livewire::test(EnrollmentsRelationManager::class, [
            'ownerRecord' => $this->edicion,
            'pageClass'   => EditCourseEdition::class,
        ])
            ->assertActionHidden(TestAction::make('practica')->table($i))
            ->assertActionHidden(TestAction::make('citar')->table($i));
    }

    /** La coordinación cita: hora y evaluador concretos, y correo a la persona. */
    public function test_la_coordinacion_cita_a_la_practica(): void
    {
        $michael = $this->evaluador('Michael');
        $admin = $this->evaluador('Admin', User::ROL_ADMINISTRADOR);
        $i = $this->conTeoria();

        $this->entra($admin);

        Livewire::test(EnrollmentsRelationManager::class, [
            'ownerRecord' => $this->edicion,
            'pageClass'   => EditCourseEdition::class,
        ])
            ->callAction(TestAction::make('citar')->table($i), [
                'inicio'       => '2026-08-24 14:00',
                'evaluador_id' => $michael->id,
                'nota'         => 'Trae tu archivo.',
            ])
            ->assertHasNoActionErrors();

        $reserva = $i->fresh()->practicaAgendada();

        $this->assertNotNull($reserva);
        $this->assertSame($michael->id, $reserva->reservable_id);
        $this->assertSame('14:00', $reserva->starts_at->timezone(config('fabos.lab.timezone'))->format('H:i'));

        $aviso = NotificationLog::where('key', 'practica.citada')->where('user_id', $i->user_id)->firstOrFail();
        $this->assertSame('enviado', $aviso->status);
        $this->assertStringContainsString('Trae tu archivo', $aviso->body);
        $this->assertStringContainsString('Michael', $aviso->body);
    }

    /**
     * Citar para dentro de un rato, por la tarde. El minimo del campo llegaba
     * en UTC, cinco horas adelante: a las cuatro de la tarde no dejaba citar
     * para las cinco.
     */
    public function test_se_puede_citar_para_dentro_de_un_rato(): void
    {
        $michael = $this->evaluador('Michael');
        $admin = $this->evaluador('Admin', User::ROL_ADMINISTRADOR);
        $i = $this->conTeoria();

        $this->travelTo(Carbon::parse('2026-08-24 16:36', config('fabos.lab.timezone')));
        $this->entra($admin);

        $accion = Livewire::test(EnrollmentsRelationManager::class, [
            'ownerRecord' => $this->edicion,
            'pageClass'   => EditCourseEdition::class,
        ]);

        $accion->callAction(TestAction::make('citar')->table($i), [
            'inicio'       => '2026-08-24 17:00',
            'evaluador_id' => $michael->id,
        ])->assertHasNoActionErrors();

        $this->assertSame('17:00', $i->fresh()->practicaAgendada()->starts_at->timezone(config('fabos.lab.timezone'))->format('H:i'));

    }

    /**
     * Las horas que se ofrecen al citar son las libres de esa persona: en
     * jornada, sin nada reservado, fuera de su descanso, y solo por venir.
     */
    public function test_al_citar_se_ofrecen_solo_las_horas_libres_del_evaluador(): void
    {
        $michael = $this->evaluador('Michael');
        $i = $this->conTeoria();

        // Ya tiene una practica a las 10 y su almuerzo es de 12 a 13.
        app(PracticaService::class)->agendar($this->conTeoria(), $this->hora('10:00'));
        WorkSchedule::where('user_id', $michael->id)->update(['break_starts_at' => '12:00']);

        $horas = app(PracticaService::class)->horasDe($michael, $i, dias: 1);

        $this->assertArrayHasKey('2026-08-24 09:00', $horas);
        $this->assertArrayNotHasKey('2026-08-24 10:00', $horas, 'ya tiene una práctica');
        $this->assertArrayNotHasKey('2026-08-24 12:00', $horas, 'es su almuerzo');
        $this->assertArrayHasKey('2026-08-24 13:00', $horas);
        $this->assertArrayNotHasKey('2026-08-24 06:00', $horas, 'antes de su jornada');
        $this->assertSame('Lun 24/08 · 13:00–14:00', $horas['2026-08-24 13:00']);

        // Y una hora que no esta en la lista no se acepta desde el panel.
        $admin = $this->evaluador('Admin', User::ROL_ADMINISTRADOR);
        $this->entra($admin);

        Livewire::test(EnrollmentsRelationManager::class, [
            'ownerRecord' => $this->edicion,
            'pageClass'   => EditCourseEdition::class,
        ])
            ->callAction(TestAction::make('citar')->table($i), [
                'evaluador_id' => $michael->id,
                'inicio'       => '2026-08-24 12:00',
            ])
            ->assertHasActionErrors(['inicio']);
    }

    /** Citar a alguien que ya tiene algo a esa hora se rechaza, y se dice qué tiene. */
    public function test_no_se_cita_con_quien_esta_ocupado(): void
    {
        $michael = $this->evaluador('Michael');
        $otra = $this->conTeoria();
        app(PracticaService::class)->agendar($otra, $this->hora('10:00'));

        $i = $this->conTeoria();

        $this->expectException(TrainingException::class);
        $this->expectExceptionMessage('Michael ya tiene algo a esa hora');

        app(PracticaService::class)->citar($i, $michael, $this->hora('10:30'));
    }
}
