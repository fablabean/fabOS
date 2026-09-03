<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Evidencia;
use App\Models\Reservation;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Booking\EliminarReserva;
use App\Services\Ledger\LedgerService;
use App\Services\Money\ChargeService;
use App\Services\Money\Quote;
use App\Support\FactoresDeSesion;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Borrar una reserva desde la lista (§7).
 *
 * Existe para limpiar las de prueba. Lo que se fija aquí no es que la fila
 * desaparezca —eso lo hace cualquier delete— sino lo que un borrado a secas
 * dejaría mal: FabCoins retenidos por una reserva que ya no existe, fotos
 * huérfanas en el disco, y tres botones que borran cada uno a su manera.
 */
class EliminarReservaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
    }

    private function persona(): User
    {
        $cat = UserCategory::create([
            'slug' => 'c-' . uniqid(), 'name' => 'Estudiante', 'can_reserve' => true, 'rate_factor' => 1,
        ]);

        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    private function reserva(User $u, array $cambios = []): Reservation
    {
        $activo = Asset::create(['name' => 'Equipo ' . uniqid(), 'kind' => 'fijo', 'status' => 'operativo']);

        return Reservation::create(array_merge([
            'reservable_type' => Asset::class, 'reservable_id' => $activo->id,
            'user_id' => $u->id, 'status' => 'confirmada', 'mode' => 'directa',
            'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(2),
        ], $cambios));
    }

    public function test_borrar_se_lleva_la_reserva_y_sus_archivos(): void
    {
        $r = $this->reserva($this->persona());

        Storage::disk('local')->put('evidencia/foto.jpg', 'no es una foto');
        $r->evidence()->create(['kind' => 'foto', 'file_path' => 'evidencia/foto.jpg', 'original_name' => 'foto.jpg']);

        $resumen = app(EliminarReserva::class)($r, $this->persona());

        $this->assertDatabaseMissing('reservations', ['id' => $r->id]);
        $this->assertSame(0, Evidencia::count(), 'la fila de la evidencia se va con la reserva');
        Storage::disk('local')->assertMissing('evidencia/foto.jpg');
        $this->assertSame(1, $resumen['archivos']);
    }

    /**
     * Lo retenido vuelve a su dueño.
     *
     * Sin esto, el saldo de esa persona quedaría descontado por una reserva
     * que ya no existe, y nadie sabría por qué.
     */
    public function test_borrar_devuelve_lo_comprometido_en_fabcoins(): void
    {
        Setting::put(Settings::COBROS_ACTIVOS, true, 'finanzas');
        $cobros = app(ChargeService::class);
        $libro = app(LedgerService::class);

        $u = $this->persona();
        $cobros->dotar($u, 10_000, '2026-09');

        $r = $this->reserva($u);
        $cobros->comprometer($r, new Quote([], 4_000));

        $this->assertSame(6_000, $libro->saldoDe($u), 'reservar retiene');

        $resumen = app(EliminarReserva::class)($r, $this->persona());

        $this->assertSame(10_000, $libro->saldoDe($u->fresh()), 'borrar devuelve');
        $this->assertSame(4_000, $resumen['devuelto']);
        $this->assertTrue($libro->verificarCadena()['intacta']);
    }

    /** Con el cobro apagado no hay nada que devolver y no revienta. */
    public function test_con_el_cobro_apagado_borra_sin_mas(): void
    {
        Setting::put(Settings::COBROS_ACTIVOS, false, 'finanzas');
        $r = $this->reserva($this->persona());

        $resumen = app(EliminarReserva::class)($r);

        $this->assertDatabaseMissing('reservations', ['id' => $r->id]);
        $this->assertSame(0, $resumen['devuelto']);
    }

    /** Las hijas de una reserva en conjunto se van con la madre. */
    public function test_las_hijas_se_van_con_la_madre(): void
    {
        $u = $this->persona();
        $madre = $this->reserva($u);
        $hija = $this->reserva($u, ['parent_reservation_id' => $madre->id]);

        $resumen = app(EliminarReserva::class)($madre);

        $this->assertDatabaseMissing('reservations', ['id' => $hija->id]);
        $this->assertSame(1, $resumen['hijas']);
    }

    // ------------------------------------------------------------ la pantalla

    /** El botón está en la fila: para limpiar veinte de prueba no se entra a editar veinte veces. */
    public function test_la_lista_ofrece_borrar_en_cada_fila(): void
    {
        $jefa = $this->persona();
        $jefa->assignRole(Role::findOrCreate(User::ROL_SUPERADMIN, 'web'));

        $factores = app(TwoFactorService::class);
        $secreto = $factores->generarSecreto($jefa);
        $factores->confirmar($jefa, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->reserva($this->persona(), ['purpose' => 'test']);

        $this->actingAs($jefa->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]])
            ->get('/admin/reservations')
            ->assertOk()
            // La ventana de confirmacion se pinta al abrirla; lo que esta en
            // la fila desde el principio es el boton con su rotulo.
            ->assertSee('Borrar');
    }
}
