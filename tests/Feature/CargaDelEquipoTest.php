<?php

namespace Tests\Feature;

use App\Filament\Resources\Reservations\Widgets\CargaDelEquipo;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use App\Models\UserCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Quién del equipo tiene qué, encima del listado de reservas (§10).
 *
 * Una persona se cuenta por tres vías distintas y ninguna sobra: su tiempo
 * reservado como asesoría, figurar como quien acompaña o recibe, o estar entre
 * los acompañantes de un espacio. En el laboratorio real se reparten así —hay
 * quien casi siempre asesora y quien casi siempre supervisa—, así que contar
 * una sola vía dibujaba a media plantilla sin trabajo.
 */
class CargaDelEquipoTest extends TestCase
{
    use RefreshDatabase;

    private Asset $equipo;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->travelTo(Carbon::parse('2026-08-24 12:00', config('fabos.lab.timezone')));

        $area = Area::create(['slug' => 'electronica', 'name' => 'Electrónica']);

        $this->equipo = Asset::create([
            'area_id' => $area->id, 'name' => 'Prusa MK4', 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true, 'booking_mode' => 'directa',
            'min_minutes' => 30, 'autonomous_minutes' => 480, 'max_minutes' => 720,
        ]);
    }

    private function admin(string $nombre): User
    {
        $u = User::create(['name' => $nombre, 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        return $u;
    }

    private function practicante(string $nombre): User
    {
        $u = User::create(['name' => $nombre, 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_PRACTICANTE, 'web'));

        return $u;
    }

    private function alguien(): User
    {
        $cat = UserCategory::firstOrCreate(['slug' => 'estudiante'], ['name' => 'Estudiante', 'can_reserve' => true]);

        return User::create([
            'name' => 'Quien reserva', 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    /** @param array<string,mixed> $extra */
    private function reserva(string $desde, string $hasta, string $estado, array $extra = []): Reservation
    {
        $tz = config('fabos.lab.timezone');

        return Reservation::create(array_merge([
            'reservable_type' => Asset::class,
            'reservable_id'   => $this->equipo->id,
            'user_id'         => $this->alguien()->id,
            'status'          => $estado,
            'mode'            => 'directa',
            'starts_at'       => Carbon::parse($desde, $tz),
            'ends_at'         => Carbon::parse($hasta, $tz),
        ], $extra));
    }

    /** @return array<string,array<string,int>> nombre => casillas */
    private function tarjetas(): array
    {
        return collect(app(CargaDelEquipo::class)->getTarjetas())
            ->mapWithKeys(fn (array $t) => [$t['persona']->name => [
                'activas'  => $t['activas'],
                'futuras'  => $t['futuras'],
                'cerradas' => $t['cerradas'],
            ]])
            ->all();
    }

    // ------------------------------------------------- las tres vias de tener trabajo

    public function test_cuenta_a_quien_supervisa(): void
    {
        $camilo = $this->admin('Camilo');
        $this->reserva('2026-08-25 10:00', '2026-08-25 12:00', 'confirmada', ['supervisor_id' => $camilo->id]);

        $this->assertSame(1, $this->tarjetas()['Camilo']['futuras']);
    }

    /** Una asesoría reserva el TIEMPO de quien atiende: ella es el recurso. */
    public function test_cuenta_a_quien_asesora(): void
    {
        $jhonatan = $this->admin('Jhonatan');

        $this->reserva('2026-08-25 10:00', '2026-08-25 11:00', 'confirmada', [
            'reservable_type' => User::class,
            'reservable_id'   => $jhonatan->id,
            'mode'            => 'asesoria',
        ]);

        $this->assertSame(1, $this->tarjetas()['Jhonatan']['futuras']);
    }

    public function test_cuenta_a_quien_acompana_un_espacio(): void
    {
        $juan = $this->admin('Juan');
        $sala = Space::create([
            'slug' => 'taller', 'name' => 'Taller', 'type' => 'fisico',
            'capacity' => 10, 'is_reservable' => true,
        ]);

        $r = $this->reserva('2026-08-25 10:00', '2026-08-25 12:00', 'confirmada', [
            'reservable_type' => Space::class,
            'reservable_id'   => $sala->id,
        ]);
        $r->companions()->attach($juan->id);

        $this->assertSame(1, $this->tarjetas()['Juan']['futuras']);
    }

    /**
     * Y la misma tarde no se cuenta dos veces.
     *
     * El bloque de tiempo del acompañante cuelga de la reserva que acompaña.
     * Contar también las hijas sumaría dos por una sola actividad.
     */
    public function test_el_bloque_del_acompanante_no_duplica(): void
    {
        $camilo = $this->admin('Camilo');

        $madre = $this->reserva('2026-08-25 10:00', '2026-08-25 12:00', 'confirmada', [
            'supervisor_id' => $camilo->id,
        ]);

        // El bloque que le aparta el tiempo, colgando de la madre.
        $this->reserva('2026-08-25 10:00', '2026-08-25 12:00', 'confirmada', [
            'reservable_type'       => User::class,
            'reservable_id'         => $camilo->id,
            'parent_reservation_id' => $madre->id,
        ]);

        $this->assertSame(1, $this->tarjetas()['Camilo']['futuras'], 'una actividad, no dos');
    }

    // ------------------------------------------------------------ las tres casillas

    public function test_reparte_activas_futuras_y_cerradas(): void
    {
        $camilo = $this->admin('Camilo');
        $suya = fn (string $estado, string $desde, string $hasta, array $extra = []) => $this->reserva(
            $desde, $hasta, $estado, array_merge(['supervisor_id' => $camilo->id], $extra),
        );

        // La segunda activa va en OTRO equipo: la base prohibe dos reservas
        // solapadas sobre el mismo, y estas dos tienen que correr a la vez.
        $otro = Asset::create([
            'area_id' => $this->equipo->area_id, 'name' => 'Prusa MK4 bis', 'kind' => 'fijo',
            'status' => 'operativo', 'is_reservable' => true, 'booking_mode' => 'directa',
            'min_minutes' => 30, 'autonomous_minutes' => 480, 'max_minutes' => 720,
        ]);

        // Son las 12:00 del 24.
        $suya('en_curso', '2026-08-24 11:00', '2026-08-24 13:00');      // activa
        $suya('confirmada', '2026-08-24 11:30', '2026-08-24 12:30', ['reservable_id' => $otro->id]);    // activa: su franja corre
        $suya('confirmada', '2026-08-25 10:00', '2026-08-25 12:00');    // futura
        $suya('solicitada', '2026-08-26 10:00', '2026-08-26 12:00');    // futura: espera decisión
        $suya('completada', '2026-08-20 10:00', '2026-08-20 12:00');    // cerrada
        $suya('no_show', '2026-08-21 10:00', '2026-08-21 12:00');       // cerrada

        $this->assertSame(['activas' => 2, 'futuras' => 2, 'cerradas' => 2], $this->tarjetas()['Camilo']);
    }

    /** Lo que no ocurrió no se le apunta a nadie. */
    public function test_lo_cancelado_y_lo_rechazado_no_suma(): void
    {
        $camilo = $this->admin('Camilo');

        $this->reserva('2026-08-25 10:00', '2026-08-25 12:00', 'cancelada', ['supervisor_id' => $camilo->id]);
        $this->reserva('2026-08-26 10:00', '2026-08-26 12:00', 'rechazada', ['supervisor_id' => $camilo->id]);

        $this->assertSame(['activas' => 0, 'futuras' => 0, 'cerradas' => 0], $this->tarjetas()['Camilo']);
    }

    // ----------------------------------------------------------------- quien sale

    public function test_solo_salen_administradores_y_superadmins(): void
    {
        $this->admin('Camilo');
        $this->practicante('Edwin');

        $tarjetas = $this->tarjetas();

        $this->assertArrayHasKey('Camilo', $tarjetas);
        $this->assertArrayNotHasKey('Edwin', $tarjetas, 'un practicante no atiende reservas');
    }

    /** Quien no tiene nada sale igual, en ceros: saber que está libre es el dato. */
    public function test_quien_no_tiene_nada_sale_en_ceros(): void
    {
        $this->admin('Camilo');

        $this->assertSame(['activas' => 0, 'futuras' => 0, 'cerradas' => 0], $this->tarjetas()['Camilo']);
    }
}
