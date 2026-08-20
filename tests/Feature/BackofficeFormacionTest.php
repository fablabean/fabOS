<?php

namespace Tests\Feature;

use App\Filament\Resources\CourseEditions\Pages\ListCourseEditions;
use App\Models\Area;
use App\Models\Certifab;
use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\RiskFamily;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Training\TrainingService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Las pantallas de Formación (§9). */
class BackofficeFormacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function conRol(?string $rol = null): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $cat = UserCategory::firstOrCreate(
            ['slug' => 'estudiante'],
            ['name' => 'Estudiante', 'can_reserve' => true],
        );

        $u = User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co',
            'status' => 'activo', 'user_category_id' => $cat->id,
        ]);

        if ($rol) {
            $u->assignRole($rol);
        }

        return $u->fresh();
    }

    private function entra(User $u): self
    {
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession(['segundo_factor_verificado' => true]);

        return $this;
    }

    private function edicion(array $datos = []): CourseEdition
    {
        $area = Area::create(['slug' => 'a-' . uniqid(), 'name' => 'Impresión 3D']);
        $familia = RiskFamily::create([
            'area_id' => $area->id, 'slug' => 'f-' . uniqid(), 'name' => 'FDM',
        ]);

        $curso = Course::create([
            'slug' => 'c-' . uniqid(), 'name' => 'byte · Impresión 3D',
            'level' => 'byte', 'is_active' => true, 'is_public' => true,
        ]);
        $curso->riskFamilies()->sync([$familia->id]);

        return CourseEdition::create(array_merge([
            'course_id' => $curso->id,
            'code'      => app(TrainingService::class)->siguienteCodigo(),
            'starts_on' => now()->addWeek()->toDateString(),
            'capacity'  => 10,
            'status'    => 'abierta',
        ], $datos));
    }

    public function test_los_listados_de_formacion_cargan(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $edicion = $this->edicion();

        $this->entra($admin)->get('/admin/courses')
            ->assertOk()
            ->assertSee('byte · Impresión 3D')
            ->assertSee('FDM');

        $this->entra($admin)->get('/admin/course-editions')
            ->assertOk()
            ->assertSee($edicion->code)
            ->assertSee('0 / 10');
    }

    public function test_cerrar_una_edicion_aprueba_y_habilita(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $edicion = $this->edicion();

        $alumno = $this->conRol();
        app(TrainingService::class)->inscribir($edicion, $alumno);

        $this->entra($admin);

        Livewire::test(ListCourseEditions::class)
            ->callAction(TestAction::make('cerrar')->table($edicion))
            ->assertHasNoActionErrors();

        $this->assertSame('cerrada', $edicion->fresh()->status);

        $certifab = Certifab::where('user_id', $alumno->id)->first();
        $this->assertNotNull($certifab, 'cerrar la edición habilita a quien aprobó');
        $this->assertSame('curso', $certifab->granted_via);

        $inscripcion = $edicion->enrollments()->first();
        $this->assertSame('aprobado', $inscripcion->status);
        $this->assertNotNull($inscripcion->certificate_code, 'y le deja su certificado');
    }

    public function test_abrir_inscripciones_desde_el_listado(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $edicion = $this->edicion(['status' => 'planeada']);

        $this->entra($admin);

        Livewire::test(ListCourseEditions::class)
            ->callAction(TestAction::make('abrir')->table($edicion))
            ->assertHasNoActionErrors();

        $this->assertSame('abierta', $edicion->fresh()->status);
    }
}
