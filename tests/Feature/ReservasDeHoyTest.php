<?php

namespace Tests\Feature;

use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * «¿Qué hay hoy?», de todo el mundo.
 *
 * Es lo primero que se pregunta al abrir el laboratorio por la mañana, y hasta
 * ahora había que ordenar la lista por fecha y leer hasta donde cambiaba el
 * día. Lo que se defiende aquí es que el atajo enseñe **todas** las de hoy —sean
 * de quien sean— y que una que empezó anoche y sigue puesta cuente como de hoy.
 */
class ReservasDeHoyTest extends TestCase
{
    use RefreshDatabase;

    private Asset $maquina;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        // Un martes cualquiera, a media mañana en la hora del laboratorio.
        $this->travelTo(Carbon::parse('2026-08-25 10:00', config('fabos.lab.timezone')));

        $area = Area::create(['slug' => 'fab-' . uniqid(), 'name' => 'Fabricación']);

        $this->maquina = Asset::create([
            'area_id' => $area->id, 'name' => 'Láser CO₂', 'kind' => 'maquina',
            'status' => 'operativo', 'is_reservable' => true, 'booking_mode' => 'directa',
            'min_minutes' => 30, 'autonomous_minutes' => 480, 'max_minutes' => 720,
        ]);
    }

    private function persona(string $nombre): User
    {
        return User::create([
            'name' => $nombre, 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
    }

    private function reserva(User $quien, string $desde, string $hasta): Reservation
    {
        $tz = config('fabos.lab.timezone');

        return Reservation::create([
            'reservable_type' => Asset::class,
            'reservable_id'   => $this->maquina->id,
            'user_id'         => $quien->id,
            'status'          => 'confirmada',
            'mode'            => 'directa',
            'starts_at'       => Carbon::parse($desde, $tz),
            'ends_at'         => Carbon::parse($hasta, $tz),
        ]);
    }

    private function entraComoAdmin(): void
    {
        $admin = $this->persona('Jefa');
        $admin->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $factores = app(TwoFactorService::class);
        $secreto = $factores->generarSecreto($admin);
        $factores->confirmar($admin, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($admin->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);
    }

    // ------------------------------------------------------------- el filtro

    public function test_el_filtro_deja_las_de_hoy_y_esconde_las_de_otros_dias(): void
    {
        $quien = $this->persona('Ana');
        $hoy = $this->reserva($quien, '2026-08-25 14:00', '2026-08-25 16:00');
        $ayer = $this->reserva($quien, '2026-08-24 14:00', '2026-08-24 16:00');
        $manana = $this->reserva($quien, '2026-08-26 09:00', '2026-08-26 11:00');

        $this->entraComoAdmin();

        $visibles = Livewire::test(ListReservations::class)
            ->set('tableFilters.hoy.isActive', true)
            ->instance()->getTableRecords()->pluck('id')->all();

        $this->assertContains($hoy->id, $visibles);
        $this->assertNotContains($ayer->id, $visibles);
        $this->assertNotContains($manana->id, $visibles);
    }

    public function test_son_de_todo_el_mundo_no_solo_de_quien_mira(): void
    {
        // Es la razón de ser del botón: qué hay hoy en el laboratorio, sin
        // importar a nombre de quién esté.
        $deAna = $this->reserva($this->persona('Ana'), '2026-08-25 09:00', '2026-08-25 10:00');
        $deJuan = $this->reserva($this->persona('Juan'), '2026-08-25 15:00', '2026-08-25 17:00');

        $this->entraComoAdmin();

        $visibles = Livewire::test(ListReservations::class)
            ->set('tableFilters.hoy.isActive', true)
            ->instance()->getTableRecords()->pluck('id')->all();

        $this->assertContains($deAna->id, $visibles);
        $this->assertContains($deJuan->id, $visibles);
    }

    public function test_la_que_empezo_anoche_y_sigue_puesta_cuenta_como_de_hoy(): void
    {
        // Está ocupando la máquina ahora mismo: quien mira la lista tiene que
        // verla, aunque su hora de inicio sea de ayer.
        $quien = $this->persona('Ana');
        $cruzaMedianoche = $this->reserva($quien, '2026-08-24 22:00', '2026-08-25 02:00');

        $this->entraComoAdmin();

        $visibles = Livewire::test(ListReservations::class)
            ->set('tableFilters.hoy.isActive', true)
            ->instance()->getTableRecords()->pluck('id')->all();

        $this->assertContains($cruzaMedianoche->id, $visibles);
    }

    public function test_el_dia_es_el_del_laboratorio_no_el_del_servidor(): void
    {
        // A las 19:00 de Bogotá ya es el día siguiente en UTC. Una reserva de
        // esta noche tiene que seguir siendo «de hoy».
        $estaNoche = $this->reserva($this->persona('Ana'), '2026-08-25 20:00', '2026-08-25 22:00');

        $this->assertTrue(Reservation::deHoy()->whereKey($estaNoche->id)->exists());
    }

    // -------------------------------------------------------------- el botón

    public function test_el_boton_deja_puesto_el_filtro_de_hoy(): void
    {
        $quien = $this->persona('Ana');
        $hoy = $this->reserva($quien, '2026-08-25 14:00', '2026-08-25 16:00');
        $manana = $this->reserva($quien, '2026-08-26 09:00', '2026-08-26 11:00');

        $this->entraComoAdmin();

        $prueba = Livewire::test(ListReservations::class)
            ->callAction(TestAction::make('hoy'))
            ->assertHasNoActionErrors();

        $visibles = $prueba->instance()->getTableRecords()->pluck('id')->all();

        $this->assertContains($hoy->id, $visibles);
        $this->assertNotContains($manana->id, $visibles);
    }

    public function test_el_boton_limpia_lo_que_se_estuviera_filtrando_antes(): void
    {
        // Quien venía mirando solo lo de una persona se llevaría una respuesta
        // incompleta sin notarlo: la pregunta es qué hay hoy en el laboratorio.
        $deAna = $this->reserva($this->persona('Ana'), '2026-08-25 09:00', '2026-08-25 10:00');
        $deJuan = $this->reserva($this->persona('Juan'), '2026-08-25 15:00', '2026-08-25 17:00');

        $this->entraComoAdmin();

        $prueba = Livewire::test(ListReservations::class)
            ->set('tableFilters.solo_equipos.isActive', true)
            ->callAction(TestAction::make('hoy'));

        $visibles = $prueba->instance()->getTableRecords()->pluck('id')->all();

        $this->assertContains($deAna->id, $visibles);
        $this->assertContains($deJuan->id, $visibles);
        $this->assertFalse((bool) ($prueba->get('tableFilters')['solo_equipos']['isActive'] ?? false));
    }

    public function test_el_boton_dice_cuantas_hay(): void
    {
        $this->reserva($this->persona('Ana'), '2026-08-25 09:00', '2026-08-25 10:00');
        $this->reserva($this->persona('Juan'), '2026-08-25 15:00', '2026-08-25 17:00');
        $this->reserva($this->persona('Sara'), '2026-08-26 15:00', '2026-08-26 17:00');

        $this->entraComoAdmin();

        // El número delante, para no tener que entrar a contarlo.
        $this->get('/admin/reservations')->assertOk()->assertSee('Reservas de hoy (2)');
    }
}
