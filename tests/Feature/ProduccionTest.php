<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Install\ReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** La revisión previa al despliegue (§18). */
class ProduccionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function revision(): ReadinessService
    {
        return app(ReadinessService::class);
    }

    private function hallazgo(string $fragmento): ?array
    {
        return $this->revision()->revisar()
            ->first(fn (array $r) => str_contains($r['titulo'], $fragmento));
    }

    public function test_avisa_si_la_depuracion_esta_encendida(): void
    {
        config(['app.debug' => true]);

        $hallazgo = $this->hallazgo('APP_DEBUG');

        // Con la depuración encendida, un error muestra rutas, consultas y
        // variables a quien lo provoque.
        $this->assertSame(ReadinessService::GRAVE, $hallazgo['nivel']);
        $this->assertTrue($this->revision()->hayBloqueos());
    }

    public function test_avisa_si_el_sitio_no_esta_en_https(): void
    {
        config(['app.url' => 'http://192.168.1.10']);

        $hallazgo = $this->hallazgo('HTTPS');

        $this->assertSame(ReadinessService::GRAVE, $hallazgo['nivel']);
        // El motivo operativo importa tanto como el de seguridad.
        $this->assertStringContainsString('cámara', $hallazgo['detalle']);
    }

    public function test_https_configurado_pasa(): void
    {
        config(['app.url' => 'https://fablab.club']);

        $this->assertSame(ReadinessService::BIEN, $this->hallazgo('HTTPS')['nivel']);
    }

    public function test_avisa_si_el_correo_no_sale_de_la_maquina(): void
    {
        config(['mail.default' => 'log']);

        $hallazgo = $this->hallazgo('correo no sale');

        $this->assertSame(ReadinessService::GRAVE, $hallazgo['nivel']);
        $this->assertStringContainsString('nadie entra', $hallazgo['detalle']);
    }

    public function test_avisa_si_el_correo_va_a_mailpit(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'mailpit',
        ]);

        $this->assertSame(ReadinessService::GRAVE, $this->hallazgo('Mailpit')['nivel']);
    }

    public function test_avisa_si_no_hay_ningun_superadmin(): void
    {
        $this->assertSame(ReadinessService::GRAVE, $this->hallazgo('superadmin')['nivel']);
    }

    public function test_con_superadmin_deja_de_avisar(): void
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create(['name' => 'Coordinación', 'email' => 'c@test.co', 'status' => 'activo']);
        $u->assignRole(User::ROL_SUPERADMIN);

        $this->assertSame(ReadinessService::BIEN, $this->hallazgo('Superadmin')['nivel']);
    }

    public function test_avisa_que_los_correos_bloquean_la_peticion(): void
    {
        config(['queue.default' => 'sync']);

        $hallazgo = $this->hallazgo('dentro de la petición');

        // No bloquea el despliegue, pero quien pide un código espera a que el
        // servidor de correo conteste.
        $this->assertSame(ReadinessService::AVISO, $hallazgo['nivel']);
    }

    public function test_avisa_si_no_hay_rastro_del_planificador(): void
    {
        $hallazgo = $this->hallazgo('planificador');

        // Es lo que más se olvida y no da ningún error cuando falta.
        $this->assertSame(ReadinessService::AVISO, $hallazgo['nivel']);
        $this->assertStringContainsString('schedule:run', $hallazgo['arreglo']);
    }

    public function test_cada_hallazgo_dice_como_arreglarse(): void
    {
        config(['app.debug' => true, 'app.url' => 'http://x', 'mail.default' => 'log']);

        foreach ($this->revision()->revisar()->where('nivel', '!=', ReadinessService::BIEN) as $r) {
            $this->assertNotEmpty($r['detalle'], $r['titulo'] . ' no explica por qué importa');
        }
    }

    public function test_el_comando_falla_si_algo_bloquea(): void
    {
        config(['app.debug' => true]);

        $this->artisan('fabos:revisar')->assertFailed();
    }

    public function test_el_comando_pasa_cuando_todo_esta_en_orden(): void
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create(['name' => 'Coordinación', 'email' => 'c@test.co', 'status' => 'activo']);
        $u->assignRole(User::ROL_SUPERADMIN);

        config([
            'app.debug'   => false,
            'app.url'     => 'https://fablab.club',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.proveedor.com',
            'mail.from.address' => 'lab@fablab.club',
        ]);

        // Los avisos no impiden desplegar: solo lo grave.
        $this->artisan('fabos:revisar')->assertSuccessful();
    }

    public function test_la_guia_de_produccion_existe(): void
    {
        $this->assertFileExists(base_path('docs/PRODUCCION.md'));

        $guia = file_get_contents(base_path('docs/PRODUCCION.md'));

        // Los dos olvidos que de verdad duelen.
        $this->assertStringContainsString('schedule:run', $guia);
        $this->assertStringContainsString('fabos:respaldar', $guia);
    }
}
