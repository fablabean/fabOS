<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * La portada: áreas con foto, equipos al azar y el mapa de módulos (§3).
 *
 * La vitrina salía siempre igual —los seis primeros equipos por orden
 * alfabético— y el encabezado de áreas decía «Siete» con nueve tarjetas
 * debajo, porque el número estaba escrito a mano.
 */
class PortadaConFotosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function area(string $nombre, ?string $foto = null): Area
    {
        return Area::create([
            'slug' => \Illuminate\Support\Str::slug($nombre) . '-' . uniqid(),
            'name' => $nombre,
            'photo_path' => $foto,
        ]);
    }

    private function equipo(Area $area, string $nombre, ?string $foto = 'fotos/x.jpg'): Asset
    {
        return Asset::create([
            'area_id' => $area->id, 'name' => $nombre, 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true, 'is_public' => true,
            'photo_path' => $foto,
            'min_minutes' => 30, 'autonomous_minutes' => 480, 'max_minutes' => 720,
        ]);
    }

    // ------------------------------------------------------------- las áreas

    public function test_la_tarjeta_del_area_lleva_su_foto(): void
    {
        $area = $this->area('Impresión 3D', 'areas/impresion3d.jpg');
        $this->equipo($area, 'Prusa MK4');

        $this->get(route('publico.home'))
            ->assertOk()
            ->assertSee('areas/impresion3d.jpg', false)
            ->assertSee('Impresión 3D');
    }

    /** Sin foto no se pinta un hueco: la tarjeta se lee igual. */
    public function test_un_area_sin_foto_no_rompe_la_portada(): void
    {
        $area = $this->area('Taller');
        $this->equipo($area, 'Banco de trabajo');

        $this->get(route('publico.home'))
            ->assertOk()
            ->assertSee('Taller');
    }

    /**
     * El número de áreas se cuenta, no se escribe.
     *
     * Decía «Siete áreas de trabajo» con nueve tarjetas debajo.
     */
    public function test_el_encabezado_cuenta_las_areas_que_hay(): void
    {
        foreach (['Uno', 'Dos', 'Tres'] as $nombre) {
            $this->equipo($this->area($nombre), 'Equipo de ' . $nombre);
        }

        $this->get(route('publico.home'))
            ->assertOk()
            ->assertSee('3', false)
            ->assertDontSee('Siete áreas de trabajo');
    }

    /** Un área sin equipos públicos no cuenta ni sale. */
    public function test_un_area_vacia_no_cuenta(): void
    {
        $conEquipos = $this->area('Con equipos');
        $this->equipo($conEquipos, 'Una máquina');
        $this->area('Vacía');

        $this->get(route('publico.home'))
            ->assertOk()
            ->assertSee('Con equipos')
            ->assertDontSee('Vacía');
    }

    // ---------------------------------------------------------- los equipos

    public function test_la_vitrina_trae_ocho_equipos_como_mucho(): void
    {
        $area = $this->area('Impresión 3D');

        foreach (range(1, 20) as $n) {
            $this->equipo($area, 'Máquina ' . $n);
        }

        $html = $this->get(route('publico.home'))->assertOk()->getContent();

        $this->assertSame(8, substr_count($html, 'class="equipo"'));
    }

    /** Y solo con foto: una tarjeta que dice «sin foto» no invita a nada. */
    public function test_la_vitrina_solo_trae_equipos_con_foto(): void
    {
        $area = $this->area('Impresión 3D');
        $this->equipo($area, 'Con su retrato');
        $this->equipo($area, 'Sin retrato ninguno', null);

        $this->get(route('publico.home'))
            ->assertOk()
            ->assertSee('Con su retrato')
            ->assertDontSee('Sin retrato ninguno');
    }

    /**
     * Distintos en cada visita.
     *
     * Con veinte equipos y ocho sitios, dos portadas seguidas que salgan
     * exactamente en el mismo orden serían casualidad; veinte intentos sin una
     * sola diferencia, no. Así la prueba no depende de la suerte de una tirada.
     */
    public function test_la_vitrina_cambia_entre_visitas(): void
    {
        $area = $this->area('Impresión 3D');

        foreach (range(1, 20) as $n) {
            $this->equipo($area, 'Máquina ' . $n);
        }

        $primera = $this->get(route('publico.home'))->getContent();

        for ($intento = 0; $intento < 20; $intento++) {
            if ($this->get(route('publico.home'))->getContent() !== $primera) {
                $this->addToAssertionCount(1);

                return;
            }
        }

        $this->fail('veinte portadas seguidas idénticas: la vitrina no se está barajando');
    }

    // ------------------------------------------------------------- el mapa

    /**
     * Cada módulo trae su dibujo, y el dibujo existe.
     *
     * Un nombre de icono mal escrito en la configuración no rompe nada: el
     * partial cae al `@default` y pinta un punto. Nueve puntos iguales serían
     * peor que ningún icono, y nadie se daría cuenta mirando la página.
     */
    public function test_cada_modulo_tiene_su_dibujo(): void
    {
        $partial = file_get_contents(resource_path('views/publico/icono-modulo.blade.php'));

        foreach (config('fabos.roadmap') as $modulo) {
            $this->assertArrayHasKey('icono', $modulo, $modulo['nombre'] . ' se quedó sin icono');

            $this->assertStringContainsString(
                "@case('" . $modulo['icono'] . "')",
                $partial,
                'el icono «' . $modulo['icono'] . '» de ' . $modulo['nombre'] . ' no está dibujado',
            );
        }
    }

    /** Y no se repiten: nueve tarjetas con el mismo dibujo no dicen nada. */
    public function test_los_dibujos_no_se_repiten(): void
    {
        $iconos = collect(config('fabos.roadmap'))->pluck('icono');

        $this->assertSame($iconos->count(), $iconos->unique()->count(), 'hay iconos repetidos');
    }

    public function test_los_dibujos_llegan_a_la_portada(): void
    {
        $area = $this->area('Impresión 3D');
        $this->equipo($area, 'Prusa MK4');

        $html = $this->get(route('publico.home'))->assertOk()->getContent();

        $this->assertSame(
            count(config('fabos.roadmap')),
            substr_count($html, 'class="icono"'),
            'cada módulo pinta el suyo',
        );
    }

    public function test_fabcoins_sale_como_funcionando(): void
    {
        $modulos = collect(config('fabos.roadmap'));
        $fabcoins = $modulos->firstWhere('nombre', 'FabCoins');

        $this->assertSame('listo', $fabcoins['estado']);

        $this->get(route('publico.home'))
            ->assertOk()
            ->assertSee($modulos->count() . '</strong> de ' . $modulos->count() . ' módulos', false);
    }
}
