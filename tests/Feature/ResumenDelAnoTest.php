<?php

namespace Tests\Feature;

use App\Models\Budget;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Purchasing\PurchasingService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El resumen del año (§13).
 *
 * Con seis presupuestos separados, saber cuánto queda en total obligaba a sumar
 * seis cifras a mano. Es justo la cuenta que sale mal cuando hay prisa.
 *
 * Lo que este archivo defiende de verdad es la **separación**: un presupuesto
 * de venta es una meta de ingresos, no plata asignada. Sumarlo al aprobado
 * diría que hay diez millones más para gastar de los que hay, y esa mentira no
 * se descubre hasta que se intenta gastarlos.
 */
class ResumenDelAnoTest extends TestCase
{
    use RefreshDatabase;

    private int $ano;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->ano = (int) now()->year;

        UserCategory::firstOrCreate(
            ['slug' => 'invitado'],
            ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1, 'client_kind' => 'externo'],
        );
    }

    private function presupuesto(array $cambios = []): Budget
    {
        return Budget::create(array_merge([
            'name' => 'Materiales', 'year' => $this->ano,
            'amount' => 10_000_000, 'status' => 'vigente',
        ], $cambios));
    }

    private function quien(): User
    {
        return User::create([
            'name' => 'Quien pide', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
    }

    /** Una solicitud aprobada, sin impuesto, contra un presupuesto. */
    private function comprometer(Budget $p, int $valor): PurchaseRequest
    {
        $compras = app(PurchasingService::class);
        $s = $compras->abrirCarrito($this->quien(), null, 'Algo');
        $s->update(['tax_rate' => 0]);
        $compras->agregar($s, 'Una cosa', 1, $valor);

        return $compras->aprobar($s->fresh()->load('items'), $this->quien(), $p);
    }

    // ------------------------------------------------------------- las sumas

    public function test_suma_los_presupuestos_de_gasto_del_ano(): void
    {
        $this->presupuesto(['name' => 'Materiales', 'amount' => 45_000_000]);
        $this->presupuesto(['name' => 'Licencias', 'amount' => 5_000_000]);

        $resumen = Budget::resumenDelAno($this->ano);

        $this->assertSame(2, $resumen['gasto']['cuantos']);
        $this->assertSame(50_000_000, $resumen['gasto']['aprobado']);
        $this->assertSame(50_000_000, $resumen['gasto']['disponible']);
    }

    /**
     * Lo importante de todo esto: la meta de ventas va aparte.
     *
     * Si entrara en el aprobado, el resumen diría que hay diez millones más
     * para gastar de los que la Universidad giró.
     */
    public function test_la_meta_de_ventas_no_entra_en_lo_que_hay_para_gastar(): void
    {
        $this->presupuesto(['name' => 'Materiales', 'amount' => 45_000_000]);
        $this->presupuesto(['name' => 'Venta servicios', 'kind' => 'venta', 'amount' => 10_000_000]);

        $resumen = Budget::resumenDelAno($this->ano);

        $this->assertSame(45_000_000, $resumen['gasto']['aprobado']);
        $this->assertSame(1, $resumen['gasto']['cuantos']);

        $this->assertSame(10_000_000, $resumen['venta']['meta']);
        $this->assertSame(1, $resumen['venta']['cuantos']);
    }

    /** Un borrador todavía no es plata que se pueda comprometer. */
    public function test_los_borradores_y_los_cerrados_no_cuentan(): void
    {
        $this->presupuesto(['amount' => 45_000_000]);
        $this->presupuesto(['name' => 'Sin aprobar', 'amount' => 9_000_000, 'status' => 'borrador']);
        $this->presupuesto(['name' => 'Del año pasado', 'amount' => 9_000_000, 'status' => 'cerrado']);

        $this->assertSame(45_000_000, Budget::resumenDelAno($this->ano)['gasto']['aprobado']);
    }

    public function test_otro_ano_no_se_mezcla(): void
    {
        $this->presupuesto(['amount' => 45_000_000]);
        $this->presupuesto(['name' => 'El que viene', 'year' => $this->ano + 1, 'amount' => 9_000_000]);

        $this->assertSame(45_000_000, Budget::resumenDelAno($this->ano)['gasto']['aprobado']);
    }

    // ------------------------------------------------- comprometido y ejecutado

    public function test_lo_comprometido_baja_el_disponible_del_ano(): void
    {
        $p = $this->presupuesto(['amount' => 45_000_000]);
        $this->comprometer($p, 2_000_000);

        $resumen = Budget::resumenDelAno($this->ano);

        $this->assertSame(2_000_000, $resumen['gasto']['comprometido']);
        $this->assertSame(43_000_000, $resumen['gasto']['disponible']);
    }

    /** El arranque se dice aparte: es lo único que no se puede rastrear. */
    public function test_el_ejecutado_de_arranque_cuenta_y_se_dice(): void
    {
        $this->presupuesto(['amount' => 45_000_000, 'opening_executed' => 19_000_000]);

        $resumen = Budget::resumenDelAno($this->ano);

        $this->assertSame(19_000_000, $resumen['gasto']['ejecutado']);
        $this->assertSame(19_000_000, $resumen['gasto']['arranque']);
        $this->assertSame(26_000_000, $resumen['gasto']['disponible']);
    }

    /** Sin presupuestos, el porcentaje no revienta ni miente. */
    public function test_sin_presupuestos_no_divide_por_cero(): void
    {
        $resumen = Budget::resumenDelAno($this->ano);

        $this->assertSame(0, $resumen['gasto']['aprobado']);
        $this->assertSame(0.0, $resumen['gasto']['usado']);
        $this->assertSame(0.0, $resumen['venta']['avance']);
    }

    /**
     * En enero, antes de que la Universidad asigne lo del año nuevo, un panel
     * en cero encima de una tabla con seis presupuestos parece una averia.
     */
    public function test_si_el_ano_en_curso_no_tiene_nada_se_resume_el_ultimo_que_si(): void
    {
        $this->presupuesto(['year' => $this->ano - 1, 'amount' => 45_000_000]);

        $panel = new \App\Filament\Resources\Budgets\Widgets\ResumenDelAno;

        $this->assertSame($this->ano - 1, $panel->ano());
        $this->assertSame(45_000_000, $panel->getResumen()['gasto']['aprobado']);
    }

    // ------------------------------------------------------------ la pantalla

    public function test_el_resumen_se_ve_encima_del_listado(): void
    {
        $u = User::create([
            'name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        $this->presupuesto(['amount' => 45_000_000]);
        $this->presupuesto(['name' => 'Venta servicios', 'kind' => 'venta', 'amount' => 10_000_000]);

        $this->get('/admin/budgets')
            ->assertOk()
            ->assertSee('El año ' . $this->ano)
            ->assertSee('$45.000.000')
            ->assertSee('lo que esperamos vender');
    }
}
