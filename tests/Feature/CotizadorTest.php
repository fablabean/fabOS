<?php

namespace Tests\Feature;

use App\Filament\Pages\Cotizador;
use App\Models\Area;
use App\Models\Asset;
use App\Models\RateCard;
use App\Models\RiskFamily;
use App\Models\User;
use App\Models\UserCategory;
use App\Support\FactoresDeSesion;
use App\Services\Auth\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El cotizador (§12).
 *
 * La conversación de todos los días: alguien llega con una pieza y el
 * colaborador tiene que decir un número. El número a ojo es el problema —cada
 * quien dice uno distinto, y ninguno coincide con el que luego cobra el
 * sistema—. Lo que se comprueba aquí es que sale de la MISMA tarifa.
 */
class CotizadorTest extends TestCase
{
    use RefreshDatabase;

    private function impresora(): Asset
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Impresión 3D']);

        $familia = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'FDM',
            'required_course_level' => 'byte', 'requires_companion' => false,
        ]);

        $equipo = Asset::create([
            'name' => 'Bambu X1', 'slug' => 'bambu-' . uniqid(),
            'area_id' => $area->id, 'risk_family_id' => $familia->id,
            'kind' => 'fijo', 'status' => 'operativo', 'is_reservable' => true,
        ]);

        RateCard::create([
            'slug' => 'tarifa-' . uniqid(), 'name' => 'Impresión FDM',
            'rateable_type' => Asset::class, 'rateable_id' => $equipo->id,
            'basis' => 'tiempo', 'unit' => 'hora',
            'price_minor' => 1000, 'rounding_minutes' => 30, 'is_active' => true,
        ]);

        return $equipo;
    }

    private function colaborador(): User
    {
        $u = User::create([
            'name' => 'Asesor ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    public function test_calcula_el_tiempo_de_maquina(): void
    {
        $equipo = $this->impresora();
        $this->colaborador();

        $pagina = Livewire::test(Cotizador::class)
            ->set('datos.asset_id', $equipo->id)
            ->set('datos.minutos', 60);

        $this->assertSame(1000, $pagina->instance()->cotizacion['total']);
    }

    /**
     * El bloque de facturación redondea hacia arriba. Cobrar al minuto exacto
     * invita a discutir por dos minutos; el bloque es explicable.
     */
    public function test_redondea_al_bloque_de_facturacion(): void
    {
        $equipo = $this->impresora();
        $this->colaborador();

        $pagina = Livewire::test(Cotizador::class)
            ->set('datos.asset_id', $equipo->id)
            ->set('datos.minutos', 61);

        // 61 min redondea a 90: hora y media.
        $this->assertSame(1500, $pagina->instance()->cotizacion['total']);
    }

    /** Los gramos entran a costo, sin el factor de la categoría. */
    public function test_suma_el_material_por_gramos(): void
    {
        $equipo = $this->impresora();
        $this->colaborador();

        $filamento = RateCard::create([
            'slug' => 'material-' . uniqid(), 'name' => 'Filamento PLA',
            'basis' => 'unidad', 'unit' => 'g',
            'price_minor' => 12, 'is_active' => true,
        ]);

        $pagina = Livewire::test(Cotizador::class)
            ->set('datos.asset_id', $equipo->id)
            ->set('datos.minutos', 60)
            ->set('datos.materiales', [
                ['rate_card_id' => $filamento->id, 'cantidad' => 250],
            ]);

        // 1000 de máquina + 250 g × 12.
        $this->assertSame(1000 + 3000, $pagina->instance()->cotizacion['total']);
    }

    /** El factor de la categoría cambia el tiempo, nunca el material. */
    public function test_la_categoria_abarata_el_tiempo_pero_no_el_material(): void
    {
        $equipo = $this->impresora();
        $this->colaborador();

        $cat = UserCategory::create([
            'slug' => 'estudiante-' . uniqid(), 'name' => 'Estudiante',
            'can_reserve' => true, 'rate_factor' => 0.5,
        ]);

        $estudiante = User::create([
            'name' => 'Quien pide', 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);

        $filamento = RateCard::create([
            'slug' => 'material-' . uniqid(), 'name' => 'Filamento PLA',
            'basis' => 'unidad', 'unit' => 'g',
            'price_minor' => 12, 'is_active' => true,
        ]);

        $pagina = Livewire::test(Cotizador::class)
            ->set('datos.asset_id', $equipo->id)
            ->set('datos.minutos', 60)
            ->set('datos.user_id', $estudiante->id)
            ->set('datos.materiales', [
                ['rate_card_id' => $filamento->id, 'cantidad' => 250],
            ]);

        // La máquina a mitad de precio; el filamento cuesta lo que cuesta.
        $this->assertSame(500 + 3000, $pagina->instance()->cotizacion['total']);
    }

    public function test_sin_maquina_no_calcula_nada(): void
    {
        $this->colaborador();

        $this->assertNull(Livewire::test(Cotizador::class)->instance()->cotizacion);
    }

    public function test_no_es_para_cualquiera(): void
    {
        $suelto = User::create([
            'name' => 'Alguien', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);

        $this->actingAs($suelto)->get('/admin/cotizador')->assertForbidden();
    }

    /** No compromete nada: ni reserva, ni cobra, ni descuenta inventario. */
    public function test_cotizar_no_guarda_nada(): void
    {
        $equipo = $this->impresora();
        $this->colaborador();

        Livewire::test(Cotizador::class)
            ->set('datos.asset_id', $equipo->id)
            ->set('datos.minutos', 120);

        $this->assertDatabaseCount('reservations', 0);
    }
}
