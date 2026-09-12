<?php

namespace Tests\Feature;

use App\Filament\Resources\Assets\Pages\ListAssets;
use App\Filament\Resources\Assets\Widgets\AreasDelCatalogo;
use App\Models\Area;
use App\Models\Asset;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Entrar al catálogo por área, con foto (§7).
 *
 * Ochenta y dos fichas de veinticinco en veinticinco: llegar a las de fresado
 * era pasar páginas o acordarse de un filtro escondido en un desplegable.
 *
 * Agrupar la tabla no bastaba: la rejilla agrupa lo que hay en la PÁGINA, así
 * que plegada solo salen las áreas de esas veinticinco fichas. Las tarjetas
 * están siempre completas porque no dependen de la paginación — y eso es
 * justo lo que esta prueba fija.
 */
class AreasDelCatalogoTest extends TestCase
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

    private function equipo(Area $area, string $nombre): Asset
    {
        return Asset::create([
            'area_id' => $area->id, 'name' => $nombre, 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true, 'booking_mode' => 'directa',
            'min_minutes' => 30, 'autonomous_minutes' => 480, 'max_minutes' => 720,
        ]);
    }

    private function entraComoAdmin(): void
    {
        $admin = User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $admin->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $factores = app(TwoFactorService::class);
        $secreto = $factores->generarSecreto($admin);
        $factores->confirmar($admin, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($admin->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);
    }

    /** @return array<string,int> nombre => cuantos */
    private function tarjetas(): array
    {
        return collect(app(AreasDelCatalogo::class)->getAreas())
            ->mapWithKeys(fn (array $a) => [$a['nombre'] => $a['cuantos']])
            ->all();
    }

    public function test_cada_area_con_equipos_tiene_su_tarjeta_y_su_cuenta(): void
    {
        $impresion = $this->area('Impresión 3D');
        $this->equipo($impresion, 'Prusa MK4');
        $this->equipo($impresion, 'Bambu A1');

        $laser = $this->area('Corte Láser');
        $this->equipo($laser, 'xTool F1');

        // Por «position» y, a igualdad, alfabético: así se ordenan las áreas
        // en todo el sistema, y la tarjeta no inventa un orden propio.
        $this->assertSame(['Corte Láser' => 1, 'Impresión 3D' => 2], $this->tarjetas());
    }

    /** Un área sin equipos no se ofrece: pulsarla enseñaría una tabla vacía. */
    public function test_un_area_vacia_no_sale(): void
    {
        $this->equipo($this->area('Con equipos'), 'Una máquina');
        $this->area('Vacía');

        $this->assertArrayNotHasKey('Vacía', $this->tarjetas());
    }

    public function test_la_tarjeta_lleva_su_foto(): void
    {
        $area = $this->area('Impresión 3D', 'areas/impresion.jpg');
        $this->equipo($area, 'Prusa MK4');

        $this->assertStringContainsString(
            'areas/impresion.jpg',
            app(AreasDelCatalogo::class)->getAreas()[0]['foto'],
        );
    }

    /**
     * El índice está completo aunque la tabla solo enseñe la primera página.
     *
     * Es la razón de que estas tarjetas existan y no baste con agrupar.
     */
    public function test_salen_todas_las_areas_aunque_la_tabla_pagine(): void
    {
        foreach (range(1, 8) as $n) {
            $area = $this->area('Área ' . $n);

            // Bastantes fichas para llenar varias páginas de la tabla.
            foreach (range(1, 6) as $m) {
                $this->equipo($area, 'Equipo ' . $n . '-' . $m);
            }
        }

        $this->assertCount(8, $this->tarjetas(), 'las ocho áreas, no solo las de la primera página');
    }

    /**
     * La tarjeta FILTRA de verdad la tabla de abajo.
     *
     * Se ABRE LA URL, como haría el navegador, en vez de asignarle las
     * propiedades al componente a mano. Es la diferencia que dejó pasar el
     * fallo: `Livewire::test($componente, $params)` rellena propiedades
     * públicas por su nombre, y el navegador no hace eso — solo lee de la URL
     * las que están publicadas con `#[Url]`, y `tableFilters` sale publicada
     * con el nombre `filters`. La prueba vieja pasaba con el enlace roto.
     */
    public function test_la_tarjeta_filtra_de_verdad_la_tabla(): void
    {
        $impresion = $this->area('Impresión 3D');
        $this->equipo($impresion, 'Prusa MK4');

        $laser = $this->area('Corte Láser');
        $this->equipo($laser, 'xTool F1');

        $this->entraComoAdmin();

        $enlace = collect(app(AreasDelCatalogo::class)->getAreas())
            ->firstWhere('nombre', 'Impresión 3D')['enlace'];

        $this->get($enlace)
            ->assertOk()
            ->assertSee('Prusa MK4')
            ->assertDontSee('xTool F1');
    }

    /** Y el enlace de salida los devuelve a todos. */
    public function test_ver_todo_el_catalogo_quita_el_filtro(): void
    {
        $this->equipo($this->area('Impresión 3D'), 'Prusa MK4');
        $this->equipo($this->area('Corte Láser'), 'xTool F1');

        $this->entraComoAdmin();

        $this->get(app(AreasDelCatalogo::class)->getEnlaceATodos())
            ->assertOk()
            ->assertSee('Prusa MK4')
            ->assertSee('xTool F1');
    }

    // ------------------------------------------------------------- la tabla

    public function test_la_tabla_abre_agrupada_por_area_y_plegada(): void
    {
        $this->equipo($this->area('Impresión 3D'), 'Prusa MK4');
        $this->entraComoAdmin();

        $tabla = Livewire::test(ListAssets::class)->instance()->getTable();

        $this->assertSame('area.name', $tabla->getDefaultGroup()?->getId());
        $this->assertTrue($tabla->areGroupsCollapsedByDefault());

        // Y con todo cargado: agrupar lo que hay en una pagina de veinticinco
        // ensenaba tres areas y escondia las demas detras del paginador.
        $this->assertSame('all', $tabla->getDefaultPaginationPageOption());
    }

    /**
     * Pero filtrada a un área sola, ese grupo abre de una vez.
     *
     * Quien pulsa la tarjeta ya dijo lo que quiere: dejarle un único grupo
     * cerrado le cobra un clic por algo que acaba de pedir.
     *
     * Se abre la URL, no se monta el componente a mano: la condición lee la
     * petición, y montar el componente con parámetros no la reproduce.
     */
    public function test_filtrada_a_un_area_el_grupo_abre_solo(): void
    {
        $area = $this->area('Impresión 3D');
        $this->equipo($area, 'Prusa MK4');
        $this->equipo($this->area('Corte Láser'), 'xTool F1');

        $this->entraComoAdmin();

        $enlace = collect(app(AreasDelCatalogo::class)->getAreas())
            ->firstWhere('nombre', 'Impresión 3D')['enlace'];

        // Es lo que la tabla le pasa a Alpine para decidir si arranca plegada.
        $this->get($enlace)
            ->assertOk()
            ->assertSee('areGroupsCollapsedByDefault: false', false);

        $this->get(app(AreasDelCatalogo::class)->getEnlaceATodos())
            ->assertOk()
            ->assertSee('areGroupsCollapsedByDefault: true', false);
    }

    public function test_la_pantalla_pinta_las_tarjetas(): void
    {
        $this->equipo($this->area('Impresión 3D'), 'Prusa MK4');
        $this->entraComoAdmin();

        Livewire::test(ListAssets::class)
            ->assertSee('Por área')
            ->assertSee('Impresión 3D');
    }
}
