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

        Http::fake([
            'accounts.spotify.com/api/token' => Http::response(['access_token' => 'ACCESO', 'expires_in' => 3600]),
            'api.spotify.com/v1/search*' => Http::response(['tracks' => ['items' => [
                $pista('4uLU6hMCjMI75M1A2tKUQC', 'De música ligera', false),
                $pista('0explicita0000000000AB', 'Una explícita', true),
            ]]]),
            'api.spotify.com/v1/tracks/4uLU6hMCjMI75M1A2tKUQC*' => Http::response($pista('4uLU6hMCjMI75M1A2tKUQC', 'De música ligera', false)),
            'api.spotify.com/v1/tracks/0explicita0000000000AB*' => Http::response($pista('0explicita0000000000AB', 'Una explícita', true)),
            'api.spotify.com/v1/me/player/queue*' => Http::response(null, $cola),
        ]);
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
        $this->get(route('rocola'))->assertSee('Lo último que se pidió')->assertSee('la puso Ana');

        // Con la música en YouTube, la rocola cierra.
        Setting::put(MusicaDelParlante::FUENTE, 'youtube', 'parlante');
        $this->assertFalse(Rocola::abierta());
        $this->get(route('rocola'))->assertOk()->assertSee('La rocola está cerrada');
        $this->post(route('rocola.pedir'), ['cancion' => '4uLU6hMCjMI75M1A2tKUQC'])->assertSessionHasErrors('cancion');
        $this->assertSame(100, $this->saldo($ana));
    }
}
