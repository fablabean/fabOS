<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Models\LedgerAccount;
use App\Models\LedgerTransaction;
use App\Models\RateCard;
use App\Models\Reservation;
use App\Models\RiskFamily;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Booking\AttendanceService;
use App\Services\Booking\BookingService;
use App\Services\Ledger\LedgerException;
use App\Services\Ledger\LedgerService;
use App\Services\Money\ChargeService;
use App\Services\Money\Quote;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** El libro contable de los FabCoins y el ciclo de cobro de una reserva (§12). */
class FabCoinsTest extends TestCase
{
    use RefreshDatabase;

    private function libro(): LedgerService
    {
        return app(LedgerService::class);
    }

    private function cobros(): ChargeService
    {
        Setting::put(Settings::COBROS_ACTIVOS, true, 'finanzas');

        return app(ChargeService::class);
    }

    private function persona(float $factor = 1): User
    {
        $cat = UserCategory::create([
            'slug' => 'c-' . uniqid(), 'name' => 'Estudiante',
            'can_reserve' => true, 'rate_factor' => $factor,
        ]);

        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    private function reserva(User $u): Reservation
    {
        $activo = Asset::create([
            'name' => 'Equipo ' . uniqid(), 'kind' => 'fijo', 'status' => 'operativo',
        ]);

        return Reservation::create([
            'reservable_type' => Asset::class, 'reservable_id' => $activo->id,
            'user_id' => $u->id, 'status' => 'confirmada',
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(2),
        ]);
    }

    // --------------------------------------------------------------- invariantes

    public function test_una_transaccion_que_no_cuadra_no_se_escribe(): void
    {
        $a = $this->libro()->cuentaDeSistema(LedgerAccount::EMISION);
        $b = $this->libro()->cuentaDe($this->persona());

        $this->expectException(LedgerException::class);

        try {
            $this->libro()->asentar('ajuste', [
                ['cuenta' => $a, 'direccion' => 'D', 'importe' => 1000],
                ['cuenta' => $b, 'direccion' => 'C', 'importe' => 900],
            ]);
        } finally {
            $this->assertSame(0, LedgerTransaction::count(), 'no debe quedar rastro');
        }
    }

    public function test_los_importes_negativos_se_rechazan(): void
    {
        $a = $this->libro()->cuentaDeSistema(LedgerAccount::EMISION);
        $b = $this->libro()->cuentaDe($this->persona());

        $this->expectException(LedgerException::class);

        // El signo lo da la dirección, nunca el importe: permitir negativos
        // abriría la puerta a una transacción que "cuadra" restando.
        $this->libro()->asentar('ajuste', [
            ['cuenta' => $a, 'direccion' => 'D', 'importe' => -500],
            ['cuenta' => $b, 'direccion' => 'C', 'importe' => -500],
        ]);
    }

    public function test_repetir_la_misma_operacion_no_cobra_dos_veces(): void
    {
        $u = $this->persona();

        $primera = app(ChargeService::class)->dotar($u, 5000, '2026-08');
        $segunda = app(ChargeService::class)->dotar($u, 5000, '2026-08');

        $this->assertSame($primera->id, $segunda->id, 'el doble clic devuelve la misma');
        $this->assertSame(5000, $this->libro()->saldoDe($u));
    }

    public function test_el_saldo_se_deriva_de_los_asientos(): void
    {
        $u = $this->persona();

        app(ChargeService::class)->dotar($u, 10000, '2026-08');
        app(ChargeService::class)->bonificar($u, 2500, 'Apoyo en el curso de láser');

        $this->assertSame(12500, $this->libro()->saldoDe($u));

        // Contrapartida: la emisión salió en negativo por lo mismo que entregó.
        $this->assertSame(-12500, $this->libro()->cuentaDeSistema(LedgerAccount::EMISION)->saldoMenor());
    }

    public function test_la_cadena_de_hashes_detecta_una_alteracion(): void
    {
        $u = $this->persona();
        app(ChargeService::class)->dotar($u, 1000, '2026-08');
        app(ChargeService::class)->dotar($u, 2000, '2026-09');
        app(ChargeService::class)->dotar($u, 3000, '2026-10');

        $this->assertTrue($this->libro()->verificarCadena()['intacta']);

        // Alguien edita el pasado directamente en la base de datos.
        $segunda = LedgerTransaction::orderBy('id')->skip(1)->first();
        $segunda->updateQuietly(['memo' => 'Dotación inventada']);

        $veredicto = $this->libro()->verificarCadena();

        $this->assertFalse($veredicto['intacta']);
        $this->assertSame($segunda->id, $veredicto['rota_en']);
    }

    // ------------------------------------------------------- ciclo de la reserva

    public function test_el_cobro_apagado_no_mueve_saldo(): void
    {
        Setting::put(Settings::COBROS_ACTIVOS, false, 'finanzas');

        $u = $this->persona();
        app(ChargeService::class)->dotar($u, 10000, '2026-08');

        $r = $this->reserva($u);
        $this->assertNull(app(ChargeService::class)->comprometer($r, new Quote([], 5000)));
        $this->assertSame(10000, $this->libro()->saldoDe($u), 'el saldo queda intacto');
    }

    public function test_reservar_retiene_el_compromiso_en_garantias(): void
    {
        $cobros = $this->cobros();
        $u = $this->persona();
        $cobros->dotar($u, 10000, '2026-08');

        $cobros->comprometer($this->reserva($u), new Quote([], 4000));

        $this->assertSame(6000, $this->libro()->saldoDe($u));
        $this->assertSame(4000, $this->libro()->cuentaDeSistema(LedgerAccount::GARANTIAS)->saldoMenor());
    }

    public function test_no_se_puede_comprometer_mas_de_lo_que_hay(): void
    {
        $cobros = $this->cobros();
        $u = $this->persona();
        $cobros->dotar($u, 1000, '2026-08');

        $this->expectException(LedgerException::class);
        $cobros->comprometer($this->reserva($u), new Quote([], 4000));
    }

    public function test_usar_menos_de_lo_reservado_devuelve_la_diferencia(): void
    {
        $cobros = $this->cobros();
        $u = $this->persona();
        $cobros->dotar($u, 10000, '2026-08');
        $r = $this->reserva($u);

        $cobros->comprometer($r, new Quote([], 4000));   // reservó dos horas
        $cobros->liquidar($r, 1500);                     // usó menos de una

        // Paga lo usado y recupera el resto: 10000 - 1500.
        $this->assertSame(8500, $this->libro()->saldoDe($u));
        $this->assertSame(0, $this->libro()->cuentaDeSistema(LedgerAccount::GARANTIAS)->saldoMenor());
        $this->assertSame(1500, $this->libro()->cuentaDeSistema(LedgerAccount::INGRESO)->saldoMenor());
    }

    public function test_pasarse_del_tiempo_cobra_la_diferencia(): void
    {
        $cobros = $this->cobros();
        $u = $this->persona();
        $cobros->dotar($u, 10000, '2026-08');
        $r = $this->reserva($u);

        $cobros->comprometer($r, new Quote([], 4000));
        $cobros->liquidar($r, 6000);   // el trabajo se alargó

        $this->assertSame(4000, $this->libro()->saldoDe($u));
        $this->assertSame(0, $this->libro()->cuentaDeSistema(LedgerAccount::GARANTIAS)->saldoMenor());
        $this->assertSame(6000, $this->libro()->cuentaDeSistema(LedgerAccount::INGRESO)->saldoMenor());
    }

    public function test_una_reserva_que_no_se_uso_se_devuelve_integra(): void
    {
        $cobros = $this->cobros();
        $u = $this->persona();
        $cobros->dotar($u, 10000, '2026-08');
        $r = $this->reserva($u);

        $cobros->comprometer($r, new Quote([], 4000));
        $cobros->devolver($r, 'El equipo entró a mantenimiento');

        $this->assertSame(10000, $this->libro()->saldoDe($u));
        $this->assertSame(0, $cobros->comprometidoDe($r));
    }

    public function test_reservar_y_cerrar_antes_cobra_solo_lo_usado(): void
    {
        $cobros = $this->cobros();

        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Área']);
        $familia = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'FDM',
            'required_course_level' => 'byte', 'requires_companion' => false,
        ]);
        $equipo = Asset::create([
            'area_id' => $area->id, 'risk_family_id' => $familia->id,
            'name' => 'Impresora ' . uniqid(), 'kind' => 'fijo', 'status' => 'operativo',
            'is_reservable' => true, 'min_minutes' => 30,
            'autonomous_minutes' => 240, 'max_minutes' => 720,
        ]);
        RateCard::create([
            'slug' => 't-' . uniqid(), 'name' => 'Tarifa', 'basis' => 'tiempo', 'unit' => 'hora',
            'rateable_type' => Asset::class, 'rateable_id' => $equipo->id,
            'price_minor' => 2000, 'rounding_minutes' => 15,
        ]);

        $u = $this->persona();
        Certifab::create(['user_id' => $u->id, 'risk_family_id' => $familia->id, 'level' => 'byte']);
        $cobros->dotar($u, 20000, '2026-08');

        // Reserva tres horas: se comprometen 60,00.
        $desde = now()->addMinutes(5);
        $reserva = app(BookingService::class)->reservar($u, $equipo, $desde, $desde->copy()->addHours(3));

        $this->assertSame(6000, $reserva->estimated_cost_minor);
        $this->assertSame(14000, $this->libro()->saldoDe($u), 'el compromiso ya salió de su cuenta');

        // Llega, trabaja un rato y cierra: se cobra solo esa hora.
        $asistencia = app(AttendanceService::class);
        $asistencia->checkIn($reserva->refresh());
        $this->travel(50)->minutes();
        $cerrada = $asistencia->checkOut($reserva->refresh());

        $this->assertSame(2000, $cerrada->actual_cost_minor, '50 minutos redondean a una hora');
        $this->assertSame(18000, $this->libro()->saldoDe($u), 'le devolvieron lo que no usó');
        $this->assertSame(0, $this->libro()->cuentaDeSistema(LedgerAccount::GARANTIAS)->saldoMenor());
    }

    public function test_la_dotacion_del_mes_se_puede_repetir_sin_miedo(): void
    {
        $u = $this->persona();
        $u->category->update(['allowance_minor' => 50000]);

        $this->artisan('fabos:dotar', ['--periodo' => '2026-08'])->assertSuccessful();
        $this->artisan('fabos:dotar', ['--periodo' => '2026-08'])->assertSuccessful();

        // Correrlo dos veces —planificador nervioso, reintento manual— no debe
        // duplicar el saldo de nadie.
        $this->assertSame(50000, $this->libro()->saldoDe($u));

        // Otro periodo sí es otra dotación.
        $this->artisan('fabos:dotar', ['--periodo' => '2026-09'])->assertSuccessful();
        $this->assertSame(100000, $this->libro()->saldoDe($u));
    }

    public function test_la_dotacion_no_alcanza_a_quien_esta_inactivo(): void
    {
        $u = $this->persona();
        $u->category->update(['allowance_minor' => 50000]);
        $u->update(['status' => 'inactivo']);

        $this->artisan('fabos:dotar', ['--periodo' => '2026-08'])->assertSuccessful();

        $this->assertSame(0, $this->libro()->saldoDe($u));
    }

    public function test_todo_el_ciclo_deja_el_libro_cuadrado(): void
    {
        $cobros = $this->cobros();
        $u = $this->persona();
        $cobros->dotar($u, 10000, '2026-08');
        $r = $this->reserva($u);
        $cobros->comprometer($r, new Quote([], 4000));
        $cobros->liquidar($r, 2500);

        // La suma de todas las cuentas debe ser cero: nada se creó ni se perdió
        // por el camino. Si esta prueba falla, hay dinero inventado.
        $total = LedgerAccount::all()->sum(fn (LedgerAccount $c) => $c->saldoMenor());

        $this->assertSame(0, $total);
        $this->assertTrue($this->libro()->verificarCadena()['intacta']);
    }
}
