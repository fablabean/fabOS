<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Models\NotificationLog;
use App\Models\Project;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Database\Seeders\NotificationTemplateSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pedir un proyecto desde la web (§11).
 *
 * Lo que se pierde hoy no son los proyectos grandes: son las ideas que llegan
 * un domingo y nunca se anotan. El formulario las anota y crea la cuenta con la
 * que quien pide podrá seguirlas —una diferencia deliberada con el proyecto que
 * anota el laboratorio, que sigue sin exigir cuenta a nadie—.
 */
class SolicitudDeProyectoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(NotificationTemplateSeeder::class);

        UserCategory::firstOrCreate(
            ['slug' => 'invitado'],
            ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1],
        );
    }

    /** @return array<string,mixed> */
    private function solicitud(array $cambios = []): array
    {
        return array_merge([
            'titulo'       => 'Señalética para el edificio de Bienestar',
            'resumen'      => 'Necesitamos veinte letreros en acrílico para señalizar el edificio.',
            'entregables'  => "20 letreros en acrílico\nLos archivos de corte",
            'cliente'      => 'externo',
            'nombre'       => 'Steban Gómez',
            'correo'       => 'steban@ejemplo.co',
            'telefono'     => '3001234567',
            'organizacion' => 'Bienestar Universitario',
        ], $cambios);
    }

    private function jefa(): User
    {
        $u = User::create([
            'name' => 'Jefa ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    // ------------------------------------------------------------ el formulario

    public function test_el_formulario_es_publico(): void
    {
        $this->get(route('proyectos.solicitar'))
            ->assertOk()
            ->assertSee('Proponer un proyecto');
    }

    public function test_una_solicitud_crea_el_proyecto_en_idea(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud())
            ->assertRedirect(route('proyectos.solicitar'));

        $p = Project::where('name', 'Señalética para el edificio de Bienestar')->firstOrFail();

        $this->assertSame('idea', $p->stage, 'Es una solicitud, no un compromiso.');
        $this->assertSame('activo', $p->status);
        $this->assertSame('formulario', $p->source);
        $this->assertSame('Bienestar Universitario', $p->organization);
        $this->assertNotNull($p->code);
    }

    /**
     * Aquí sí se crea cuenta, y es deliberado: quien escribe por la web va a
     * querer seguir su proyecto, y sin cuenta no hay dónde seguirlo.
     */
    public function test_una_solicitud_crea_la_cuenta_de_quien_pide(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());

        $persona = User::where('email', 'steban@ejemplo.co')->firstOrFail();

        $this->assertSame('Steban Gómez', $persona->name);
        $this->assertSame($persona->id, Project::first()->requested_by);
    }

    /**
     * Rellenar un formulario público no puede ser la forma de conseguir acceso
     * a las máquinas. Para eso está el certifab.
     */
    public function test_la_cuenta_nace_sin_permiso_de_reservar(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());

        $persona = User::where('email', 'steban@ejemplo.co')->firstOrFail();

        $this->assertFalse((bool) $persona->category?->can_reserve);
    }

    /** Dos cuentas con el mismo correo partirían el historial en dos. */
    public function test_si_ya_tiene_cuenta_se_reutiliza(): void
    {
        $ya = User::create([
            'name' => 'Steban Gómez', 'email' => 'steban@ejemplo.co', 'status' => 'activo',
        ]);

        $this->post(route('proyectos.solicitar.store'), $this->solicitud());

        $this->assertSame(1, User::where('email', 'steban@ejemplo.co')->count());
        $this->assertSame($ya->id, Project::first()->requested_by);
    }

    public function test_lo_que_escribio_queda_como_entregables(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());

        $p = Project::first();

        $this->assertCount(2, $p->deliverables);
        $this->assertSame('20 letreros en acrílico', $p->deliverables->first()->title);
    }

    public function test_se_le_avisa_que_llego(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());

        $this->assertDatabaseHas('notification_logs', [
            'key'    => 'proyecto.recibido',
            'status' => 'enviado',
        ]);
    }

    // -------------------------------------------------------------- soportes

    /**
     * Una idea contada solo con palabras se entiende de tantas formas como
     * personas la lean. Una foto de la pieza rota ahorra tres correos.
     */
    public function test_se_pueden_adjuntar_fotos_y_documentos(): void
    {
        Storage::fake('local');

        $this->post(route('proyectos.solicitar.store'), $this->solicitud([
            'soportes' => [
                UploadedFile::fake()->image('pieza-rota.jpg', 800, 600),
                UploadedFile::fake()->create('plano.pdf', 120, 'application/pdf'),
            ],
        ]))->assertRedirect();

        $p = Project::first();

        $this->assertCount(2, $p->evidence);

        $foto = $p->evidence->firstWhere('kind', 'foto');
        $documento = $p->evidence->firstWhere('kind', 'archivo');

        $this->assertNotNull($foto);
        $this->assertNotNull($documento);
        $this->assertSame('plano.pdf', $documento->original_name);

        // Disco privado: nada de lo que suba un desconocido queda en una URL
        // adivinable.
        Storage::disk('local')->assertExists($documento->file_path);
        $this->assertStringNotContainsString('/storage/', $documento->enlace());
    }

    /** Un ejecutable no es un soporte de proyecto. */
    public function test_un_tipo_de_archivo_que_no_toca_se_rechaza(): void
    {
        Storage::fake('local');

        $this->post(route('proyectos.solicitar.store'), $this->solicitud([
            'soportes' => [UploadedFile::fake()->create('cosa.exe', 10)],
        ]))->assertSessionHasErrors('soportes.0');

        $this->assertDatabaseCount('projects', 0);
    }

    /** El dibujo hecho en la propia página llega como PNG y se guarda. */
    public function test_el_dibujo_se_guarda_como_imagen(): void
    {
        Storage::fake('local');

        // Un PNG mínimo, de verdad: la validación comprueba la firma.
        $png = base64_encode(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
        ));

        $this->post(route('proyectos.solicitar.store'), $this->solicitud([
            'dibujo' => 'data:image/png;base64,' . $png,
        ]))->assertRedirect();

        $dibujo = Project::first()->evidence->firstWhere('caption', 'Dibujo hecho al pedirlo');

        $this->assertNotNull($dibujo);
        Storage::disk('local')->assertExists($dibujo->file_path);
    }

    /** Lo que no sea un PNG de verdad no se guarda, venga como venga. */
    public function test_un_dibujo_falso_no_se_guarda(): void
    {
        Storage::fake('local');

        $this->post(route('proyectos.solicitar.store'), $this->solicitud([
            'dibujo' => 'data:image/png;base64,' . base64_encode('<script>alert(1)</script>'),
        ]))->assertRedirect();

        $this->assertCount(0, Project::first()->evidence);
    }

    /** Quien lo subió puede volver a abrirlo; un tercero no. */
    public function test_quien_pidio_puede_abrir_su_soporte(): void
    {
        Storage::fake('local');

        $this->post(route('proyectos.solicitar.store'), $this->solicitud([
            'soportes' => [UploadedFile::fake()->create('plano.pdf', 20, 'application/pdf')],
        ]));

        $soporte = Project::first()->evidence->first();

        $this->actingAs(User::where('email', 'steban@ejemplo.co')->firstOrFail())
            ->get(route('proyectos.evidencia', $soporte))
            ->assertOk();

        $otro = User::create(['name' => 'Otro', 'email' => 'otro@x.co', 'status' => 'activo']);

        $this->actingAs($otro)
            ->get(route('proyectos.evidencia', $soporte))
            ->assertForbidden();
    }

    // --------------------------------------------------- ya entró al sistema

    /** A quien ya entró no se le vuelve a preguntar quién es. */
    public function test_si_ya_entro_no_se_le_pregunta_quien_es(): void
    {
        $persona = User::create([
            'name' => 'Erick Hansen', 'email' => 'erick@ejemplo.co',
            'status' => 'activo', 'phone' => '3009999999',
        ]);

        $this->actingAs($persona)
            ->get(route('proyectos.solicitar'))
            ->assertOk()
            ->assertSee('Lo pides como')
            ->assertSee('erick@ejemplo.co')
            ->assertDontSee('Con este correo se crea tu cuenta');
    }

    /**
     * Y el proyecto cuelga de su cuenta, no de una nueva. Pedirle otra vez el
     * correo abriría la puerta a que escriba uno distinto y el proyecto acabe
     * en una cuenta que no es la suya.
     */
    public function test_quien_ya_entro_no_necesita_escribir_su_correo(): void
    {
        $persona = User::create([
            'name' => 'Erick Hansen', 'email' => 'erick@ejemplo.co', 'status' => 'activo',
        ]);

        $this->actingAs($persona)
            ->post(route('proyectos.solicitar.store'), [
                'titulo'  => 'Prototipo de sensor',
                'resumen' => 'Necesitamos una carcasa impresa para un sensor de calidad del aire.',
                'cliente' => 'estudiante',
            ])
            ->assertRedirect();

        $p = Project::firstOrFail();

        $this->assertSame($persona->id, $p->requested_by);
        $this->assertSame('erick@ejemplo.co', $p->contact_email);
        $this->assertSame(1, User::where('email', 'erick@ejemplo.co')->count());
    }

    // ---------------------------------------------------------------- frenos

    public function test_un_resumen_de_dos_palabras_no_pasa(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud(['resumen' => 'algo']))
            ->assertSessionHasErrors('resumen');

        $this->assertDatabaseCount('projects', 0);
    }

    /** La trampa para robots: nadie la ve, nadie debería llenarla. */
    public function test_el_campo_trampa_frena_la_solicitud(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud(['sitio_web' => 'http://spam']))
            ->assertSessionHasErrors('sitio_web');

        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseMissing('users', ['email' => 'steban@ejemplo.co']);
    }

    /** El contador del menú es lo que hace que alguien mire. */
    public function test_el_menu_cuenta_las_solicitudes_sin_responder(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());

        $this->assertSame(
            '1',
            \App\Filament\Resources\Projects\ProjectResource::getNavigationBadge(),
        );

        Project::first()->update(['proposal_sent_at' => now()]);

        $this->assertNull(
            \App\Filament\Resources\Projects\ProjectResource::getNavigationBadge(),
            'Respondida deja de contar.',
        );
    }

    /** Y quien pidió ve el proyecto en su cuenta: por eso se le creó. */
    public function test_los_proyectos_salen_en_mi_cuenta(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());

        $p = Project::first();
        $p->update(['proposal_sent_at' => now()]);

        $this->actingAs(User::where('email', 'steban@ejemplo.co')->firstOrFail())
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Mis proyectos')
            ->assertSee($p->code)
            ->assertSee('Ver la propuesta');
    }

    // ---------------------------------------------------- de quién es el encargo

    /**
     * Un área de la propia institución no paga: mueve presupuesto por la venta
     * interna, un circuito de cuatro manos que no se corre en tres días.
     * Prometer una fecha más cercana sería prometer lo que el trámite no puede
     * cumplir, y el «no» llegaría tarde y peor.
     */
    public function test_un_encargo_interno_necesita_quince_dias(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud([
            'cliente'     => 'interno',
            'para_cuando' => now()->addDays(5)->toDateString(),
        ]))->assertSessionHasErrors('para_cuando');

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_con_los_quince_dias_el_encargo_interno_pasa(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud([
            'cliente'     => 'interno',
            'para_cuando' => now()->addDays(20)->toDateString(),
        ]))->assertRedirect();

        $this->assertSame('interno', Project::first()->client_kind);
    }

    /** A un estudiante o a alguien de fuera ese plazo no le aplica. */
    public function test_a_un_estudiante_no_se_le_pide_ese_plazo(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud([
            'cliente'     => 'estudiante',
            'para_cuando' => now()->addDays(3)->toDateString(),
        ]))->assertRedirect();

        $this->assertSame('estudiante', Project::first()->client_kind);
    }

    public function test_el_formulario_explica_el_circuito_interno(): void
    {
        $this->get(route('proyectos.solicitar'))
            ->assertOk()
            ->assertSee('Cómo se paga un encargo interno')
            ->assertSee('Líder emisor')
            ->assertSee('Traslado');
    }

    // -------------------------------------------------------- la aceptación

    private function conPropuesta(array $cambios = []): Project
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud($cambios));

        $p = Project::first();
        $p->update(['proposal_sent_at' => now(), 'estimated_value' => 2_000_000]);

        return $p->fresh();
    }

    /**
     * Sin una fecha de «sí», el proyecto avanza sobre un acuerdo verbal, que es
     * justo lo que las compuertas documentales existen para evitar.
     */
    public function test_quien_pidio_acepta_la_propuesta(): void
    {
        $p = $this->conPropuesta();
        $persona = User::where('email', 'steban@ejemplo.co')->firstOrFail();

        $this->actingAs($persona)
            ->post(route('proyectos.aceptar', $p), ['nota' => 'Todo bien.'])
            ->assertRedirect();

        $p->refresh();

        $this->assertNotNull($p->accepted_at);
        $this->assertSame($persona->id, $p->accepted_by);
        $this->assertSame('Todo bien.', $p->acceptance_note);
    }

    /** Y al área institucional se le explica el traslado, sin el cual no se fabrica. */
    public function test_al_cliente_interno_se_le_explica_la_venta_interna(): void
    {
        config(['fabos.proyectos.formulario_venta_interna' => 'https://forms.ejemplo/pedido']);

        $p = $this->conPropuesta([
            'cliente'     => 'interno',
            'para_cuando' => now()->addDays(30)->toDateString(),
        ]);

        $this->actingAs(User::where('email', 'steban@ejemplo.co')->firstOrFail())
            ->post(route('proyectos.aceptar', $p));

        $aviso = NotificationLog::where('key', 'proyecto.venta_interna')->firstOrFail();

        $this->assertSame('enviado', $aviso->status);
        $this->assertStringContainsString('https://forms.ejemplo/pedido', $aviso->body);
        $this->assertStringContainsString('planeación', mb_strtolower($aviso->body));
    }

    /** A quien no pasa por ese trámite, ese correo solo le enturbiaría el mensaje. */
    public function test_a_un_externo_no_se_le_manda_lo_de_la_venta_interna(): void
    {
        $p = $this->conPropuesta();

        $this->actingAs(User::where('email', 'steban@ejemplo.co')->firstOrFail())
            ->post(route('proyectos.aceptar', $p));

        $this->assertDatabaseHas('notification_logs', ['key' => 'proyecto.aceptada']);
        $this->assertDatabaseMissing('notification_logs', ['key' => 'proyecto.venta_interna']);
    }

    public function test_no_se_acepta_dos_veces(): void
    {
        $p = $this->conPropuesta();
        $persona = User::where('email', 'steban@ejemplo.co')->firstOrFail();

        $this->actingAs($persona)->post(route('proyectos.aceptar', $p));
        $primera = $p->fresh()->accepted_at;

        $this->actingAs($persona)
            ->post(route('proyectos.aceptar', $p))
            ->assertSessionHasErrors('aceptar');

        $this->assertEquals($primera, $p->fresh()->accepted_at);
    }

    /** No se acepta lo que todavía no se ha propuesto. */
    public function test_no_se_acepta_una_propuesta_que_no_se_ha_mandado(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());
        $p = Project::first();

        $this->actingAs(User::where('email', 'steban@ejemplo.co')->firstOrFail())
            ->post(route('proyectos.aceptar', $p))
            ->assertSessionHasErrors('aceptar');
    }

    /** El backoffice mira, no acepta en nombre de nadie. */
    public function test_el_backoffice_no_acepta_por_el_cliente(): void
    {
        $p = $this->conPropuesta();

        $this->jefa();

        $this->post(route('proyectos.aceptar', $p))->assertForbidden();

        $this->assertNull($p->fresh()->accepted_at);
    }

    /** Con el enlace del correo también se puede aceptar, sin haber entrado. */
    public function test_se_acepta_desde_el_enlace_del_correo(): void
    {
        $p = $this->conPropuesta();

        $enlace = URL::temporarySignedRoute('proyectos.aceptar', now()->addDays(60), ['project' => $p->id]);

        $this->post($enlace)->assertRedirect();

        $this->assertNotNull($p->fresh()->accepted_at);
    }

    public function test_la_pagina_ofrece_aceptar_y_explica_el_circuito(): void
    {
        $p = $this->conPropuesta([
            'cliente'     => 'interno',
            'para_cuando' => now()->addDays(30)->toDateString(),
        ]);

        $this->actingAs(User::where('email', 'steban@ejemplo.co')->firstOrFail())
            ->get(route('proyectos.propuesta', $p))
            ->assertOk()
            ->assertSee('Acepto la propuesta')
            ->assertSee('Cómo se paga')
            ->assertSee('Líder receptor');
    }

    /** A quien no pasa por el traslado, ese circuito no se le enseña. */
    public function test_a_un_externo_no_se_le_ensena_el_circuito(): void
    {
        $p = $this->conPropuesta();

        $this->actingAs(User::where('email', 'steban@ejemplo.co')->firstOrFail())
            ->get(route('proyectos.propuesta', $p))
            ->assertOk()
            ->assertSee('Acepto la propuesta')
            ->assertDontSee('Líder receptor');
    }

    // ------------------------------------------------------------ la respuesta

    public function test_la_propuesta_se_manda_con_su_enlace(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());
        $p = Project::first();

        $this->jefa();

        Livewire::test(ListProjects::class)
            ->callAction(TestAction::make('propuesta')->table($p), ['mensaje' => 'Lo vimos con el equipo.'])
            ->assertHasNoActionErrors();

        $aviso = NotificationLog::where('key', 'proyecto.propuesta')->firstOrFail();

        $this->assertSame('enviado', $aviso->status);
        $this->assertNotNull($p->fresh()->proposal_sent_at, 'Sin esto no se ve a quién se dejó esperando.');
    }

    /**
     * Dónde se redacta la propuesta: en el mismo sitio desde el que se manda.
     *
     * La propuesta **es el proyecto** —sus entregables, su valor, sus fechas—.
     * Un documento aparte se separaría de la ficha a la primera corrección, y
     * entonces habría dos versiones de lo que se prometió.
     */
    public function test_la_propuesta_se_redacta_al_mandarla(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());
        $p = Project::first();

        $this->jefa();

        Livewire::test(ListProjects::class)
            ->callAction(TestAction::make('propuesta')->table($p), [
                'entregables' => [
                    ['id' => $p->deliverables->first()->id, 'title' => '20 letreros en acrílico de 3 mm'],
                    ['id' => null, 'title' => 'Instalación en sitio', 'due_on' => '2026-10-30'],
                ],
                'estimated_value' => 3_400_000,
                'due_on'          => '2026-11-15',
                'mensaje'         => 'Lo vimos con el equipo.',
            ])
            ->assertHasNoActionErrors();

        $p->refresh()->load('deliverables');

        $this->assertSame(3_400_000, (int) $p->estimated_value);
        $this->assertSame('2026-11-15', $p->due_on->toDateString());

        // El que ya existía se actualiza en vez de recrearse: si se borrara y
        // volviera a crear, perdería su tarea en el tablero.
        $this->assertCount(2, $p->deliverables);
        $this->assertSame('20 letreros en acrílico de 3 mm', $p->deliverables->first()->title);
        $this->assertSame('Instalación en sitio', $p->deliverables->last()->title);
        $this->assertSame('2026-10-30', $p->deliverables->last()->due_on->toDateString());
    }

    /** Lo que se quita de la lista se quita de verdad. */
    public function test_quitar_un_entregable_al_redactar_lo_borra(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());
        $p = Project::first();

        $this->assertCount(2, $p->deliverables);

        $this->jefa();

        Livewire::test(ListProjects::class)
            ->callAction(TestAction::make('propuesta')->table($p), [
                'entregables' => [
                    ['id' => $p->deliverables->first()->id, 'title' => '20 letreros en acrílico'],
                ],
            ])
            ->assertHasNoActionErrors();

        $this->assertCount(1, $p->fresh()->deliverables);
    }

    /**
     * El laboratorio anota proyectos de quien no tiene cuenta —una empresa que
     * escribió por WhatsApp— y responderle es igual de necesario.
     */
    public function test_se_responde_a_quien_no_tiene_cuenta(): void
    {
        $p = app(\App\Services\Projects\ProjectService::class)->registrarIdea([
            'name'          => 'Trofeos para la premiación',
            'contact_name'  => 'Marcela Ruiz',
            'contact_email' => 'marcela@empresa.co',
        ]);

        $this->assertNull($p->requested_by);

        $this->jefa();

        Livewire::test(ListProjects::class)
            ->callAction(TestAction::make('propuesta')->table($p), [
                'entregables'     => [['id' => null, 'title' => '30 trofeos en acrílico']],
                'estimated_value' => 1_200_000,
            ])
            ->assertHasNoActionErrors();

        $aviso = NotificationLog::where('key', 'proyecto.propuesta')->firstOrFail();

        $this->assertSame('enviado', $aviso->status);
        $this->assertSame('marcela@empresa.co', $aviso->to);
        $this->assertNull($aviso->user_id, 'No hay persona del sistema detrás: se escribió a una dirección.');
        $this->assertNotNull($p->fresh()->proposal_sent_at);
    }

    public function test_sin_correo_de_contacto_no_se_puede_responder(): void
    {
        $p = app(\App\Services\Projects\ProjectService::class)->registrarIdea([
            'name' => 'Idea suelta anotada en una reunión',
        ]);

        $this->expectException(\App\Services\Projects\ProjectException::class);

        app(\App\Services\Projects\ProjectService::class)->enviarPropuesta($p);
    }

    /**
     * Antes de responder, la página sigue sirviendo: quien pidió tiene derecho
     * a ver lo que mandó y en qué va, sin tener que preguntar.
     */
    public function test_quien_pidio_ve_su_proyecto_antes_de_la_propuesta(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());
        $p = Project::first();

        $this->actingAs(User::where('email', 'steban@ejemplo.co')->firstOrFail())
            ->get(route('proyectos.propuesta', $p))
            ->assertOk()
            ->assertSee('en revisión')
            ->assertSee('Qué pediste')
            ->assertDontSee('Qué entregaríamos');
    }

    /**
     * El enlace del correo tiene que funcionar sin haber entrado: obligar a
     * iniciar sesión antes de leer la propuesta es la forma más segura de que
     * no se lea.
     */
    public function test_el_enlace_firmado_abre_la_propuesta_sin_sesion(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());
        $p = Project::first();

        $enlace = URL::temporarySignedRoute('proyectos.propuesta', now()->addDays(60), ['project' => $p->id]);

        $this->get($enlace)
            ->assertOk()
            ->assertSee($p->name)
            ->assertSee('20 letreros en acrílico');
    }

    public function test_sin_firma_y_sin_sesion_no_se_ve(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());
        $p = Project::first();

        $this->get(route('proyectos.propuesta', $p))->assertForbidden();
    }

    /** Y con la sesión de quien pidió, para cuando el correo se pierda. */
    public function test_quien_pidio_la_ve_desde_su_cuenta(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());
        $p = Project::first();

        $this->actingAs(User::where('email', 'steban@ejemplo.co')->firstOrFail())
            ->get(route('proyectos.propuesta', $p))
            ->assertOk()
            ->assertSee($p->name);
    }

    /** Pero no la de otra persona. */
    public function test_un_tercero_no_ve_la_propuesta(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());
        $p = Project::first();

        $otro = User::create([
            'name' => 'Otro', 'email' => 'otro@ejemplo.co', 'status' => 'activo',
        ]);

        $this->actingAs($otro)
            ->get(route('proyectos.propuesta', $p))
            ->assertForbidden();
    }

    public function test_un_enlace_caducado_ya_no_abre(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());
        $p = Project::first();

        $enlace = URL::temporarySignedRoute('proyectos.propuesta', now()->addMinute(), ['project' => $p->id]);

        $this->travel(2)->minutes();

        $this->get($enlace)->assertForbidden();
    }
}
