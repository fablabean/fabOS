<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Asset;
use App\Models\Budget;
use App\Models\Certifab;
use App\Models\Project;
use App\Models\RateCard;
use App\Models\RiskFamily;
use App\Models\Supply;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Booking\AttendanceService;
use App\Services\Booking\BookingService;
use App\Services\Projects\CostingService;
use App\Services\Projects\ProjectService;
use App\Services\Purchasing\PurchasingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Costeo real de un proyecto contra lo acordado (§11, §12). */
class CosteoProyectoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function costeo(): CostingService
    {
        return app(CostingService::class);
    }

    private function persona(): User
    {
        $cat = UserCategory::firstOrCreate(
            ['slug' => 'estudiante'],
            ['name' => 'Estudiante', 'can_reserve' => true, 'rate_factor' => 1],
        );

        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    private function proyecto(int $valor = 5_000_000): Project
    {
        return app(ProjectService::class)->registrarIdea([
            'name'         => 'Señalética para el campus',
            'organization' => 'Bienestar Universitario',
            'agreed_value' => $valor,
        ]);
    }

    private function equipo(): Asset
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Corte láser']);
        $rf = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'Láser CO₂',
            'required_course_level' => 'byte', 'requires_companion' => false,
        ]);

        $equipo = Asset::create([
            'area_id' => $area->id, 'risk_family_id' => $rf->id,
            'name' => 'Láser ' . uniqid(), 'kind' => 'fijo', 'status' => 'operativo',
            'is_reservable' => true, 'min_minutes' => 30,
            'autonomous_minutes' => 480, 'max_minutes' => 720,
        ]);

        // 20 FBC la hora: la tarifa ancla del laboratorio.
        RateCard::create([
            'slug' => 't-' . uniqid(), 'name' => 'Láser', 'basis' => 'tiempo', 'unit' => 'hora',
            'rateable_type' => Asset::class, 'rateable_id' => $equipo->id,
            'price_minor' => 2000, 'rounding_minutes' => 15,
        ]);

        return $equipo;
    }

    /** Una sesión cargada al proyecto. */
    private function sesion(Project $p, User $u, Asset $equipo, int $minutos, array $materiales = []): void
    {
        Certifab::firstOrCreate(
            ['user_id' => $u->id, 'risk_family_id' => $equipo->risk_family_id],
            ['level' => 'byte'],
        );

        $inicio = now()->addMinutes(5);
        $reserva = app(BookingService::class)->reservar($u, $equipo, $inicio, $inicio->copy()->addHours(3));
        $reserva->update(['project_id' => $p->id]);

        $asistencia = app(AttendanceService::class);
        $asistencia->checkIn($reserva->refresh());
        $this->travel($minutos)->minutes();
        $asistencia->checkOut($reserva->refresh(), $materiales);
        $this->travelBack();
    }

    // ------------------------------------------------------------- máquina

    public function test_una_sesion_cargada_al_proyecto_cuesta_tiempo_de_maquina(): void
    {
        $p = $this->proyecto();
        $this->sesion($p, $this->persona(), $this->equipo(), 60);

        $costo = $this->costeo()->costear($p->fresh());

        // Una hora de láser: 20 FBC → 20.000 pesos a la tasa configurada.
        $this->assertSame(20_000, $costo['maquina']);
        $this->assertSame(20_000, $costo['total']);
    }

    public function test_se_cuenta_lo_usado_y_no_lo_reservado(): void
    {
        $p = $this->proyecto();
        // Reserva tres horas, usa una.
        $this->sesion($p, $this->persona(), $this->equipo(), 60);

        // Un bloque de cuatro horas que se cerró en una costó una.
        $this->assertSame(20_000, $this->costeo()->costear($p->fresh())['maquina']);
    }

    public function test_una_reserva_sin_proyecto_no_le_carga_nada(): void
    {
        $p = $this->proyecto();
        $u = $this->persona();
        $equipo = $this->equipo();

        Certifab::create(['user_id' => $u->id, 'risk_family_id' => $equipo->risk_family_id, 'level' => 'byte']);
        $inicio = now()->addMinutes(5);
        $reserva = app(BookingService::class)->reservar($u, $equipo, $inicio, $inicio->copy()->addHour());
        app(AttendanceService::class)->checkIn($reserva->refresh());
        app(AttendanceService::class)->checkOut($reserva->refresh());

        $this->assertSame(0, $this->costeo()->costear($p->fresh())['maquina']);
    }

    // ------------------------------------------------------------ material

    public function test_el_material_se_valora_al_costo_de_compra(): void
    {
        $p = $this->proyecto();
        $equipo = $this->equipo();
        $insumo = Supply::create([
            'area_id' => $equipo->area_id, 'name' => 'Acrílico', 'unit' => 'hoja',
            'stock' => 100, 'last_cost' => 25_000, 'is_active' => true,
        ]);

        $this->sesion($p, $this->persona(), $equipo, 60, [$insumo->id => 4]);

        // Al costo con que se repone (4 × 25.000), no al precio de la tienda:
        // para el proyecto interesa lo que costó, no lo que se le cobraría a un
        // tercero por vendérselo.
        $costo = $this->costeo()->costear($p->fresh());

        $this->assertSame(100_000, $costo['material']);

        // Y no se cuenta dos veces: la liquidación de la reserva ya cobró ese
        // material a precio de tienda, así que del tiempo de máquina se
        // descuenta. Queda una hora de láser (20.000) más el acrílico a costo.
        $this->assertSame(20_000, $costo['maquina']);
        $this->assertSame(120_000, $costo['total'], 'material más tiempo de máquina');
    }

    // ------------------------------------------------------------- compras

    public function test_una_compra_recibida_cuenta_y_una_aprobada_todavia_no(): void
    {
        $p = $this->proyecto();
        $u = $this->persona();
        $presupuesto = Budget::create([
            'name' => 'Insumos', 'year' => 2026, 'amount' => 10_000_000, 'status' => 'vigente',
        ]);

        $compras = app(PurchasingService::class);

        $recibida = $compras->abrirCarrito($u, $presupuesto);
        $recibida->update(['project_id' => $p->id]);
        $linea = $compras->agregar($recibida, 'Vinilo', 10, 8_000);
        $compras->enviar($recibida);
        $compras->aprobar($recibida->refresh(), $u);
        $compras->recibir($recibida->refresh(), [$linea->id => 10], $u);

        $pendiente = $compras->abrirCarrito($u, $presupuesto);
        $pendiente->update(['project_id' => $p->id]);
        $compras->agregar($pendiente, 'Tornillería', 1, 500_000);
        $compras->enviar($pendiente);
        $compras->aprobar($pendiente->refresh(), $u);

        $costo = $this->costeo()->costear($p->fresh());

        // 10 × 8.000 + IVA = 95.200. Lo aprobado y sin llegar aún no costó.
        $this->assertSame(95_200, $costo['compras']);
    }

    // --------------------------------------------------------------- gente

    public function test_las_horas_del_equipo_cuestan_a_tarifa_de_referencia(): void
    {
        $p = $this->proyecto();

        $p->timeLogs()->create([
            'user_id' => $this->persona()->id,
            'worked_on' => now()->toDateString(),
            'hours' => 8,
            'activity' => 'Diseño y preparación de archivos',
        ]);

        // 8 × 45.000, la tarifa configurada.
        $this->assertSame(360_000, $this->costeo()->costear($p->fresh())['gente']);
    }

    public function test_el_costo_por_hora_queda_congelado(): void
    {
        $p = $this->proyecto();
        $log = $p->timeLogs()->create([
            'worked_on' => now()->toDateString(), 'hours' => 2, 'external_name' => 'Diseñador externo',
        ]);

        config(['fabos.money.hourly_cost' => 90_000]);

        // Subir la tarifa el año que viene no debe reescribir lo que costó un
        // proyecto ya cerrado.
        $this->assertSame(45_000, $log->fresh()->hourly_cost);
        $this->assertSame(90_000, $this->costeo()->costear($p->fresh())['gente'], '2 × 45.000');
    }

    public function test_una_hora_externa_se_registra_sin_cuenta(): void
    {
        $p = $this->proyecto();
        $log = $p->timeLogs()->create([
            'worked_on' => now()->toDateString(), 'hours' => 3,
            'external_name' => 'Acrílicos del Norte', 'hourly_cost' => 60_000,
        ]);

        $this->assertSame('Acrílicos del Norte', $log->quien());
        $this->assertSame(180_000, $log->costo());
    }

    // -------------------------------------------------------------- margen

    public function test_el_margen_compara_lo_acordado_con_lo_que_costo(): void
    {
        $p = $this->proyecto(1_000_000);
        $p->timeLogs()->create(['worked_on' => now()->toDateString(), 'hours' => 10]);

        $costo = $this->costeo()->costear($p->fresh());

        $this->assertSame(450_000, $costo['total']);
        $this->assertSame(550_000, $costo['margen']);
        $this->assertSame(55.0, $costo['margen_pct']);
    }

    public function test_un_proyecto_que_costo_mas_de_lo_acordado_da_margen_negativo(): void
    {
        $p = $this->proyecto(100_000);
        $p->timeLogs()->create(['worked_on' => now()->toDateString(), 'hours' => 10]);

        $costo = $this->costeo()->costear($p->fresh());

        // Saberlo es justo el punto: un proyecto que cuesta más de lo que deja
        // no es un fracaso si se sabe, es información para la próxima cotización.
        $this->assertSame(-350_000, $costo['margen']);
    }

    public function test_un_proyecto_sin_valor_acordado_no_divide_por_cero(): void
    {
        $p = $this->proyecto(0);
        $p->timeLogs()->create(['worked_on' => now()->toDateString(), 'hours' => 4]);

        $costo = $this->costeo()->costear($p->fresh());

        $this->assertSame(180_000, $costo['total']);
        $this->assertNull($costo['margen_pct'], 'sin valor acordado no hay porcentaje');
    }

    public function test_el_desglose_permite_explicar_cada_peso(): void
    {
        $p = $this->proyecto();
        $equipo = $this->equipo();
        $insumo = Supply::create([
            'area_id' => $equipo->area_id, 'name' => 'Acrílico', 'unit' => 'hoja',
            'stock' => 100, 'last_cost' => 25_000, 'is_active' => true,
        ]);

        $this->sesion($p, $this->persona(), $equipo, 60, [$insumo->id => 2]);
        $p->timeLogs()->create(['worked_on' => now()->toDateString(), 'hours' => 4]);

        $detalle = $this->costeo()->costear($p->fresh())['detalle'];

        // Un total sin desglose no se puede defender ante nadie.
        $this->assertCount(1, $detalle['reservas']);
        $this->assertCount(1, $detalle['materiales']);
        $this->assertCount(1, $detalle['horas']);
        $this->assertSame('Acrílico', $detalle['materiales']->first()['insumo']);
        $this->assertSame(50_000, $detalle['materiales']->first()['costo']);
    }
}
