<?php

namespace Tests\Feature;

use App\Filament\Resources\CandidateBatches\Pages\ListCandidateBatches;
use App\Models\CandidateBatch;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use App\Services\Projects\LoteDeCandidatos;
use App\Services\Projects\ProjectException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Importar una lista diciendo qué columna va a dónde (§11).
 *
 * Cada convocatoria manda su tablero con sus propias columnas. Las fijas de
 * antes -nombre, organización, contacto, correo, descripción- tiraban la
 * mitad de lo que la convocatoria ya había decidido. Ahora se mira la lista,
 * se propone un mapa a partir de la cabecera, y lo que no tiene sitio se
 * guarda como dato extra con el nombre de su columna.
 *
 * El tablero de Science2Venture es el caso real que lo motivó: trece
 * columnas, separadas por punto y coma, con BOM de Excel y comillas dentro.
 */
class ImportarCandidatosTest extends TestCase
{
    use RefreshDatabase;

    /** Dos filas del tablero real, tal como las exporta Excel. */
    private const TABLERO = "\xEF\xBB\xBFPuntaje;Proyecto;Programa;Ruta;Modalidad;Prioridad;Financiación;Acción requerida;Resumen del proyecto;Síntesis evaluación 1;Evaluación 2 / decisión;Nota de seguimiento;Valor a financiar\r\n"
        . "913;Sabbia;Construye;Ruta 4-6;Directa;Estándar;No;Cambio de ruta sugerido;LEHO SAS instala soluciones de saneamiento ecológico sin agua.;Empresa constituida y con contratos, pero sin equipo.;\"Incluir en Ruta 4-6, fase Construye. Fortalecer el modelo; explorar financiadores.\";Se sugiere Ruta 4-6.;\r\n"
        . "843;Tótem Inteligente;Construye;Ruta 4-6;Condicional;Estándar;Sí;Definir hitos de desembolso;Tótem con IA totalmente offline y solar.;Ya genera ventas.;Validación: pruebas.;Depende de hitos.;12000000\r\n";

    private function lote(): CandidateBatch
    {
        return CandidateBatch::create(['name' => 'Science2Venture · resultados', 'source' => 'Convocatoria']);
    }

    private function servicio(): LoteDeCandidatos
    {
        return app(LoteDeCandidatos::class);
    }

    // ------------------------------------------------------------ analizar

    public function test_reconoce_el_separador_la_cabecera_y_las_columnas(): void
    {
        $a = $this->servicio()->analizar(self::TABLERO);

        $this->assertSame(';', $a['separador']);
        $this->assertTrue($a['cabecera']);
        $this->assertCount(13, $a['columnas']);
        $this->assertSame('Puntaje', $a['columnas'][0], 'el BOM de Excel no se cuela en la primera cabecera');
        $this->assertSame(2, $a['filas']);
    }

    /** El mapa propuesto: Proyecto es el nombre, el resumen la descripción, el puntaje un extra. */
    public function test_propone_un_mapa_a_partir_de_la_cabecera(): void
    {
        $a = $this->servicio()->analizar(self::TABLERO);

        $this->assertSame('extra', $a['mapa'][0], 'un puntaje de otra convocatoria no es la nota de aquí');
        $this->assertSame('name', $a['mapa'][1]);
        $this->assertSame('description', $a['mapa'][8]);
        $this->assertSame('evaluation_note', $a['mapa'][9]);
        $this->assertSame('extra', $a['mapa'][12]);
    }

    public function test_sin_cabecera_propone_el_orden_de_siempre(): void
    {
        $a = $this->servicio()->analizar("Sensores\tAgroTech\tLaura\tlaura@agro.co\tSensores para invernadero\n");

        $this->assertFalse($a['cabecera']);
        $this->assertSame(['name', 'organization', 'contact_name', 'contact_email', 'description'], $a['mapa']);
    }

    // ------------------------------------------------------------ importar

    public function test_importa_con_el_mapa_y_guarda_lo_demas_como_extra(): void
    {
        $lote = $this->lote();
        $a = $this->servicio()->analizar(self::TABLERO);

        $cuantos = $this->servicio()->importar($lote, self::TABLERO, $a['mapa'], true);

        $this->assertSame(2, $cuantos);

        $sabbia = $lote->candidates()->where('name', 'Sabbia')->firstOrFail();

        $this->assertSame('LEHO SAS instala soluciones de saneamiento ecológico sin agua.', $sabbia->description);
        $this->assertSame('Empresa constituida y con contratos, pero sin equipo.', $sabbia->evaluation_note);
        $this->assertSame('913', $sabbia->extras()['Puntaje']);
        $this->assertSame('Ruta 4-6', $sabbia->extras()['Ruta']);
        // La celda entre comillas con punto y coma dentro no partió la fila.
        $this->assertSame('Incluir en Ruta 4-6, fase Construye. Fortalecer el modelo; explorar financiadores.', $sabbia->extras()['Evaluación 2 / decisión']);
        // Y lo vacío no se guarda como extra.
        $this->assertArrayNotHasKey('Valor a financiar', $sabbia->extras());

        $totem = $lote->candidates()->where('name', 'Tótem Inteligente')->firstOrFail();
        $this->assertSame('12000000', $totem->extras()['Valor a financiar']);
    }

    /** Quien importa corrige el mapa: aquí el puntaje se ignora y la ruta va a organización. */
    public function test_el_mapa_corregido_manda(): void
    {
        $lote = $this->lote();
        $mapa = $this->servicio()->analizar(self::TABLERO)['mapa'];
        $mapa[0] = 'ignorar';
        $mapa[3] = 'organization';

        $this->servicio()->importar($lote, self::TABLERO, $mapa, true);

        $sabbia = $lote->candidates()->where('name', 'Sabbia')->firstOrFail();

        $this->assertSame('Ruta 4-6', $sabbia->organization);
        $this->assertArrayNotHasKey('Puntaje', $sabbia->extras());
    }

    public function test_sin_columna_de_nombre_no_se_importa(): void
    {
        $lote = $this->lote();
        $mapa = array_fill(0, 13, 'extra');

        $this->expectException(ProjectException::class);
        $this->expectExceptionMessageMatches('/nombre/');

        $this->servicio()->importar($lote, self::TABLERO, $mapa, true);
    }

    /** Desde el panel: se pega el tablero, se acepta el mapa propuesto, y entra. */
    public function test_desde_el_panel_se_pega_y_se_importa_con_el_mapa(): void
    {
        $jefa = User::create(['name' => 'Jefa', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $jefa->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));
        $factores = app(TwoFactorService::class);
        $secreto = $factores->generarSecreto($jefa);
        $factores->confirmar($jefa, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($jefa->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        $lote = $this->lote();
        $mapa = $this->servicio()->analizar(self::TABLERO)['mapa'];

        Livewire::test(ListCandidateBatches::class)
            ->callAction(TestAction::make('pegar')->table($lote), [
                'lista'    => self::TABLERO,
                'cabecera' => true,
                'mapa'     => $mapa,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(2, $lote->candidates()->count());
        $this->assertSame('913', $lote->candidates()->where('name', 'Sabbia')->first()->extras()['Puntaje']);
    }

    /** Pegar como antes sigue funcionando: el orden fijo es un mapa más. */
    public function test_pegar_como_antes_sigue_igual(): void
    {
        $lote = $this->lote();

        $cuantos = $this->servicio()->pegar($lote, "Sensores\tAgroTech SAS\tLaura Díaz\tlaura@agrotech.co\tPara invernadero\n");

        $this->assertSame(1, $cuantos);
        $this->assertSame('AgroTech SAS', $lote->candidates()->first()->organization);
    }
}
