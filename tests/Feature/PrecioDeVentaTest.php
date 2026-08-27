<?php

namespace Tests\Feature;

use App\Filament\Resources\ServiceOfferings\Pages\CreateServiceOffering;
use App\Filament\Resources\ServiceOfferings\Pages\EditServiceOffering;
use App\Filament\Resources\Supplies\Pages\CreateSupply;
use App\Filament\Resources\Supplies\Pages\EditSupply;
use App\Models\RateCard;
use App\Models\ServiceOffering;
use App\Models\Supply;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Money\PricingService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El precio de venta al público no es el costo (§12, §14).
 *
 * Sin un precio propio, la tienda estimaba con el costo de compra y el margen.
 * Sirve para que un rollo de filamento tenga precio desde el primer día, pero
 * es falso para lo que se fabrica: una pieza impresa se acababa vendiendo por
 * el precio del plástico que lleva, sin el diseño, la máquina ni las horas.
 *
 * El precio se escribe donde se decide vender —la ficha del insumo— y se guarda
 * en la **tarifa**, que es lo que ya leen el carrito, la venta de mostrador y el
 * costeo de un proyecto. Un segundo número para lo mismo es un número que algún
 * día dirá otra cosa.
 */
class PrecioDeVentaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config([
            'fabos.currency.peso_rate'     => 1000,
            'fabos.currency.minor_units'   => 100,
            'fabos.currency.retail_margin' => 0.30,
        ]);

        UserCategory::firstOrCreate(
            ['slug' => 'invitado'],
            ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1, 'client_kind' => 'externo'],
        );
    }

    private function insumo(array $cambios = []): Supply
    {
        return Supply::create(array_merge([
            'name' => 'DinoClick', 'unit' => 'unidad', 'stock' => 10,
            'last_cost' => 5_000, 'is_active' => true, 'is_public' => true,
        ], $cambios));
    }

    private function admin(): User
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

        return $u->fresh();
    }

    // ------------------------------------------------------------- el precio

    /** Sin precio propio, la tienda estima. Y lo dice. */
    public function test_sin_precio_propio_se_estima_del_costo(): void
    {
        $precios = app(PricingService::class);
        $insumo = $this->insumo();

        $this->assertNull($precios->precioEnPesosDe($insumo));
        $this->assertTrue($precios->esDerivado($insumo));

        // 5.000 + 30 % = 6.500
        $this->assertSame(6_500, $precios->aPesos($precios->precioDe($insumo)));
    }

    /** Con precio propio manda el precio, no el costo. */
    public function test_el_precio_de_venta_manda_sobre_el_costo(): void
    {
        $precios = app(PricingService::class);
        $insumo = $this->insumo();

        $precios->fijarPrecioEnPesos($insumo, 25_000);

        $this->assertSame(25_000, $precios->precioEnPesosDe($insumo->fresh()));
        $this->assertFalse($precios->esDerivado($insumo->fresh()));
        $this->assertSame(2_500, $precios->precioDe($insumo->fresh()));
    }

    /** Cambiar el costo no mueve un precio que alguien decidió. */
    public function test_cambiar_el_costo_no_mueve_el_precio_decidido(): void
    {
        $precios = app(PricingService::class);
        $insumo = $this->insumo();

        $precios->fijarPrecioEnPesos($insumo, 25_000);
        $insumo->forceFill(['last_cost' => 9_000])->save();

        $this->assertSame(25_000, $precios->precioEnPesosDe($insumo->fresh()));
    }

    /**
     * Vaciarlo desactiva la tarifa, no la borra: queda el rastro de que hubo un
     * precio y de cuál era, que es lo que se pregunta cuando alguien reclama lo
     * que le cobraron el mes pasado.
     */
    public function test_vaciar_el_precio_vuelve_al_estimado_sin_borrar_el_rastro(): void
    {
        $precios = app(PricingService::class);
        $insumo = $this->insumo();

        $precios->fijarPrecioEnPesos($insumo, 25_000);
        $precios->fijarPrecioEnPesos($insumo->fresh(), null);

        $this->assertNull($precios->precioEnPesosDe($insumo->fresh()));
        $this->assertTrue($precios->esDerivado($insumo->fresh()));
        $this->assertDatabaseHas('rate_cards', [
            'rateable_id' => $insumo->id, 'price_minor' => 2_500, 'is_active' => false,
        ]);
    }

    /** Y volver a ponerlo reusa la misma tarifa, no crea una segunda. */
    public function test_volver_a_ponerlo_no_duplica_la_tarifa(): void
    {
        $precios = app(PricingService::class);
        $insumo = $this->insumo();

        $precios->fijarPrecioEnPesos($insumo, 25_000);
        $precios->fijarPrecioEnPesos($insumo->fresh(), null);
        $precios->fijarPrecioEnPesos($insumo->fresh(), 30_000);

        $this->assertSame(1, RateCard::where('rateable_id', $insumo->id)
            ->where('rateable_type', Supply::class)->count());
        $this->assertSame(30_000, $precios->precioEnPesosDe($insumo->fresh()));
    }

    // ---------------------------------------------------------- desde la ficha

    public function test_se_pone_al_crear_el_insumo(): void
    {
        $this->admin();

        Livewire::test(CreateSupply::class)
            ->fillForm([
                'name' => 'DinoClick', 'unit' => 'unidad',
                'precio_venta' => 25_000,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $insumo = Supply::where('name', 'DinoClick')->firstOrFail();

        $this->assertSame(25_000, app(PricingService::class)->precioEnPesosDe($insumo));
    }

    public function test_se_edita_desde_la_ficha_y_se_lee_de_vuelta(): void
    {
        $this->admin();
        $insumo = $this->insumo();

        app(PricingService::class)->fijarPrecioEnPesos($insumo, 25_000);

        Livewire::test(EditSupply::class, ['record' => $insumo->getKey()])
            ->assertFormSet(['precio_venta' => 25_000])
            ->fillForm(['precio_venta' => 32_000])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(32_000, app(PricingService::class)->precioEnPesosDe($insumo->fresh()));
    }

    // ------------------------------------------------------------ servicios

    /**
     * El precio de un servicio también se escribe en pesos.
     *
     * Pedirlo en centésimas de FabCoin obligaba a traducir de cabeza —30.000
     * pesos son 3.000 centésimas— y un cero de más ahí sale publicado en la
     * tienda. El libro sigue guardando unidades menores.
     */
    public function test_el_precio_de_un_servicio_se_escribe_en_pesos(): void
    {
        $this->admin();

        Livewire::test(CreateServiceOffering::class)
            ->fillForm([
                'name' => 'Corte láser en MDF de 3 mm', 'unit' => 'hoja',
                'price_minor' => 30_000,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $servicio = ServiceOffering::where('name', 'Corte láser en MDF de 3 mm')->firstOrFail();

        $this->assertSame(3_000, (int) $servicio->price_minor);
    }

    /** Y se lee de vuelta en pesos, no en centésimas. */
    public function test_el_precio_de_un_servicio_se_lee_de_vuelta_en_pesos(): void
    {
        $this->admin();

        $servicio = ServiceOffering::create([
            'name' => 'Corte láser', 'unit' => 'hoja',
            'price_minor' => 3_000, 'is_active' => true, 'is_public' => true,
        ]);

        Livewire::test(EditServiceOffering::class, ['record' => $servicio->getKey()])
            ->assertFormSet(['price_minor' => 30_000]);
    }

    // ------------------------------------------------------- cuál moneda manda

    private function trozoDeLaFicha(string $html): string
    {
        $donde = strpos($html, 'DinoClick');

        $this->assertNotFalse($donde, 'El insumo no aparece en la tienda.');

        return substr($html, $donde, 900);
    }

    /**
     * Quien entra de fuera piensa en pesos: un precio en una moneda que no
     * conoce no le dice si puede pagarlo.
     */
    public function test_a_quien_entra_sin_cuenta_se_le_habla_en_pesos(): void
    {
        $insumo = $this->insumo();
        app(PricingService::class)->fijarPrecioEnPesos($insumo, 25_000);

        $trozo = $this->trozoDeLaFicha($this->get('/tienda')->assertOk()->getContent());

        $this->assertMatchesRegularExpression(
            '/\$25\.000.*?25 FBC/s',
            $trozo,
            'Los pesos van primero para quien no tiene cuenta.',
        );
    }

    /** Quien tiene cuenta paga con FabCoins: ese número es el que le mueve el saldo. */
    public function test_a_quien_tiene_cuenta_se_le_habla_en_fabcoins(): void
    {
        $insumo = $this->insumo();
        app(PricingService::class)->fijarPrecioEnPesos($insumo, 25_000);

        $quien = User::create([
            'name' => 'Estudiante', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        $trozo = $this->trozoDeLaFicha(
            $this->actingAs($quien)->get('/tienda')->assertOk()->getContent()
        );

        $this->assertMatchesRegularExpression(
            '/25 FBC.*?\$25\.000/s',
            $trozo,
            'Los FabCoins van primero para quien tiene cuenta.',
        );
    }
}
