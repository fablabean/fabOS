<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductionJobs\Pages\ListProductionJobs;
use App\Models\ProductionJob;
use App\Models\Setting;
use App\Models\Supply;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Ledger\LedgerService;
use App\Services\Money\ChargeService;
use App\Services\Shop\ProductionService;
use App\Support\Settings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Pedir un encargo desde la tienda y atenderlo desde la cola (§14). */
class EncargosPublicoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function persona(?string $rol = null): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $cat = UserCategory::firstOrCreate(
            ['slug' => 'profesor'],
            ['name' => 'Profesor', 'can_reserve' => true, 'rate_factor' => 1],
        );

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

    public function test_la_tienda_ofrece_pedir_un_trabajo(): void
    {
        $this->actingAs($this->persona())
            ->get(route('tienda'))
            ->assertOk()
            ->assertSee('Pedir un trabajo')
            ->assertSee('No hace falta saber operar la máquina');
    }

    public function test_alguien_pide_un_encargo_desde_la_tienda(): void
    {
        $u = $this->persona();

        $this->actingAs($u)
            ->post(route('tienda.encargar'), [
                'title'       => '40 piezas para la clase de diseño',
                'description' => 'Acrílico de 3 mm',
                'quantity'    => 40,
            ])
            ->assertRedirect();

        $encargo = ProductionJob::first();

        $this->assertSame($u->id, $encargo->user_id);
        $this->assertSame('solicitado', $encargo->status);
        $this->assertSame(40.0, (float) $encargo->quantity);
    }

    public function test_quien_pide_es_quien_acepta_la_cotizacion(): void
    {
        $u = $this->persona();
        $encargo = app(ProductionService::class)->pedir($u, ['title' => 'Corte de piezas']);
        app(ProductionService::class)->cotizar($encargo, 15_000, 120, now()->addDays(3)->toDateString());

        $this->actingAs($u)->get(route('tienda'))
            ->assertOk()
            ->assertSee('Mis encargos')
            ->assertSee('150,00')
            ->assertSee('Aceptar');

        $this->actingAs($u)
            ->post(route('tienda.encargo.aceptar', $encargo->refresh()))
            ->assertRedirect();

        $this->assertSame('en_cola', $encargo->fresh()->status);
    }

    public function test_nadie_acepta_ni_cancela_el_encargo_de_otro(): void
    {
        $encargo = app(ProductionService::class)->pedir($this->persona(), ['title' => 'Corte']);
        app(ProductionService::class)->cotizar($encargo, 15_000);

        $this->actingAs($this->persona())
            ->post(route('tienda.encargo.aceptar', $encargo->refresh()))
            ->assertForbidden();

        $this->actingAs($this->persona())
            ->post(route('tienda.encargo.cancelar', $encargo->refresh()))
            ->assertForbidden();
    }

    public function test_encargar_exige_sesion(): void
    {
        $this->post(route('tienda.encargar'), ['title' => 'Algo'])->assertRedirect(route('login'));
        $this->assertSame(0, ProductionJob::count());
    }

    // ------------------------------------------------------------ backoffice

    public function test_la_cola_carga_en_el_backoffice(): void
    {
        $admin = $this->persona(User::ROL_ADMINISTRADOR);
        $encargo = app(ProductionService::class)->pedir($this->persona(), [
            'title' => '40 piezas para la clase de diseño',
        ]);

        $this->entra($admin)->get('/admin/production-jobs')
            ->assertOk()
            ->assertSee($encargo->code)
            ->assertSee('40 piezas para la clase de diseño');
    }

    public function test_cotizar_desde_el_backoffice_avisa_al_cliente(): void
    {
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $admin = $this->persona(User::ROL_ADMINISTRADOR);
        $encargo = app(ProductionService::class)->pedir($this->persona(), ['title' => 'Corte']);

        $this->entra($admin);

        Livewire::test(ListProductionJobs::class)
            ->callAction(TestAction::make('cotizar')->table($encargo), [
                'total'   => 150,
                'minutos' => 120,
                'notas'   => 'Incluye el acrílico.',
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('cotizado', $encargo->fresh()->status);
        $this->assertSame(15_000, $encargo->fresh()->quoted_total_minor);
    }

    public function test_entregar_desde_el_backoffice_cobra_y_descuenta(): void
    {
        Setting::put(Settings::COBROS_ACTIVOS, true, 'finanzas');

        $admin = $this->persona(User::ROL_ADMINISTRADOR);
        $cliente = $this->persona();
        app(ChargeService::class)->dotar($cliente, 100_000, '2026-08');

        $insumo = Supply::create([
            'name' => 'Acrílico 3 mm', 'unit' => 'hoja', 'stock' => 20,
            'last_cost' => 25_000, 'is_active' => true,
        ]);

        $produccion = app(ProductionService::class);
        $encargo = $produccion->pedir($cliente, ['title' => 'Corte de piezas']);
        $produccion->cotizar($encargo, 15_000);
        $produccion->aceptar($encargo->refresh());
        $produccion->iniciar($encargo->refresh(), $admin);
        $produccion->terminar($encargo->refresh());

        $this->entra($admin);

        Livewire::test(ListProductionJobs::class)
            ->callAction(TestAction::make('entregar')->table($encargo->refresh()), [
                'material_' . $insumo->id => 4,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame('entregado', $encargo->fresh()->status);
        $this->assertSame(16.0, (float) $insumo->fresh()->stock);
        $this->assertSame(85_000, app(LedgerService::class)->saldoDe($cliente));
    }
}
