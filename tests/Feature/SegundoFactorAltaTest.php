<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Dar de alta la app de autenticación: el QR que se ve es el que vale.
 *
 * Antes, si la sesión no recordaba el secreto (otra pestaña, una recarga
 * mientras el servidor iba lento, dos peticiones a la vez), se generaba uno
 * nuevo: la pantalla enseñaba un QR y en la base quedaba otro, y ningún
 * código de la app coincidía.
 */
class SegundoFactorAltaTest extends TestCase
{
    use RefreshDatabase;

    private function administrador(): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }
        $u = User::create(['name' => 'Admin', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(User::ROL_SUPERADMIN);

        return $u->fresh();
    }

    public function test_volver_a_la_pantalla_sin_sesion_muestra_el_mismo_qr(): void
    {
        $u = $this->administrador();

        $primera = $this->actingAs($u)->get(route('dosfactores.configurar'))->assertOk();
        $secreto1 = Crypt::decryptString($u->fresh()->two_factor_secret);
        $primera->assertSee($secreto1);

        // Otra pestaña / sesión nueva: sin «2fa_secreto_provisional».
        $this->flushSession();
        $segunda = $this->actingAs($u->fresh())->get(route('dosfactores.configurar'))->assertOk();
        $secreto2 = Crypt::decryptString($u->fresh()->two_factor_secret);

        $this->assertSame($secreto1, $secreto2);
        $segunda->assertSee($secreto1);
    }

    public function test_el_codigo_del_qr_mostrado_activa_aunque_se_haya_recargado(): void
    {
        $u = $this->administrador();

        $this->actingAs($u)->get(route('dosfactores.configurar'))->assertOk();
        $this->flushSession();
        $this->actingAs($u->fresh())->get(route('dosfactores.configurar'))->assertOk();

        $secreto = Crypt::decryptString($u->fresh()->two_factor_secret);
        $codigo = app(Google2FA::class)->getCurrentOtp($secreto);

        $this->actingAs($u->fresh())
            ->post(route('dosfactores.activar'), ['codigo' => $codigo])
            ->assertRedirect('/admin');

        $this->assertNotNull($u->fresh()->two_factor_confirmed_at);
        $destino = $this->actingAs($u->fresh())->get('/admin');
        $this->assertStringNotContainsString('segundo-factor', (string) $destino->headers->get('Location'));
    }

    public function test_acepta_el_codigo_con_espacios_y_un_poco_atrasado(): void
    {
        $u = $this->administrador();
        $this->actingAs($u)->get(route('dosfactores.configurar'));
        $secreto = Crypt::decryptString($u->fresh()->two_factor_secret);

        $g = app(Google2FA::class);
        $anterior = $g->oathTotp($secreto, $g->getTimestamp() - 1);
        $conEspacio = substr($anterior, 0, 3) . ' ' . substr($anterior, 3);

        $this->actingAs($u->fresh())
            ->post(route('dosfactores.activar'), ['codigo' => $conEspacio])
            ->assertRedirect('/admin');
    }

    public function test_ya_confirmado_no_cambia_el_secreto(): void
    {
        $u = $this->administrador();
        $this->actingAs($u)->get(route('dosfactores.configurar'));
        $secreto = Crypt::decryptString($u->fresh()->two_factor_secret);
        $this->actingAs($u->fresh())->post(route('dosfactores.activar'), [
            'codigo' => app(Google2FA::class)->getCurrentOtp($secreto),
        ]);

        // Ya activado: la pantalla de alta lo manda a verificar, sin tocar nada.
        $this->actingAs($u->fresh())->get(route('dosfactores.configurar'))
            ->assertRedirect(route('dosfactores.verificar'));
        $this->assertSame($secreto, Crypt::decryptString($u->fresh()->two_factor_secret));
    }
}
