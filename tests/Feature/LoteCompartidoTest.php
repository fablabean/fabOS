<?php

namespace Tests\Feature;

use App\Models\CandidateBatch;
use App\Models\User;
use App\Services\Projects\LoteCompartido;
use App\Services\Projects\LoteDeCandidatos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Compartir la evaluación de un lote, y lo que el Fablab puede hacer (§11).
 *
 * El resultado de una convocatoria lo quiere ver gente de fuera: el jurado,
 * el aliado que mandó el tablero. Una página con enlace firmado, de solo
 * lectura, con la tabla completa, filtros, gráficas y CSV. Las tres cosas
 * salen de la misma tabla, así que no pueden contradecirse.
 */
class LoteCompartidoTest extends TestCase
{
    use RefreshDatabase;

    private const TABLERO = "Puntaje;Proyecto;Programa;Ruta;Resumen del proyecto\r\n"
        . "913;Sabbia;Construye;Ruta 4-6;Saneamiento ecológico sin agua.\r\n"
        . "843;Tótem Inteligente;Construye;Ruta 4-6;Tótem con IA offline.\r\n"
        . "597;HandTalk CB;Founder Hub;Ruta 1-3;Lengua de señas con IA.\r\n";

    private CandidateBatch $lote;
    private User $jefa;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->jefa = User::create(['name' => 'Jefa', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $this->jefa->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $this->lote = CandidateBatch::create(['name' => 'Science2Venture · resultados', 'source' => 'Convocatoria']);

        $s = app(LoteDeCandidatos::class);
        $s->importar($this->lote, self::TABLERO, $s->analizar(self::TABLERO)['mapa'], true);

        $sabbia = $this->lote->candidates()->where('name', 'Sabbia')->firstOrFail();
        $s->evaluar($sabbia, 'aceptado', 4, 'Potencial claro.', $this->jefa, 'Desarrollo de prototipo IoT');

        $handtalk = $this->lote->candidates()->where('name', 'HandTalk CB')->firstOrFail();
        $s->evaluar($handtalk, 'descartado', 2, 'Le falta desarrollo.', $this->jefa);
    }

    private function compartido(): LoteCompartido
    {
        return app(LoteCompartido::class);
    }

    // ----------------------------------------------------- lo del Fablab

    public function test_lo_que_puede_hacer_el_fablab_va_en_su_columna(): void
    {
        $sabbia = $this->lote->candidates()->where('name', 'Sabbia')->firstOrFail();

        $this->assertSame('Potencial claro.', $sabbia->evaluation_note);
        $this->assertSame('Desarrollo de prototipo IoT', $sabbia->fablab_note);
    }

    public function test_y_pasa_al_resumen_del_proyecto_al_convertirlo(): void
    {
        $sabbia = $this->lote->candidates()->where('name', 'Sabbia')->firstOrFail();

        $proyecto = app(LoteDeCandidatos::class)->convertir($sabbia, $this->jefa);

        $this->assertStringContainsString('Lo que puede hacer el Fablab: Desarrollo de prototipo IoT', $proyecto->summary);
    }

    // -------------------------------------------------- lista de espera

    /** Con nota y sin decidir, no está «sin evaluar»: está en espera. */
    public function test_con_nota_y_sin_decidir_queda_en_lista_de_espera(): void
    {
        $totem = $this->lote->candidates()->where('name', 'Tótem Inteligente')->firstOrFail();

        $r = app(LoteDeCandidatos::class)->evaluar($totem, 'pendiente', 3, 'Falta ver el modelo de negocio.', $this->jefa);

        $this->assertSame('espera', $r->status);
        $this->assertSame('En lista de espera', $r->enQueVa());
        $this->assertTrue($r->estaEvaluado());
        $this->assertSame($this->jefa->id, $r->evaluated_by, 'alguien lo miró, y queda quién');

        // Y el lote ya no lo cuenta como pendiente.
        $this->assertSame(0, $this->lote->fresh()->pendientes());
    }

    /** Sin nota, «sin evaluar» sigue siendo sin evaluar. */
    public function test_sin_nota_sigue_sin_evaluar(): void
    {
        $totem = $this->lote->candidates()->where('name', 'Tótem Inteligente')->firstOrFail();

        $r = app(LoteDeCandidatos::class)->evaluar($totem, 'pendiente', null, null, $this->jefa);

        $this->assertSame('pendiente', $r->status);
    }

    // ------------------------------------------------------------ la tabla

    public function test_la_tabla_trae_las_columnas_fijas_y_los_extras_de_todos(): void
    {
        $t = $this->compartido()->tabla($this->lote);

        $this->assertContains('Candidato', $t['columnas']);
        $this->assertContains('Qué puede hacer el Fablab', $t['columnas']);
        $this->assertSame(['Puntaje', 'Programa', 'Ruta'], $t['extras']);
        $this->assertCount(3, $t['filas']);

        $sabbia = collect($t['filas'])->firstWhere('Candidato', 'Sabbia');
        $this->assertSame('Aceptado', $sabbia['Decisión']);
        $this->assertSame('4', $sabbia['Nota']);
        $this->assertSame('913', $sabbia['Puntaje']);
        $this->assertSame('Desarrollo de prototipo IoT', $sabbia['Qué puede hacer el Fablab']);
    }

    public function test_el_csv_lleva_bom_y_punto_y_coma(): void
    {
        $csv = $this->compartido()->csv($this->lote);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv, 'sin BOM, Excel muestra «SeÃ±alÃ©tica»');
        $this->assertStringContainsString('Candidato;', $csv);
        $this->assertStringContainsString('Sabbia;', $csv);
        $this->assertSame(4, substr_count($csv, "\n"), 'cabecera y tres filas');
    }

    public function test_las_graficas_cuentan_decision_nota_y_los_extras_que_se_dejan(): void
    {
        $g = collect($this->compartido()->graficas($this->lote))->keyBy('titulo');

        $this->assertSame(1, collect($g['Decisión']['barras'])->firstWhere('etiqueta', 'Aceptado')['cuantos']);
        $this->assertSame(1, collect($g['Decisión']['barras'])->firstWhere('etiqueta', 'Sin evaluar')['cuantos']);
        $this->assertSame(1, collect($g['Nota (1 a 5)']['barras'])->firstWhere('etiqueta', '4')['cuantos']);

        // Ruta tiene dos valores: se grafica. Puntaje tiene tres distintos entre tres: es una lista, no una gráfica.
        $this->assertTrue($g->has('Ruta'));
        $this->assertSame(2, collect($g['Ruta']['barras'])->firstWhere('etiqueta', 'Ruta 4-6')['cuantos']);
        $this->assertFalse($g->has('Puntaje'));
    }

    // --------------------------------------------------------- la pagina

    public function test_con_el_enlace_firmado_se_ve_sin_cuenta(): void
    {
        $this->get($this->compartido()->enlace($this->lote))
            ->assertOk()
            ->assertSee('Science2Venture · resultados')
            ->assertSee('Sabbia')
            ->assertSee('Desarrollo de prototipo IoT')
            ->assertSee('Descargar CSV')
            // Los filtros de lo que se deja contar, y el de decisión.
            ->assertSee('data-filtro="Ruta"', false)
            ->assertSee('data-filtro="Decisión"', false)
            // Organización y contacto van debajo del nombre, no en columnas.
            ->assertDontSee('data-col="Organización"', false)
            ->assertSee('data-col="Candidato"', false);
    }

    /** El CSV sí lleva la organización y el contacto en columnas: en Excel se filtran. */
    public function test_el_csv_conserva_organizacion_y_contacto_en_columnas(): void
    {
        $this->assertStringContainsString('Organización;Contacto;Correo;Teléfono', $this->compartido()->csv($this->lote));
    }

    public function test_sin_enlace_ni_sesion_no_se_ve(): void
    {
        $this->get(route('lotes.compartido', $this->lote))->assertForbidden();
        $this->get(route('lotes.compartido.csv', $this->lote))->assertForbidden();
    }

    public function test_el_csv_se_descarga_con_el_enlace_firmado(): void
    {
        $this->get($this->compartido()->enlaceCsv($this->lote))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->assertSee('Sabbia;');
    }

    /** Quien es del backoffice entra con su sesión, sin enlace. */
    public function test_el_backoffice_lo_ve_con_su_sesion(): void
    {
        $this->actingAs($this->jefa)->get(route('lotes.compartido', $this->lote))->assertOk();
    }
}
