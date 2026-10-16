<?php

namespace Tests\Feature;

use App\Filament\Pages\Parlante;
use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use App\Support\MusicaDelParlante;
use App\Support\SpotifyDelParlante;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Spotify como música de fondo del Parlante (§3).
 *
 * La cuenta del laboratorio se conecta una vez; lo guardado va cifrado; y el
 * reproductor pide su acceso a fabOS, que lo renueva solo.
 */
class ParlanteSpotifyTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create(['name' => 'Erick', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $u->assignRole(User::ROL_SUPERADMIN);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    private function configurar(): void
    {
        Livewire::test(Parlante::class)
            ->callAction('musica', [
                'fuente' => 'spotify', 'volumen' => 40, 'aleatoria' => true,
                'spotify_lista' => 'https://open.spotify.com/intl-es/playlist/37i9dQZF1DXcBWIGoYBM5M?si=abc',
                'spotify_cliente' => 'cliente123', 'spotify_secreto' => 'secreto456',
            ])
            ->assertHasNoActionErrors();
    }

    /** Pulsa «Conectar» y devuelve el estado que se mandó a Spotify. */
    private function irASpotify(): string
    {
        parse_str(parse_url($this->get(route('parlante.spotify.conectar'))->headers->get('Location'), PHP_URL_QUERY), $q);

        return $q['state'];
    }

    private function spotifyResponde(): void
    {
        Http::fake([
            'accounts.spotify.com/api/token' => Http::response(['access_token' => 'ACCESO-1', 'refresh_token' => 'PERMISO-1', 'expires_in' => 3600]),
            'api.spotify.com/v1/me' => Http::response(['display_name' => 'FABLAB EAN', 'product' => 'premium']),
        ]);
    }

    public function test_lee_los_enlaces_de_listas_de_spotify(): void
    {
        $this->assertSame('37i9dQZF1DXcBWIGoYBM5M', SpotifyDelParlante::leerLista('https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M?si=x'));
        $this->assertSame('37i9dQZF1DXcBWIGoYBM5M', SpotifyDelParlante::leerLista('https://open.spotify.com/intl-es/playlist/37i9dQZF1DXcBWIGoYBM5M'));
        $this->assertSame('37i9dQZF1DXcBWIGoYBM5M', SpotifyDelParlante::leerLista('spotify:playlist:37i9dQZF1DXcBWIGoYBM5M'));

        $this->assertNull(SpotifyDelParlante::leerLista('https://open.spotify.com/album/1ATL5GLyefJaxhQzSPVrLX'));
        $this->assertNull(SpotifyDelParlante::leerLista('https://www.youtube.com/playlist?list=PLabc'));
        $this->assertNull(SpotifyDelParlante::leerLista('https://open.spotify.com.evil.co/playlist/37i9dQZF1DXcBWIGoYBM5M'));
    }

    public function test_se_configura_con_el_secreto_cifrado_y_se_conecta_una_vez(): void
    {
        $this->admin();
        $this->configurar();

        // El secreto no queda a la vista en la base.
        $this->assertNotSame('secreto456', Setting::get(SpotifyDelParlante::SECRETO));
        $this->assertSame('secreto456', SpotifyDelParlante::secreto());
        $this->assertSame('spotify', MusicaDelParlante::fuente());
        $this->assertFalse(SpotifyDelParlante::conectada());

        // Sin conectar, todavía no hay música que tocar; y aparece el botón.
        $this->assertNull(MusicaDelParlante::paraElReproductor());
        Livewire::test(Parlante::class)->assertActionVisible('conectarSpotify');

        // «Conectar» manda a Spotify con lo justo, y recuerda el pedido.
        $ida = $this->get(route('parlante.spotify.conectar'));
        $ida->assertRedirect();
        $destino = $ida->headers->get('Location');
        $this->assertStringStartsWith('https://accounts.spotify.com/authorize?', $destino);
        parse_str(parse_url($destino, PHP_URL_QUERY), $q);
        $this->assertSame('cliente123', $q['client_id']);
        $this->assertSame(SpotifyDelParlante::direccionDeVuelta(), $q['redirect_uri']);
        $this->assertStringContainsString('streaming', $q['scope']);
        $estado = $q['state'];

        // La vuelta de ese pedido conecta la cuenta.
        $this->spotifyResponde();
        $this->get(route('parlante.spotify.vuelta', ['code' => 'CODIGO', 'state' => $estado]))
            ->assertRedirect(Parlante::getUrl())
            ->assertSessionHas('spotify', fn ($m) => str_contains($m, 'FABLAB EAN'));

        Http::assertSent(fn ($r) => $r->url() === 'https://accounts.spotify.com/api/token'
            && ($r->data()['grant_type'] ?? null) === 'authorization_code' && ($r->data()['code'] ?? null) === 'CODIGO'
            && $r->hasHeader('Authorization', 'Basic ' . base64_encode('cliente123:secreto456')));

        $this->assertTrue(SpotifyDelParlante::conectada());
        $this->assertNotSame('PERMISO-1', Setting::get(SpotifyDelParlante::PERMISO), 'el permiso va cifrado');
        $this->assertSame('FABLAB EAN', SpotifyDelParlante::cuenta());

        // Una vuelta que no es de un pedido nuestro —un enlace armado— no hace nada.
        $this->get(route('parlante.spotify.vuelta', ['code' => 'OTRO', 'state' => 'inventado']))
            ->assertRedirect(Parlante::getUrl())
            ->assertSessionHas('spotify', fn ($m) => str_contains($m, 'no corresponde'));
        Http::assertNotSent(fn ($r) => ($r->data()['code'] ?? null) === 'OTRO');

        // Y el Parlante ya tiene qué tocar.
        $musica = MusicaDelParlante::paraElReproductor();
        $this->assertSame('spotify', $musica['fuente']);
        $this->assertSame('37i9dQZF1DXcBWIGoYBM5M', $musica['lista']);
        Livewire::test(Parlante::class)->assertSee('Música de fondo')->assertSee('FABLAB EAN');
    }

    public function test_el_reproductor_pide_su_acceso_y_se_renueva_solo(): void
    {
        $this->admin();
        $this->configurar();

        // Spotify responde, en orden: la conexión, una renovación, y un permiso revocado.
        Http::fake([
            'accounts.spotify.com/api/token' => Http::sequence()
                ->push(['access_token' => 'ACCESO-1', 'refresh_token' => 'PERMISO-1', 'expires_in' => 3600])
                ->push(['access_token' => 'ACCESO-2', 'expires_in' => 3600])
                ->push(['error' => 'invalid_grant', 'error_description' => 'Refresh token revoked'], 400),
            'api.spotify.com/v1/me' => Http::response(['display_name' => 'FABLAB EAN', 'product' => 'premium']),
        ]);

        $this->get(route('parlante.spotify.vuelta', ['code' => 'CODIGO', 'state' => $this->irASpotify()]));

        // Recién conectado, el acceso ya está: no se vuelve a pedir.
        $this->getJson(route('parlante.spotify.acceso'))->assertOk()->assertJson(['token' => 'ACCESO-1']);
        Http::assertSentCount(2);

        // Vencido, se renueva con el permiso guardado.
        \Illuminate\Support\Facades\Cache::flush();

        $this->getJson(route('parlante.spotify.acceso'))->assertOk()->assertJson(['token' => 'ACCESO-2']);
        Http::assertSent(fn ($r) => ($r->data()['grant_type'] ?? null) === 'refresh_token' && ($r->data()['refresh_token'] ?? null) === 'PERMISO-1');

        // Un permiso revocado en Spotify deja la cuenta desconectada, y lo dice.
        \Illuminate\Support\Facades\Cache::flush();

        $this->getJson(route('parlante.spotify.acceso'))->assertStatus(409)->assertJsonFragment(['error' => 'Spotify no dio acceso: Refresh token revoked.']);
        $this->assertFalse(SpotifyDelParlante::conectada());
    }

    public function test_solo_quien_usa_el_parlante_conecta_o_pide_acceso(): void
    {
        $estudiante = User::create(['name' => 'Ana', 'email' => 'ana@lab.co', 'status' => 'activo']);
        $this->actingAs($estudiante);

        $this->get(route('parlante.spotify.conectar'))->assertForbidden();
        $this->getJson(route('parlante.spotify.acceso'))->assertForbidden();
    }
}
