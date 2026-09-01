<?php

namespace Tests\Feature;

use App\Filament\Resources\Banners\Pages\CreateBanner;
use App\Filament\Resources\Banners\Pages\ListBanners;
use App\Models\Banner;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El editor del banner de la portada (§3).
 *
 * Lo que se prueba aquí no es que el formulario dibuje campos, sino la promesa
 * entera: que anunciar algo en la portada deje de ser un despliegue.
 */
class EditorDelBannerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $cat = UserCategory::create([
            'slug' => 'c-' . uniqid(), 'name' => 'Estudiante', 'can_reserve' => true,
        ]);

        $u = User::create([
            'name' => 'Comunicaciones', 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);

        $u->assignRole(User::ROL_ADMINISTRADOR);

        return $u->fresh();
    }

    private function entra(User $u): self
    {
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([
            FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true],
        ]);

        return $this;
    }

    public function test_la_pantalla_del_editor_carga_con_las_laminas_de_serie(): void
    {
        $this->entra($this->admin())->get('/admin/banners')
            ->assertOk()
            ->assertSee('Nos vemos en *LIBERA*');
    }

    /**
     * El formulario se abre sobre una lámina de foto.
     *
     * Media docena de campos aparecen o desaparecen según el tipo de fondo. Un
     * error ahí no se ve en la lista: se ve al abrir a editar, que es cuando ya
     * hay alguien delante intentando cambiar algo.
     */
    public function test_el_formulario_abre_una_lamina_de_foto(): void
    {
        $lamina = Banner::where('fondo_tipo', 'imagen')->orderBy('position')->firstOrFail();

        $this->entra($this->admin())
            ->get('/admin/banners/' . $lamina->getKey() . '/edit')
            ->assertOk()
            ->assertSee('Cuánto se oscurece el fondo');
    }

    /** Escribir una lámina en el panel y verla en la portada, sin desplegar. */
    public function test_una_lamina_escrita_en_el_panel_sale_en_la_portada(): void
    {
        $this->entra($this->admin());

        Livewire::test(CreateBanner::class)
            ->fillForm([
                'rotulo'       => 'Charla abierta',
                'titulo'       => 'Ven a *cacharrear* con nosotros',
                'texto'        => 'Taller abierto el jueves a las cuatro.',
                'fondo_tipo'   => 'color',
                'fondo_color'  => '#0B3A34',
                'efecto'       => 'cortina',
                'alineacion'   => 'centro',
                'accion_texto' => 'Cómo llegar',
                'accion_url'   => 'https://libera.example.co',
                'is_active'    => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $lamina = Banner::where('rotulo', 'Charla abierta')->firstOrFail();

        // Va al final: quien la crea decide después dónde ponerla.
        $this->assertSame(Banner::max('position'), $lamina->position);

        $this->get(route('publico.home'))
            ->assertOk()
            ->assertSee('Taller abierto el jueves a las cuatro.')
            ->assertSee('Cómo llegar');
    }

    /** Se ordena arrastrando: el orden del banner se decide mirándolo. */
    public function test_las_laminas_se_reordenan_arrastrando(): void
    {
        $this->entra($this->admin());

        Banner::query()->delete();
        $primera = Banner::create(['titulo' => 'Primera', 'position' => 0]);
        $segunda = Banner::create(['titulo' => 'Segunda', 'position' => 1]);

        Livewire::test(ListBanners::class)
            ->call('reorderTable', [$segunda->getKey(), $primera->getKey()]);

        $this->assertSame(
            ['Segunda', 'Primera'],
            Banner::paraLaPortada()->pluck('titulo')->all(),
        );
    }

    /**
     * La fecha de fin es lo que hace que un anuncio no se quede colgado. Si
     * dependiera de que alguien se acuerde de apagarlo, no se apagaría.
     */
    public function test_lo_que_caduca_desaparece_solo(): void
    {
        Banner::query()->delete();

        Banner::create([
            'titulo'   => 'Nos vemos en *LIBERA*',
            'texto'    => 'Del 10 al 12 de septiembre.',
            'ends_at'  => Carbon::now()->addDay(),
            'position' => 0,
        ]);

        $this->get(route('publico.home'))->assertOk()->assertSee('Del 10 al 12 de septiembre.');

        // Pasada la feria, la portada deja de invitar a ella sola.
        $this->travel(2)->days();

        $this->get(route('publico.home'))->assertOk()->assertDontSee('Del 10 al 12 de septiembre.');
    }

    /** El editor es de Comunicaciones: no lo abre cualquiera con sesión. */
    public function test_quien_no_tiene_la_seccion_no_entra(): void
    {
        $cat = UserCategory::create([
            'slug' => 'c-' . uniqid(), 'name' => 'Estudiante', 'can_reserve' => true,
        ]);

        $suelto = User::create([
            'name' => 'Sin rol', 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);

        $this->entra($suelto)->get('/admin/banners')->assertForbidden();
    }
}
