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

    /**
     * Lanzado desde la barra, un favorito suena las veces que diga, con su
     * pausa; y si lleva el timbre de emergencia, va como alerta.
     */
    public function test_el_favorito_suena_con_su_frecuencia_y_la_alerta_al_maximo(): void
    {
        $this->servidorDeVoz();
        $a = $this->anuncio(['titulo' => 'Evacuación', 'sonido_previo' => 'emergencia', 'favorito' => true,
            'repeticiones' => 3, 'pausa' => 15]);
        $a->programaciones()->create(['dias' => [1], 'hora' => '09:00', 'repeticiones' => 1, 'pausa' => 0]);
        $a = $a->fresh();

        $this->assertTrue($a->esAlerta());
        $this->assertSame(['repeticiones' => 3, 'pausa' => 15], $a->frecuencia());

        // Lo programado sigue con la frecuencia de su horario, y va como alerta.
        $desde = \Carbon\Carbon::parse('2026-10-12 00:00', config('fabos.lab.timezone'));
        $evento = Anuncio::agenda($desde, $desde->copy()->endOfDay())[0];
        $this->assertTrue($evento['alerta']);
        $this->assertSame(1, $evento['repeticiones']);

        $this->admin();
        Livewire::test(AnunciosFavoritos::class)
            ->assertSeeHtml((string) \Illuminate\Support\Js::from(['repeticiones' => 3, 'pausa' => 15, 'alerta' => true]));

        // Por defecto suena una vez, y sin timbre de emergencia no es alerta.
        $b = $this->anuncio(['titulo' => 'Cierre', 'sonido_previo' => 'timbre'])->fresh();
        $this->assertSame(1, $b->frecuencia()['repeticiones']);
        $this->assertFalse($b->esAlerta());
    }

    /**
     * La agenda sale con el instante exacto, a la hora del laboratorio, solo
     * los días marcados.
     */
    public function test_la_agenda_suena_los_dias_marcados_a_la_hora_del_laboratorio(): void
    {
        $this->servidorDeVoz();
        $tz = config('fabos.lab.timezone');
        $a = $this->anuncio(['titulo' => 'Cierre pronto', 'is_active' => false]);
        $a->programaciones()->create(['dias' => [1, 2, 3, 4, 5], 'hora' => '17:50', 'repeticiones' => 2, 'pausa' => 20]);

        // Del lunes 12 de octubre de 2026 al domingo 18, enteros.
        $desde = \Carbon\Carbon::parse('2026-10-12 00:00', $tz);
        $eventos = Anuncio::agenda($desde, $desde->copy()->endOfWeek());

        $this->assertCount(5, $eventos);
        $this->assertSame(
            \Carbon\Carbon::parse('2026-10-12 17:50', $tz)->getTimestampMs(),
            $eventos[0]['t'],
        );
        $this->assertSame('17:50', $eventos[0]['hora']);
        $this->assertSame(2, $eventos[0]['repeticiones']);
        $this->assertSame(20, $eventos[0]['pausa']);
        $this->assertSame($a->fresh()->audioUrl(), $eventos[0]['url']);
        $this->assertCount(5, array_unique(array_column($eventos, 'clave')));
    }

    /** La vigencia manda: el anuncio de la feria deja de sonar cuando la feria pasa. */
    public function test_la_agenda_respeta_la_vigencia_y_pide_audio(): void
    {
        $this->servidorDeVoz();
        $tz = config('fabos.lab.timezone');
        $a = $this->anuncio(['ends_at' => \Carbon\Carbon::parse('2026-10-14 12:00', $tz)]);
        $a->programaciones()->create(['dias' => [1, 2, 3, 4, 5, 6, 7], 'hora' => '09:00']);

        $desde = \Carbon\Carbon::parse('2026-10-12 00:00', $tz);
        $this->assertCount(3, Anuncio::agenda($desde, $desde->copy()->endOfWeek()));

        // Con el texto cambiado y el audio sin generar, no hay qué reproducir.
        Queue::fake();
        $a->update(['texto' => 'Otro texto.']);
        $this->assertSame([], Anuncio::agenda($desde, $desde->copy()->endOfWeek()));
    }

    public function test_los_horarios_se_guardan_desde_el_formulario(): void
    {
        Queue::fake();
        $this->admin();

        Livewire::test(CreateAnuncio::class)
            ->fillForm([
                'titulo' => 'Cierre pronto',
                'texto' => 'El FabLab cierra en diez minutos.',
                'voz' => 'hombre',
                'programaciones' => [
                    ['dias' => ['1', '2', '3', '4', '5'], 'hora' => '17:50', 'repeticiones' => 2, 'pausa' => 30],
                    ['dias' => ['6'], 'hora' => '12:50', 'repeticiones' => 1],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $a = Anuncio::where('titulo', 'Cierre pronto')->firstOrFail();
        $this->assertSame(
            // Por hora, que es como se leen.
            ['Sáb · 12:50', 'Lun a Vie · 17:50 · 2 veces'],
            $a->programaciones->map->resumen()->all(),
        );
    }

    public function test_la_pantalla_del_parlante_carga_con_lo_que_viene(): void
    {
        $this->servidorDeVoz();
        $this->admin();
        $a = $this->anuncio(['titulo' => 'Cierre pronto']);
        $a->programaciones()->create(['dias' => [1, 2, 3, 4, 5, 6, 7], 'hora' => now(config('fabos.lab.timezone'))->addHour()->format('H:i')]);

        $this->get(\App\Filament\Pages\Parlante::getUrl())->assertOk()
            ->assertSee('Encender el parlante')
            ->assertSee('Cierre pronto');
    }

    public function test_el_texto_no_pasa_de_cinco_mil_caracteres(): void
    {
        $this->admin();

        Livewire::test(CreateAnuncio::class)
            ->fillForm(['titulo' => 'Largo', 'texto' => str_repeat('a', 5001), 'voz' => 'mujer'])
            ->call('create')
            ->assertHasFormErrors(['texto' => 'max']);
    }

    // ------------------------------------------------------ el sonido de antes

    public function test_el_sonido_de_antes_se_elige_y_no_vuelve_a_pedir_la_voz(): void
    {
        $this->servidorDeVoz();
        $a = $this->anuncio();

        // Por defecto, el de aeropuerto; y el archivo existe.
        $this->assertSame('aeropuerto', $a->fresh()->sonido_previo);
        $this->assertStringEndsWith('/sonidos/anuncios/aeropuerto.mp3', $a->fresh()->sonidoPrevioUrl());

        foreach (array_keys(Anuncio::SONIDOS) as $sonido) {
            if ($sonido !== 'ninguno') {
                $this->assertFileExists(public_path('sonidos/anuncios/' . $sonido . '.mp3'));
            }
        }

        // Cambiarlo no toca la voz: no se le vuelve a pedir al servidor.
        Http::assertSentCount(1);
        $a->update(['sonido_previo' => 'noticia']);
        Http::assertSentCount(1);
        $this->assertStringEndsWith('/sonidos/anuncios/noticia.mp3', $a->fresh()->sonidoPrevioUrl());

        $a->update(['sonido_previo' => 'ninguno']);
        $this->assertNull($a->fresh()->sonidoPrevioUrl());
    }

    public function test_el_parlante_y_los_botones_tocan_el_sonido_antes_de_la_voz(): void
    {
        $this->servidorDeVoz();
        $tz = config('fabos.lab.timezone');
        $a = $this->anuncio(['titulo' => 'Cierre pronto', 'sonido_previo' => 'timbre', 'favorito' => true]);
        $a->programaciones()->create(['dias' => [1], 'hora' => '17:50', 'repeticiones' => 1, 'pausa' => 0]);

        $desde = \Carbon\Carbon::parse('2026-10-12 00:00', $tz);
        $eventos = Anuncio::agenda($desde, $desde->copy()->endOfDay());

        $this->assertSame($a->fresh()->sonidoPrevioUrl(), $eventos[0]['previo']);

        $this->admin();

        // La lista y la barra de arriba le pasan el tono al reproductor.
        Livewire::test(AnunciosFavoritos::class)
            ->assertSeeHtml('anuncios\/timbre.mp3');

        // Y el formulario deja escucharlos antes de elegir.
        Livewire::test(CreateAnuncio::class)
            ->assertSee('Sonido antes del anuncio')
            ->assertSeeHtml('anuncios\/aeropuerto.mp3')
            ->assertSeeHtml('anuncios\/noticia.mp3')
            ->assertSeeHtml('anuncios\/emergencia.mp3');
    }

    // ------------------------------------------------------ la música de fondo

    public function test_lee_los_enlaces_de_youtube_y_de_youtube_music(): void
    {
        $leer = fn (string $url) => \App\Support\MusicaDelParlante::leer($url);

        $this->assertSame(['lista' => 'PLx0sYbCqOb8TBPRdmBHs5Iftvv9TPboYG', 'video' => null],
            $leer('https://www.youtube.com/playlist?list=PLx0sYbCqOb8TBPRdmBHs5Iftvv9TPboYG'));
        $this->assertSame(['lista' => 'OLAK5uy_kXv0ZqY8a3', 'video' => null],
            $leer('https://music.youtube.com/playlist?list=OLAK5uy_kXv0ZqY8a3&si=abc'));
        $this->assertSame(['lista' => 'PLabcdef12', 'video' => 'dQw4w9WgXcQ'],
            $leer('https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PLabcdef12&index=3'));
        $this->assertSame(['lista' => null, 'video' => 'dQw4w9WgXcQ'], $leer('https://youtu.be/dQw4w9WgXcQ?si=x'));

        // Lo que no es de YouTube, o no trae ni lista ni video, no vale.
        $this->assertNull($leer('https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M'));
        $this->assertNull($leer('https://www.youtube.com/@fablab'));
        $this->assertNull($leer('https://youtube.com.evil.co/playlist?list=PLabcdef12'));
    }

    /** El deslizador del Parlante guarda el volumen de la música, y nada más. */
    public function test_el_volumen_de_la_musica_se_cambia_desde_la_pantalla(): void
    {
        $this->admin();
        \App\Support\MusicaDelParlante::guardar('https://www.youtube.com/playlist?list=PLmanana01', 40);

        Livewire::test(\App\Filament\Pages\Parlante::class)
            ->assertSee('Volumen de la música')
            ->call('guardarVolumen', 65);
        $this->assertSame(65, \App\Support\MusicaDelParlante::volumen());

        Livewire::test(\App\Filament\Pages\Parlante::class)->call('guardarVolumen', 300);
        $this->assertSame(100, \App\Support\MusicaDelParlante::volumen());
        $this->assertSame('https://www.youtube.com/playlist?list=PLmanana01', \App\Support\MusicaDelParlante::url());
    }

    public function test_la_musica_se_configura_en_el_parlante_y_suena_ahi(): void
    {
        $this->admin();

        // Sin música puesta, la pantalla no la muestra.
        Livewire::test(\App\Filament\Pages\Parlante::class)->assertDontSee('Música de fondo');

        // Un enlace que no es de YouTube no se guarda.
        Livewire::test(\App\Filament\Pages\Parlante::class)
            ->callAction('musica', ['url' => 'https://open.spotify.com/playlist/37i9dQ', 'volumen' => 40])
            ->assertHasActionErrors(['url']);

        Livewire::test(\App\Filament\Pages\Parlante::class)
            ->callAction('musica', ['url' => 'https://music.youtube.com/playlist?list=OLAK5uy_kXv0ZqY8a3', 'volumen' => 35])
            ->assertHasNoActionErrors();

        $this->assertSame(35, \App\Support\MusicaDelParlante::volumen());
        $this->assertTrue(\App\Support\MusicaDelParlante::aleatoria(), 'en desorden, salvo que se diga otra cosa');

        Livewire::test(\App\Filament\Pages\Parlante::class)
            ->callAction('musica', ['url' => 'https://music.youtube.com/playlist?list=OLAK5uy_kXv0ZqY8a3', 'volumen' => 35, 'aleatoria' => false])
            ->assertHasNoActionErrors();

        $this->assertFalse(\App\Support\MusicaDelParlante::paraElReproductor()['aleatoria']);

        Livewire::test(\App\Filament\Pages\Parlante::class)
            ->assertSee('Música de fondo')
            ->assertSee('Con música')
            ->assertSeeHtml('OLAK5uy_kXv0ZqY8a3')
            ->assertSeeHtml('musica-del-parlante')
            // Sin listas guardadas, dice cómo agregarlas.
            ->assertSee('Otras listas a mano');

        // Vaciarlo la quita.
        Livewire::test(\App\Filament\Pages\Parlante::class)
            ->callAction('musica', ['url' => '', 'volumen' => 35])
            ->assertHasNoActionErrors();

        $this->assertNull(\App\Support\MusicaDelParlante::paraElReproductor());
    }

    public function test_se_guardan_varias_listas_y_se_cambia_en_vivo(): void
    {
        $this->admin();
        $parlante = \App\Filament\Pages\Parlante::class;

        // Un enlace que no es de YouTube no se guarda como lista.
        Livewire::test($parlante)
            ->callAction('musica', ['fuente' => 'youtube', 'url' => 'https://www.youtube.com/playlist?list=PLmanana01', 'volumen' => 40, 'aleatoria' => true,
                'listas_youtube' => [['nombre' => 'Mala', 'enlace' => 'https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M']]])
            ->assertHasActionErrors();

        Livewire::test($parlante)
            ->callAction('musica', ['fuente' => 'youtube', 'url' => 'https://www.youtube.com/playlist?list=PLmanana01', 'volumen' => 40, 'aleatoria' => true,
                'listas_youtube' => [
                    ['nombre' => 'Mañana tranquila', 'enlace' => 'https://www.youtube.com/playlist?list=PLmanana01'],
                    ['nombre' => 'Viernes', 'enlace' => 'https://music.youtube.com/playlist?list=OLAKviernes9'],
                ]])
            ->assertHasNoActionErrors();

        $this->assertCount(2, \App\Support\MusicaDelParlante::listas('youtube'));

        // La pantalla ofrece las dos, con la que suena elegida.
        Livewire::test($parlante)
            ->assertSee('Poner esta lista')
            ->assertSee('Mañana tranquila')
            ->assertSee('Viernes')
            // Y se cambia en vivo: queda como principal y el reproductor se entera.
            ->call('ponerLista', 1)
            ->assertDispatched('parlante-cambiar-lista', fuente: 'youtube', nombre: 'Viernes', lista: 'OLAKviernes9');

        $this->assertSame('https://music.youtube.com/playlist?list=OLAKviernes9', \App\Support\MusicaDelParlante::url());
        $this->assertSame('OLAKviernes9', \App\Support\MusicaDelParlante::paraElReproductor()['lista']);

        // Un índice que no existe no cambia nada.
        Livewire::test($parlante)->call('ponerLista', 7)->assertNotDispatched('parlante-cambiar-lista');
    }
}
