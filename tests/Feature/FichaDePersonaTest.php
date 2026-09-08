<?php

namespace Tests\Feature;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Certifab;
use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\Enrollment;
use App\Models\Project;
use App\Models\Reservation;
use App\Models\RiskFamily;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Money\ChargeService;
use App\Services\Projects\ProjectService;
use App\Support\FactoresDeSesion;
use Database\Seeders\NotificationTemplateSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La ficha de una persona: todo lo que ha pasado con ella, en una pantalla.
 *
 * Saber si alguien tenia proyectos, que habia reservado, que cursos llevaba
 * o cuanto saldo le quedaba obligaba a ir seccion por seccion filtrando por
 * su nombre.
 */
class FichaDePersonaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(NotificationTemplateSeeder::class);

        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        UserCategory::firstOrCreate(
            ['slug' => 'invitado'],
            ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1, 'client_kind' => 'externo'],
        );
    }

    private function entra(string $rol): User
    {
        $u = User::create(['name' => 'Equipo ' . uniqid(), 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $u->assignRole($rol);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    /** Una persona con historia: saldo, certifab, curso, proyecto y reservas. */
    private function personaConHistoria(): User
    {
        $cat = UserCategory::create(['slug' => 'estudiante', 'name' => 'Estudiante', 'can_reserve' => true, 'rate_factor' => 1, 'client_kind' => 'estudiante']);
        $p = User::create(['name' => 'Samuel Rojas', 'email' => 'samuel@test.co', 'phone' => '3001112233', 'status' => 'activo', 'user_category_id' => $cat->id, 'category_confirmed' => true]);

        app(ChargeService::class)->dotar($p, 50_000, '2026-09');

        $area = Area::create(['slug' => 'impresion-3d', 'name' => 'Impresión 3D']);
        $familia = RiskFamily::create(['area_id' => $area->id, 'slug' => 'fdm', 'name' => 'Impresión FDM']);
        Certifab::create(['user_id' => $p->id, 'risk_family_id' => $familia->id, 'level' => 'kilo', 'granted_at' => now()]);

        $curso = Course::create([
            'slug' => 'creality', 'name' => 'Creality Hi · kilo', 'area_id' => $area->id, 'level' => 'kilo',
            'summary' => 'x', 'hours' => 4, 'passing_score' => 80, 'requires_practical' => true, 'is_active' => true, 'is_public' => true,
        ]);
        $edicion = CourseEdition::create(['course_id' => $curso->id, 'code' => 'ED-7', 'capacity' => 10, 'status' => 'abierta', 'is_self_paced' => true]);
        Enrollment::create(['course_edition_id' => $edicion->id, 'user_id' => $p->id, 'status' => 'inscrito', 'enrolled_at' => now(), 'theory_score' => 100, 'theory_attempts' => 1, 'theory_passed_at' => now()]);

        $proyecto = app(ProjectService::class)->registrarIdea(['name' => 'Trofeos de robótica', 'source' => 'whatsapp', 'organization' => 'Semillero']);
        $proyecto->update(['requested_by' => $p->id]);

        $equipo = Asset::create(['name' => 'Creality Hi', 'area_id' => $area->id, 'kind' => 'fijo', 'status' => 'operativo', 'is_reservable' => true]);
        Reservation::create([
            'reservable_type' => Asset::class, 'reservable_id' => $equipo->id, 'user_id' => $p->id,
            'status' => 'confirmada', 'mode' => 'directa', 'starts_at' => now()->addDay()->setTime(10, 0), 'ends_at' => now()->addDay()->setTime(11, 0),
            'purpose' => 'Imprimir el trofeo',
        ]);

        return $p->fresh();
    }

    public function test_la_ficha_ensena_todo_lo_de_la_persona(): void
    {
        $this->entra(User::ROL_ADMINISTRADOR);
        $p = $this->personaConHistoria();

        $this->get(UserResource::getUrl('view', ['record' => $p]))
            ->assertOk()
            ->assertSee('Samuel Rojas')
            ->assertSee('samuel@test.co')
            ->assertSee('3001112233')
            ->assertSee('Estudiante')
            ->assertSee('confirmada')
            // FabCoins
            ->assertSee('500,00')
            // Certifab
            ->assertSee('Impresión FDM')
            ->assertSee('kilo')
            ->assertSee('vigente')
            // Formación
            ->assertSee('Creality Hi · kilo')
            ->assertSee('100% · 1 intento')
            ->assertSee('Pendiente')
            // Proyecto
            ->assertSee('Trofeos de robótica')
            ->assertSee('Lo pidió')
            // Reserva
            ->assertSee('Creality Hi')
            ->assertSee('Imprimir el trofeo')
            ->assertSee('Confirmada');
    }

    /** Un consultor mira; es justo lo que la ficha es: mirar. */
    public function test_un_consultor_tambien_la_ve(): void
    {
        $this->entra(User::ROL_CONSULTOR);
        $p = $this->personaConHistoria();

        $this->get(UserResource::getUrl('view', ['record' => $p]))->assertOk()->assertSee('Samuel Rojas');
    }

    /** Una persona sin historia no revienta la ficha: dice que no hay nada. */
    public function test_sin_historia_dice_que_no_hay_nada(): void
    {
        $this->entra(User::ROL_ADMINISTRADOR);
        $p = User::create(['name' => 'Nadie Nuevo', 'email' => 'nadie@test.co', 'status' => 'activo']);

        $this->get(UserResource::getUrl('view', ['record' => $p]))
            ->assertOk()
            ->assertSee('Sin movimientos todavía')
            ->assertSee('Ninguno todavía')
            ->assertSee('No se ha inscrito en ningún curso')
            ->assertSee('No aparece en ningún proyecto')
            ->assertSee('No ha reservado nada');
    }

    /** En el listado, el ojo abre la ficha y las acciones van como iconos. */
    public function test_el_listado_tiene_el_ojo(): void
    {
        $this->entra(User::ROL_ADMINISTRADOR);
        $p = $this->personaConHistoria();

        Livewire::test(ListUsers::class)
            ->assertActionExists(TestAction::make('view')->table($p))
            ->assertActionHasUrl(TestAction::make('view')->table($p), UserResource::getUrl('view', ['record' => $p]))
            ->assertActionExists(TestAction::make('edit')->table($p));
    }
}
