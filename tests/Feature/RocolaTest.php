<?php

namespace Tests\Feature;

use App\Models\CancionPedida;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Money\ChargeService;
use App\Services\Musica\Rocola;
use App\Services\Musica\RocolaException;
use App\Support\MusicaDelParlante;
use App\Support\SpotifyDelParlante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La rocola del Parlante: 1 FabCoin por canción, nada explícito, y el
 * FabCoin vuelve si la canción no entra (§3).
 */
class RocolaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Spotify conectado, como lo deja «Conectar Spotify».
        Setting::put(MusicaDelParlante::FUENTE, 'spotify', 'parlante');
        Setting::put(SpotifyDelParlante::CLIENTE, 'cliente', 'parlante');
        Setting::put(SpotifyDelParlante::SECRETO, Crypt::encryptString('secreto'), 'parlante');
        Setting::put(SpotifyDelParlante::PERMISO, Crypt::encryptString('permiso'), 'parlante');
        Setting::put(SpotifyDelParlante::LISTA, 'https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M', 'parlante');
    }

    private function spotify(int $cola = 204): void
    {
        $pista = fn (string $id, string $nombre, bool $explicita) => [
            'id' => $id, 'name' => $nombre, 'explicit' => $explicita, 'duration_ms' => 215000,
            'artists' => [['name' => 'Soda Stereo']],
            'album' => ['images' => [['url' => 'https://i.scdn.co/' . $id . '.jpg', 'width' => 300]]],
        ];

        // Un Spotify con su cola: suena «Persiana americana» desde hace un
        // minuto, y lo que se pone entra delante de la lista.
        $suena = $pista('3AhXZa8sUQht0UEdBJgpGc', 'Persiana americana', false);
        $lista = [$pista('5lista0000000000000AB', 'Siguiente de la lista', false)];
        $pedidas = [];

        Http::fake(function ($r) use ($pista, $cola, $suena, $lista, &$pedidas) {
            $url = $r->url();

            return match (true) {
                str_contains($url, 'accounts.spotify.com/api/token') => Http::response(['access_token' => 'ACCESO', 'expires_in' => 3600]),
                str_contains($url, '/v1/search') => Http::response(['tracks' => ['items' => [
                    $pista('4uLU6hMCjMI75M1A2tKUQC', 'De música ligera', false),
                    $pista('0explicita0000000000AB', 'Una explícita', true),
                ]]]),
                str_contains($url, '/v1/tracks/4uLU6hMCjMI75M1A2tKUQC') => Http::response($pista('4uLU6hMCjMI75M1A2tKUQC', 'De música ligera', false)),
                str_contains($url, '/v1/tracks/0explicita0000000000AB') => Http::response($pista('0explicita0000000000AB', 'Una explícita', true)),
                str_contains($url, '/me/player/queue') && $r->method() === 'POST' => (function () use ($cola, $pista, &$pedidas) {
                    if ($cola === 204) {
                        $pedidas[] = $pista('4uLU6hMCjMI75M1A2tKUQC', 'De música ligera', false);
                    }

                    return Http::response(null, $cola);
                })(),
                str_contains($url, '/me/player/queue') => Http::response(['currently_playing' => $suena, 'queue' => [...$pedidas, ...$lista]]),
                str_contains($url, '/me/player') => Http::response(['is_playing' => true, 'progress_ms' => 60000, 'item' => $suena]),
                default => Http::response(null, 404),
            };
        });
    }

    private function persona(int $fabcoins): User
    {
        $u = User::create(['name' => 'Ana Torres', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);

        if ($fabcoins > 0) {
            app(ChargeService::class)->dotar($u, $fabcoins * 100, 'prueba-' . $u->id);
        }

        return $u;
    }

    private function saldo(User $u): int
    {
        return app(LedgerService::class)->saldoDe($u);
    }

    public function test_busca_sin_las_explicitas(): void
    {
        $this->spotify();

        $resultados = app(Rocola::class)->buscar('soda');

        $this->assertCount(1, $resultados);
        $this->assertSame('De música ligera', $resultados[0]['titulo']);
        $this->assertSame('Soda Stereo', $resultados[0]['artista']);

        // Nunca más de 10 por página: Spotify responde «Invalid limit» con más.
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/search') && ($r->data()['limit'] ?? null) === 10);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/v1/search') && ($r->data()['limit'] ?? 0) > 10);
    }

    public function test_poner_una_cancion_cuesta_un_fabcoin_y_entra_a_la_cola(): void
    {
        $this->spotify();
        $ana = $this->persona(3);

        $pedido = app(Rocola::class)->pedir($ana, '4uLU6hMCjMI75M1A2tKUQC');

        $this->assertSame('en_cola', $pedido->estado);
        $this->assertSame(200, $this->saldo($ana), 'de 3 FabCoins le quedan 2');
        Http::assertSent(fn ($r) => str_contains($r->url(), '/me/player/queue')
            && str_contains(urldecode($r->url()), 'uri=spotify:track:4uLU6hMCjMI75M1A2tKUQC'));
    }

    public function test_una_explicita_no_entra_aunque_se_pida_por_su_identificador(): void
    {
        $this->spotify();
        $ana = $this->persona(3);

        $this->expectException(RocolaException::class);

        try {
            app(Rocola::class)->pedir($ana, '0explicita0000000000AB');
        } finally {
            $this->assertSame(300, $this->saldo($ana));
            $this->assertSame(0, CancionPedida::count());
        }
    }

    public function test_sin_fabcoins_no_se_pone_nada(): void
    {
        $this->spotify();
        $ana = $this->persona(0);

        try {
            app(Rocola::class)->pedir($ana, '4uLU6hMCjMI75M1A2tKUQC');
            $this->fail('entró sin saldo');
        } catch (RocolaException $e) {
            $this->assertStringContainsString('No te alcanzan los FabCoins', $e->getMessage());
        }

        $this->assertSame(0, CancionPedida::count());
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/me/player/queue'));
    }

    public function test_si_el_parlante_esta_apagado_se_devuelve_el_fabcoin(): void
    {
        $this->spotify(cola: 404);
        $ana = $this->persona(3);

        try {
            app(Rocola::class)->pedir($ana, '4uLU6hMCjMI75M1A2tKUQC');
            $this->fail('no debía quedar en cola');
        } catch (RocolaException $e) {
            $this->assertStringContainsString('Te devolvimos el FabCoin', $e->getMessage());
        }

        $this->assertSame(300, $this->saldo($ana), 'el FabCoin volvió');
        $this->assertSame('devuelta', CancionPedida::sole()->estado);
    }

    public function test_la_pagina_busca_y_pone_y_se_cierra_sin_spotify(): void
    {
        $this->spotify();
        $ana = $this->persona(2);
        $this->actingAs($ana);

        $this->get(route('rocola', ['q' => 'soda']))
            ->assertOk()
            ->assertSee('De música ligera')
            ->assertDontSee('Una explícita')
            ->assertSee('Ponerla');

        $this->post(route('rocola.pedir'), ['cancion' => '4uLU6hMCjMI75M1A2tKUQC'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        $this->assertSame(100, $this->saldo($ana));
        $this->get(route('rocola'))->assertSee('Lo que viene')->assertSee('♪ Ana');

        // Con la música en YouTube, la rocola cierra.
        Setting::put(MusicaDelParlante::FUENTE, 'youtube', 'parlante');
        $this->assertFalse(Rocola::abierta());
        $this->get(route('rocola'))->assertOk()->assertSee('La rocola está cerrada');
        $this->post(route('rocola.pedir'), ['cancion' => '4uLU6hMCjMI75M1A2tKUQC'])->assertSessionHasErrors('cancion');
        $this->assertSame(100, $this->saldo($ana));
    }

    public function test_dice_que_suena_y_a_que_hora_suena_cada_una(): void
    {
        $this->spotify();
        $ana = $this->persona(3);
        $this->travelTo(now()->setTime(10, 0, 0));

        $pedido = app(Rocola::class)->pedir($ana, '4uLU6hMCjMI75M1A2tKUQC');
        $estado = app(Rocola::class)->estado(fresco: true);

        // Suena «Persiana americana»: lleva 1:00 de 3:35, termina en 2:35.
        $this->assertSame('Persiana americana', $estado['actual']['titulo']);
        $this->assertTrue($estado['actual']['sonando']);
        $this->assertEquals(now()->addSeconds(155), $estado['actual']['termina']);

        // Lo pedido va primero, con su hora y quién lo pidió; después, la lista.
        [$primera, $segunda] = $estado['siguientes'];
        $this->assertSame('De música ligera', $primera['titulo']);
        $this->assertSame('Ana', $primera['pidio']);
        $this->assertSame($pedido->id, $primera['pedido_id']);
        $this->assertEquals(now()->addSeconds(155), $primera['empieza']);
        $this->assertSame('Siguiente de la lista', $segunda['titulo']);
        $this->assertNull($segunda['pidio']);
        $this->assertEquals(now()->addSeconds(155 + 215), $segunda['empieza']);

        $this->assertEquals(now()->addSeconds(155), app(Rocola::class)->cuandoSuena($pedido));
    }

    /**
     * La segunda vez sale de la caché, que en producción serializa y no deja
     * pasar objetos: guardar fechas ahí devolvía objetos rotos (error 500).
     */
    public function test_el_estado_sale_bien_tambien_desde_la_cache(): void
    {
        config(['cache.default' => 'array', 'cache.stores.array.serialize' => true, 'cache.serializable_classes' => false]);
        \Illuminate\Support\Facades\Cache::forgetDriver('array');
        $this->spotify();
        $this->actingAs($this->persona(1));

        $primera = app(Rocola::class)->estado();
        $segunda = app(Rocola::class)->estado();

        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $segunda['actual']['termina']);
        $this->assertInstanceOf(\Carbon\CarbonInterface::class, $segunda['siguientes'][0]['empieza']);
        $this->assertEquals($primera['actual']['termina'], $segunda['actual']['termina']);

        $this->get(route('rocola'))->assertOk()->assertSee('Persiana americana');
        $this->get(route('rocola'))->assertOk()->assertSee('Persiana americana');
    }

    public function test_quien_pone_una_cancion_sabe_cuando_sonara_y_la_pagina_muestra_la_cola(): void
    {
        $this->spotify();
        $ana = $this->persona(2);
        $this->actingAs($ana);
        $this->travelTo(now(config('fabos.lab.timezone'))->setTime(10, 0, 0));
        $hora = now()->addSeconds(155)->timezone(config('fabos.lab.timezone'))->format('H:i');

        $this->post(route('rocola.pedir'), ['cancion' => '4uLU6hMCjMI75M1A2tKUQC'])
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'sonará hacia las ' . $hora) && str_contains($m, 'en unos 3 min'));

        $this->get(route('rocola'))->assertOk()
            ->assertSee('Sonando ahora')
            ->assertSee('Persiana americana')
            ->assertSeeInOrder(['Lo que viene', '~' . $hora, 'De música ligera', '♪ Ana', 'Siguiente de la lista']);

        // Y se refresca sola, por partes.
        $this->get(route('rocola.estado'))->assertOk()->assertSee('Persiana americana')->assertDontSee('<html', false);
    }
}
