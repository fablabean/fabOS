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

    public function test_la_tarjeta_enlaza_al_catalogo_filtrado_por_esa_area(): void
    {
        $area = $this->area('Impresión 3D');
        $this->equipo($area, 'Prusa MK4');

        $enlace = app(AreasDelCatalogo::class)->getAreas()[0]['enlace'];

        $this->assertStringContainsString('area', $enlace);
        $this->assertStringContainsString((string) $area->id, $enlace);
    }

    /** Y hay salida: quitar un filtro que uno no puso a mano no es evidente. */
    public function test_hay_una_manera_de_volver_al_catalogo_entero(): void
    {
        $this->equipo($this->area('Impresión 3D'), 'Prusa MK4');

        $this->assertNotEmpty(app(AreasDelCatalogo::class)->getEnlaceATodos());
    }

    // ------------------------------------------------------------- la tabla

    public function test_la_tabla_abre_agrupada_por_area_y_plegada(): void
    {
        $this->equipo($this->area('Impresión 3D'), 'Prusa MK4');
        $this->entraComoAdmin();

        $tabla = Livewire::test(ListAssets::class)->instance()->getTable();

        $this->assertSame('area.name', $tabla->getDefaultGroup()?->getId());
        $this->assertTrue($tabla->areGroupsCollapsedByDefault());
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
