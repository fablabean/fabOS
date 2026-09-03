<?php

namespace Tests\Feature;

use App\Filament\Resources\Banners\Pages\CreateBanner;
use App\Models\Banner;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El velo y el filtro del fondo de la lámina (§3).
 *
 * Una foto de colores vivos compite con el titular, y hay fotos que ni con el
 * velo al tope dejaban leer. El velo llega ahora a 100 —por encima de 70 se
 * vuelve una capa entera— y la foto admite un filtro sin retocarla.
 */
class FondoDelBannerTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create([
            'name' => 'Comunicaciones', 'email' => uniqid() . '@test.co', 'status' => 'activo',
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

    private function lamina(array $datos): Banner
    {
        Banner::query()->update(['is_active' => false]);

        return Banner::create(array_merge([
            'titulo' => 'Con foto', 'fondo_tipo' => 'imagen', 'fondo_path' => 'img/hero/fabricacion.svg',
            'efecto' => 'ninguno', 'alineacion' => 'izquierda', 'is_active' => true, 'position' => 0,
        ], $datos));
    }

    public function test_el_filtro_sale_como_clase_de_la_lamina(): void
    {
        $this->lamina(['filtro' => 'gris', 'velo' => 100]);

        $this->get(route('publico.home'))
            ->assertOk()
            ->assertSee('filtro-gris')
            // Al tope: la capa entera se suma al degradado.
            ->assertSee('--velo:1;', false);
    }

    public function test_sin_filtro_no_hay_clase_y_un_color_plano_no_lo_lleva_nunca(): void
    {
        // Se mira la clase de la lámina y no la página entera: la hoja de
        // estilos siempre lleva las reglas «.filtro-…».
        $sinFiltro = '/class="lamina[^"]*filtro-/';

        $this->lamina(['filtro' => 'ninguno', 'velo' => 40]);
        $html = $this->get(route('publico.home'))->assertOk()->assertSee('--velo:0.4;', false)->getContent();
        $this->assertDoesNotMatchRegularExpression($sinFiltro, $html);

        // Un color plano no se filtra: el filtro es de la foto, no del color.
        $this->lamina(['fondo_tipo' => 'color', 'fondo_color' => '#0B3A34', 'fondo_path' => null, 'filtro' => 'sepia']);
        $html = $this->get(route('publico.home'))->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression($sinFiltro, $html);
    }

    public function test_el_editor_acepta_el_velo_al_tope_y_el_filtro(): void
    {
        $this->entra($this->admin());

        Livewire::test(CreateBanner::class)
            ->fillForm([
                'titulo' => 'Muy oscura', 'fondo_tipo' => 'imagen', 'efecto' => 'ninguno',
                'alineacion' => 'izquierda', 'velo' => 100, 'filtro' => 'desenfoque',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $l = Banner::where('titulo', 'Muy oscura')->firstOrFail();

        $this->assertSame(100, $l->velo);
        $this->assertSame('desenfoque', $l->filtro);
    }
}
