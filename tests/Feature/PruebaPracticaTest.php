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
        $michael = $this->evaluador('Michael');
        $i = $this->conTeoria();
        $reserva = app(PracticaService::class)->agendar($i, $this->hora('10:00'));

        // La firma quien la tiene asignada: Michael, que fue quien la vio.
        $this->entra($michael);

        Livewire::test(EnrollmentsRelationManager::class, [
            'ownerRecord' => $this->edicion,
            'pageClass'   => EditCourseEdition::class,
        ])
            ->callAction(TestAction::make('practica')->table($i), ['notas' => 'Niveló la cama sin ayuda.'])
            ->assertHasNoActionErrors();

        $i->refresh();

        $this->assertTrue($i->practicaAprobada());
        $this->assertSame($michael->id, $i->practical_by);
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

    /**
     * Con la practica asignada, la firma o la reprueba solo quien la tiene:
     * ni un administrador. Sin nadie asignado, la coordinacion.
     */
    public function test_solo_quien_tiene_asignada_la_practica_la_firma_o_la_reprueba(): void
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
            ->assertActionHidden(TestAction::make('practica')->table($i))
            ->assertActionHidden(TestAction::make('reprobar')->table($i));

        // Y por el servicio tampoco: quitar el boton no basta.
        try {
            app(TrainingService::class)->registrarPractica($i, $admin);
            $this->fail('no debía dejar firmar');
        } catch (TrainingException $e) {
            $this->assertStringContainsString('Michael', $e->getMessage());
        }

        // Sin practica agendada —se vio sin cita— firma la coordinacion.
        $sinCita = $this->conTeoria();
        $this->assertTrue($sinCita->puedeEvaluarLaPractica($admin));
        $this->assertFalse($sinCita->puedeEvaluarLaPractica($michael->fresh()), 'un consultor no asignado, no');
    }

    /** Sin el examen aprobado no hay nada que citar, firmar ni aprobar. */
    public function test_sin_examen_aprobado_no_se_cita_ni_se_firma_ni_se_aprueba(): void
    {
        $admin = $this->evaluador('Admin', User::ROL_ADMINISTRADOR);
        $i = $this->inscrito();

        $this->entra($admin);

        Livewire::test(EnrollmentsRelationManager::class, [
            'ownerRecord' => $this->edicion,
            'pageClass'   => EditCourseEdition::class,
        ])
            ->assertActionHidden(TestAction::make('citar')->table($i))
            ->assertActionHidden(TestAction::make('practica')->table($i))
            ->assertActionHidden(TestAction::make('aprobar')->table($i));
    }

    /**
     * Una practica que paso sin firma no desaparece: el barrido de ausencias
     * no la toca, el panel la enseña como «sin validar», y la cierra quien la
     * esperaba diciendo que no vino. Entonces la persona puede pedir otra.
     */
    public function test_una_practica_sin_firmar_no_desaparece_y_la_cierra_quien_la_esperaba(): void
    {
        $michael = $this->evaluador('Michael');
        $admin = $this->evaluador('Admin', User::ROL_ADMINISTRADOR);
        $i = $this->conTeoria();
        $practica = app(PracticaService::class)->agendar($i, $this->hora('10:00'));

        // Pasa la hora, y el barrido de ausencias corre.
        $this->travelTo($this->hora('12:00'));
        app(\App\Services\Booking\AttendanceService::class)->liberarAusencias();

        $this->assertSame('confirmada', $practica->fresh()->status, 'el barrido no la toca');
        $this->assertSame($practica->id, $i->fresh()->practicaSinValidar()?->id);
        $this->assertFalse($i->fresh()->puedeAgendarPractica(), 'mientras falte la firma no pide otra');
        $this->assertFalse($i->fresh()->firmaAtrasada(), 'todavia dentro del dia habil');
        $this->assertStringContainsString('falta que Michael la firme', $i->fresh()->queFaltaParaAprobar());

        // Y a la persona se lo dice su cuenta.
        $this->actingAs($i->user)->get(route('home'))->assertOk()->assertSee('falta que Michael la firme');

        // Pasado un dia habil sin firmar, sigue faltando la firma, en rojo.
        $this->travelTo($this->hora('12:00')->addDays(2));
        $this->assertTrue($i->fresh()->firmaAtrasada());

        // El panel lo dice, y el boton de «no vino» es de Michael, no del admin.
        $this->entra($admin);
        Livewire::test(EnrollmentsRelationManager::class, ['ownerRecord' => $this->edicion, 'pageClass' => EditCourseEdition::class])
            ->assertTableColumnStateSet('practica', 'Falta la firma · 24/08 10:00', $i)
            ->assertActionHidden(TestAction::make('no_vino')->table($i));

        $this->entra($michael);
        Livewire::test(EnrollmentsRelationManager::class, ['ownerRecord' => $this->edicion, 'pageClass' => EditCourseEdition::class])
            ->assertActionVisible(TestAction::make('practica')->table($i))
            ->callAction(TestAction::make('no_vino')->table($i), ['nota' => 'Te esperé media hora.'])
            ->assertHasNoActionErrors();

        $this->assertSame('no_show', $practica->fresh()->status);
        $this->assertStringContainsString('Michael', $practica->fresh()->status_reason);
        $this->assertNull($i->fresh()->practicaSinValidar());
        $this->assertTrue($i->fresh()->puedeAgendarPractica(), 'puede pedir otra hora');

        $aviso = NotificationLog::where('key', 'practica.no_asistio')->where('user_id', $i->user_id)->firstOrFail();
        $this->assertSame('enviado', $aviso->status);
        $this->assertStringContainsString('Te esperé media hora', $aviso->body);
    }

    /** Un consultor que no tiene la practica asignada no ve el botón. */
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
    // ------------------------------------------------ la segunda oportunidad

    /**
     * Una práctica fallida es «todavía no», no «nunca»: pasada la semana se
     * cita de nuevo, con la teoría ya aprobada, y la vieja deja de pedir firma.
     */
    public function test_quien_no_paso_la_practica_se_cita_de_nuevo_tras_una_semana(): void
    {
        $michael = $this->evaluador('Michael');
        $i = $this->conTeoria();
        app(PracticaService::class)->citar($i, $michael, $this->hora('14:00'));

        // La evaluación pasó y no aprobó.
        $this->travelTo($this->hora('16:00'));
        app(TrainingService::class)->reprobar($i->fresh(), 2.5, 'Le faltó nivelar la cama.');

        $i->refresh();
        $this->assertSame('reprobado', $i->status);
        $this->assertNotNull($i->failed_at);
        $this->assertNull($i->practicaSinValidar(), 'la práctica evaluada ya no pide firma');
        $this->assertFalse($i->puedeRepetirLaPractica(), 'todavía no ha pasado la semana');

        // Al tercer día, no: se dice desde cuándo.
        $this->travelTo($this->hora('16:00')->addDays(3));
        try {
            app(PracticaService::class)->citarDeNuevo($i->fresh(), $michael, Carbon::parse('2026-08-31 14:00', config('fabos.lab.timezone')));
            $this->fail('faltaban días');
        } catch (TrainingException $e) {
            $this->assertStringContainsString('desde el 31/08/2026', $e->getMessage());
        }

        // A la semana, sí: el lunes siguiente, con quien evalúa.
        $this->travelTo(Carbon::parse('2026-08-31 07:00', config('fabos.lab.timezone')));
        $i->refresh();
        $this->assertTrue($i->puedeRepetirLaPractica());

        $reserva = app(PracticaService::class)->citarDeNuevo($i, $michael, Carbon::parse('2026-08-31 14:00', config('fabos.lab.timezone')));

        $i->refresh();
        $this->assertSame('inscrito', $i->status, 'vuelve a estar en curso');
        $this->assertNull($i->grade);
        $this->assertSame('Le faltó nivelar la cama.', $i->feedback, 'lo de la vez anterior se queda: es lo que hay que practicar');
        $this->assertTrue($i->teoriaAprobada(), 'la teoría no se repite');
        $this->assertSame($reserva->id, $i->practicaAgendada()?->id);
    }

    public function test_desde_el_panel_el_boton_de_citar_de_nuevo_espera_la_semana(): void
    {
        $michael = $this->evaluador('Michael');
        $admin = $this->evaluador('Admin', User::ROL_ADMINISTRADOR);
        $i = $this->conTeoria();
        app(TrainingService::class)->reprobar($i, 2.0, 'No');
        $this->entra($admin);

        $pestana = fn () => Livewire::test(EnrollmentsRelationManager::class, [
            'ownerRecord' => $this->edicion, 'pageClass' => EditCourseEdition::class,
        ]);

        // Recién reprobada: el botón está, pero no deja.
        $pestana()->assertTableActionVisible('citar_de_nuevo', $i)->assertTableActionDisabled('citar_de_nuevo', $i);

        $this->travelTo(Carbon::parse('2026-08-31 07:00', config('fabos.lab.timezone')));

        $pestana()
            ->assertTableActionEnabled('citar_de_nuevo', $i->fresh())
            ->callAction(TestAction::make('citar_de_nuevo')->table($i->fresh()), [
                'inicio' => '2026-08-31 14:00', 'evaluador_id' => $michael->id,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('inscrito', $i->fresh()->status);
        $this->assertNotNull($i->fresh()->practicaAgendada());
    }

    // ------------------------------------------ desde cuándo cuenta la semana

    /**
     * La semana se cuenta desde la práctica, no desde la última edición.
     *
     * El fallo, tal cual salió: la práctica fue el lunes, alguien tocó la
     * ficha una semana después —una nota, un cambio cualquiera— y la pantalla
     * pasó a ofrecer citar de nuevo una semana más tarde. Cada edición
     * reiniciaba la espera, y quien no aprobó no tenía forma de que le tocara.
     */
    public function test_editar_la_ficha_despues_no_reinicia_la_semana(): void
    {
        $michael = $this->evaluador('Michael');
        $i = $this->conTeoria();
        app(PracticaService::class)->citar($i, $michael, $this->hora('14:00'));

        $this->travelTo($this->hora('16:00'));
        app(TrainingService::class)->reprobar($i->fresh(), 2.5, 'Le faltó nivelar la cama.');

        // Una semana después alguien anota algo en la ficha.
        $this->travelTo($this->hora('16:00')->addDays(8));
        $i->fresh()->update(['practical_notes' => 'Se le explicó otra vez el nivelado.']);

        // La cuenta sigue saliendo de la práctica del 24, así que ya puede.
        $i->refresh();
        $this->assertSame(
            '2026-08-31',
            $i->puedeRepetirDesde()->format('Y-m-d'),
            'la semana corre desde la práctica, no desde la nota',
        );
        $this->assertTrue($i->puedeRepetirLaPractica());
    }

    /**
     * Una matrícula sin fecha —marcada antes de que existiera la columna, o a
     * mano en la base— cuenta desde su práctica, no desde su último cambio.
     */
    public function test_sin_fecha_anotada_cuenta_desde_la_practica(): void
    {
        $michael = $this->evaluador('Michael');
        $i = $this->conTeoria();
        app(PracticaService::class)->citar($i, $michael, $this->hora('14:00'));

        $this->travelTo($this->hora('16:00'));
        app(TrainingService::class)->reprobar($i->fresh(), 2.5, 'No');

        // Como estaban las de antes: sin fecha, y tocadas mucho después.
        $this->travelTo($this->hora('16:00')->addDays(9));
        \Illuminate\Support\Facades\DB::table('enrollments')
            ->where('id', $i->id)
            ->update(['failed_at' => null, 'updated_at' => now()]);

        $this->assertSame('2026-08-31', $i->fresh()->puedeRepetirDesde()->format('Y-m-d'));
    }

    /** Cambiar el estado a mano, sin pasar por el servicio, también deja fecha. */
    public function test_marcarla_a_mano_tambien_deja_la_fecha(): void
    {
        $michael = $this->evaluador('Michael');
        $i = $this->conTeoria();
        app(PracticaService::class)->citar($i, $michael, $this->hora('14:00'));

        $this->travelTo($this->hora('16:00'));
        $i->fresh()->update(['status' => 'reprobado']);

        $i->refresh();
        $this->assertNotNull($i->failed_at);
        $this->assertSame('2026-08-24', $i->failed_at->timezone(config('fabos.lab.timezone'))->format('Y-m-d'));
    }

    /**
     * Y la segunda vez cuenta desde la segunda práctica.
     *
     * Si la fecha de la primera se quedara pegada, quien reprueba otra vez
     * podría ser citado el mismo día: la espera habría caducado hace semanas.
     */
    public function test_al_reprobar_de_nuevo_la_semana_arranca_otra_vez(): void
    {
        $michael = $this->evaluador('Michael');
        $i = $this->conTeoria();
        app(PracticaService::class)->citar($i, $michael, $this->hora('14:00'));

        $this->travelTo($this->hora('16:00'));
        app(TrainingService::class)->reprobar($i->fresh(), 2.5, 'No');

        // Pasada la semana se la cita de nuevo: la fecha de la vez anterior se va.
        $this->travelTo(Carbon::parse('2026-08-31 07:00', config('fabos.lab.timezone')));
        app(PracticaService::class)->citarDeNuevo($i->fresh(), $michael, Carbon::parse('2026-08-31 14:00', config('fabos.lab.timezone')));

        $this->assertNull($i->fresh()->failed_at, 'ya no está reprobada');

        // Y vuelve a no pasar.
        $this->travelTo(Carbon::parse('2026-08-31 16:00', config('fabos.lab.timezone')));
        app(TrainingService::class)->reprobar($i->fresh(), 2.0, 'Otra vez');

        $i->refresh();
        $this->assertSame('2026-09-07', $i->puedeRepetirDesde()->format('Y-m-d'));
        $this->assertFalse($i->puedeRepetirLaPractica(), 'la semana empieza de cero');
    }
}
