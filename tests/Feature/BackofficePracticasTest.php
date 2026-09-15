<?php

namespace Tests\Feature;

use App\Filament\Resources\InternshipCalls\Pages\ListInternshipCalls;
use App\Filament\Resources\InternshipCalls\RelationManagers\ApplicationsRelationManager;
use App\Models\InternshipApplication;
use App\Models\InternshipCall;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Personas\ConvocatoriaDePractica;
use App\Support\FactoresDeSesion;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Las pantallas de prácticas responden como una persona las usaría (§5). */
class BackofficePracticasTest extends TestCase
{
    use RefreshDatabase;

    private function conRol(string $rol): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole($rol);

        return $u->fresh();
    }

    private function entra(User $u): self
    {
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $this;
    }

    private function admin(): User
    {
        $u = $this->conRol(User::ROL_ADMINISTRADOR);
        $this->entra($u);

        return $u;
    }

    private function convocatoria(array $datos = []): InternshipCall
    {
        return InternshipCall::create(array_merge([
            'name' => 'Prácticas 2026-1', 'period' => '2026-1', 'is_public' => true,
        ], $datos));
    }

    private function postulacion(InternshipCall $convocatoria, array $datos = []): InternshipApplication
    {
        return app(ConvocatoriaDePractica::class)->postular($convocatoria, array_merge([
            'name'       => 'Ana Pérez',
            'email'      => 'ana' . uniqid() . '@otra.edu.co',
            'program'    => 'Diseño industrial',
            'motivation' => 'Quiero aprender fabricación digital.',
        ], $datos), origen: 'equipo');
    }

    // ------------------------------------------------------------ la pantalla

    public function test_el_administrador_ve_las_convocatorias(): void
    {
        $this->admin();
        $convocatoria = $this->convocatoria(['name' => 'Prácticas del semestre']);
        $this->postulacion($convocatoria);

        $this->get('/admin/internship-calls')
            ->assertOk()
            ->assertSee('Prácticas del semestre');
    }

    public function test_la_lista_dice_cuantas_faltan_por_evaluar(): void
    {
        $this->admin();
        $convocatoria = $this->convocatoria();
        $this->postulacion($convocatoria);
        $this->postulacion($convocatoria);

        // Es la pregunta con la que se abre la pantalla.
        $this->get('/admin/internship-calls')->assertOk()->assertSee('Sin evaluar');
    }

    public function test_se_puede_abrir_una_convocatoria(): void
    {
        $this->admin();

        Livewire::test(ListInternshipCalls::class)
            ->callAction('create', [
                'name'      => 'Prácticas 2027-1',
                'status'    => 'abierta',
                'is_public' => true,
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(1, InternshipCall::where('name', 'Prácticas 2027-1')->count());
    }

    // ------------------------------------------------------------ evaluar

    public function test_evaluar_deja_la_decision_con_autor_y_motivo(): void
    {
        $quien = $this->admin();
        $convocatoria = $this->convocatoria();
        $postulacion = $this->postulacion($convocatoria);

        Livewire::test(ApplicationsRelationManager::class, [
            'ownerRecord' => $convocatoria,
            'pageClass'   => \App\Filament\Resources\InternshipCalls\Pages\EditInternshipCall::class,
        ])
            ->callTableAction('evaluar', $postulacion, [
                'decision' => 'aceptado',
                'score'    => 5,
                'nota'     => 'Buen portafolio',
                'fablab'   => 'Podría llevar el taller de textiles',
            ])
            ->assertHasNoTableActionErrors();

        $postulacion->refresh();
        $this->assertSame('aceptado', $postulacion->status);
        $this->assertSame(5, $postulacion->score);
        $this->assertSame($quien->id, $postulacion->evaluated_by);
    }

    public function test_la_accion_de_crearle_cuenta_solo_aparece_en_los_aceptados(): void
    {
        $this->admin();
        $convocatoria = $this->convocatoria();
        $aceptado = $this->postulacion($convocatoria);
        $sinEvaluar = $this->postulacion($convocatoria);
        app(ConvocatoriaDePractica::class)->evaluar($aceptado, 'aceptado');

        $componente = Livewire::test(ApplicationsRelationManager::class, [
            'ownerRecord' => $convocatoria,
            'pageClass'   => \App\Filament\Resources\InternshipCalls\Pages\EditInternshipCall::class,
        ]);

        $componente->assertTableActionVisible('cuenta', $aceptado);
        $componente->assertTableActionHidden('cuenta', $sinEvaluar);
    }

    public function test_crearle_cuenta_desde_la_lista_le_da_el_rol_de_practicante(): void
    {
        $this->admin();
        Role::findOrCreate(User::ROL_PRACTICANTE, 'web');
        $categoria = UserCategory::firstOrCreate(['slug' => 'externo'], ['name' => 'Externo', 'position' => 1]);
        $convocatoria = $this->convocatoria();
        $postulacion = $this->postulacion($convocatoria);
        app(ConvocatoriaDePractica::class)->evaluar($postulacion, 'aceptado');

        Livewire::test(ApplicationsRelationManager::class, [
            'ownerRecord' => $convocatoria,
            'pageClass'   => \App\Filament\Resources\InternshipCalls\Pages\EditInternshipCall::class,
        ])
            ->callTableAction('cuenta', $postulacion, [
                'user_category_id' => $categoria->id,
                'roles'            => [User::ROL_PRACTICANTE],
            ])
            ->assertHasNoTableActionErrors();

        $persona = $postulacion->fresh()->user;

        $this->assertNotNull($persona);
        $this->assertTrue($persona->hasRole(User::ROL_PRACTICANTE));
    }

    // ------------------------------------------------------------ accesos

    public function test_un_consultor_no_puede_abrir_convocatorias(): void
    {
        $this->entra($this->conRol(User::ROL_CONSULTOR));

        $this->assertFalse(\App\Filament\Resources\InternshipCalls\InternshipCallResource::canCreate());
    }

    public function test_las_reglas_de_las_practicas_quedan_documentadas(): void
    {
        $this->admin();

        $this->get('/admin/reglas')
            ->assertOk()
            ->assertSee('Convocatorias de práctica')
            ->assertSee('no lleva jornada');
    }
}
