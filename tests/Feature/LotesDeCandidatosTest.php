<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\CandidateBatch;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\LoteDeCandidatos;
use App\Services\Projects\ProjectException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Lotes de candidatos (§11).
 *
 * A veces no llega un proyecto: llega una lista. Veinte spin-offs, los ganadores
 * de una convocatoria. Sin un sitio para eso, la lista vive en un Excel que
 * alguien reenvía, se evalúa en una reunión, y lo acordado se pierde entre la
 * reunión y el momento de arrancar.
 */
class LotesDeCandidatosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function lote(): CandidateBatch
    {
        return CandidateBatch::create([
            'name'   => 'Spin-offs de la incubadora, cohorte 2026-2',
            'source' => 'Dirección de Emprendimiento',
        ]);
    }

    private function persona(): User
    {
        return User::create([
            'name' => 'Quien evalúa', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
    }

    private function servicio(): LoteDeCandidatos
    {
        return app(LoteDeCandidatos::class);
    }

    // ---------------------------------------------------------- pegar

    /**
     * Se pega tal como llega. Pedirle a quien pega que primero convierta el
     * formato es pedirle que no lo haga.
     */
    public function test_se_pega_una_lista_de_una_hoja_de_calculo(): void
    {
        $lote = $this->lote();

        $cuantos = $this->servicio()->pegar($lote, implode("\n", [
            "Sensores para invernadero\tAgroTech SAS\tLaura Díaz\tlaura@agrotech.co\tRiego automático",
            "Carcasa para prótesis\tBioMakers\tJulián Roa\tjulian@biomakers.co",
        ]));

        $this->assertSame(2, $cuantos);

        $primero = $lote->candidates()->first();

        $this->assertSame('Sensores para invernadero', $primero->name);
        $this->assertSame('AgroTech SAS', $primero->organization);
        $this->assertSame('Laura Díaz', $primero->contact_name);
        $this->assertSame('laura@agrotech.co', $primero->contact_email);
        $this->assertSame('Riego automático', $primero->description);
    }

    /** Punto y coma y barra vertical también: es lo que sale de un correo. */
    public function test_sirven_otros_separadores(): void
    {
        $lote = $this->lote();

        $this->servicio()->pegar($lote, "Uno;Empresa A\nDos|Empresa B");

        $this->assertSame(['Uno', 'Dos'], $lote->candidates()->pluck('name')->all());
        $this->assertSame('Empresa B', $lote->candidates()->skip(1)->first()->organization);
    }

    /** Una lista de nombres a secas ya sirve para empezar a evaluar. */
    public function test_basta_con_los_nombres(): void
    {
        $lote = $this->lote();

        $this->assertSame(3, $this->servicio()->pegar($lote, "Uno\nDos\nTres"));
        $this->assertNull($lote->candidates()->first()->organization);
    }

    public function test_las_lineas_vacias_no_cuentan(): void
    {
        $lote = $this->lote();

        $this->assertSame(2, $this->servicio()->pegar($lote, "Uno\n\n  \nDos\n"));
    }

    /** Una cabecera pegada por descuido no es un candidato. */
    public function test_la_cabecera_de_la_hoja_no_entra(): void
    {
        $lote = $this->lote();

        $this->servicio()->pegar($lote, "Nombre\tOrganización\nUno\tEmpresa A");

        $this->assertSame(['Uno'], $lote->candidates()->pluck('name')->all());
    }

    /** Y pegar dos veces añade al final, no reemplaza. */
    public function test_pegar_otra_vez_anade_al_final(): void
    {
        $lote = $this->lote();

        $this->servicio()->pegar($lote, "Uno\nDos");
        $this->servicio()->pegar($lote, "Tres");

        $this->assertSame(['Uno', 'Dos', 'Tres'], $lote->candidates()->pluck('name')->all());
    }

    public function test_un_correo_que_no_lo_es_no_se_guarda_como_correo(): void
    {
        $lote = $this->lote();

        $this->servicio()->pegar($lote, "Uno\tEmpresa\tAlguien\tno-es-un-correo");

        $this->assertNull($lote->candidates()->first()->contact_email);
    }

    // -------------------------------------------------------- evaluar

    /**
     * Una decisión sin autor se discute otra vez dentro de un mes, y nadie
     * recuerda por qué se dijo que no.
     */
    public function test_evaluar_deja_quien_y_cuando(): void
    {
        $lote = $this->lote();
        $this->servicio()->pegar($lote, 'Sensores para invernadero');

        $quien = $this->persona();

        $candidato = $this->servicio()->evaluar(
            $lote->candidates()->first(),
            'aceptado',
            4,
            'Encaja con impresión 3D y corte láser.',
            $quien,
        );

        $this->assertSame('aceptado', $candidato->status);
        $this->assertSame(4, $candidato->score);
        $this->assertSame($quien->id, $candidato->evaluated_by);
        $this->assertNotNull($candidato->evaluated_at);
    }

    public function test_una_decision_que_no_existe_no_pasa(): void
    {
        $lote = $this->lote();
        $this->servicio()->pegar($lote, 'Uno');

        $this->expectException(ProjectException::class);

        $this->servicio()->evaluar($lote->candidates()->first(), 'quiza');
    }

    // ------------------------------------------------------ convertir

    public function test_lo_aceptado_se_convierte_en_proyecto(): void
    {
        $lote = $this->lote();
        $this->servicio()->pegar($lote, "Sensores para invernadero\tAgroTech SAS\tLaura Díaz\tlaura@agrotech.co\tRiego automático");

        $candidato = $lote->candidates()->first();
        $this->servicio()->evaluar($candidato, 'aceptado', 5, 'Encaja perfecto.', $this->persona());

        $proyecto = $this->servicio()->convertir($candidato->fresh());

        $this->assertNotNull($proyecto->code);
        $this->assertSame('Sensores para invernadero', $proyecto->name);
        $this->assertSame('AgroTech SAS', $proyecto->organization);
        $this->assertSame('laura@agrotech.co', $proyecto->contact_email);
        $this->assertSame('idea', $proyecto->stage);

        // Lo que se escribió al evaluarlo es lo primero que hay que recordar.
        $this->assertStringContainsString('Riego automático', $proyecto->summary);
        $this->assertStringContainsString('Encaja perfecto', $proyecto->summary);
        $this->assertStringContainsString($lote->name, $proyecto->notes);

        $this->assertSame($proyecto->id, $candidato->fresh()->project_id);
    }

    /** Solo lo aceptado: convertir lo que no se ha mirado es saltarse el paso. */
    public function test_lo_no_evaluado_no_se_convierte(): void
    {
        $lote = $this->lote();
        $this->servicio()->pegar($lote, 'Uno');

        $this->expectException(ProjectException::class);

        $this->servicio()->convertir($lote->candidates()->first());
    }

    /**
     * Y solo una vez: dos conversiones darían dos proyectos para el mismo
     * encargo, y el segundo aparecería sin explicación.
     */
    public function test_no_se_convierte_dos_veces(): void
    {
        $lote = $this->lote();
        $this->servicio()->pegar($lote, 'Uno');

        $candidato = $lote->candidates()->first();
        $this->servicio()->evaluar($candidato, 'aceptado');
        $this->servicio()->convertir($candidato->fresh());

        $this->expectException(ProjectException::class);

        $this->servicio()->convertir($candidato->fresh());
    }

    public function test_se_convierte_todo_lo_aceptado_de_una_vez(): void
    {
        $lote = $this->lote();
        $this->servicio()->pegar($lote, "Uno\nDos\nTres");

        $candidatos = $lote->candidates()->get();

        $this->servicio()->evaluar($candidatos[0], 'aceptado');
        $this->servicio()->evaluar($candidatos[1], 'descartado', null, 'Fuera de alcance.');
        $this->servicio()->evaluar($candidatos[2], 'aceptado');

        $this->assertSame(2, $this->servicio()->convertirLoAceptado($lote->fresh()));
        $this->assertSame(2, Project::count());

        // Y correrlo otra vez no crea nada: ya no queda nada por convertir.
        $this->assertSame(0, $this->servicio()->convertirLoAceptado($lote->fresh()));
        $this->assertSame(2, Project::count());
    }

    // ---------------------------------------------------------- contar

    public function test_el_lote_dice_que_falta(): void
    {
        $lote = $this->lote();
        $this->servicio()->pegar($lote, "Uno\nDos\nTres");

        $candidatos = $lote->candidates()->get();
        $this->servicio()->evaluar($candidatos[0], 'aceptado');
        $this->servicio()->evaluar($candidatos[1], 'descartado');

        $lote->refresh();

        $this->assertSame(1, $lote->pendientes());
        $this->assertSame(1, $lote->aceptados());
        $this->assertSame(1, $lote->sinConvertir());

        $this->servicio()->convertirLoAceptado($lote);

        $this->assertSame(0, $lote->fresh()->sinConvertir());
    }

    /** El contador del menú es lo que hace que alguien vuelva a mirarlo. */
    public function test_el_menu_cuenta_lo_que_falta_por_evaluar(): void
    {
        $lote = $this->lote();
        $this->servicio()->pegar($lote, "Uno\nDos");

        $this->assertSame(
            '2',
            \App\Filament\Resources\CandidateBatches\CandidateBatchResource::getNavigationBadge(),
        );

        // Un lote cerrado deja de pedir atención: ya se decidió lo que había.
        $lote->update(['status' => 'cerrado']);

        $this->assertNull(
            \App\Filament\Resources\CandidateBatches\CandidateBatchResource::getNavigationBadge(),
        );
    }

    public function test_borrar_el_lote_se_lleva_sus_candidatos(): void
    {
        $lote = $this->lote();
        $this->servicio()->pegar($lote, "Uno\nDos");

        $lote->delete();

        $this->assertDatabaseCount('candidates', 0);
    }

    /** Pero no los proyectos que ya salieron de él: esos ya tienen vida propia. */
    public function test_borrar_el_lote_no_se_lleva_los_proyectos(): void
    {
        $lote = $this->lote();
        $this->servicio()->pegar($lote, 'Uno');

        $candidato = $lote->candidates()->first();
        $this->servicio()->evaluar($candidato, 'aceptado');
        $proyecto = $this->servicio()->convertir($candidato->fresh());

        $lote->delete();

        $this->assertDatabaseHas('projects', ['id' => $proyecto->id]);
    }
}
