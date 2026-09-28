<?php

namespace Tests\Feature;

use App\Filament\Resources\CourseEditions\Pages\EditCourseEdition;
use App\Filament\Resources\CourseEditions\Pages\ResultadosDeEncuesta;
use App\Filament\Resources\CourseEditions\RelationManagers\EnrollmentsRelationManager;
use App\Filament\Resources\CourseEditions\RelationManagers\SessionsRelationManager;
use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\CourseSession;
use App\Models\Enrollment;
use App\Models\NotificationLog;
use App\Models\SessionAttendance;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Training\Actividades;
use App\Services\Training\AsistenciaDeActividad;
use App\Services\Training\TrainingException;
use App\Services\Training\TrainingService;
use App\Support\FactoresDeSesion;
use Database\Seeders\NotificationTemplateSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cursos, talleres y eventos, de la publicación a la encuesta (§9).
 *
 * Lo que se defiende: que nadie tome una silla que no hay, que quien se queda
 * sin cupo quede en lista de espera y se le diga, que un requisito obligatorio
 * no se pueda saltar, que cada cambio avise y deje rastro, que escanear dos
 * veces no cuente dos veces, y que la encuesta vaya solo a quien vino.
 */
class ActividadesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');
        $this->seed(NotificationTemplateSeeder::class);
        config(['fabos.identity.institutional_domain' => 'universidadean.edu.co']);
    }

    private function taller(array $datos = []): Course
    {
        return Course::create(array_merge([
            'slug' => 'laser-' . uniqid(), 'name' => 'Taller de corte láser', 'level' => 'byte', 'kind' => 'taller',
            'summary' => 'Del vector a la pieza.', 'hours' => 3, 'is_active' => true, 'is_public' => true,
            'materials_to_bring' => 'Tu diseño en SVG.',
        ], $datos));
    }

    private function edicion(?Course $curso = null, array $datos = []): CourseEdition
    {
        return CourseEdition::create(array_merge([
            'course_id'  => ($curso ?? $this->taller())->id,
            'code'       => app(TrainingService::class)->siguienteCodigo(),
            'starts_on'  => now()->addWeek()->toDateString(),
            'start_time' => '09:00',
            'end_time'   => '12:00',
            'location'   => 'Auditorio',
            'capacity'   => 2,
            'status'     => 'abierta',
            'audience'   => 'ambos',
        ], $datos));
    }

    private function inscribir(CourseEdition $e, array $datos = [])
    {
        return $this->post(route('actividad.inscribir', $e->code), array_merge([
            'nombre'   => 'Ana Gómez',
            'correo'   => 'ana@correo.co',
            'programa' => 'Diseño',
            'tipo'     => 'externo',
            'acepta'   => '1',
        ], $datos));
    }

    private function jefa(): User
    {
        $u = User::create(['name' => 'Jefa', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));
        app(\App\Services\Auth\MatrizDeAccesos::class)->sincronizar();

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    // ============================================================ inscripción

    public function test_la_pagina_muestra_la_actividad_y_los_cupos(): void
    {
        $e = $this->edicion(null, ['is_paid' => true, 'price' => 45000, 'payment_info' => 'Paga con el QR.']);

        $this->get(route('actividad', $e->code))
            ->assertOk()
            ->assertSee('Taller de corte láser')
            ->assertSee('Taller')
            ->assertSee('nivel byte')
            ->assertSee('09:00 a 12:00')
            ->assertSee('Auditorio')
            ->assertSee('$45.000')
            ->assertSee('Paga con el QR.')
            ->assertSee('Tu diseño en SVG.')
            ->assertSee('2 <small>cupos disponibles', false)
            ->assertSee('Acepto las condiciones de inscripción y cancelación');
    }

    public function test_una_planeada_no_se_ve_salvo_en_vista_previa_del_equipo(): void
    {
        $e = $this->edicion(null, ['status' => 'planeada']);

        $this->get(route('actividad', $e->code))->assertNotFound();

        $this->jefa();
        $this->get(route('actividad', $e->code))->assertOk()->assertSee('Vista previa');
    }

    public function test_inscribirse_crea_la_persona_y_confirma_por_correo(): void
    {
        $e = $this->edicion();

        $this->inscribir($e)->assertRedirect(route('actividad.listo', $e->code));

        $i = Enrollment::firstOrFail();
        $this->assertSame('inscrito', $i->status);
        $this->assertSame('externo', $i->participant_type);
        $this->assertSame('Diseño', $i->program);
        $this->assertSame('web', $i->source);
        $this->assertNotNull($i->consent_at);
        $this->assertSame('ana@correo.co', $i->user->email);

        $this->assertTrue(NotificationLog::where('key', 'actividad.inscrito')->where('user_id', $i->user_id)->exists());

        $this->followRedirects($this->inscribir($this->edicion(), ['correo' => 'otra@correo.co']))
            ->assertSee('¡Quedaste inscrito!');

        // La misma persona dos veces, no.
        $this->inscribir($e)->assertSessionHasErrors('actividad');
    }

    public function test_sin_cupo_queda_en_lista_de_espera_y_se_le_dice(): void
    {
        $e = $this->edicion(null, ['capacity' => 1]);

        $this->inscribir($e, ['correo' => 'uno@correo.co']);
        $respuesta = $this->inscribir($e, ['correo' => 'dos@correo.co', 'nombre' => 'Beto Ruiz']);

        $beto = Enrollment::whereHas('user', fn ($q) => $q->where('email', 'dos@correo.co'))->firstOrFail();
        $this->assertSame(Enrollment::EN_ESPERA, $beto->status);
        $this->assertNotNull($beto->waitlisted_at);
        $this->assertSame(1, $e->fresh()->inscritos(), 'la lista de espera no ocupa silla');
        $this->assertSame(1, $e->fresh()->enEspera());

        $this->followRedirects($respuesta)
            ->assertSee('Quedaste en lista de espera')
            ->assertSee('Todavía no tienes cupo');

        $this->assertTrue(NotificationLog::where('key', 'actividad.lista_espera')->where('user_id', $beto->user_id)->exists());

        // La página ofrece la lista de espera, no cierra la puerta.
        $this->get(route('actividad', $e->code))->assertSee('Cupo lleno')->assertSee('Anotarme en la lista de espera');

        // Sin cupo libre no se le puede dar.
        $this->expectException(TrainingException::class);
        app(Actividades::class)->darCupo($beto);
    }

    public function test_si_alguien_cancela_se_le_da_el_cupo_a_la_lista_de_espera(): void
    {
        $e = $this->edicion(null, ['capacity' => 1]);
        $this->inscribir($e, ['correo' => 'uno@correo.co']);
        $this->inscribir($e, ['correo' => 'dos@correo.co']);

        $uno = Enrollment::whereHas('user', fn ($q) => $q->where('email', 'uno@correo.co'))->firstOrFail();
        $dos = Enrollment::whereHas('user', fn ($q) => $q->where('email', 'dos@correo.co'))->firstOrFail();

        // Cancela desde el enlace firmado del correo.
        $enlace = URL::signedRoute('inscripcion.cancelar', ['enrollment' => $uno->id]);
        $this->get($enlace)->assertOk()->assertSee('Cancelar mi inscripción');
        $this->post(URL::signedRoute('inscripcion.cancelar.confirmar', ['enrollment' => $uno->id]), ['motivo' => 'Me salió un viaje'])
            ->assertSessionHas('cancelada');

        $this->assertSame('retirado', $uno->fresh()->status);
        $this->assertSame(1, $e->fresh()->cuposLibres());

        $this->jefa();

        Livewire::test(EnrollmentsRelationManager::class, ['ownerRecord' => $e, 'pageClass' => EditCourseEdition::class])
            ->callAction(TestAction::make('dar_cupo')->table($dos))
            ->assertHasNoActionErrors();

        $this->assertSame('inscrito', $dos->fresh()->status);
        $this->assertNotNull($dos->fresh()->promoted_at);
        $this->assertTrue(NotificationLog::where('key', 'actividad.cupo_asignado')->where('user_id', $dos->user_id)->exists());
        $this->assertTrue($e->changes()->where('kind', 'cupo_asignado')->exists());
    }

    public function test_un_archivo_obligatorio_no_se_puede_saltar(): void
    {
        $curso = $this->taller();
        $diseno = $curso->registrationQuestions()->create(['type' => 'archivo', 'label' => 'Tu diseño', 'required' => true]);
        $nivel = $curso->registrationQuestions()->create(['type' => 'seleccion', 'label' => '¿Has usado una láser?', 'required' => true, 'options' => ['Nunca', 'Alguna vez']]);
        $e = $this->edicion($curso);

        $this->inscribir($e, ['p' . $nivel->id => 'Nunca'])->assertSessionHasErrors('p' . $diseno->id);
        $this->assertSame(0, Enrollment::count());

        $this->inscribir($e, ['p' . $nivel->id => 'Quizás', 'p' . $diseno->id => UploadedFile::fake()->create('pieza.svg', 20)])
            ->assertSessionHasErrors('p' . $nivel->id);

        $this->inscribir($e, [
            'p' . $nivel->id  => 'Nunca',
            'p' . $diseno->id => UploadedFile::fake()->create('pieza.svg', 20),
        ])->assertSessionHasNoErrors();

        $respuestas = Enrollment::firstOrFail()->answers;
        $this->assertSame('Nunca', $respuestas[(string) $nivel->id]['valor']);
        $this->assertSame('pieza.svg', $respuestas[(string) $diseno->id]['archivo']['nombre']);
        Storage::disk('local')->assertExists($respuestas[(string) $diseno->id]['archivo']['ruta']);
    }

    public function test_cada_grupo_tiene_su_cupo(): void
    {
        $curso = $this->taller();
        $horario = $curso->registrationQuestions()->create([
            'type' => 'seleccion', 'label' => 'Selecciona el horario', 'required' => true, 'capacity_per_option' => true,
            'options' => [['texto' => 'Grupo 1', 'cupo' => 1], ['texto' => 'Grupo 2', 'cupo' => 1]],
        ]);
        $e = $this->edicion($curso, ['capacity' => 5]);
        $campo = 'p' . $horario->id;

        $this->inscribir($e, ['correo' => 'a@correo.co', $campo => 'Grupo 1']);
        $this->followRedirects($this->inscribir($e, ['correo' => 'b@correo.co', $campo => 'Grupo 1']))
            ->assertSee('Los cupos de <strong>Grupo 1</strong> están llenos', false);
        $this->inscribir($e, ['correo' => 'c@correo.co', $campo => 'Grupo 2']);

        $estado = fn (string $correo) => Enrollment::whereHas('user', fn ($q) => $q->where('email', $correo))->value('status');
        $this->assertSame('inscrito', $estado('a@correo.co'));
        $this->assertSame(Enrollment::EN_ESPERA, $estado('b@correo.co'), 'el grupo 1 se llenó aunque la edición tenga sitio');
        $this->assertSame('inscrito', $estado('c@correo.co'));

        $this->get(route('actividad', $e->code))
            ->assertSee('Grupo 1 · lleno, lista de espera')
            ->assertSee('Grupo 2 · lleno, lista de espera');

        // Con cupo en la edición pero no en su grupo, no se le puede dar.
        $b = Enrollment::whereHas('user', fn ($q) => $q->where('email', 'b@correo.co'))->firstOrFail();
        try {
            app(Actividades::class)->darCupo($b);
            $this->fail('Dio un cupo de un grupo lleno.');
        } catch (TrainingException $ex) {
            $this->assertStringContainsString('Grupo 1', $ex->getMessage());
        }

        // Se libera uno del grupo 1: ahora sí.
        app(Actividades::class)->cancelarInscripcion(Enrollment::whereHas('user', fn ($q) => $q->where('email', 'a@correo.co'))->firstOrFail());
        app(Actividades::class)->darCupo($b->fresh());
        $this->assertSame('inscrito', $b->fresh()->status);
    }

    public function test_el_formulario_del_curso_guarda_opciones_con_cupo_y_lee_las_viejas(): void
    {
        $curso = $this->taller();
        $vieja = $curso->registrationQuestions()->create(['type' => 'seleccion', 'label' => 'Talla', 'options' => ['S', 'M']]);

        $this->jefa();

        Livewire::test(\App\Filament\Resources\Courses\Pages\EditCourse::class, ['record' => $curso->getRouteKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(['S', 'M'], $vieja->fresh()->opciones());
    }

    public function test_las_preguntas_de_un_tipo_de_participante_no_se_exigen_a_los_demas(): void
    {
        $curso = $this->taller();
        $codigo = $curso->registrationQuestions()->create([
            'type' => 'texto', 'label' => 'Código de estudiante', 'required' => true, 'participant_types' => ['estudiante'],
        ]);
        $e = $this->edicion($curso);

        $this->inscribir($e)->assertSessionHasNoErrors();

        $this->inscribir($e, ['tipo' => 'estudiante', 'correo' => 'ana@universidadean.edu.co'])
            ->assertSessionHasErrors('p' . $codigo->id);
    }

    public function test_la_comunidad_ean_se_inscribe_con_su_correo_y_el_publico_se_respeta(): void
    {
        $e = $this->edicion(null, ['audience' => 'ean']);

        $this->inscribir($e)->assertSessionHasErrors('tipo');
        $this->inscribir($e, ['tipo' => 'estudiante', 'correo' => 'ana@gmail.com'])->assertSessionHasErrors('correo');
        $this->inscribir($e, ['tipo' => 'estudiante', 'correo' => 'ana@universidadean.edu.co'])->assertSessionHasNoErrors();

        $this->assertSame('estudiante', Enrollment::firstOrFail()->participant_type);
    }

    public function test_cerradas_o_canceladas_no_reciben_inscripciones(): void
    {
        $e = $this->edicion(null, ['status' => 'inscripciones_cerradas']);

        $this->get(route('actividad', $e->code))->assertOk()->assertSee('Las inscripciones de esta actividad están cerradas');
        $this->inscribir($e)->assertSessionHasErrors('actividad');
        $this->assertSame(0, Enrollment::count());
    }

    // ============================================================ cambios

    public function test_reprogramar_avisa_a_todos_y_deja_historial(): void
    {
        $e = $this->edicion(null, ['capacity' => 1]);
        $this->inscribir($e, ['correo' => 'uno@correo.co']);
        $this->inscribir($e, ['correo' => 'dos@correo.co']);
        $sesion = $e->sessions()->create(['starts_at' => Carbon::parse($e->starts_on->format('Y-m-d') . ' 09:00', config('fabos.lab.timezone'))]);

        $jefa = $this->jefa();
        $nueva = now()->addWeeks(2)->toDateString();

        Livewire::test(EditCourseEdition::class, ['record' => $e->getRouteKey()])
            ->callAction('reprogramar', [
                'starts_on' => $nueva, 'start_time' => '14:00', 'end_time' => '17:00', 'location' => 'Sala 2',
                'cause' => 'cierre', 'motivo' => 'Cierre de la sede ese sábado.', 'avisar' => true,
            ])
            ->assertHasNoActionErrors();

        $e->refresh();
        $this->assertSame($nueva, $e->starts_on->format('Y-m-d'));
        $this->assertSame('Sala 2', $e->location);

        // La sesión única se movió con la actividad.
        $this->assertSame('14:00', $sesion->fresh()->starts_at->timezone(config('fabos.lab.timezone'))->format('H:i'));

        $cambio = $e->changes()->where('kind', 'reprogramada')->firstOrFail();
        $this->assertSame('cierre', $cambio->cause);
        $this->assertSame(2, $cambio->notified, 'inscrito y lista de espera');
        $this->assertSame($jefa->id, $cambio->user_id);

        $aviso = NotificationLog::where('key', 'actividad.novedad')->firstOrFail();
        $this->assertStringContainsString('Cierre de la sede', $aviso->body);
        $this->assertStringContainsString('Sala 2', $aviso->body);
        $this->assertStringContainsString('Antes:', $aviso->body);
    }

    public function test_cancelar_avisa_y_la_pagina_lo_dice(): void
    {
        $e = $this->edicion();
        $this->inscribir($e);

        ['avisados' => $n] = app(Actividades::class)->cancelar($e, 'sismo', 'Evacuación del edificio.', true);

        $this->assertSame(1, $n);
        $this->assertSame('cancelada', $e->fresh()->status);
        $this->get(route('actividad', $e->code))->assertOk()->assertSee('Esta actividad se canceló')->assertSee('Evacuación del edificio.');
    }

    public function test_publicar_cerrar_y_duplicar(): void
    {
        $curso = $this->taller();
        $curso->registrationQuestions()->create(['type' => 'texto', 'label' => 'Talla', 'required' => false]);
        $curso->surveyQuestions()->create(['type' => 'escala', 'label' => '¿Qué tal?']);
        $e = $this->edicion($curso, ['status' => 'planeada', 'title' => 'Grupo sábados']);

        $this->jefa();

        Livewire::test(EditCourseEdition::class, ['record' => $e->getRouteKey()])
            ->callAction('publicar')
            ->assertHasNoActionErrors();
        $this->assertSame('abierta', $e->fresh()->status);
        $this->assertNotNull($e->fresh()->published_at);

        Livewire::test(EditCourseEdition::class, ['record' => $e->getRouteKey()])
            ->callAction('cerrar_inscripciones', ['motivo' => 'Ya está completo'])
            ->assertHasNoActionErrors();
        $this->assertSame('inscripciones_cerradas', $e->fresh()->status);

        $copia = app(Actividades::class)->duplicarEdicion($e->fresh());
        $this->assertSame('planeada', $copia->status);
        $this->assertSame('Grupo sábados', $copia->title);
        $this->assertNotSame($e->code, $copia->code);
        $this->assertSame(0, $copia->enrollments()->count());

        $otro = app(Actividades::class)->duplicarCurso($curso);
        $this->assertFalse($otro->is_public);
        $this->assertSame(1, $otro->registrationQuestions()->count());
        $this->assertSame(1, $otro->surveyQuestions()->count());

        $this->assertEqualsCanonicalizing(
            ['publicada', 'inscripciones_cerradas'],
            $e->changes()->pluck('kind')->all(),
        );
    }

    // ============================================================ asistencia

    public function test_el_qr_registra_una_sola_vez_y_solo_a_tiempo(): void
    {
        $e = $this->edicion();
        $this->inscribir($e);
        $i = Enrollment::firstOrFail();

        $inicio = now()->addMinutes(30);
        $sesion = $e->sessions()->create(['starts_at' => $inicio, 'ends_at' => $inicio->copy()->addHours(2)]);

        $this->get(route('asistencia', $sesion->token))->assertOk()->assertSee('Registrar mi asistencia');

        $this->post(route('asistencia.registrar', $sesion->token), ['correo' => 'ANA@correo.co'])
            ->assertSessionHas('registrada', fn ($r) => $r['nueva'] === true);
        $this->post(route('asistencia.registrar', $sesion->token), ['correo' => 'ana@correo.co'])
            ->assertSessionHas('registrada', fn ($r) => $r['nueva'] === false);

        $this->assertSame(1, SessionAttendance::count(), 'escanear dos veces no cuenta dos veces');
        $this->assertSame('qr', SessionAttendance::first()->method);

        // Un correo que no está inscrito, no.
        $this->post(route('asistencia.registrar', $sesion->token), ['correo' => 'nadie@correo.co'])->assertSessionHasErrors('correo');

        // Fuera de la ventana, no registra.
        $this->expectException(TrainingException::class);
        app(AsistenciaDeActividad::class)->registrarPorQr($sesion, 'ana@correo.co', $inicio->copy()->addHours(5));
    }

    public function test_pasar_lista_anota_y_corrige_sin_borrar_el_qr(): void
    {
        $e = $this->edicion(null, ['capacity' => 3]);
        $this->inscribir($e, ['correo' => 'uno@correo.co']);
        $this->inscribir($e, ['correo' => 'dos@correo.co']);
        $this->inscribir($e, ['correo' => 'tres@correo.co']);
        [$uno, $dos, $tres] = Enrollment::orderBy('id')->get()->all();

        $sesion = $e->sessions()->create(['starts_at' => now(), 'ends_at' => now()->addHours(2)]);
        app(AsistenciaDeActividad::class)->registrarPorQr($sesion, 'uno@correo.co');

        $this->jefa();

        Livewire::test(SessionsRelationManager::class, ['ownerRecord' => $e, 'pageClass' => EditCourseEdition::class])
            ->callAction(TestAction::make('lista')->table($sesion), ['presentes' => [(string) $dos->id]])
            ->assertHasNoActionErrors();

        $marca = fn (Enrollment $i) => SessionAttendance::where('enrollment_id', $i->id)->first();
        $this->assertSame('asistio', $marca($uno)->status, 'el QR no se borra por quedar sin marcar');
        $this->assertSame('asistio', $marca($dos)->status);
        $this->assertSame('manual', $marca($dos)->method);
        $this->assertSame('no_asistio', $marca($tres)->status);

        $resumen = app(AsistenciaDeActividad::class)->resumen($tres->fresh());
        $this->assertSame('No asistió', $resumen['estado']);
    }

    // ============================================================ encuesta

    public function test_la_encuesta_va_solo_a_quien_vino_y_se_responde_una_vez(): void
    {
        $curso = $this->taller();
        $escala = $curso->surveyQuestions()->create(['type' => 'escala', 'label' => '¿Qué tan satisfecho quedaste?', 'required' => true]);
        $abierta = $curso->surveyQuestions()->create(['type' => 'texto', 'label' => '¿Qué mejorarías?', 'required' => false]);
        $e = $this->edicion($curso, ['capacity' => 3]);

        $this->inscribir($e, ['correo' => 'vino@correo.co']);
        $this->inscribir($e, ['correo' => 'novino@correo.co']);
        [$vino, $noVino] = Enrollment::orderBy('id')->get()->all();

        $sesion = $e->sessions()->create(['starts_at' => now(), 'ends_at' => now()->addHour()]);
        app(AsistenciaDeActividad::class)->registrarPorQr($sesion, 'vino@correo.co');

        $this->assertSame(1, app(Actividades::class)->enviarEncuesta($e));
        $this->assertTrue(NotificationLog::where('key', 'actividad.encuesta')->where('user_id', $vino->user_id)->exists());
        $this->assertFalse(NotificationLog::where('key', 'actividad.encuesta')->where('user_id', $noVino->user_id)->exists());

        $enlace = app(Actividades::class)->enlaceDeEncuesta($vino);
        $this->get($enlace)->assertOk()->assertSee('¿Qué tan satisfecho quedaste?');

        $this->post($enlace, ['r' => [$escala->id => '4', $abierta->id => 'Más tiempo de máquina']])->assertSessionHas('gracias');
        $this->post($enlace, ['r' => [$escala->id => '1']])->assertSessionHasErrors('encuesta');

        // Sin firma, no.
        $this->get(route('encuesta', $vino))->assertForbidden();

        $r = app(\App\Services\Training\EncuestaDeActividad::class)->resultados($curso, $e);
        $this->assertSame(1, $r['respuestas']);
        $this->assertSame(1, $r['asistentes']);
        $this->assertEquals(4, $r['satisfaccion']);
        $this->assertSame(['Más tiempo de máquina'], $r['preguntas'][1]['textos']);

        $this->jefa();
        Livewire::test(ResultadosDeEncuesta::class, ['record' => $e->getRouteKey()])
            ->assertOk()
            ->assertSee('Más tiempo de máquina');
    }

    // ============================================================ el panel

    public function test_el_panel_abre_la_actividad_con_su_formulario_y_encuesta(): void
    {
        $curso = $this->taller();
        $e = $this->edicion($curso);
        $this->inscribir($e);

        $this->jefa();

        $this->get(\App\Filament\Resources\Courses\CourseResource::getUrl('edit', ['record' => $curso]))
            ->assertOk()
            ->assertSee('Formulario de inscripción')
            ->assertSee('Encuesta de satisfacción');

        $this->get(\App\Filament\Resources\CourseEditions\CourseEditionResource::getUrl('edit', ['record' => $e]))
            ->assertOk()
            ->assertSee('Cerrar inscripciones');

        $this->get(\App\Filament\Resources\CourseEditions\CourseEditionResource::getUrl('index'))->assertOk();

        $sesion = $e->sessions()->create(['starts_at' => now()->addDay()]);
        $this->get(route('asistencia.qr', $sesion))->assertOk()->assertSee('<svg', false);

        $csv = app(\App\Services\Training\ListaDeInscritos::class)->csv($e);
        $this->assertStringContainsString('Ana Gómez', $csv);
    }
}
