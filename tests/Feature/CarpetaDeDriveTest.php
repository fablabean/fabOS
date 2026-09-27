<?php

namespace Tests\Feature;

use App\Filament\Pages\CarpetaDeDrive;
use App\Models\Setting;
use App\Models\User;
use App\Services\Contenido\DriveDelLaboratorio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** La carpeta de Drive del laboratorio, leída desde el backoffice (§21). */
class CarpetaDeDriveTest extends TestCase
{
    use RefreshDatabase;

    private const RAIZ = '1AbCdEfGhIjKlMnOpQrStUv';

    private function conectar(): void
    {
        $llave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($llave, $privada);

        app(DriveDelLaboratorio::class)->configurar(
            'https://drive.google.com/drive/folders/' . self::RAIZ . '?usp=sharing',
            json_encode([
                'type' => 'service_account', 'client_email' => 'fabos-drive@lab.iam.gserviceaccount.com',
                'private_key' => $privada, 'token_uri' => 'https://oauth2.googleapis.com/token',
            ]),
        );
    }

    private function fingirDrive(): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'www.googleapis.com/drive/v3/files*' => function (Request $r) {
                parse_str(parse_url($r->url(), PHP_URL_QUERY), $q);

                return str_contains($q['q'], self::RAIZ)
                    ? Http::response(['files' => [
                        ['id' => 'sub123456789', 'name' => 'Feria 2026', 'mimeType' => 'application/vnd.google-apps.folder'],
                        ['id' => 'foto12345678', 'name' => 'impresoras.jpg', 'mimeType' => 'image/jpeg',
                         'thumbnailLink' => 'https://lh3.googleusercontent.com/abc=s220', 'webViewLink' => 'https://drive.google.com/file/d/foto12345678/view'],
                    ]])
                    : Http::response(['files' => [
                        ['id' => 'video1234567', 'name' => 'corte.mp4', 'mimeType' => 'video/mp4', 'webViewLink' => 'https://drive.google.com/file/d/video1234567/view'],
                    ]]);
            },
        ]);
    }

    private function entrarComoAdmin(): void
    {
        $u = User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_SUPERADMIN, 'web'));
        $this->actingAs($u);
    }

    public function test_la_llave_se_guarda_cifrada_y_el_enlace_da_el_id(): void
    {
        $this->conectar();

        $this->assertSame(self::RAIZ, app(DriveDelLaboratorio::class)->carpeta());
        $this->assertStringNotContainsString('PRIVATE KEY', (string) Setting::get(DriveDelLaboratorio::CREDENCIALES));
        $this->assertStringContainsString('PRIVATE KEY', Crypt::decryptString(Setting::get(DriveDelLaboratorio::CREDENCIALES)));
        $this->assertSame('fabos-drive@lab.iam.gserviceaccount.com', app(DriveDelLaboratorio::class)->correoDeLaCuenta());
    }

    public function test_una_llave_que_no_es_de_cuenta_de_servicio_se_rechaza(): void
    {
        $this->expectException(\RuntimeException::class);

        app(DriveDelLaboratorio::class)->configurar('https://drive.google.com/drive/folders/' . self::RAIZ, '{"type":"authorized_user"}');
    }

    public function test_lista_la_carpeta_con_miniaturas_de_google(): void
    {
        $this->conectar();
        $this->fingirDrive();

        $archivos = app(DriveDelLaboratorio::class)->listar();

        $this->assertCount(2, $archivos);
        $this->assertTrue($archivos[0]['esCarpeta']);
        $this->assertSame('https://lh3.googleusercontent.com/abc=s400', $archivos[1]['miniatura']);

        // Se firmó un JWT con la llave, en vez de mandar la llave.
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com')
            && $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
            && substr_count($r['assertion'], '.') === 2);
    }

    public function test_la_pagina_navega_solo_a_subcarpetas_del_listado(): void
    {
        $this->conectar();
        $this->fingirDrive();
        $this->entrarComoAdmin();

        Livewire::test(CarpetaDeDrive::class)
            ->assertSee('impresoras.jpg')
            ->assertSee('Feria 2026')
            ->call('entrar', 'sub123456789')
            ->assertSee('corte.mp4')
            ->assertSet('ruta', [['id' => 'sub123456789', 'nombre' => 'Feria 2026']])
            ->call('volverA', -1)
            ->assertSee('impresoras.jpg')
            // Una carpeta que no está en el listado no se abre por pedirla.
            ->call('entrar', 'otraCarpeta99999')
            ->assertSet('ruta', []);
    }

    public function test_sin_configurar_explica_los_pasos(): void
    {
        $this->entrarComoAdmin();

        Livewire::test(CarpetaDeDrive::class)
            ->assertSee('Falta conectar la carpeta')
            ->assertSee('API de Google Drive');
    }
}
