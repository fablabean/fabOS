<?php

namespace Tests\Feature;

use App\Filament\Resources\Reservations\Pages\ListReservations;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\WorkSchedule;
use App\Services\Auth\TwoFactorService;
use App\Services\Booking\EspacioBookingService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Cada actividad, junta en la lista de reservas (§10).
 *
 * Quien reserva una sala y toma dos herramientas dentro genera tres filas.
 * Sueltas y mezcladas con las de todo el mundo, leer la lista era reconstruir
 * a ojo que la fuente de voltaje de las seis era la misma actividad que el Lab
 * electrónica de las seis. Ahora las hijas van pegadas debajo de su madre y
 * dicen de quién cuelgan.
 */
class ReservasAnidadasEnLaListaTest extends TestCase
{
    use RefreshDatabase;

    private Space $sala;

    private Asset $fuente;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        // Lunes por la mañana: las jornadas de prueba son de lunes.
        $this->travelTo(Carbon::parse('2026-08-24 07:00', config('fabos.lab.timezone')));

        $area = Area::create(['slug' => 'electronica', 'name' => 'Electrónica']);

        $this->sala = Space::create([
            'slug' => 'lab-electronica', 'name' => 'Lab electrónica', 'type' => 'fisico',
            'capacity' => 8, 'is_reservable' => true,
        ]);
        $this->sala->areas()->attach($area->id);

        // Una herramienta que vive en esa sala.
        $this->fuente = Asset::create([
            'area_id' => $area->id, 'name' => 'Fuente voltaje 1', 'kind' => 'herramienta',
            'status' => 'operativo', 'is_reservable' => true, 'booking_mode' => 'directa',
            'space_id' => $this->sala->id,
            'min_minutes' => 30, 'autonomous_minutes' => 480, 'max_minutes' => 720,
        ]);
    }

    private function alguien(): User
    {
        $cat = UserCategory::firstOrCreate(['slug' => 'estudiante'], ['name' => 'Estudiante', 'can_reserve' => true]);

        return User::create([
            'name' => 'Quien reserva', 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    /** Alguien del equipo en jornada, para que la reserva se confirme sola. */
    private function colaborador(): User
    {
        $u = User::create(['name' => 'Colaborador', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_PRACTICANTE, 'web'));

        WorkSchedule::create([
            'user_id' => $u->id, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '18:00',
            'break_minutes' => 0, 'modalidad' => WorkSchedule::PRESENCIAL, 'effective_from' => '2026-01-01',
        ]);

        return $u;
    }

    private function entraComoAdmin(): void
    {
        $admin = User::create(['name' => 'Jefa', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $admin->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $factores = app(TwoFactorService::class);
        $secreto = $factores->generarSecreto($admin);
        $factores->confirmar($admin, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($admin->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);
    }

    /** La sala y la herramienta que se tomó dentro: una actividad, dos filas pegadas. */
    private function reservaConHerramienta(): Reservation
    {
        $this->colaborador();

        $desde = Carbon::parse('2026-08-24 10:00', config('fabos.lab.timezone'));

        return app(EspacioBookingService::class)->reservar(
            $this->alguien(), $this->sala, $desde, $desde->copy()->addHours(2), 1, [$this->fuente->id],
        );
    }

    public function test_la_herramienta_va_debajo_de_la_sala_y_dice_de_donde_cuelga(): void
    {
        $madre = $this->reservaConHerramienta();
        $hija = Reservation::where('parent_reservation_id', $madre->id)->firstOrFail();

        $this->entraComoAdmin();

        Livewire::test(ListReservations::class)
            // Primero la sala, y la herramienta pegada debajo.
            ->assertCanSeeTableRecords([$madre, $hija], inOrder: true)
            ->assertSee('↳ Fuente voltaje 1')
            ->assertSee('equipo, dentro de Lab electrónica');
    }

    /**
     * Y una sala se llama sala.
     *
     * La columna solo distinguía equipos de «todo lo demás», así que un
     * espacio salía etiquetado «acompañamiento», que es el tiempo de una
     * persona. Leído en la lista no tenía ningún sentido.
     */
    public function test_un_espacio_no_se_etiqueta_como_acompanamiento(): void
    {
        $this->reservaConHerramienta();
        $this->entraComoAdmin();

        Livewire::test(ListReservations::class)
            ->assertSee('espacio')
            ->assertDontSee('acompañamiento');
    }

    /** Lo que no cuelga de nada se sigue viendo suelto, sin flecha. */
    public function test_una_reserva_suelta_no_lleva_flecha(): void
    {
        $this->colaborador();
        $desde = Carbon::parse('2026-08-24 14:00', config('fabos.lab.timezone'));

        app(EspacioBookingService::class)->reservar(
            $this->alguien(), $this->sala, $desde, $desde->copy()->addHour(),
        );

        $this->entraComoAdmin();

        Livewire::test(ListReservations::class)
            ->assertSee('Lab electrónica')
            ->assertDontSee('↳');
    }
}
