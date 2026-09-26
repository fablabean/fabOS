<?php

namespace Tests\Feature;

use App\Filament\Pages\GuiaDeReservas as PantallaDeLaGuia;
use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Ia\GuiaDeReservas;
use App\Support\FactoresDeSesion;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Corregir a la guía sin desplegar (§10).
 *
 * La guía acierta casi siempre y se equivoca en lo que no puede saber leyendo
 * el catálogo: «impresión 3D para un proyecto» no es un encargo, es casi
 * siempre alguien empezando. Eso se descubre leyendo lo que la gente
 * pregunta, y hasta ahora corregirlo era tocar el código.
 *
 * Dos palancas, y la diferencia entre ellas es el punto:
 *
 *  · **Las reglas** se añaden a sus instrucciones y cambian lo que decide.
 *  · **Las advertencias** se pegan a la respuesta después, sin pasar por la
 *    IA: «reservar la sala de la láser no da derecho a usar la láser» tiene
 *    que salir las cinco veces de cada cinco, no las cuatro que se acuerde.
 */
class ControlManualDeLaGuiaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['fabos.ia.activa' => true, 'fabos.ia.clave' => 'prueba', 'fabos.ia.max_por_dia' => 50]);
    }

    /** Lo que va a contestar la API la próxima vez. */
    private array $loQueContesta = ['camino' => 'asesoria', 'porque' => 'Por esto y lo otro.'];

    /**
     * Un solo doble, mutable.
     *
     * `Http::fake()` acumula: llamarlo dos veces no reemplaza el primero, lo
     * añade, y para una misma dirección gana el que se registró antes. Con un
     * cierre que lee una propiedad, cambiar la respuesta a mitad de prueba sí
     * surte efecto —que es justo lo que hace falta para comprobar que una
     * regla nueva obliga a volver a preguntar—.
     */
    private function contesta(string $camino, string $porque = 'Por esto y lo otro.'): void
    {
        $this->loQueContesta = ['camino' => $camino, 'porque' => $porque];

        Http::fake(fn () => Http::response([
            'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => json_encode($this->loQueContesta)]],
        ]));
    }

    private function guia(): GuiaDeReservas
    {
        return app(GuiaDeReservas::class);
    }

    private function admin(): User
    {
        $u = User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_SUPERADMIN, 'web'));

        $factores = app(TwoFactorService::class);
        $secreto = $factores->generarSecreto($u);
        $factores->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    // -------------------------------------------------------- las advertencias

    /** Al recomendar fabricación se recuerda que hacen falta los archivos. */
    public function test_fabricacion_avisa_de_los_archivos(): void
    {
        $this->contesta('proyecto');

        $r = $this->guia()->recomendar('Necesito 20 letreros en acrílico');

        $this->assertSame('proyecto', $r['camino']);
        $this->assertStringContainsString('archivos listos para producción', $r['advertencia']);
    }

    /** Y al recomendar un espacio, que el espacio no es la máquina. */
    public function test_el_espacio_avisa_que_no_incluye_la_maquina(): void
    {
        $this->contesta('espacio');

        $r = $this->guia()->recomendar('Necesito la sala de la láser para mi clase');

        $this->assertStringContainsString('no da derecho a usarla', $r['advertencia']);
    }

    /** Los caminos sin advertencia no inventan ninguna. */
    public function test_un_camino_sin_advertencia_no_dice_nada(): void
    {
        $this->contesta('asesoria');

        $this->assertNull($this->guia()->recomendar('No sé qué máquina necesito')['advertencia']);
    }

    /**
     * La advertencia se pega después de la memoria.
     *
     * Corregirla tiene que surtir efecto de inmediato, también en lo que ya
     * se había preguntado: si no, se corrige, se prueba con la misma frase y
     * parece que no sirvió.
     */
    public function test_cambiar_la_advertencia_alcanza_a_lo_ya_contestado(): void
    {
        $this->contesta('espacio');

        $this->guia()->recomendar('Quiero la sala grande');

        Setting::put(Settings::GUIA_ADVERTENCIAS, ['espacio' => 'Trae tu propio café.'], 'comunicaciones');

        // Contestada de memoria —no se vuelve a preguntar a la API— y aun así
        // con la advertencia nueva.
        $r = $this->guia()->recomendar('Quiero la sala grande');

        $this->assertSame('Trae tu propio café.', $r['advertencia']);
        Http::assertSentCount(1);
    }

    /** Vaciarlas es una decisión, y se respeta: no vuelven las de fábrica. */
    public function test_quitar_todas_las_advertencias_las_quita(): void
    {
        Setting::put(Settings::GUIA_ADVERTENCIAS, [], 'comunicaciones');
        $this->contesta('proyecto');

        $this->assertNull($this->guia()->recomendar('20 letreros en acrílico')['advertencia']);
    }

    // ------------------------------------------------------------- las reglas

    /** Lo escrito en el panel llega a las instrucciones del modelo. */
    public function test_las_reglas_de_la_casa_llegan_al_modelo(): void
    {
        Setting::put(
            Settings::GUIA_INSTRUCCIONES,
            'La láser grande está de baja: no la recomiendes.',
            'comunicaciones',
        );

        $this->contesta('asesoria');
        $this->guia()->recomendar('Quiero cortar en la láser grande');

        Http::assertSent(function ($peticion) {
            return str_contains($peticion['system'], 'REGLAS DE ESTE LABORATORIO')
                && str_contains($peticion['system'], 'La láser grande está de baja');
        });
    }

    /** Sin reglas propias no se añade una sección vacía al texto. */
    public function test_sin_reglas_propias_no_se_añade_nada(): void
    {
        $this->contesta('asesoria');
        $this->guia()->recomendar('Quiero aprender a usar la láser');

        Http::assertSent(fn ($peticion) => ! str_contains($peticion['system'], 'REGLAS DE ESTE LABORATORIO'));
    }

    /**
     * Cambiar las reglas invalida lo recordado.
     *
     * Sin esto, la corrección no se notaría durante un día entero en todo lo
     * ya preguntado —justo lo que uno corre a comprobar después de
     * corregirla— y parecería que no sirvió.
     */
    public function test_cambiar_las_reglas_obliga_a_volver_a_preguntar(): void
    {
        $this->contesta('proyecto');
        $this->guia()->recomendar('Impresión 3D para un proyecto');

        Setting::put(Settings::GUIA_INSTRUCCIONES, 'Eso es asesoría, no un encargo.', 'comunicaciones');

        $this->contesta('asesoria');
        $r = $this->guia()->recomendar('Impresión 3D para un proyecto');

        $this->assertSame('asesoria', $r['camino']);
    }

    // ---------------------------------------------------- lo que se le enseña

    /**
     * El certifab sólo existe en «Hago mi pieza».
     *
     * Salió una respuesta que mandaba a asesoría a alguien con el diseño
     * listo, razonando que «no dices si tienes el certifab». Encargar una
     * pieza no lo exige —reservar una sala tampoco, ni pedir prestada una
     * herramienta—, así que eso le pone un requisito que no existe.
     */
    public function test_se_le_dice_que_el_certifab_es_solo_de_autonomia(): void
    {
        $this->contesta('asesoria');
        $this->guia()->recomendar('Tengo un diseño ya listo y quiero imprimirlo');

        Http::assertSent(function ($peticion) {
            return str_contains($peticion['system'], 'EL CERTIFAB SOLO EXISTE EN autonomia')
                && str_contains($peticion['system'], 'Mandar a hacer algo no lo exige');
        });
    }

    /**
     * Y que un diseño listo es un encargo, no una duda.
     *
     * La corrección anterior —«impresión 3D para un proyecto» no es un
     * encargo— se pasó de frenada y empezó a mandar a asesoría también a
     * quien ya tenía el archivo. Los dos casos van como ejemplo enfrentado,
     * que es lo que distingue uno de otro.
     */
    public function test_se_le_ensenan_los_dos_casos_enfrentados(): void
    {
        $this->contesta('proyecto');
        $this->guia()->recomendar('Tengo un diseño ya listo y quiero imprimirlo');

        Http::assertSent(function ($peticion) {
            return str_contains($peticion['system'], 'tengo un diseño ya listo y quiero imprimirlo» → proyecto')
                && str_contains($peticion['system'], 'impresión 3D para un proyecto», «corte láser para mi tesis» → asesoria');
        });
    }

    // ------------------------------------------------------------- la pantalla

    public function test_se_administra_desde_el_panel(): void
    {
        $this->admin();

        Livewire::test(PantallaDeLaGuia::class)
            ->set('datos.instrucciones', 'Las impresiones de clase son asesoría.')
            ->set('datos.advertencias.proyecto', 'Ten los archivos listos.')
            ->set('datos.advertencias.espacio', '')
            ->call('save');

        $this->assertSame('Las impresiones de clase son asesoría.', Settings::instruccionesDeLaGuia());
        $this->assertSame('Ten los archivos listos.', Settings::advertenciaDelCamino('proyecto'));
        $this->assertNull(Settings::advertenciaDelCamino('espacio'));
    }

    /** Y la pantalla llega con lo que hay puesto, incluidas las de fábrica. */
    public function test_la_pantalla_trae_las_de_fabrica(): void
    {
        $this->admin();

        Livewire::test(PantallaDeLaGuia::class)
            ->assertSet('datos.advertencias.proyecto', Settings::ADVERTENCIAS_POR_DEFECTO['proyecto']);
    }
}
