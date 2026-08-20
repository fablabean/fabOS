<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Las pantallas de Comunicaciones y las preferencias de cada persona (§15). */
class BackofficeComunicacionesTest extends TestCase
{
    use RefreshDatabase;

    private function conRol(?string $rol = null): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $cat = UserCategory::create([
            'slug' => 'c-' . uniqid(), 'name' => 'Estudiante', 'can_reserve' => true,
        ]);

        $u = User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);

        if ($rol) {
            $u->assignRole($rol);
        }

        return $u->fresh();
    }

    private function entra(User $u): self
    {
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession(['segundo_factor_verificado' => true]);

        return $this;
    }

    public function test_las_pantallas_de_comunicaciones_cargan(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        app(NotificationService::class)->enviar('saldo.abonado', $admin, [
            'importe' => '500,00', 'concepto' => 'dotación', 'saldo' => '500,00',
        ]);

        // La primera página del listado, ordenada por clave. No se afirma sobre
        // una plantilla concreta del final: añadir avisos nuevos la empujaría a
        // la segunda página y rompería la prueba sin que nada esté mal.
        $this->entra($admin)->get('/admin/notification-templates')
            ->assertOk()
            ->assertSee('Habilitación otorgada');

        $this->entra($admin)->get('/admin/notification-logs')
            ->assertOk()
            ->assertSee('saldo.abonado')
            ->assertSee('Enviado');
    }

    public function test_la_bitacora_muestra_tambien_lo_que_no_se_envio(): void
    {
        Mail::fake();
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);

        NotificationTemplate::create([
            'key' => 'prueba.apagada', 'name' => 'Aviso apagado',
            'body' => 'Nada', 'is_active' => false,
        ]);

        app(NotificationService::class)->enviar('prueba.apagada', $admin);

        // Si alguien reclama que no le avisaron, aquí está la respuesta.
        $this->entra($admin)->get('/admin/notification-logs')
            ->assertOk()
            ->assertSee('Omitido')
            ->assertSee('La plantilla está apagada');
    }

    public function test_una_persona_elige_que_avisos_recibe(): void
    {
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);
        $u = $this->conRol();

        $this->actingAs($u)->get(route('home'))
            ->assertOk()
            ->assertSee('Qué avisos quiero recibir')
            ->assertSee('Recordatorio de reserva');

        // Marca solo uno: el resto de lo prescindible queda apagado.
        $this->actingAs($u)
            ->post(route('cuenta.avisos'), ['avisos' => ['reserva.recordatorio' => '1']])
            ->assertRedirect();

        $this->assertTrue(NotificationPreference::where('user_id', $u->id)
            ->where('key', 'reserva.recordatorio')->first()->email);

        $this->assertFalse(NotificationPreference::where('user_id', $u->id)
            ->where('key', 'reserva.confirmada')->first()->email);
    }

    public function test_las_reglas_de_comunicaciones_quedan_documentadas(): void
    {
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $this->entra($this->conRol(User::ROL_CONSULTOR))->get('/admin/reglas')
            ->assertOk()
            ->assertSee('Lo esencial no se puede silenciar')
            ->assertSee('Nunca se avisa dos veces lo mismo')
            ->assertSee('reserva.recordatorio');
    }

    public function test_lo_esencial_no_aparece_como_apagable(): void
    {
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);
        $u = $this->conRol();

        // Ofrecer apagar algo que no se apaga sería mentirle a la persona.
        $this->actingAs($u)->get(route('home'))
            ->assertOk()
            ->assertDontSee('Tu reserva quedó afectada por un mantenimiento');
    }

    public function test_apagar_un_aviso_desde_la_cuenta_lo_detiene_de_verdad(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);
        $u = $this->conRol();

        $this->actingAs($u)->post(route('cuenta.avisos'), ['avisos' => []]);

        app(NotificationService::class)->enviar('reserva.recordatorio', $u, ['equipo' => 'X']);

        $this->assertSame('omitido', NotificationLog::where('key', 'reserva.recordatorio')->first()->status);
        Mail::assertNothingSent();
    }
}
