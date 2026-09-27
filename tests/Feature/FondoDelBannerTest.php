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

    /**
     * Un MP4 se sube aunque la lámina se abriera como foto.
     *
     * El selector leía los formatos una sola vez al abrir la página, y al
     * cambiar a «Video» seguía escondiendo los MP4 (un video de WhatsApp de
     * 3 MB no aparecía). Ahora acepta ambos y el servidor comprueba el tipo.
     */
    /**
     * La imagen de carga sale sola del video, si no se subió ninguna; y si el
     * video cambia, se rehace. Con un video de verdad, hecho con ffmpeg.
     */
    public function test_la_imagen_de_carga_sale_sola_del_video(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $disco = \Illuminate\Support\Facades\Storage::disk('public');
        $disco->makeDirectory('banners');

        $hacer = function (string $ruta, string $color) use ($disco) {
            (new \Symfony\Component\Process\Process([
                'ffmpeg', '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', "color=c={$color}:s=320x180:d=2",
                '-pix_fmt', 'yuv420p', $disco->path($ruta),
            ]))->mustRun();
        };

        $hacer('banners/uno.mp4', 'red');

        $l = Banner::create([
            'titulo' => 'Con video', 'fondo_tipo' => 'video', 'fondo_path' => 'banners/uno.mp4',
            'efecto' => 'ninguno', 'alineacion' => 'izquierda', 'velo' => 40,
        ]);

        $primero = $l->poster_path;
        $this->assertTrue(\App\Services\Media\FotogramaDeVideo::esGenerado($primero));
        $this->assertTrue($disco->exists($primero));

        // Otro video: el fotograma generado se rehace.
        $hacer('banners/dos.mp4', 'blue');
        $l->update(['fondo_path' => 'banners/dos.mp4']);
        $this->assertNotSame($primero, $l->fresh()->poster_path);

        // Una imagen subida a mano no se toca.
        $l->update(['poster_path' => 'banners/mia.jpg', 'fondo_path' => 'banners/uno.mp4']);
        $this->assertSame('banners/mia.jpg', $l->fresh()->poster_path);
    }

    public function test_el_fondo_acepta_un_mp4_y_exige_que_coincida_con_el_tipo(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->entra($this->admin());

        Livewire::test(CreateBanner::class)
            ->fillForm([
                'titulo' => 'Con video', 'fondo_tipo' => 'video', 'efecto' => 'ninguno', 'alineacion' => 'izquierda',
                'fondo_path' => \Illuminate\Http\UploadedFile::fake()->create('whatsapp.mp4', 3068, 'video/mp4'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertStringEndsWith('.mp4', (string) Banner::where('titulo', 'Con video')->value('fondo_path'));

        Livewire::test(CreateBanner::class)
            ->fillForm([
                'titulo' => 'Video que es foto', 'fondo_tipo' => 'video', 'efecto' => 'ninguno', 'alineacion' => 'izquierda',
                'fondo_path' => \Illuminate\Http\UploadedFile::fake()->image('foto.jpg'),
            ])
            ->call('create')
            ->assertHasFormErrors(['fondo_path']);
    }
}
