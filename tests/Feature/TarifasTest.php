<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\RateCard;
use App\Models\RiskFamily;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Money\ChargeService;
use App\Services\Money\QuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Tarifas compuestas: tiempo, montaje, acompañamiento y material (§12). */
class TarifasTest extends TestCase
{
    use RefreshDatabase;

    private function cotizador(): QuoteService
    {
        return app(QuoteService::class);
    }

    private function persona(float $factor = 1): User
    {
        $cat = UserCategory::create([
            'slug' => 'c-' . uniqid(), 'name' => 'Categoría',
            'can_reserve' => true, 'rate_factor' => $factor,
        ]);

        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    private function equipo(): Asset
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Área']);
        $rf = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'Familia',
        ]);

        return Asset::create([
            'area_id' => $area->id, 'risk_family_id' => $rf->id,
            'name' => 'Equipo ' . uniqid(), 'kind' => 'fijo', 'status' => 'operativo',
        ]);
    }

    private function tarifa(mixed $rateable, array $datos = []): RateCard
    {
        return RateCard::create(array_merge([
            'slug' => 't-' . uniqid(), 'name' => 'Tarifa',
            'rateable_type' => $rateable ? $rateable::class : null,
            'rateable_id' => $rateable?->getKey(),
            'basis' => 'tiempo', 'unit' => 'hora',
            'price_minor' => 2000, 'rounding_minutes' => 15,
        ], $datos));
    }

    // ------------------------------------------------------------- resolución

    public function test_la_tarifa_del_equipo_gana_sobre_la_de_su_familia(): void
    {
        $e = $this->equipo();
        $this->tarifa(null, ['price_minor' => 600]);
        $this->tarifa($e->riskFamily, ['price_minor' => 2000]);
        $propia = $this->tarifa($e, ['price_minor' => 3000]);

        $this->assertSame($propia->id, RateCard::para($e)->id);
    }

    public function test_sin_tarifa_propia_hereda_la_de_la_familia(): void
    {
        $e = $this->equipo();
        $this->tarifa(null, ['price_minor' => 600]);
        $familia = $this->tarifa($e->riskFamily, ['price_minor' => 2000]);

        // Es lo que permite administrar 82 equipos cambiando 17 números.
        $this->assertSame($familia->id, RateCard::para($e)->id);
    }

    public function test_una_tarifa_inactiva_no_se_aplica(): void
    {
        $e = $this->equipo();
        $base = $this->tarifa(null, ['price_minor' => 600]);
        $this->tarifa($e->riskFamily, ['price_minor' => 2000, 'is_active' => false]);

        $this->assertSame($base->id, RateCard::para($e)->id);
    }

    public function test_un_equipo_sin_ninguna_tarifa_no_cuesta_nada(): void
    {
        // Preferible a inventar un precio: el vacío se ve y se corrige.
        $c = $this->cotizador()->cotizar($this->persona(), $this->equipo(), 60);

        $this->assertSame(0, $c->totalMenor);
        $this->assertSame([], $c->lineas);
    }

    // ------------------------------------------------------------- cálculo

    public function test_el_tiempo_se_redondea_al_bloque_de_facturacion(): void
    {
        $e = $this->equipo();
        $this->tarifa($e, ['price_minor' => 2000, 'rounding_minutes' => 15]);

        // 61 minutos se cobran como 75: el bloque es explicable, el minuto no.
        $c = $this->cotizador()->cotizar($this->persona(), $e, 61);

        $this->assertSame(2500, $c->totalMenor);
    }

    public function test_el_factor_de_la_categoria_afecta_el_tiempo(): void
    {
        $e = $this->equipo();
        $this->tarifa($e, ['price_minor' => 2000]);

        $externo = $this->cotizador()->cotizar($this->persona(2), $e, 60);
        $becado  = $this->cotizador()->cotizar($this->persona(0.5), $e, 60);

        $this->assertSame(4000, $externo->totalMenor);
        $this->assertSame(1000, $becado->totalMenor);
    }

    public function test_el_material_va_a_costo_para_todos(): void
    {
        $e = $this->equipo();
        $this->tarifa($e, ['price_minor' => 2000]);
        $filamento = $this->tarifa(null, ['basis' => 'unidad', 'unit' => 'g', 'price_minor' => 12]);

        $externo = $this->cotizador()->cotizar($this->persona(2), $e, 60, materiales: [
            ['tarifa' => $filamento, 'cantidad' => 100],
        ]);

        // 4000 de máquina (con factor 2) + 1200 de filamento (sin factor).
        $this->assertSame(5200, $externo->totalMenor);
    }

    public function test_el_montaje_se_cobra_una_sola_vez(): void
    {
        $e = $this->equipo();
        $this->tarifa($e, ['price_minor' => 2000, 'setup_minor' => 300]);

        $unaHora = $this->cotizador()->cotizar($this->persona(), $e, 60);
        $tresHoras = $this->cotizador()->cotizar($this->persona(), $e, 180);

        $this->assertSame(2300, $unaHora->totalMenor);
        $this->assertSame(6300, $tresHoras->totalMenor, 'el montaje no se triplica');
    }

    public function test_el_acompanamiento_solo_se_cobra_si_lo_hay(): void
    {
        $e = $this->equipo();
        $this->tarifa($e, ['price_minor' => 2000, 'supervision_hour_minor' => 1000]);

        $solo = $this->cotizador()->cotizar($this->persona(), $e, 60);
        $conAlguien = $this->cotizador()->cotizar($this->persona(), $e, 60, conAcompanante: true);

        $this->assertSame(2000, $solo->totalMenor);
        $this->assertSame(3000, $conAlguien->totalMenor);
    }

    public function test_el_minimo_protege_los_trabajos_muy_cortos(): void
    {
        $e = $this->equipo();
        $this->tarifa($e, ['price_minor' => 2000, 'rounding_minutes' => 15, 'minimum_minor' => 1000]);

        // 15 minutos costarían 500, pero montar y desmontar ocupa igual.
        $c = $this->cotizador()->cotizar($this->persona(), $e, 10);

        $this->assertSame(1000, $c->totalMenor);
        $lineas = $c->lineas;
        $this->assertSame('Ajuste al cobro mínimo', end($lineas)['concepto']);
    }

    public function test_el_minimo_no_arrastra_el_material(): void
    {
        $e = $this->equipo();
        $this->tarifa($e, ['price_minor' => 2000, 'minimum_minor' => 1000]);
        $material = $this->tarifa(null, ['basis' => 'unidad', 'unit' => 'g', 'price_minor' => 10]);

        $c = $this->cotizador()->cotizar($this->persona(), $e, 10, materiales: [
            ['tarifa' => $material, 'cantidad' => 50],
        ]);

        // 1000 de mínimo + 500 de material, sin que el material suba el mínimo.
        $this->assertSame(1500, $c->totalMenor);
    }

    public function test_el_deposito_es_lo_que_se_compromete_al_reservar(): void
    {
        $e = $this->equipo();
        $this->tarifa($e, ['price_minor' => 2000, 'deposit_minor' => 500]);

        $c = $this->cotizador()->cotizar($this->persona(), $e, 240);

        $this->assertSame(8000, $c->totalMenor);
        $this->assertSame(500, $c->comprometidoMenor(), 'se retiene la garantía, no el total');
    }

    public function test_sin_deposito_se_compromete_el_total_estimado(): void
    {
        $e = $this->equipo();
        $this->tarifa($e, ['price_minor' => 2000]);

        $c = $this->cotizador()->cotizar($this->persona(), $e, 120);

        $this->assertSame(4000, $c->comprometidoMenor());
    }

    public function test_la_cotizacion_avisa_cuando_la_tarifa_es_supuesta(): void
    {
        $e = $this->equipo();
        $this->tarifa($e, ['price_minor' => 2000, 'is_assumed' => true]);

        // Mientras el ancla no esté decidida, el precio se muestra como estimado.
        $this->assertTrue($this->cotizador()->cotizar($this->persona(), $e, 60)->esSupuesta);
    }

    // ------------------------------------------------------------- en pantalla

    public function test_la_ficha_del_equipo_muestra_el_desglose(): void
    {
        $e = $this->equipo();
        $this->tarifa($e, ['price_minor' => 2000, 'setup_minor' => 300, 'is_assumed' => true]);

        // Un total opaco genera desconfianza: se muestra de qué se compone.
        $this->actingAs($this->persona())
            ->get(route('reservas.show', $e))
            ->assertOk()
            ->assertSee('Cuánto cuesta')
            ->assertSee('Tiempo de máquina')
            ->assertSee('Montaje y alistamiento')
            ->assertSee('23,00')
            ->assertSee('provisional');
    }

    public function test_la_cuenta_muestra_el_saldo_y_sus_movimientos(): void
    {
        $u = $this->persona();
        app(ChargeService::class)->dotar($u, 4500, '2026-08');

        $this->actingAs($u)
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Mi saldo')
            ->assertSee('45,00')
            ->assertSee('Dotación institucional');
    }
}
