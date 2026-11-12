<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Spatie\Permission\Models\Role;
use PragmaRX\Google2FA\Google2FA;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Tests\TestCase;

/** La pantalla Cámaras: cerrada salvo para quien se le abre, y la foto sale de ffmpeg. */
class CamarasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['fabos.camaras' => [['numero' => 1, 'nombre' => 'Entrada', 'url' => 'rtsp://u:c@10.0.0.9:554/stream2']]]);
    }

    private function con(string $rol): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::factory()->create(['status' => 'activo']);
        $u->assignRole($rol);

        // El panel pide los dos factores; en la prueba se dan por hechos.
        $servicio = app(TwoFactorService::class);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($servicio->generarSecreto($u)));
        $this->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    public function test_el_superadmin_ve_la_pantalla_y_la_foto(): void
    {
        Process::fake(['*' => Process::result(output: "\xFF\xD8\xFFfoto")]);
        $yo = $this->con(User::ROL_SUPERADMIN);

        $this->actingAs($yo)->get('/admin/camaras')->assertOk()->assertSee('Entrada');

        $this->actingAs($yo)->get(route('panel.camara.foto', 1))
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        Process::assertRan(fn ($p) => in_array('rtsp://u:c@10.0.0.9:554/stream2', $p->command, true));
    }

    public function test_nace_cerrada_para_los_demas_roles(): void
    {
        $admin = $this->con(User::ROL_ADMINISTRADOR);

        $this->actingAs($admin)->get('/admin/camaras')->assertForbidden();
        $this->actingAs($admin)->get(route('panel.camara.foto', 1))->assertForbidden();
    }

    public function test_si_la_camara_no_responde_lo_dice(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1)]);

        $this->actingAs($this->con(User::ROL_SUPERADMIN))
            ->get(route('panel.camara.foto', 1))->assertStatus(503);
    }
}
