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
 * Los filtros del panel filtran de verdad, y las reservas abren completas.
 *
 * «Solo equipos» venía puesto de fábrica y decía esconder todo lo que no fuera
 * una máquina. No escondía nada: el parámetro de su closure se llamaba `$q` y
 * Filament los inyecta por NOMBRE, así que resolvía un constructor de consulta
 * sin modelo del contenedor y el `where` se aplicaba a un objeto de usar y
 * tirar. Cinco filtros de tres pantallas estaban así, tres de ellos puestos por
 * defecto: la pantalla decía una cosa y enseñaba otra.
 *
 * Y arreglado ya no viene puesto: escondía los espacios, que son justo lo que
 * hay que ver junto a las herramientas que se tomaron dentro.
 */
class FiltroSoloEquiposTest extends TestCase
{
    use RefreshDatabase;

    private Space $sala;

    private Asset $multimetro;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        $this->travelTo(Carbon::parse('2026-08-24 09:00', config('fabos.lab.timezone')));

        $area = Area::create(['slug' => 'electronica', 'name' => 'Electrónica']);

        $this->sala = Space::create([
            'slug' => 'lab-electronica', 'name' => 'Lab electrónica', 'type' => 'fisico',
            'capacity' => 8, 'is_reservable' => true,
        ]);

        $this->multimetro = Asset::create([
            'area_id' => $area->id, 'name' => 'Multímetro 1', 'kind' => 'herramienta',
            'status' => 'operativo', 'is_reservable' => true, 'booking_mode' => 'directa',
            'space_id' => $this->sala->id,
            'min_minutes' => 30, 'autonomous_minutes' => 480, 'max_minutes' => 720,
        ]);

        $u = User::create(['name' => 'Colaborador', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_PRACTICANTE, 'web'));
        WorkSchedule::create([
            'user_id' => $u->id, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '18:00',
            'break_minutes' => 0, 'modalidad' => WorkSchedule::PRESENCIAL, 'effective_from' => '2026-01-01',
        ]);
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

    private function reservaConHerramienta(): Reservation
    {
        $cat = UserCategory::firstOrCreate(['slug' => 'estudiante'], ['name' => 'Estudiante', 'can_reserve' => true]);
        $quien = User::create([
            'name' => 'Quien reserva', 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);

        $desde = Carbon::parse('2026-08-24 10:00', config('fabos.lab.timezone'));

        return app(EspacioBookingService::class)->reservar(
            $quien, $this->sala, $desde, $desde->copy()->addHours(2), 1, [$this->multimetro->id],
        );
    }

    /** @return list<int> */
    private function idsVisibles(array $filtros = []): array
    {
        $prueba = Livewire::test(ListReservations::class);

        foreach ($filtros as $nombre => $valor) {
            $prueba->set('tableFilters.' . $nombre . '.isActive', $valor);
        }

        return $prueba->instance()->getTableRecords()->pluck('id')->all();
    }

    public function test_la_lista_abre_con_la_sala_y_su_herramienta(): void
    {
        $sala = $this->reservaConHerramienta();
        $herramienta = Reservation::where('parent_reservation_id', $sala->id)->firstOrFail();

        $this->entraComoAdmin();

        $visibles = $this->idsVisibles();

        $this->assertContains($sala->id, $visibles, 'la sala ya no se esconde de entrada');
        $this->assertContains($herramienta->id, $visibles);
    }

    /** Y encendido, filtra de verdad: era lo que no hacía. */
    public function test_solo_equipos_encendido_esconde_la_sala(): void
    {
        $sala = $this->reservaConHerramienta();
        $herramienta = Reservation::where('parent_reservation_id', $sala->id)->firstOrFail();

        $this->entraComoAdmin();

        $visibles = $this->idsVisibles(['solo_equipos' => true]);

        $this->assertContains($herramienta->id, $visibles, 'una herramienta es un equipo');
        $this->assertNotContains($sala->id, $visibles, 'una sala no lo es');
    }

    /**
     * Y que no vuelva a pasar en ninguna pantalla.
     *
     * Filament inyecta los parámetros de sus closures por NOMBRE. Uno llamado
     * de otra manera no recibe la consulta de la tabla sino un constructor sin
     * modelo sacado del contenedor: no falla, no avisa, simplemente no hace
     * nada. Es el peor tipo de fallo —la pantalla dice que filtra y enseña
     * todo— y por eso se vigila desde aquí y no a base de acordarse.
     */
    public function test_ninguna_closure_de_filament_llama_query_de_otra_manera(): void
    {
        $sospechosas = [];

        $ficheros = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Filament')),
        );

        foreach ($ficheros as $fichero) {
            if ($fichero->getExtension() !== 'php') {
                continue;
            }

            $contenido = file_get_contents($fichero->getPathname());

            /*
             * Solo en el BORDE de Filament: `->query()`, `->modifyQueryUsing()`,
             * `->defaultSort()`. Una closure anidada dentro de un `where()` de
             * Eloquent puede llamarse como quiera —Laravel la pasa por
             * posición— y mirarlas todas daba falsos positivos.
             */
            preg_match_all(
                '/->(?:query|modifyQueryUsing|defaultSort)\(\s*(?:fn|function)\s*\(\s*Builder\s+\$(?!query\b)(\w+)/',
                $contenido,
                $coincidencias,
            );

            foreach ($coincidencias[1] as $nombre) {
                $sospechosas[] = str_replace(app_path(), '', $fichero->getPathname()) . ' → $' . $nombre;
            }
        }

        $this->assertSame(
            [],
            $sospechosas,
            "Estas closures reciben un Builder con otro nombre y Filament no les pasa la consulta:\n"
            . implode("\n", $sospechosas),
        );
    }
}
