<?php

namespace Tests\Feature;

use App\Support\FactoresDeSesion;
use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Models\Project;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Projects\ProjectService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Las pantallas de Proyectos y el tablero (§11). */
class BackofficeProyectosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function conRol(?string $rol = null): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        if ($rol) {
            $u->assignRole($rol);
        }

        return $u->fresh();
    }

    private function entra(User $u): self
    {
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $this;
    }

    private function proyecto(?User $lead = null): Project
    {
        return app(ProjectService::class)->registrarIdea([
            'name'         => 'Señalética para el campus',
            'source'       => 'whatsapp',
            'organization' => 'Bienestar Universitario',
            'lead_id'      => $lead?->id,
        ]);
    }

    public function test_el_listado_de_proyectos_carga(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $p = $this->proyecto($admin);

        $this->entra($admin)->get('/admin/projects')
            ->assertOk()
            ->assertSee($p->code)
            ->assertSee('Señalética para el campus')
            ->assertSee('Bienestar Universitario');
    }

    /**
     * Lo primero que hace cualquiera es anotar una idea con lo minimo: un
     * nombre y por donde llego. El resto del formulario va vacio, y eso tiene
     * que bastar. Antes no bastaba: «valor acordado» en blanco llegaba como
     * NULL a una columna NOT NULL y el guardado reventaba con un error que
     * desde la pantalla solo se veia como «Error al cargar la pagina».
     */
    public function test_se_crea_un_proyecto_con_lo_minimo(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $this->entra($admin);

        Livewire::test(\App\Filament\Resources\Projects\Pages\CreateProject::class)
            ->fillForm(['name' => 'Metro 1', 'source' => 'correo'])
            ->call('create')
            ->assertHasNoFormErrors();

        $p = Project::where('name', 'Metro 1')->firstOrFail();

        $this->assertSame(0, (int) $p->agreed_value, 'Sin acordar son 0 pesos, no NULL.');
        $this->assertNotNull($p->code, 'El codigo se genera solo.');
        $this->assertSame('idea', $p->stage);
    }

    /** Gerencia manda proyectos, y no es ni correo ni iniciativa propia. */
    public function test_gerencia_es_un_origen_valido(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $this->entra($admin);

        Livewire::test(\App\Filament\Resources\Projects\Pages\CreateProject::class)
            ->fillForm(['name' => 'Encargo de gerencia', 'source' => 'gerencia'])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('projects', ['name' => 'Encargo de gerencia', 'source' => 'gerencia']);
    }

    /** Dejar el avance en blanco es «todavia nada», no un error de guardado. */
    public function test_una_tarea_se_guarda_sin_avance(): void
    {
        $p = $this->proyecto();

        $tarea = $p->tasks()->create(['title' => 'Cortar piezas', 'progress' => null]);

        $this->assertSame(0, $tarea->fresh()->progress);
    }

    /** Y borrar el costo por hora al corregir vuelve a la tarifa de referencia. */
    public function test_borrar_el_costo_por_hora_al_editar_no_rompe_nada(): void
    {
        $p = $this->proyecto();
        $log = $p->timeLogs()->create(['worked_on' => now()->toDateString(), 'hours' => 2, 'hourly_cost' => 90_000]);

        $log->update(['hourly_cost' => null]);

        $this->assertSame((int) config('fabos.money.hourly_cost'), (int) $log->fresh()->hourly_cost);
    }

    public function test_avanzar_sin_la_compuerta_avisa_que_falta(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $p = $this->proyecto();   // sin responsable

        $this->entra($admin);

        Livewire::test(ListProjects::class)
            ->callAction(TestAction::make('avanzar')->table($p));

        // El error se muestra como notificación y el proyecto no se mueve.
        $this->assertSame('idea', $p->fresh()->stage);
    }

    public function test_avanzar_con_todo_en_su_sitio(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $p = $this->proyecto($admin);

        $this->entra($admin);

        Livewire::test(ListProjects::class)
            ->callAction(TestAction::make('avanzar')->table($p))
            ->assertHasNoActionErrors();

        $this->assertSame('propuesta', $p->fresh()->stage);
    }

    public function test_el_tablero_muestra_kanban_y_gantt(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $p = $this->proyecto($admin);

        $p->tasks()->createMany([
            ['title' => 'Cortar piezas', 'status' => 'en_curso', 'starts_on' => '2026-09-01', 'due_on' => '2026-09-05'],
            ['title' => 'Entrega final', 'status' => 'por_hacer', 'starts_on' => '2026-09-10', 'is_milestone' => true],
            ['title' => 'Sin fechas', 'status' => 'por_hacer'],
        ]);

        $this->actingAs($admin)
            ->get(route('proyectos.tablero', $p))
            ->assertOk()
            ->assertSee('Tablero')
            ->assertSee('Cronograma')
            ->assertSee('Cortar piezas')
            ->assertSee('Sin fechas')          // vive en el tablero
            ->assertSee('01/09/2026');          // y el rango del Gantt
    }

    public function test_una_tarjeta_se_mueve_de_columna_desde_el_tablero(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $p = $this->proyecto($admin);
        $tarea = $p->tasks()->create(['title' => 'Cortar piezas']);

        $this->actingAs($admin)
            ->post(route('proyectos.tarea.mover', $tarea), ['estado' => 'hecha'])
            ->assertRedirect();

        $this->assertSame('hecha', $tarea->fresh()->status);
        $this->assertSame(100, $tarea->fresh()->progress);
    }

    public function test_el_tablero_muestra_el_costeo_y_el_margen(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $p = $this->proyecto($admin);
        $p->update(['agreed_value' => 1_000_000]);
        $p->timeLogs()->create(['worked_on' => now()->toDateString(), 'hours' => 10]);

        $this->actingAs($admin)
            ->get(route('proyectos.tablero', $p))
            ->assertOk()
            ->assertSee('Costeo')
            ->assertSee('$450.000')     // 10 h a la tarifa de referencia
            ->assertSee('$550.000');    // margen contra el millón acordado
    }

    public function test_una_reserva_se_carga_a_un_proyecto_desde_el_backoffice(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $p = $this->proyecto($admin);

        $reserva = \App\Models\Reservation::create([
            'reservable_type' => \App\Models\Asset::class,
            'reservable_id'   => \App\Models\Asset::create([
                'name' => 'Equipo ' . uniqid(), 'kind' => 'fijo', 'status' => 'operativo',
            ])->id,
            'user_id'   => $admin->id,
            'status'    => 'completada',
            'starts_at' => now()->subHours(2),
            'ends_at'   => now()->subHour(),
        ]);

        $this->entra($admin);

        Livewire::test(\App\Filament\Resources\Reservations\Pages\ListReservations::class)
            ->callAction(TestAction::make('proyecto')->table($reserva), ['project_id' => $p->id])
            ->assertHasNoActionErrors();

        $this->assertSame($p->id, $reserva->fresh()->project_id);
    }

    /**
     * Los costos que no pasan por el laboratorio se anotan junto al resto del
     * proyecto, no en una pantalla aparte: se registran cuando llega la factura,
     * que es cuando alguien ya está mirando el proyecto.
     */
    public function test_se_anota_un_costo_asociado_desde_la_ficha(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $p = $this->proyecto($admin);

        $this->entra($admin);

        Livewire::test(\App\Filament\Resources\Projects\RelationManagers\CostsRelationManager::class, [
            'ownerRecord' => $p,
            'pageClass'   => \App\Filament\Resources\Projects\Pages\EditProject::class,
        ])
            ->callAction(TestAction::make('create')->table(), [
                'concept'  => 'Pintura electrostática',
                'kind'     => 'servicio',
                'supplier' => 'Taller externo',
                'amount'   => 800_000,
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('project_costs', [
            'project_id' => $p->id,
            'concept'    => 'Pintura electrostática',
            'amount'     => 800_000,
        ]);

        // Queda constancia de quién lo anotó: una cifra sin dueño no se audita.
        $this->assertSame($admin->id, $p->costs()->first()->registered_by);
    }

    // ------------------------------------------------------------- evidencia

    /**
     * «Se hizo» es una afirmación; una foto es una comprobación. En la tarjeta
     * del tablero, que es donde se mira cuando alguien pregunta cómo va.
     */
    public function test_la_evidencia_de_una_tarea_se_ve_en_el_tablero(): void
    {
        $p = $this->proyecto();
        $tarea = $p->tasks()->create(['title' => 'Cortar piezas']);

        $tarea->evidence()->create([
            'kind'    => 'video',
            'url'     => 'https://www.youtube.com/watch?v=ejemplo',
            'caption' => 'Primer corte',
        ]);

        $this->actingAs($this->conRol(User::ROL_CONSULTOR))
            ->get(route('proyectos.tablero', $p))
            ->assertOk()
            ->assertSee('Primer corte')
            ->assertSee('youtube.com/watch?v=ejemplo', false);
    }

    /**
     * Las fotos del trabajo de un cliente viven en el disco privado: en el
     * público quedarían en una URL adivinable, sin sesión.
     */
    public function test_una_foto_de_evidencia_no_se_sirve_a_cualquiera(): void
    {
        $p = $this->proyecto();
        $tarea = $p->tasks()->create(['title' => 'Cortar piezas']);

        $prueba = $tarea->evidence()->create([
            'kind'      => 'foto',
            'file_path' => 'proyectos/evidencia/ejemplo.webp',
        ]);

        // La URL que se publica es la ruta con sesión, no /storage.
        $this->assertStringContainsString('/proyectos/evidencia/' . $prueba->id, $prueba->enlace());
        $this->assertStringNotContainsString('/storage/', $prueba->enlace());

        $this->actingAs($this->conRol())
            ->get(route('proyectos.evidencia', $prueba))
            ->assertForbidden();
    }

    public function test_una_foto_que_no_esta_no_revienta(): void
    {
        $p = $this->proyecto();
        $tarea = $p->tasks()->create(['title' => 'Cortar piezas']);

        $prueba = $tarea->evidence()->create([
            'kind'      => 'foto',
            'file_path' => 'proyectos/evidencia/no-existe.webp',
        ]);

        $this->actingAs($this->conRol(User::ROL_ADMINISTRADOR))
            ->get(route('proyectos.evidencia', $prueba))
            ->assertNotFound();
    }

    // ----------------------------------------------------- compromiso interno

    public function test_el_tablero_avisa_cuando_el_compromiso_es_interno(): void
    {
        $p = $this->proyecto();
        $p->update(['is_internal' => true, 'estimated_value' => 4_000_000]);

        $this->actingAs($this->conRol(User::ROL_CONSULTOR))
            ->get(route('proyectos.tablero', $p))
            ->assertOk()
            ->assertSee('Compromiso interno')
            ->assertSee('no entra dinero por él')
            ->assertSee('Valor del beneficio')
            ->assertSee('Beneficio neto')
            ->assertDontSee('Valor acordado');
    }

    // ---------------------------------------------------------- entregables

    /**
     * Un párrafo no se puede marcar como cumplido. En lista, cada compromiso
     * tiene estado propio, y al cerrar se puede decir cuál se entregó y cuál no.
     */
    public function test_los_entregables_se_escriben_uno_por_renglon(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $this->entra($admin);

        Livewire::test(\App\Filament\Resources\Projects\Pages\CreateProject::class)
            ->fillForm([
                'name'         => 'CapiDog',
                'source'       => 'interno',
                'deliverables' => [
                    ['title' => 'Piel del perro robot en función de chigüiro'],
                    ['title' => 'Animatrónico de rostro', 'due_on' => '2026-10-30'],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $p = Project::where('name', 'CapiDog')->firstOrFail();

        $this->assertCount(2, $p->deliverables);
        $this->assertSame('Piel del perro robot en función de chigüiro', $p->deliverables->first()->title);
        $this->assertFalse($p->deliverables->first()->estaEntregado());
    }

    /**
     * Un entregable es un compromiso con fecha: en el tablero es un hito, no
     * una barra larga.
     */
    public function test_los_entregables_se_llevan_al_tablero_como_hitos(): void
    {
        $p = $this->proyecto();
        $p->update(['due_on' => '2026-11-30']);

        $p->deliverables()->createMany([
            ['title' => 'Piel del perro robot', 'position' => 0],
            ['title' => 'Animatrónico de rostro', 'due_on' => '2026-10-30', 'position' => 1],
        ]);

        $cuantas = app(\App\Services\Projects\ProjectService::class)
            ->llevarEntregablesAlTablero($p);

        $this->assertSame(2, $cuantas);
        $this->assertCount(2, $p->tasks()->get());

        $hito = $p->tasks()->where('title', 'Animatrónico de rostro')->firstOrFail();
        $this->assertTrue($hito->is_milestone);
        $this->assertSame('2026-10-30', $hito->due_on->toDateString());

        // Sin fecha propia hereda la del proyecto: un hito sin fecha no sale en
        // el cronograma, que es donde se mira si da tiempo.
        $otro = $p->tasks()->where('title', 'Piel del perro robot')->firstOrFail();
        $this->assertSame('2026-11-30', $otro->due_on->toDateString());

        $this->assertSame($otro->id, $p->deliverables()->where('title', 'Piel del perro robot')->first()->task_id);
    }

    /** Pulsarlo dos veces no duplica el tablero, que es lo que uno teme. */
    public function test_traer_los_entregables_dos_veces_no_duplica_nada(): void
    {
        $p = $this->proyecto();
        $p->deliverables()->create(['title' => 'Piel del perro robot']);

        $servicio = app(\App\Services\Projects\ProjectService::class);

        $this->assertSame(1, $servicio->llevarEntregablesAlTablero($p));
        $this->assertSame(0, $servicio->llevarEntregablesAlTablero($p->fresh()));
        $this->assertSame(1, $p->tasks()->count());
    }

    /** Cerrar la tarea en el tablero da por cumplido su entregable. */
    public function test_cerrar_la_tarea_cumple_el_entregable(): void
    {
        $p = $this->proyecto();
        $entregable = $p->deliverables()->create(['title' => 'Animatrónico de rostro']);

        app(\App\Services\Projects\ProjectService::class)->llevarEntregablesAlTablero($p);
        $entregable->refresh();

        $this->assertFalse($entregable->estaEntregado());

        app(\App\Services\Projects\ProjectService::class)->moverTarea($entregable->task, 'hecha');

        $this->assertTrue($entregable->fresh()->load('task')->estaEntregado());
    }

    public function test_el_tablero_lista_los_entregables_y_su_estado(): void
    {
        $p = $this->proyecto();
        $p->deliverables()->create(['title' => 'Piel del perro robot']);

        $this->actingAs($this->conRol(User::ROL_CONSULTOR))
            ->get(route('proyectos.tablero', $p))
            ->assertOk()
            ->assertSee('Entregables')
            ->assertSee('Piel del perro robot')
            ->assertSee('todavía no es tarea');
    }

    /** La propuesta se sostiene en sus entregables, no en un párrafo. */
    public function test_sin_entregables_la_propuesta_no_tiene_evidencia(): void
    {
        $p = $this->proyecto();
        $servicio = app(\App\Services\Projects\ProjectService::class);

        $porEtapa = collect($servicio->evidencias($p))->keyBy('etapa');
        $this->assertFalse($porEtapa['propuesta']['listo']);

        $p->deliverables()->create(['title' => 'Piel del perro robot']);
        $p->documents()->create(['kind' => 'propuesta', 'title' => 'Propuesta 2026-14']);

        $porEtapa = collect($servicio->evidencias($p->fresh()))->keyBy('etapa');
        $this->assertTrue($porEtapa['propuesta']['listo']);
        $this->assertStringContainsString('Piel del perro robot', $porEtapa['propuesta']['detalle']);
    }

    // ------------------------------------------- la evidencia de cada etapa

    /**
     * Cada etapa deja algo escrito y ese algo se sostiene solo. La pantalla
     * tiene que decir, de un vistazo, qué hay y qué falta: si hay que deducirlo
     * de la etapa actual, nadie lo deduce.
     */
    public function test_el_tablero_muestra_la_evidencia_de_cada_etapa(): void
    {
        $p = $this->proyecto();
        $p->update(['summary' => 'Señalizar los seis edificios del campus.']);

        $this->actingAs($this->conRol(User::ROL_CONSULTOR))
            ->get(route('proyectos.tablero', $p))
            ->assertOk()
            ->assertSee('Etapas y su evidencia')
            ->assertSee('La idea en dos frases')
            ->assertSee('Señalizar los seis edificios')
            ->assertSee('El respaldo: contrato u orden de servicio')
            ->assertSee('Sin evidencia todavía');
    }

    public function test_la_evidencia_se_marca_lista_cuando_esta(): void
    {
        $p = $this->proyecto();
        $servicio = app(\App\Services\Projects\ProjectService::class);

        $porEtapa = collect($servicio->evidencias($p))->keyBy('etapa');
        $this->assertFalse($porEtapa['contrato']['listo']);

        $p->documents()->create(['kind' => 'contrato', 'title' => 'Orden de servicio 118']);

        $porEtapa = collect($servicio->evidencias($p->fresh()))->keyBy('etapa');
        $this->assertTrue($porEtapa['contrato']['listo']);
        $this->assertStringContainsString('Orden de servicio 118', $porEtapa['contrato']['detalle']);
    }

    /**
     * Las compuertas salen de la misma tabla que la evidencia. Si fueran dos
     * listas acabarían diciendo cosas distintas, y la pantalla prometería algo
     * que el servicio no exige.
     */
    public function test_la_compuerta_pide_el_documento_que_declara_la_evidencia(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $p = $this->proyecto($admin);
        $servicio = app(\App\Services\Projects\ProjectService::class);

        $servicio->moverA($p, 'propuesta');

        $documento = \App\Services\Projects\ProjectService::EVIDENCIAS['propuesta']['documento'];
        $this->assertStringContainsString(
            \App\Models\ProjectDocument::TIPOS[$documento],
            (string) $servicio->queFalta($p->fresh()),
        );
    }

    // --------------------------------------------------- cronograma general

    public function test_el_cronograma_general_junta_los_proyectos_con_fechas(): void
    {
        $conFechas = $this->proyecto();
        $conFechas->update([
            'name'      => 'Señalética del campus',
            'starts_on' => '2026-09-01',
            'due_on'    => '2026-10-15',
        ]);

        $sinFechas = app(\App\Services\Projects\ProjectService::class)
            ->registrarIdea(['name' => 'Idea sin fechas todavía']);

        $this->actingAs($this->conRol(User::ROL_CONSULTOR))
            ->get(route('proyectos.cronograma'))
            ->assertOk()
            ->assertSee('Cronograma de proyectos')
            ->assertSee('Señalética del campus')
            ->assertSee('Sin fechas')
            ->assertSee('Idea sin fechas todavía');

        $this->assertNotNull($sinFechas->id);
    }

    /** Por defecto solo lo vivo: un cronograma con lo descartado no se lee. */
    public function test_el_cronograma_general_deja_fuera_lo_descartado(): void
    {
        $muerto = $this->proyecto();
        $muerto->update(['name' => 'Proyecto descartado', 'starts_on' => '2026-09-01', 'due_on' => '2026-09-30']);
        app(\App\Services\Projects\ProjectService::class)->descartar($muerto, 'No hubo presupuesto.');

        $this->actingAs($this->conRol(User::ROL_CONSULTOR))
            ->get(route('proyectos.cronograma'))
            ->assertOk()
            ->assertDontSee('Proyecto descartado');

        $this->actingAs($this->conRol(User::ROL_CONSULTOR))
            ->get(route('proyectos.cronograma', ['todos' => 1]))
            ->assertOk()
            ->assertSee('Proyecto descartado');
    }

    public function test_el_cronograma_general_es_solo_del_backoffice(): void
    {
        $this->actingAs($this->conRol())
            ->get(route('proyectos.cronograma'))
            ->assertForbidden();
    }

    /** Se arrastra como en un tablero de verdad, y los botones se quedan. */
    public function test_las_tarjetas_del_tablero_se_pueden_arrastrar(): void
    {
        $p = $this->proyecto();
        $p->tasks()->create(['title' => 'Cortar piezas']);

        $this->actingAs($this->conRol(User::ROL_CONSULTOR))
            ->get(route('proyectos.tablero', $p))
            ->assertOk()
            ->assertSee('draggable="true"', false)
            ->assertSee('data-estado="hecha"', false)
            // Arrastrar no funciona con el dedo ni con teclado: los botones
            // son la via que sirve desde una tablet en el taller.
            ->assertSee('Se arrastra de una columna a otra, o se usan los botones');
    }

    public function test_el_tablero_es_solo_del_backoffice(): void
    {
        $p = $this->proyecto();

        $this->actingAs($this->conRol())
            ->get(route('proyectos.tablero', $p))
            ->assertForbidden();

        $this->actingAs($this->conRol(User::ROL_CONSULTOR))
            ->get(route('proyectos.tablero', $p))
            ->assertOk();
    }

    public function test_el_tablero_dice_que_falta_para_avanzar(): void
    {
        $p = $this->proyecto();   // sin responsable

        $this->actingAs($this->conRol(User::ROL_CONSULTOR))
            ->get(route('proyectos.tablero', $p))
            ->assertOk()
            ->assertSee('Para avanzar')
            ->assertSee('responsable');
    }
}
