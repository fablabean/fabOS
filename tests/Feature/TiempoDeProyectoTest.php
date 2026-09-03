<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\RelationManagers\TasksRelationManager;
use App\Models\Area;
use App\Models\Asset;
use App\Models\AssetAdvisor;
use App\Models\Project;
use App\Models\ProjectTask;
use App\Models\Reservation;
use App\Models\Space;
use App\Models\User;
use App\Models\UserCategory;
use App\Models\WorkSchedule;
use App\Services\Auth\TwoFactorService;
use App\Services\Booking\AsesoriaService;
use App\Services\Booking\BookingException;
use App\Services\Booking\EspacioBookingService;
use App\Services\Projects\ProjectService;
use App\Services\Projects\TiempoDeProyecto;
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
 * Apartar el tiempo de alguien para una tarea de proyecto (§10, §11).
 *
 * Quien lleva un proyecto necesita horas seguidas, y en esas horas no puede
 * estar en una asesoría ni acompañando una sala. El bloque es una reserva de
 * la persona, en la misma tabla, y todo lo que reparte gente lo respeta sin
 * haberle enseñado nada. Lo que se prueba aquí es justo eso: que el bloque
 * te saca del reparto, que no gana a lo que ya tenías, y quién puede apartar
 * el tiempo de quién.
 */
class TiempoDeProyectoTest extends TestCase
{
    use RefreshDatabase;

    private Asset $equipo;
    private User $ana;
    private User $beto;
    private Project $proyecto;
    private ProjectTask $tarea;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();

        // Lunes 24/08/2026, 07:00, quieto: las jornadas son de lunes.
        $this->travelTo(Carbon::parse('2026-08-24 07:00', config('fabos.lab.timezone')));

        $area = Area::create(['name' => 'Prototipado', 'slug' => 'prototipado']);
        $this->equipo = Asset::create([
            'name' => 'Cortadora láser', 'slug' => 'laser', 'area_id' => $area->id,
            'status' => 'operativo', 'is_reservable' => true,
        ]);

        // Dos que asesoran la láser, ambos en jornada los lunes.
        $this->ana = $this->colaborador('Ana');
        $this->beto = $this->colaborador('Beto');

        // Ana lleva un proyecto con una tarea suya.
        $this->proyecto = app(ProjectService::class)->registrarIdea(['name' => 'Señalética', 'summary' => 'Diez piezas.'], $this->ana);
        $this->proyecto->update(['lead_id' => $this->ana->id]);
        $this->tarea = $this->proyecto->tasks()->create([
            'title' => 'Cortar las piezas', 'status' => 'por_hacer', 'assigned_to' => $this->ana->id, 'created_by' => $this->ana->id,
        ]);
    }

    private function colaborador(string $nombre): User
    {
        $u = User::create(['name' => $nombre, 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_PRACTICANTE, 'web'));

        WorkSchedule::create([
            'user_id' => $u->id, 'weekday' => 1, 'starts_at' => '08:00', 'ends_at' => '18:00',
            'break_minutes' => 60, 'modalidad' => WorkSchedule::PRESENCIAL, 'effective_from' => '2026-01-01',
        ]);
        AssetAdvisor::create(['user_id' => $u->id, 'asset_id' => $this->equipo->id, 'es_responsable' => false]);

        return $u;
    }

    private function alguien(): User
    {
        $cat = UserCategory::firstOrCreate(['slug' => 'estudiante'], ['name' => 'Estudiante', 'can_reserve' => true]);

        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);
    }

    private function hora(string $hhmm): Carbon
    {
        return Carbon::parse('2026-08-24 ' . $hhmm, config('fabos.lab.timezone'));
    }

    private function tiempo(): TiempoDeProyecto
    {
        return app(TiempoDeProyecto::class);
    }

    // ------------------------------------------------------------- apartar

    public function test_el_bloque_es_una_reserva_del_tiempo_de_la_persona(): void
    {
        $b = $this->tiempo()->apartar($this->tarea, $this->ana, $this->hora('14:00'), $this->hora('17:00'), $this->ana);

        $this->assertTrue($b->esBloqueDeProyecto());
        $this->assertSame(User::class, $b->reservable_type);
        $this->assertSame($this->ana->id, $b->reservable_id);
        $this->assertSame($this->proyecto->id, $b->project_id);
        $this->assertSame($this->tarea->id, $b->project_task_id);
        $this->assertStringContainsString('Cortar las piezas', $b->purpose);
    }

    /**
     * Lo que importa: con el bloque puesto, una asesoría a esa hora se la
     * lleva Beto. Sin el bloque, el turno podría dársela a Ana.
     */
    public function test_con_el_tiempo_apartado_la_asesoria_va_a_otra_persona(): void
    {
        $this->tiempo()->apartar($this->tarea, $this->ana, $this->hora('14:00'), $this->hora('17:00'), $this->ana);

        $disponibles = app(AsesoriaService::class)->disponiblesPara($this->equipo, $this->hora('15:00'), $this->hora('15:45'));

        $this->assertFalse($disponibles->contains('id', $this->ana->id), 'Ana está en su proyecto');
        $this->assertTrue($disponibles->contains('id', $this->beto->id));

        $asesoria = app(AsesoriaService::class)->agendar($this->alguien(), $this->equipo, $this->hora('15:00'), $this->hora('15:45'));

        $this->assertSame($this->beto->id, $asesoria->reservable_id);
    }

    /** Y de acompañar una sala: quien tiene tiempo apartado no está. */
    public function test_con_el_tiempo_apartado_no_se_le_pone_a_acompanar_una_sala(): void
    {
        $this->tiempo()->apartar($this->tarea, $this->ana, $this->hora('14:00'), $this->hora('17:00'), $this->ana);
        $sala = Space::create(['slug' => 'sala', 'name' => 'Sala', 'capacity' => 10, 'is_reservable' => true]);

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/Ana ya tiene algo a esa hora/');

        app(EspacioBookingService::class)->reservar(
            $this->alguien(), $sala, $this->hora('15:00'), $this->hora('16:00'), 4, [], null, null, [$this->ana->id],
        );
    }

    /** El bloque no gana a lo que ya había: se dice qué es, para moverlo. */
    public function test_no_se_aparta_encima_de_una_asesoria_ya_asignada(): void
    {
        // Ana es la única asesora libre a las 10: la asesoría es suya.
        WorkSchedule::where('user_id', $this->beto->id)->delete();
        app(AsesoriaService::class)->agendar($this->alguien(), $this->equipo, $this->hora('10:00'), $this->hora('10:45'));

        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/ya tiene una asesoría a esa hora/');

        $this->tiempo()->apartar($this->tarea, $this->ana, $this->hora('09:00'), $this->hora('12:00'), $this->ana);
    }

    // ------------------------------------------------------- quien aparta

    public function test_cada_uno_aparta_el_suyo_y_el_responsable_el_del_equipo(): void
    {
        $this->proyecto->members()->create(['user_id' => $this->beto->id, 'role' => 'equipo']);

        // Beto se aparta el suyo.
        $b = $this->tiempo()->apartar($this->tarea, $this->beto, $this->hora('09:00'), $this->hora('10:00'), $this->beto);
        $this->assertSame($this->beto->id, $b->reservable_id);

        // Ana, que lleva el proyecto, le aparta a Beto.
        $b2 = $this->tiempo()->apartar($this->tarea, $this->beto, $this->hora('11:00'), $this->hora('12:00'), $this->ana);
        $this->assertSame($this->beto->id, $b2->reservable_id);

        // Beto no le aparta a Ana: no lleva el proyecto.
        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/quien lleva el proyecto/');

        $this->tiempo()->apartar($this->tarea, $this->ana, $this->hora('13:00'), $this->hora('14:00'), $this->beto);
    }

    public function test_no_se_aparta_tiempo_de_quien_no_es_del_proyecto(): void
    {
        $this->expectException(BookingException::class);
        $this->expectExceptionMessageMatches('/no está en el equipo/');

        $this->tiempo()->apartar($this->tarea, $this->beto, $this->hora('09:00'), $this->hora('10:00'), $this->ana);
    }

    // ---------------------------------------------------------- pantallas

    public function test_mi_cuenta_enseña_el_tiempo_apartado(): void
    {
        $this->tiempo()->apartar($this->tarea, $this->ana, $this->hora('14:00'), $this->hora('17:00'), $this->ana);

        $this->actingAs($this->ana)->get(route('home'))
            ->assertOk()
            ->assertSee('Tiempo apartado para proyectos')
            ->assertSee('Cortar las piezas');
    }

    /** Desde la pestaña de tareas del proyecto, con el selector del panel. */
    public function test_se_aparta_desde_la_tarea_en_el_panel(): void
    {
        $factores = app(TwoFactorService::class);
        $secreto = $factores->generarSecreto($this->ana);
        $factores->confirmar($this->ana, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($this->ana->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        Livewire::test(TasksRelationManager::class, [
            'ownerRecord' => $this->proyecto,
            'pageClass'   => EditProject::class,
        ])
            ->callAction(TestAction::make('apartar')->table($this->tarea), [
                'para_quien' => $this->ana->id,
                // 14:00 de Bogotá, como en pantalla: el selector guarda UTC.
                'desde' => '2026-08-24 14:00:00',
                'hasta' => '2026-08-24 17:00:00',
            ])
            ->assertHasNoActionErrors();

        $b = Reservation::where('mode', 'proyecto')->firstOrFail();

        $this->assertSame('14:00', $b->starts_at->timezone(config('fabos.lab.timezone'))->format('H:i'));
        $this->assertSame($this->tarea->id, $b->project_task_id);
    }
}
