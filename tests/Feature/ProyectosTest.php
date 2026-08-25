<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\User;
use App\Services\Projects\ProjectException;
use App\Services\Projects\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** El embudo de proyectos y sus compuertas documentales (§11). */
class ProyectosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function proyectos(): ProjectService
    {
        return app(ProjectService::class);
    }

    private function persona(): User
    {
        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
    }

    private function idea(array $datos = []): Project
    {
        return $this->proyectos()->registrarIdea(array_merge([
            'name'         => 'Señalética para el campus',
            'source'       => 'whatsapp',
            'contact_name' => 'Laura Gómez',
            'organization' => 'Bienestar Universitario',
            'summary'      => 'Necesitan 40 piezas cortadas en acrílico.',
        ], $datos));
    }

    private function documento(Project $p, string $tipo): void
    {
        $p->documents()->create([
            'kind'  => $tipo,
            'title' => 'Documento de prueba',
            'url'   => 'https://drive.example.com/doc',
        ]);
    }

    // ---------------------------------------------------------------- la idea

    public function test_una_idea_se_anota_aunque_quien_pide_no_tenga_cuenta(): void
    {
        $p = $this->idea();

        // Una empresa que escribe por WhatsApp no debería registrarse para que
        // le anoten la idea: es el paso que más se pierde.
        $this->assertSame('idea', $p->stage);
        $this->assertNull($p->requested_by);
        $this->assertSame('Bienestar Universitario', $p->quienPide());
        $this->assertSame('PRY-' . now()->year . '-0001', $p->code);
    }

    public function test_el_codigo_es_consecutivo_por_ano(): void
    {
        $this->idea();

        $this->assertSame('PRY-' . now()->year . '-0002', $this->idea()->code);
    }

    // ----------------------------------------------------------- compuertas

    public function test_sin_responsable_no_se_avanza(): void
    {
        $p = $this->idea();

        try {
            $this->proyectos()->avanzar($p);
            $this->fail('debió exigir responsable');
        } catch (ProjectException $e) {
            $this->assertStringContainsString('responsable', $e->getMessage());
            $this->assertSame('idea', $p->fresh()->stage);
        }
    }

    public function test_con_responsable_la_idea_pasa_a_propuesta(): void
    {
        $p = $this->idea();
        $p->update(['lead_id' => $this->persona()->id]);

        $this->assertSame('propuesta', $this->proyectos()->avanzar($p)->stage);
    }

    public function test_no_se_firma_contrato_sin_propuesta_escrita(): void
    {
        $p = $this->idea(['stage' => 'propuesta']);
        $p->update(['lead_id' => $this->persona()->id]);

        try {
            $this->proyectos()->avanzar($p);
            $this->fail('debió exigir la propuesta');
        } catch (ProjectException $e) {
            $this->assertStringContainsString('Propuesta', $e->getMessage());
        }

        $this->documento($p, 'propuesta');

        $this->assertSame('contrato', $this->proyectos()->avanzar($p->fresh())->stage);
    }

    public function test_no_se_fabrica_sin_brief(): void
    {
        $p = $this->idea(['stage' => 'brief']);
        $p->update(['lead_id' => $this->persona()->id]);

        try {
            $this->proyectos()->avanzar($p);
            $this->fail('debió exigir el brief');
        } catch (ProjectException $e) {
            // Fabricar sin brief es fabricar a ciegas.
            $this->assertStringContainsString('Brief', $e->getMessage());
        }
    }

    public function test_saltarse_una_etapa_es_saltarse_su_documento(): void
    {
        $p = $this->idea();
        $p->update(['lead_id' => $this->persona()->id]);
        $this->documento($p, 'propuesta');

        // De idea a ejecución hay que pasar por contrato y brief.
        $this->expectException(ProjectException::class);
        $this->proyectos()->moverA($p, 'ejecucion');
    }

    public function test_el_camino_completo_con_todos_los_documentos(): void
    {
        $p = $this->idea();
        $p->update(['lead_id' => $this->persona()->id]);

        foreach (['propuesta', 'contrato', 'brief', 'informe'] as $doc) {
            $this->documento($p, $doc);
        }

        $enEjecucion = $this->proyectos()->moverA($p->fresh(), 'ejecucion');

        $this->assertSame('ejecucion', $enEjecucion->stage);
        $this->assertNotNull($enEjecucion->starts_on, 'la ejecución fija la fecha de inicio');

        $cerrado = $this->proyectos()->moverA($enEjecucion, 'cierre');

        $this->assertSame('cierre', $cerrado->stage);
        $this->assertSame('cerrado', $cerrado->status);
        $this->assertNotNull($cerrado->closed_at);
    }

    public function test_se_puede_retroceder_sin_compuertas(): void
    {
        $p = $this->idea(['stage' => 'contrato']);
        $p->update(['lead_id' => $this->persona()->id]);

        // Una propuesta puede volver a revisarse: lo que no se permite es
        // avanzar sin lo que sostiene la etapa.
        $this->assertSame('propuesta', $this->proyectos()->moverA($p, 'propuesta')->stage);
    }

    public function test_dice_exactamente_que_falta(): void
    {
        $p = $this->idea();

        $this->assertStringContainsString('responsable', $this->proyectos()->queFalta($p));

        $p->update(['lead_id' => $this->persona()->id]);
        $this->assertNull($this->proyectos()->queFalta($p->fresh()), 'ya puede avanzar');
    }

    public function test_descartar_no_borra(): void
    {
        $p = $this->idea();

        $descartado = $this->proyectos()->descartar($p, 'El cliente consiguió otro proveedor', 'perdido');

        $this->assertSame('perdido', $descartado->status);
        $this->assertSame('El cliente consiguió otro proveedor', $descartado->closing_notes);
        $this->assertSame(1, Project::count(), 'el histórico enseña');
    }

    // --------------------------------------------------------------- equipo

    public function test_el_equipo_admite_proveedores_sin_cuenta(): void
    {
        $p = $this->idea();
        $responsable = $this->persona();

        $this->proyectos()->agregarMiembro($p, [
            'user_id' => $responsable->id, 'role' => 'responsable',
        ]);
        $this->proyectos()->agregarMiembro($p, [
            'external_name' => 'Acrílicos del Norte', 'organization' => 'Proveedor',
            'role' => 'proveedor',
        ]);

        $this->assertSame(2, $p->members()->count());
        $this->assertSame($responsable->id, $p->fresh()->lead_id, 'el responsable queda en la ficha');
        $this->assertSame('Acrílicos del Norte', $p->members()->where('role', 'proveedor')->first()->nombre());
    }

    // -------------------------------------------------------- tablero y gantt

    public function test_el_tablero_agrupa_por_columna(): void
    {
        $p = $this->idea();
        $p->tasks()->createMany([
            ['title' => 'Cortar piezas', 'status' => 'por_hacer'],
            ['title' => 'Pintar', 'status' => 'en_curso'],
            ['title' => 'Esperar material', 'status' => 'bloqueada'],
            ['title' => 'Cotizar', 'status' => 'hecha'],
            ['title' => 'Empacar', 'status' => 'por_hacer'],
        ]);

        $tablero = $this->proyectos()->tablero($p->fresh());

        $this->assertCount(2, $tablero['por_hacer']);
        $this->assertCount(1, $tablero['bloqueada']);
        $this->assertSame(['por_hacer', 'en_curso', 'bloqueada', 'hecha'], array_keys($tablero));
    }

    public function test_mover_una_tarea_a_hecha_la_completa(): void
    {
        $p = $this->idea();
        $tarea = $p->tasks()->create(['title' => 'Cortar piezas', 'progress' => 40]);

        $movida = $this->proyectos()->moverTarea($tarea, 'hecha');

        $this->assertSame(100, $movida->progress);
        $this->assertNotNull($movida->completed_at);
    }

    public function test_el_avance_sale_de_las_tareas_y_no_de_una_barra_a_dedo(): void
    {
        $p = $this->idea();
        $p->tasks()->createMany([
            ['title' => 'Una', 'status' => 'hecha'],
            ['title' => 'Dos', 'status' => 'en_curso', 'progress' => 50],
            ['title' => 'Tres', 'status' => 'por_hacer'],
        ]);

        $this->assertSame(50, $p->fresh()->avance());
    }

    public function test_el_cronograma_toma_solo_lo_que_tiene_fechas(): void
    {
        $p = $this->idea();
        $p->tasks()->createMany([
            ['title' => 'Con fechas', 'starts_on' => '2026-09-01', 'due_on' => '2026-09-05'],
            ['title' => 'Hito', 'starts_on' => '2026-09-10', 'is_milestone' => true],
            ['title' => 'Sin fechas'],
        ]);

        $cronograma = $this->proyectos()->cronograma($p->fresh());

        $this->assertCount(2, $cronograma['tareas'], 'lo que no tiene fechas solo vive en el tablero');
        $this->assertSame('2026-09-01', $cronograma['desde']->toDateString());
        $this->assertSame('2026-09-10', $cronograma['hasta']->toDateString());
    }

    public function test_una_tarea_vencida_se_reconoce(): void
    {
        $p = $this->idea();

        $vencida = $p->tasks()->create(['title' => 'Tarde', 'due_on' => now()->subDays(2)->toDateString()]);
        $hecha = $p->tasks()->create([
            'title' => 'Tarde pero hecha', 'due_on' => now()->subDays(2)->toDateString(), 'status' => 'hecha',
        ]);
        $sinFecha = $p->tasks()->create(['title' => 'Sin fecha']);

        $this->assertTrue($vencida->estaVencida());
        $this->assertFalse($hecha->estaVencida());
        $this->assertFalse($sinFecha->estaVencida());
    }

    public function test_un_hito_ocupa_un_dia_en_el_gantt(): void
    {
        $p = $this->idea();

        $hito = $p->tasks()->create(['title' => 'Entrega', 'starts_on' => '2026-09-10', 'is_milestone' => true]);
        $tarea = $p->tasks()->create(['title' => 'Corte', 'starts_on' => '2026-09-01', 'due_on' => '2026-09-05']);

        $this->assertSame(1, $hito->dias());
        $this->assertSame(5, $tarea->dias());
    }

    /**
     * El codigo lo pone el modelo, no quien crea el proyecto.
     *
     * Lo generaba el servicio, y el formulario del backoffice creaba el
     * proyecto directamente: se saltaba esa linea y la base rechazaba la fila.
     * El error salia como «Error al cargar la pagina», que no dice nada.
     */
    public function test_crear_un_proyecto_sin_pasar_por_el_servicio_igual_tiene_codigo(): void
    {
        $p = \App\Models\Project::create([
            'name'   => 'Metro 1',
            'stage'  => 'idea',
            'status' => 'activo',
            'source' => 'iniciativa',
        ]);

        $this->assertNotNull($p->code);
        $this->assertStringStartsWith('PRY-' . now(config('fabos.lab.timezone'))->year . '-', $p->code);
    }

    public function test_los_codigos_son_consecutivos(): void
    {
        $codigos = collect(range(1, 3))->map(fn ($i) => \App\Models\Project::create([
            'name' => 'Proyecto ' . $i, 'stage' => 'idea', 'status' => 'activo', 'source' => 'iniciativa',
        ])->code);

        $this->assertSame($codigos->unique()->count(), $codigos->count(), 'Dos proyectos comparten codigo.');
        $this->assertStringEndsWith('0003', $codigos->last());
    }

    /** Un codigo dado a mano se respeta: el modelo solo rellena lo que falta. */
    public function test_un_codigo_propio_no_se_pisa(): void
    {
        $p = \App\Models\Project::create([
            'code' => 'PRY-ESPECIAL', 'name' => 'Con codigo propio',
            'stage' => 'idea', 'status' => 'activo', 'source' => 'iniciativa',
        ]);

        $this->assertSame('PRY-ESPECIAL', $p->code);
    }
}
