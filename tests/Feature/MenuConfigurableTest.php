<?php

namespace Tests\Feature;

use App\Filament\Pages\Menu;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use App\Support\MenuDelPanel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Configuración → Menú: orden, colores e íconos del menú del panel (§19). */
class MenuConfigurableTest extends TestCase
{
    use RefreshDatabase;

    private function entraComoSuperadmin(): User
    {
        $u = User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_SUPERADMIN, 'web'));

        $f = app(TwoFactorService::class);
        $secreto = $f->generarSecreto($u);
        $f->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u;
    }

    private function menu(): string
    {
        $html = $this->get('/admin/projects')->assertOk()->getContent();

        return substr($html, strpos($html, 'fi-sidebar-nav') ?: 0);
    }

    private function enOrden(string $html, array $textos): void
    {
        $desde = 0;
        foreach ($textos as $texto) {
            $donde = strpos($html, $texto, $desde);
            $this->assertNotFalse($donde, "«{$texto}» no está, o no va en ese orden");
            $desde = $donde + strlen($texto);
        }
    }

    /**
     * Configuración → Menú reconoce cada opción por su nombre: dos opciones
     * con el mismo nombre comparten el ajuste guardado, y una acaba en el
     * grupo de la otra. Pasó con «Buscadores y analítica», que era a la vez
     * la configuración y su guía.
     */
    public function test_no_hay_dos_opciones_del_menu_con_el_mismo_nombre(): void
    {
        $this->entraComoSuperadmin();

        $panel = \Filament\Facades\Filament::getPanel('admin');
        $nombres = collect(array_merge($panel->getPages(), $panel->getResources()))
            ->filter(fn (string $clase) => method_exists($clase, 'shouldRegisterNavigation') && $clase::shouldRegisterNavigation())
            ->map(fn (string $clase) => (string) $clase::getNavigationLabel());

        $repetidos = $nombres->countBy()->filter(fn (int $n) => $n > 1)->keys()->all();

        $this->assertSame([], $repetidos, 'Opciones del menú con el mismo nombre: ' . implode(', ', $repetidos));
    }

    public function test_la_pantalla_abre_con_el_menu_como_se_ve(): void
    {
        $this->entraComoSuperadmin();

        $grupos = array_values(Livewire::test(Menu::class)->assertOk()->get('datos.grupos') ?? []);

        // El repetidor guarda cada fila con una clave suya, no 0, 1, 2.
        $this->assertNotEmpty($grupos, 'la pantalla no trajo los grupos del menú');
        $this->assertSame('Proyectos', $grupos[0]['nombre']);
        $this->assertNotEmpty($grupos[0]['opciones']);
    }

    /** Guardar desde la pantalla, sin tocar nada, deja el menú igual y escrito. */
    public function test_guardar_desde_la_pantalla(): void
    {
        $this->entraComoSuperadmin();

        Livewire::test(Menu::class)->call('save')->assertHasNoErrors();

        $this->assertSame('Proyectos', MenuDelPanel::ordenDeGrupos()[0]);
        $this->assertArrayHasKey('Proyectos', MenuDelPanel::opciones());
        $this->enOrden($this->menu(), ['Proyectos', 'Operación', 'Documentación', 'Configuración']);
    }

    /** Lo guardado cambia el menú de todos: orden, color, ícono y grupo. */
    public function test_guardar_reordena_colorea_y_cambia_iconos(): void
    {
        $this->entraComoSuperadmin();

        MenuDelPanel::guardar([
            ['nombre' => 'Configuración', 'color' => 'rojo', 'opciones' => []],
            ['nombre' => 'Proyectos', 'color' => null, 'opciones' => [
                ['nombre' => 'Proyectos', 'icono' => 'heroicon-o-star', 'grupo' => 'Proyectos'],
            ]],
            ['nombre' => 'Operación', 'color' => null, 'opciones' => [
                // Reservas pasa a Proyectos.
                ['nombre' => 'Reservas', 'icono' => null, 'grupo' => 'Proyectos'],
            ]],
        ]);

        $menu = $this->menu();

        $this->enOrden($menu, ['Configuración', 'Proyectos', 'Reservas', 'Operación']);
        $this->assertStringContainsString('data-group-label="Configuración"', $menu);

        $html = $this->get('/admin/projects')->getContent();
        $this->assertStringContainsString('.fi-sidebar-group[data-group-label="Configuración"]', $html);
        $this->assertStringContainsString('#dc2626', $html);
    }

    public function test_volver_al_de_fabrica_lo_deja_como_estaba(): void
    {
        $this->entraComoSuperadmin();

        MenuDelPanel::guardar([['nombre' => 'Configuración', 'color' => 'rojo', 'opciones' => []]]);
        MenuDelPanel::restaurar();

        $this->enOrden($this->menu(), ['Proyectos', 'Operación', 'Documentación', 'Configuración']);
        $this->assertStringNotContainsString('#dc2626', $this->get('/admin/projects')->getContent());
    }

    public function test_el_menu_trae_plegar_y_desplegar_todo(): void
    {
        $this->entraComoSuperadmin();

        $this->get('/admin/projects')
            ->assertSee('Plegar todo')
            ->assertSee('Desplegar todo');
    }
}
