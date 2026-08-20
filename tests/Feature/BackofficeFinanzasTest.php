<?php

namespace Tests\Feature;

use App\Support\FactoresDeSesion;
use App\Filament\Resources\LedgerAccounts\Pages\ListLedgerAccounts;
use App\Filament\Resources\RateCards\Pages\EditRateCard;
use App\Models\Area;
use App\Models\Asset;
use App\Models\RateCard;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Ledger\LedgerService;
use App\Services\Money\ChargeService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Las pantallas de Finanzas cargan de verdad.
 *
 * Una pantalla de Filament puede compilar y aun así reventar al dibujarse. Estas
 * pruebas piden las páginas por HTTP como lo haría una persona, que es la única
 * manera de saber que existen.
 */
class BackofficeFinanzasTest extends TestCase
{
    use RefreshDatabase;

    private function conRol(string $rol): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole($rol);

        return $u->fresh();
    }

    /**
     * El backoffice exige segundo factor a quien administra (§16). Aquí no es lo
     * que se prueba: se da por resuelto para llegar a las pantallas.
     */
    private function entra(User $u): self
    {
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $this;
    }

    private function tarifa(): RateCard
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Área']);
        $activo = Asset::create([
            'area_id' => $area->id, 'name' => 'Equipo ' . uniqid(),
            'kind' => 'fijo', 'status' => 'operativo',
        ]);

        return RateCard::create([
            'slug' => 't-' . uniqid(), 'name' => 'Tarifa de prueba',
            'rateable_type' => Asset::class, 'rateable_id' => $activo->id,
            'basis' => 'tiempo', 'unit' => 'hora', 'price_minor' => 2000,
            'setup_minor' => 300, 'minimum_minor' => 1000, 'is_assumed' => true,
        ]);
    }

    public function test_el_listado_de_tarifas_carga(): void
    {
        $this->tarifa();

        $this->entra($this->conRol(User::ROL_ADMINISTRADOR))
            ->get('/admin/rate-cards')
            ->assertOk()
            ->assertSee('Tarifa de prueba');
    }

    public function test_el_formulario_de_tarifa_muestra_fabcoins_no_centavos(): void
    {
        $tarifa = $this->tarifa();

        $this->entra($this->conRol(User::ROL_ADMINISTRADOR))
            ->get('/admin/rate-cards/' . $tarifa->id . '/edit')
            ->assertOk()
            ->assertSee('Montaje');

        // 2000 en la base son 20 FabCoins en pantalla: nadie debería tener que
        // multiplicar por cien mentalmente para fijar un precio. Y al guardar,
        // la conversión tiene que volver intacta.
        $pantalla = Livewire::test(EditRateCard::class, ['record' => $tarifa->getRouteKey()]);

        $this->assertEquals(20, $pantalla->get('data.price_minor'));
        $this->assertEquals(3, $pantalla->get('data.setup_minor'));

        $pantalla->set('data.price_minor', 25)->call('save');

        $this->assertSame(2500, $tarifa->fresh()->price_minor);
    }

    public function test_las_cuentas_y_los_movimientos_cargan(): void
    {
        $u = $this->conRol(User::ROL_ADMINISTRADOR);
        app(ChargeService::class)->dotar($u, 10000, '2026-08');

        $this->entra($u)->get('/admin/ledger-accounts')->assertOk()->assertSee('100,00');
        $this->entra($u)->get('/admin/ledger-transactions')->assertOk()->assertSee('Dotación');
    }

    public function test_abonar_desde_el_backoffice_mueve_el_saldo(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $beneficiario = $this->conRol(User::ROL_CONSULTOR);
        $this->entra($admin);

        Livewire::test(ListLedgerAccounts::class)
            ->callAction(TestAction::make('abonarA')->table(), [
                'user_id'  => $beneficiario->id,
                'concepto' => 'bonificacion',
                'importe'  => 15,
                'motivo'   => 'Apoyo en el curso de láser',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(1500, app(LedgerService::class)->saldoDe($beneficiario));
    }

    public function test_las_reglas_del_dinero_quedan_documentadas(): void
    {
        $this->tarifa();

        // «Todas las reglas deben quedar en el backoffice, no se pueden perder»:
        // la página las lee en vivo, no las repite a mano.
        $this->entra($this->conRol(User::ROL_CONSULTOR))
            ->get('/admin/reglas')
            ->assertOk()
            ->assertSee('El ciclo de una reserva')
            ->assertSee('Cómo se compone una tarifa')
            ->assertSee('Tarifa de prueba')
            ->assertSee('supuesta');
    }

    public function test_la_pagina_de_cobros_es_solo_del_superadmin(): void
    {
        $this->entra($this->conRol(User::ROL_ADMINISTRADOR))
            ->get('/admin/cobros')
            ->assertForbidden();

        $this->entra($this->conRol(User::ROL_SUPERADMIN))
            ->get('/admin/cobros')
            ->assertOk()
            ->assertSee('Estado del libro');
    }
}
