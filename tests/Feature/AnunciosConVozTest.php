<?php

namespace Tests\Feature;

use App\Filament\Resources\Anuncios\AnuncioResource;
use App\Filament\Resources\Anuncios\Pages\CreateAnuncio;
use App\Filament\Resources\Anuncios\Pages\ListAnuncios;
use App\Jobs\GenerarAudioDelAnuncio;
use App\Livewire\AnunciosFavoritos;
use App\Models\Anuncio;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Los anuncios con voz (§3).
 *
 * Se escriben en el panel, el servidor de voz del FabLab los convierte en MP3
 * una sola vez, y se oyen en la portada.
 */
class AnunciosConVozTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config(['fabos.voz.url' => 'http://voz.prueba:8880']);
    }

    private function admin(): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $cat = UserCategory::create(['slug' => 'c-' . uniqid(), 'name' => 'Estudiante', 'can_reserve' => true]);

        $u = User::create([
            'name' => 'Comunicaciones', 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
        $u->assignRole(User::ROL_ADMINISTRADOR);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([
            FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true],
        ]);

        return $u->fresh();
    }

    private function servidorDeVoz(): void
    {
        Http::fake([
            'voz.prueba:8880/*' => Http::response('ID3-audio', 200, ['Content-Type' => 'audio/mpeg']),
        ]);
    }

    private function anuncio(array $datos = []): Anuncio
    {
        return Anuncio::create($datos + [
            'titulo' => 'Horario de diciembre',
            'texto' => 'Del 15 al 31 de diciembre abrimos de 8:00 a 12:00.',
            'voz' => 'mujer',
        ]);
    }

    public function test_al_crearlo_se_encola_su_audio_y_no_sale_todavia(): void
    {
        Queue::fake();

        $a = $this->anuncio();

        Queue::assertPushed(GenerarAudioDelAnuncio::class, fn ($job) => $job->anuncio->is($a));
        $this->assertSame('pendiente', $a->fresh()->audio_estado);
        $this->get('/')->assertOk()->assertDontSee('Horario de diciembre');
    }

    public function test_el_trabajo_pide_el_mp3_lo_guarda_y_la_portada_lo_reproduce(): void
    {
        $this->servidorDeVoz();

        $a = $this->anuncio(['voz' => 'hombre']);

        Http::assertSent(fn ($r) => $r->url() === 'http://voz.prueba:8880/v1/audio/speech'
            && $r['input'] === 'Del 15 al 31 de diciembre abrimos de 8:00 a 12:00.'
            && $r['voice'] === 'hombre'
            && $r['response_format'] === 'mp3');

        $a->refresh();
        $this->assertSame('listo', $a->audio_estado);
        Storage::disk('public')->assertExists($a->audio_path);

        $this->get('/')->assertOk()
            ->assertSee('Horario de diciembre')
            ->assertSee($a->audioUrl(), false);
    }

    /** El anuncio es fijo: tocar fechas u orden no vuelve a molestar al servidor. */
    public function test_solo_se_vuelve_a_pedir_cuando_cambia_lo_que_se_oye(): void
    {
        $this->servidorDeVoz();
        $a = $this->anuncio();
        $primero = $a->fresh()->audio_path;
        Http::assertSentCount(1);

        $a->update(['ends_at' => now()->addWeek(), 'position' => 3, 'titulo' => 'Diciembre']);
        Http::assertSentCount(1);

        $a->update(['texto' => 'Del 15 al 31 de diciembre abrimos de 9:00 a 12:00.']);
        Http::assertSentCount(2);

        $a->refresh();
        $this->assertNotSame($primero, $a->audio_path);
        Storage::disk('public')->assertMissing($primero);
        $this->assertTrue($a->tieneAudioAlDia());
    }

    /** Un audio que dice el texto de antes no se enseña con el texto nuevo. */
    public function test_con_el_texto_cambiado_y_sin_audio_nuevo_no_sale(): void
    {
        $this->servidorDeVoz();
        $a = $this->anuncio();

        Queue::fake();
        $a->update(['texto' => 'Cerramos el 24 de diciembre.']);

        $this->assertFalse($a->fresh()->tieneAudioAlDia());
        $this->get('/')->assertOk()->assertDontSee('Horario de diciembre');
    }

    public function test_si_el_servidor_falla_el_anuncio_lo_dice(): void
    {
        Queue::fake();
        Http::fake(['voz.prueba:8880/*' => Http::response('ocupado', 503)]);
        $a = $this->anuncio();

        $job = new GenerarAudioDelAnuncio($a);

        try {
            $job->handle(app(\App\Services\Ia\TextoAVoz::class));
            $this->fail('Debió lanzar');
        } catch (RuntimeException $e) {
            $job->failed($e);
        }

        $a->refresh();
        $this->assertSame('fallo', $a->audio_estado);
        $this->assertStringContainsString('503', $a->audio_error);

        $a->pedirAudio();
        $this->assertSame('pendiente', $a->fresh()->audio_estado);
        Queue::assertPushed(GenerarAudioDelAnuncio::class, 2);
    }

    /** Una página de error con 200 —un portal cautivo— no se guarda como MP3. */
    public function test_una_respuesta_que_no_es_audio_no_se_guarda(): void
    {
        Queue::fake();
        Http::fake(['voz.prueba:8880/*' => Http::response('<html>Inicie sesión</html>', 200, ['Content-Type' => 'text/html'])]);
        $a = $this->anuncio();

        $this->expectException(RuntimeException::class);
        (new GenerarAudioDelAnuncio($a))->handle(app(\App\Services\Ia\TextoAVoz::class));
    }

    public function test_se_crea_desde_el_panel(): void
    {
        Queue::fake();
        $this->admin();

        Livewire::test(CreateAnuncio::class)
            ->fillForm([
                'titulo' => 'Feria de proyectos',
                'texto' => 'El jueves a las 10:00 es la feria de proyectos.',
                'voz' => 'mujer',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('anuncios', ['titulo' => 'Feria de proyectos', 'audio_estado' => 'pendiente']);
        Queue::assertPushed(GenerarAudioDelAnuncio::class);
    }

    /** Cada fila con audio lleva su botón de escuchar, que suena en el navegador. */
    public function test_la_lista_tiene_boton_de_escuchar(): void
    {
        $this->servidorDeVoz();
        $this->admin();
        $a = $this->anuncio();

        Livewire::test(ListAnuncios::class)
            ->assertTableActionVisible('escuchar', $a)
            ->assertSee('data-anuncio-audio="' . $a->fresh()->audioUrl() . '"', false);
    }

    /** Un favorito sale en la barra de arriba aunque no salga en la portada. */
    public function test_los_favoritos_salen_en_la_barra_de_arriba(): void
    {
        $this->servidorDeVoz();
        $this->admin();
        $a = $this->anuncio(['titulo' => 'Cierre pronto', 'is_active' => false]);

        $this->get(AnuncioResource::getUrl())->assertOk()->assertDontSee('Lanzar: Cierre pronto');

        Livewire::test(ListAnuncios::class)
            ->callTableAction('favorito', $a)
            ->assertDispatched('anuncios-favoritos');

        $this->assertTrue($a->fresh()->favorito);
        $this->get(AnuncioResource::getUrl())->assertOk()
            ->assertSee('Lanzar: Cierre pronto')
            ->assertSee('window.fabosAnuncios', false);
        $this->get('/')->assertOk()->assertDontSee('Cierre pronto');
    }

    /** Un favorito sin audio todavía no ocupa sitio: un botón que no suena no sirve. */
    public function test_un_favorito_sin_audio_no_sale_en_la_barra(): void
    {
        Queue::fake();
        $this->admin();
        $this->anuncio(['titulo' => 'Cierre pronto', 'favorito' => true]);

        Livewire::test(AnunciosFavoritos::class)->assertDontSee('Cierre pronto');
    }

    public function test_el_texto_no_pasa_de_cinco_mil_caracteres(): void
    {
        $this->admin();

        Livewire::test(CreateAnuncio::class)
            ->fillForm(['titulo' => 'Largo', 'texto' => str_repeat('a', 5001), 'voz' => 'mujer'])
            ->call('create')
            ->assertHasFormErrors(['texto' => 'max']);
    }
}
