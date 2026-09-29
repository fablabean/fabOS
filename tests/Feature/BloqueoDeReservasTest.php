<?php

namespace Tests\Feature;

use App\Filament\Pages\BloqueoDeReservas as Pantalla;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Space;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Booking\AsesoriaService;
use App\Services\Booking\BookingException;
use App\Services\Booking\BookingService;
use App\Services\Booking\EspacioBookingService;
use App\Support\BloqueoDeReservas;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BloqueoDeReservasTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create(['name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(User::ROL_ADMINISTRADOR);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u;
    }

    private function intentos(): array
    {
        $u = new User(['name' => 'Ana']);
        $desde = now()->addDay()->setTime(10, 0);
        $hasta = $desde->copy()->addHour();

        return [
            'equipo'       => fn () => app(BookingService::class)->reservar($u, new Asset, $desde, $hasta),
            'herramientas' => fn () => app(BookingService::class)->reservarHerramientas($u, [new Asset], $desde, $hasta),
            'espacio'      => fn () => app(EspacioBookingService::class)->reservar($u, new Space, $desde, $hasta),
            'espacios'     => fn () => app(EspacioBookingService::class)->reservarVarios($u, [new Space], $desde, $hasta),
            'asesoria'     => fn () => app(AsesoriaService::class)->agendar($u, new Area, $desde, $hasta),
        ];
    }

    public function test_bloqueado_nadie_reserva_nada_y_se_dice_por_que(): void
    {
        BloqueoDeReservas::activar('Mantenimiento eléctrico del edificio', null, 'Erick');

        foreach ($this->intentos() as $que => $intento) {
            try {
                $intento();
                $this->fail("Se pudo reservar: $que");
            } catch (BookingException $e) {
                $this->assertStringContainsString('Mantenimiento eléctrico del edificio', $e->getMessage(), $que);
            }
        }
    }

    public function test_con_fecha_de_reapertura_se_levanta_solo(): void
    {
        BloqueoDeReservas::activar('Obra', now()->addHour(), null);
        $this->assertTrue(BloqueoDeReservas::activo());

        $this->travel(2)->hours();
        $this->assertFalse(BloqueoDeReservas::activo());
    }

    public function test_el_sitio_avisa_con_ventana_y_franja(): void
    {
        $this->get('/')->assertOk()->assertDontSee('bq-modal');

        BloqueoDeReservas::activar('Corte de luz programado', null, null);

        $this->get('/')
            ->assertOk()
            ->assertSee('id="bq-modal"', false)
            ->assertSee('Reservas bloqueadas')
            ->assertSee('Corte de luz programado');
    }

    public function test_desde_el_panel_se_bloquea_y_se_levanta(): void
    {
        $this->admin();

        Livewire::test(Pantalla::class)
            ->set('datos.motivo', 'Inventario anual')
            ->call('activar')
            ->assertHasNoErrors();

        $this->assertTrue(BloqueoDeReservas::activo());
        $this->assertSame('Inventario anual', BloqueoDeReservas::motivo());

        Livewire::test(Pantalla::class)->call('levantar');

        $this->assertFalse(BloqueoDeReservas::activo());
    }

    public function test_sin_motivo_no_se_bloquea(): void
    {
        $this->admin();

        Livewire::test(Pantalla::class)
            ->set('datos.motivo', '')
            ->call('activar')
            ->assertHasErrors(['datos.motivo']);

        $this->assertFalse(BloqueoDeReservas::activo());
    }

    public function test_bloqueado_las_pantallas_de_elegir_hora_no_ofrecen_horas(): void
    {
        $u = User::create(['name' => 'Ana', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $this->actingAs($u);

        BloqueoDeReservas::activar('Entrenamiento de la brigada', null, null);

        $this->get(route('reservas.herramientas'))
            ->assertOk()
            ->assertSee('Las reservas están bloqueadas')
            ->assertSee('Entrenamiento de la brigada');

        BloqueoDeReservas::levantar();

        $this->get(route('reservas.herramientas'))->assertDontSee('Las reservas están bloqueadas');
    }
}
