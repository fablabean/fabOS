<?php

namespace Tests\Feature;

use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Reservation;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\MatrizDeAccesos;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\DateTimePicker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La hora que se ve y la reserva que se cayó sola (§8).
 *
 * Dos cosas que aparecieron mirando la misma fila:
 *
 *  · La lista decía «17:00» y al abrir la misma reserva ponía «22:00»: cinco
 *    horas, las de Bogotá. El libro guarda en UTC —bien— pero los selectores
 *    mostraban ese valor crudo. Y no era solo un susto: al guardar, esas 22:00
 *    se escribían como si fueran hora local y la reserva se corría de verdad.
 *  · Una reserva programada desde un proyecto se marcó «no se presentó» porque
 *    nadie validó la llegada. No hacía falta validarla —se iba a usar— y la
 *    única salida era volver a crearla, perdiendo quién la pidió y para qué.
 */
class ReservaSeLevantaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        UserCategory::firstOrCreate(
            ['slug' => 'invitado'],
            ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1, 'client_kind' => 'externo'],
        );

        app(MatrizDeAccesos::class)->sincronizar();
    }

    private function admin(): User
    {
        $u = User::create([
            'name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole(Role::findOrCreate(User::ROL_SUPERADMIN, 'web'));

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    private function reserva(string $estado = 'no_show'): Reservation
    {
        $area = Area::firstOrCreate(['slug' => 'corte'], ['name' => 'Corte láser']);

        $equipo = Asset::create([
            'area_id' => $area->id, 'name' => 'Elegoo Neptune', 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true,
            'min_minutes' => 30, 'autonomous_minutes' => 60, 'max_minutes' => 720,
        ]);

        return Reservation::create([
            'reservable_type' => Asset::class, 'reservable_id' => $equipo->id,
            'user_id' => $this->admin()->id,
            'status' => $estado, 'mode' => 'directa',
            'starts_at' => now()->subDay()->setTime(17, 0),
            'ends_at' => now()->subDay()->setTime(22, 0),
            'purpose' => 'uniones prueba hablador',
        ]);
    }

    // ---------------------------------------------------------- la hora

    /**
     * Los selectores del panel hablan en hora del laboratorio.
     *
     * Se configura de una vez para todos: los dieciséis que había estaban mal,
     * y el diecisiete también lo estaría.
     */
    public function test_los_selectores_de_fecha_usan_la_hora_del_laboratorio(): void
    {
        $campo = DateTimePicker::make('starts_at');

        $this->assertSame(config('fabos.lab.timezone'), $campo->getTimezone());
    }

    // ------------------------------------------------------- levantarla

    public function test_una_reserva_caida_se_levanta_con_su_motivo(): void
    {
        $this->admin();
        $r = $this->reserva('no_show');

        Livewire::test(ListReservations::class)
            ->callAction(
                TestAction::make('levantar')->table($r),
                ['motivo' => 'Se programó desde un proyecto: no hacía falta validar la llegada.'],
            );

        $r->refresh();

        $this->assertSame('confirmada', $r->status);
        // El motivo queda escrito: sin él, dentro de un mes nadie sabe por qué
        // se revivió.
        $this->assertStringContainsString('no hacía falta validar', $r->purpose);
        $this->assertStringContainsString('uniones prueba hablador', $r->purpose);
    }

    /**
     * Anotar que llegó a tiempo, desde el panel: para cuando el escáner falló
     * o quien atendía olvidó validar. La llegada queda a la hora reservada.
     */
    public function test_se_anota_que_llego_a_tiempo_y_queda_a_la_hora_reservada(): void
    {
        $this->admin();
        $r = $this->reserva('confirmada');
        $r->update(['starts_at' => now()->subMinutes(50), 'ends_at' => now()->addMinutes(40)]);

        Livewire::test(ListReservations::class)
            ->callAction(TestAction::make('llego_a_tiempo')->table($r));

        $r->refresh();

        $this->assertSame('en_curso', $r->status);
        $this->assertTrue($r->checked_in_at->equalTo($r->starts_at), 'la llegada es a la hora reservada, no a la de pulsar');
        $this->assertStringContainsString('anotada por', $r->status_reason);
    }

    public function test_tambien_se_levanta_una_cancelada(): void
    {
        $this->admin();
        $r = $this->reserva('cancelada');

        Livewire::test(ListReservations::class)
            ->assertActionVisible(TestAction::make('levantar')->table($r));
    }

    /** Una que está viva no se levanta: no hay nada que devolver. */
    public function test_una_confirmada_no_ofrece_levantarse(): void
    {
        $this->admin();
        $r = $this->reserva('confirmada');

        Livewire::test(ListReservations::class)
            ->assertActionHidden(TestAction::make('levantar')->table($r));
    }
}
