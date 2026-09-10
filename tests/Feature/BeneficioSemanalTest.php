<?php

namespace Tests\Feature;

use App\Models\LedgerAccount;
use App\Models\Setting;
use App\Models\User;
use App\Services\Ledger\LedgerService;
use App\Services\Money\BeneficioSemanal;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * El beneficio semanal de FabCoins (§12).
 *
 * Ocho a la semana para quien tiene correo aliado, completando el saldo:
 * no se acumula, no se repite en la semana, y se apaga desde el panel.
 */
class BeneficioSemanalTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $libro;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->travelTo(Carbon::parse('2026-09-09 10:00', config('fabos.lab.timezone')));   // miercoles, semana 37
        Setting::put(Settings::BENEFICIO_ACTIVO, true, 'finanzas');
        $this->libro = app(LedgerService::class);
    }

    private function persona(string $correo, int $saldoMenor = 0): User
    {
        $u = User::factory()->create(['email' => $correo, 'status' => 'activo']);

        if ($saldoMenor > 0) {
            $this->libro->transferir(
                $this->libro->cuentaDeSistema(LedgerAccount::EMISION), $this->libro->cuentaDe($u),
                $saldoMenor, 'dotacion', 'Dotación anterior', 'dotacion:' . $u->id . ':2026-08',
            );
        }

        return $u;
    }

    private function gastar(User $u, int $menor): void
    {
        $this->libro->transferir(
            $this->libro->cuentaDe($u), $this->libro->cuentaDeSistema(LedgerAccount::INGRESO),
            $menor, 'venta', 'Filamento', null,
        );
    }

    public function test_la_semana_iso_es_la_clave(): void
    {
        $this->assertSame('2026-W37', BeneficioSemanal::semana());
    }

    public function test_completa_hasta_el_tope_a_quien_tiene_correo_aliado(): void
    {
        $sinSaldo = $this->persona('ana@universidadean.edu.co');
        $conTres = $this->persona('beto@fablabean.com', 300);
        $conOcho = $this->persona('carla@ieee.org', 800);
        $conDoce = $this->persona('dario@universidadean.edu.co', 1200);
        $deFuera = $this->persona('eva@gmail.com');

        $r = app(BeneficioSemanal::class)->aplicar();

        $this->assertSame('2026-W37', $r['semana']);
        $this->assertSame(4, $r['personas'], 'la de Gmail no entra');
        $this->assertSame(2, $r['abonos']);
        $this->assertSame(2, $r['completas']);
        $this->assertSame(800 + 500, $r['total']);

        $this->assertSame(800, $this->libro->saldoDe($sinSaldo));
        $this->assertSame(800, $this->libro->saldoDe($conTres), 'se completa, no se suma');
        $this->assertSame(800, $this->libro->saldoDe($conOcho));
        $this->assertSame(1200, $this->libro->saldoDe($conDoce), 'con mas del tope no se toca');
        $this->assertSame(0, $this->libro->saldoDe($deFuera));
    }

    /** Dos veces en la misma semana no abona dos veces, ni aunque se gaste entre medias. */
    public function test_en_la_misma_semana_no_se_repite(): void
    {
        $ana = $this->persona('ana@universidadean.edu.co');
        $beneficio = app(BeneficioSemanal::class);

        $beneficio->aplicar();
        $this->gastar($ana, 500);
        $r = $beneficio->aplicar();

        $this->assertSame(0, $r['abonos']);
        $this->assertSame(300, $this->libro->saldoDe($ana));

        // La semana siguiente, si.
        $this->travelTo(Carbon::parse('2026-09-14 00:10', config('fabos.lab.timezone')));
        $r = $beneficio->aplicar();

        $this->assertSame('2026-W38', $r['semana']);
        $this->assertSame(1, $r['abonos']);
        $this->assertSame(800, $this->libro->saldoDe($ana));
    }

    public function test_apagado_no_abona_y_simular_no_escribe(): void
    {
        $ana = $this->persona('ana@universidadean.edu.co');
        $beneficio = app(BeneficioSemanal::class);

        Setting::put(Settings::BENEFICIO_ACTIVO, false, 'finanzas');
        $this->assertSame(0, $beneficio->aplicar()['abonos']);
        $this->assertSame(0, $this->libro->saldoDe($ana));

        Setting::put(Settings::BENEFICIO_ACTIVO, true, 'finanzas');
        $r = $beneficio->aplicar(simular: true);
        $this->assertSame(1, $r['abonos']);
        $this->assertSame(800, $r['total']);
        $this->assertSame(0, $this->libro->saldoDe($ana), 'simular no escribe');
    }

    /** Los dominios y el tope se administran: cambian la regla sin tocar codigo. */
    public function test_los_dominios_y_el_tope_se_administran(): void
    {
        Setting::put(Settings::BENEFICIO_DOMINIOS, ['ejemplo.org'], 'finanzas');
        Setting::put(Settings::BENEFICIO_SEMANAL, 500, 'finanzas');

        $dentro = $this->persona('ana@ejemplo.org');
        $fuera = $this->persona('beto@universidadean.edu.co');

        app(BeneficioSemanal::class)->aplicar();

        $this->assertSame(500, $this->libro->saldoDe($dentro));
        $this->assertSame(0, $this->libro->saldoDe($fuera));
        $this->assertSame(['ejemplo.org'], Settings::dominiosDelBeneficio());
    }

    /** La pagina del panel: se ve la regla y a quien le tocaria. */
    public function test_la_pagina_del_panel_ensena_a_quien_le_toca(): void
    {
        $this->persona('ana@universidadean.edu.co', 300);

        $jefa = User::create(['name' => 'Jefa', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $jefa->assignRole(\Spatie\Permission\Models\Role::findOrCreate(User::ROL_SUPERADMIN, 'web'));
        $servicio = app(\App\Services\Auth\TwoFactorService::class);
        $secreto = $servicio->generarSecreto($jefa);
        $servicio->confirmar($jefa, app(\PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($jefa->fresh())
            ->withSession([\App\Support\FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]])
            ->get('/admin/beneficio-semanal')
            ->assertOk()
            ->assertSee('Beneficio semanal')
            ->assertSee('universidadean.edu.co')
            ->assertSee('ana@universidadean.edu.co')
            ->assertSee('+5,00');
    }

    public function test_el_comando_lo_aplica_y_lo_cuenta(): void
    {
        $this->persona('ana@universidadean.edu.co', 300);

        $this->artisan('fabos:beneficio-semanal')
            ->expectsOutputToContain('1 abonos por 5,00')
            ->assertSuccessful();

        $this->artisan('fabos:beneficio-semanal --semana=mal')->assertFailed();
    }
}
