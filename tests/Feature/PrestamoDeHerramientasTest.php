<?php

namespace Tests\Feature;

use App\Filament\Pages\PrestamoDeHerramientas;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Models\Reservation;
use App\Models\RiskFamily;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Support\FactoresDeSesion;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Varias herramientas en una sola reserva (§7).
 *
 * Lo que se defiende: que se reserven todas o ninguna, que cuelguen de una
 * sola para cancelarse juntas, que el tope lo decida la coordinación y no el
 * código, y que cada una siga exigiendo su certifab.
 */
class PrestamoDeHerramientasTest extends TestCase
{
    use RefreshDatabase;

    private RiskFamily $familia;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $area = Area::create(['slug' => 'taller', 'name' => 'Taller']);
        $this->familia = RiskFamily::create(['area_id' => $area->id, 'slug' => 'herramienta-menor', 'name' => 'Herramienta menor']);
    }

    private function persona(bool $habilitada = true): User
    {
        $cat = UserCategory::firstOrCreate(['slug' => 'estudiante'], ['name' => 'Estudiante', 'can_reserve' => true]);
        $u = User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);

        if ($habilitada) {
            Certifab::create(['user_id' => $u->id, 'risk_family_id' => $this->familia->id, 'level' => 'byte']);
        }

        return $u;
    }

    private function herramienta(string $nombre, array $datos = []): Asset
    {
        return Asset::create(array_merge([
            'area_id' => $this->familia->area_id, 'risk_family_id' => $this->familia->id,
            'name' => $nombre, 'kind' => 'herramienta', 'status' => 'operativo',
            'is_reservable' => true, 'is_public' => true,
            'min_minutes' => 30, 'autonomous_minutes' => 720, 'max_minutes' => 720,
        ], $datos));
    }

    private function desde(): Carbon
    {
        return Carbon::now(config('fabos.lab.timezone'))->addDay()->setTime(10, 0);
    }

    // ------------------------------------------------------------- el servicio

    public function test_varias_herramientas_quedan_en_una_reserva_madre_con_hijas(): void
    {
        $u = $this->persona();
        [$a, $b, $c] = [$this->herramienta('Taladro'), $this->herramienta('Lijadora'), $this->herramienta('Caladora')];

        $madre = app(BookingService::class)->reservarHerramientas($u, [$a, $b, $c], $this->desde(), $this->desde()->addHours(2), 'Maqueta');

        $this->assertSame($a->id, $madre->reservable_id);
        $this->assertSame('confirmada', $madre->status);
        // La madre dice qué va con ella: es la única fila que la persona ve en su lista.
        $this->assertStringContainsString('Con Lijadora, Caladora', $madre->purpose);

        $hijas = Reservation::where('parent_reservation_id', $madre->id)->get();
        $this->assertSame(2, $hijas->count());
        $this->assertEqualsCanonicalizing([$b->id, $c->id], $hijas->pluck('reservable_id')->all());
    }

    public function test_si_una_no_se_puede_no_queda_ninguna(): void
    {
        $u = $this->persona();
        $a = $this->herramienta('Taladro');
        $b = $this->herramienta('Lijadora');
        // La lijadora ya la tiene otra persona a esa hora.
        app(BookingService::class)->reservar($this->persona(), $b, $this->desde(), $this->desde()->addHour());

        try {
            app(BookingService::class)->reservarHerramientas($u, [$a, $b], $this->desde(), $this->desde()->addHour());
            $this->fail('la lijadora estaba ocupada');
        } catch (BookingException) {
            $this->assertSame(0, Reservation::where('user_id', $u->id)->count(), 'el taladro tampoco se reservó');
        }
    }

    public function test_el_tope_lo_decide_la_coordinacion(): void
    {
        $u = $this->persona();
        $tres = collect(['A', 'B', 'C'])->map(fn ($n) => $this->herramienta($n));

        Setting::put(Settings::HERRAMIENTAS_POR_RESERVA, 2, 'reservas');

        try {
            app(BookingService::class)->reservarHerramientas($u, $tres, $this->desde(), $this->desde()->addHour());
            $this->fail('eran tres y el tope es dos');
        } catch (BookingException $e) {
            $this->assertStringContainsString('hasta 2 herramientas', $e->getMessage());
        }

        Setting::put(Settings::HERRAMIENTAS_POR_RESERVA, 3, 'reservas');

        $this->assertSame('confirmada', app(BookingService::class)->reservarHerramientas($u, $tres, $this->desde(), $this->desde()->addHour())->status);
    }

    public function test_sin_ajuste_el_tope_es_cinco(): void
    {
        $this->assertSame(5, Settings::maxHerramientasPorReserva());
    }

    /**
     * Pedir prestada una herramienta no exige estar habilitado.
     *
     * El certifab dice que alguien te vio operar una máquina, y un multímetro
     * no es una máquina: se pide, se usa y se devuelve. Exigir un curso para
     * llevarse un taladro solo conseguía que nadie lo pidiera.
     */
    public function test_una_herramienta_no_exige_certifab(): void
    {
        $sinCertifab = $this->persona(habilitada: false);
        $a = $this->herramienta('Taladro');

        $madre = app(BookingService::class)
            ->reservarHerramientas($sinCertifab, [$a], $this->desde(), $this->desde()->addHour());

        $this->assertNotNull($madre);
    }

    /**
     * Salvo la que se marca en su ficha: se presta, pero no a cualquiera.
     *
     * Va por equipo y no por familia de riesgo porque las familias están
     * mezcladas —«Máquina mayor» tiene seis máquinas fijas y una pulidora— y
     * quitarla ahí abriría también las máquinas.
     */
    public function test_la_que_lo_exige_en_su_ficha_lo_sigue_exigiendo(): void
    {
        $sinCertifab = $this->persona(habilitada: false);
        $robot = $this->herramienta('Robot Unitree', ['exige_certifab' => true]);

        $this->expectException(BookingException::class);

        app(BookingService::class)->reservarHerramientas($sinCertifab, [$robot], $this->desde(), $this->desde()->addHour());
    }

    public function test_una_maquina_fija_no_entra_en_el_prestamo(): void
    {
        $u = $this->persona();
        $sierra = $this->herramienta('Sierra de banco', ['kind' => 'fijo']);

        $this->expectException(BookingException::class);

        app(BookingService::class)->reservarHerramientas($u, [$sierra], $this->desde(), $this->desde()->addHour());
    }

    public function test_cancelar_la_madre_suelta_a_las_hijas(): void
    {
        $u = $this->persona();
        $madre = app(BookingService::class)->reservarHerramientas(
            $u, [$this->herramienta('A'), $this->herramienta('B')], $this->desde(), $this->desde()->addHour(),
        );

        app(BookingService::class)->cancelar($madre);

        $this->assertSame(0, Reservation::whereIn('status', Reservation::BLOQUEANTES)->count());
    }

    // ------------------------------------------------------------- las pantallas

    public function test_la_lista_deja_marcar_varias_y_las_lleva_a_una_sola_reserva(): void
    {
        $this->herramienta('Taladro');

        $this->get('/reservas?modo=herramientas')
            ->assertOk()
            ->assertSee('name="h[]"', false)
            ->assertSee('Reservar juntas')
            ->assertSee('data-tope="5"', false)
            ->assertSee(route('reservas.herramientas'), false);
    }

    /**
     * Antes de elegir hora se ve cuál se puede pedir.
     *
     * Ya casi ninguna se atasca —prestar no exige certifab— pero la que lo
     * exige en su ficha sí, y eso hay que decirlo antes de preguntar por la
     * hora: descubrirlo al final es haber hecho el camino para nada.
     */
    public function test_antes_de_elegir_hora_se_ve_cual_se_puede_pedir(): void
    {
        $u = $this->persona(habilitada: false);
        $a = $this->herramienta('Taladro');
        $b = $this->herramienta('Robot Unitree', ['exige_certifab' => true]);

        $this->actingAs($u)
            ->get(route('reservas.herramientas', ['h' => [$a->id, $b->id]]))
            ->assertOk()
            ->assertSee('Reservar 2 herramientas')
            ->assertSee('Taladro')
            ->assertSee('Robot Unitree')
            // Sin certifab para el robot: se dice antes, y no se ofrece la hora.
            ->assertSee('no se puede pedir todavía')
            ->assertDontSee('Elegir horario');
    }

    /** Y con todas prestables, se va derecho a la hora. */
    public function test_sin_nada_que_frene_se_ofrece_la_hora(): void
    {
        $u = $this->persona(habilitada: false);
        $a = $this->herramienta('Taladro');
        $b = $this->herramienta('Lijadora');

        $this->actingAs($u)
            ->get(route('reservas.herramientas', ['h' => [$a->id, $b->id]]))
            ->assertOk()
            ->assertSee('Elegir horario')
            ->assertDontSee('no se puede pedir todavía');
    }

    public function test_se_reservan_desde_el_sitio(): void
    {
        $u = $this->persona();
        $a = $this->herramienta('Taladro');
        $b = $this->herramienta('Lijadora');

        $this->actingAs($u)
            ->post(route('reservas.herramientas.store'), [
                'h' => [$a->id, $b->id],
                'fecha' => $this->desde()->toDateString(), 'inicio' => '10:00', 'duracion' => 120,
                'proposito' => 'Maqueta',
            ])
            ->assertRedirect(route('reservas.index'))
            ->assertSessionHas('status', fn ($m) => str_contains($m, '2 herramientas reservadas'));

        $this->assertSame(2, Reservation::where('user_id', $u->id)->count());
    }

    public function test_sin_nada_marcado_se_vuelve_a_la_lista(): void
    {
        $this->actingAs($this->persona())
            ->get(route('reservas.herramientas'))
            ->assertRedirect(route('publico.reservas', ['modo' => 'herramientas']));
    }

    public function test_el_tope_se_edita_desde_el_panel(): void
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }
        $admin = User::create(['name' => 'Admin', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $admin->assignRole(User::ROL_ADMINISTRADOR);
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($admin);
        $servicio->confirmar($admin, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($admin->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        Livewire::test(PrestamoDeHerramientas::class)
            ->set('maximo', 3)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(3, Settings::maxHerramientasPorReserva());

        // Y la lista pública lo refleja al momento.
        $this->herramienta('Taladro');
        $this->get('/reservas?modo=herramientas')->assertSee('data-tope="3"', false);
    }
}
