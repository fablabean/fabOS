<?php

namespace Tests\Feature;

use App\Filament\Resources\CourseEditions\Pages\EditCourseEdition;
use App\Filament\Resources\CourseEditions\RelationManagers\PreenrollmentsRelationManager;
use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\Preenrollment;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Training\PreinscripcionService;
use App\Services\Training\TrainingService;
use App\Support\FactoresDeSesion;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** La pestaña de preinscritos responde como una persona la usaría (§9). */
class BackofficePreinscripcionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(NotificationTemplateSeeder::class);
    }

    private function admin(): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole(User::ROL_ADMINISTRADOR);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u;
    }

    private function cohorte(array $curso = [], array $datos = []): CourseEdition
    {
        $curso = Course::create(array_merge([
            'slug' => 'tera-' . uniqid(), 'name' => 'tera · Fab Academy', 'level' => 'tera',
            'is_active' => true, 'is_public' => true, 'by_preenrollment' => true,
        ], $curso));

        return CourseEdition::create(array_merge([
            'course_id' => $curso->id,
            'code'      => app(TrainingService::class)->siguienteCodigo(),
            'starts_on' => now()->addMonths(4)->toDateString(),
            'capacity'  => 8,
            'status'    => 'planeada',
            'minimum_to_open' => 5,
        ], $datos));
    }

    private function preinscrito(CourseEdition $cohorte, array $datos = []): Preenrollment
    {
        return app(PreinscripcionService::class)->preinscribir($cohorte, array_merge([
            'name' => 'Ana Pérez', 'email' => 'ana' . uniqid() . '@empresa.co',
            'city' => 'Medellín', 'occupation' => 'Diseñadora', 'funding' => 'propio',
        ], $datos));
    }

    private function pestana(CourseEdition $cohorte)
    {
        return Livewire::test(PreenrollmentsRelationManager::class, [
            'ownerRecord' => $cohorte,
            'pageClass'   => EditCourseEdition::class,
        ]);
    }

    public function test_la_pestana_solo_aparece_en_cursos_que_entran_por_preinscripcion(): void
    {
        $this->admin();

        $this->assertTrue(PreenrollmentsRelationManager::canViewForRecord($this->cohorte(), EditCourseEdition::class));
        $this->assertFalse(PreenrollmentsRelationManager::canViewForRecord(
            $this->cohorte(['by_preenrollment' => false]), EditCourseEdition::class,
        ));
    }

    public function test_la_pestana_dice_cuantos_somos_de_cuantos(): void
    {
        $this->admin();
        $cohorte = $this->cohorte();
        $this->preinscrito($cohorte);
        $this->preinscrito($cohorte);

        $this->assertSame('2 de 5', PreenrollmentsRelationManager::getBadge($cohorte, EditCourseEdition::class));
    }

    public function test_se_ve_quien_es_y_como_piensa_pagarlo(): void
    {
        $this->admin();
        $cohorte = $this->cohorte();
        $this->preinscrito($cohorte, ['name' => 'Juan Beca', 'funding' => 'beca']);

        $this->pestana($cohorte)
            ->assertSee('Juan Beca')
            ->assertSee('Necesitaría una beca o apoyo');
    }

    public function test_el_equipo_anota_a_alguien_que_pregunto_por_el_pasillo(): void
    {
        $this->admin();
        $cohorte = $this->cohorte();

        $this->pestana($cohorte)
            ->callTableAction('create', data: [
                'name' => 'Luis del Pasillo', 'email' => 'Luis@test.co', 'funding' => 'no_se',
            ])
            ->assertHasNoTableActionErrors();

        $p = Preenrollment::first();
        $this->assertSame('luis@test.co', $p->email);
        $this->assertSame('equipo', $p->source);
        $this->assertNull($p->consent_at, 'lo que anota el equipo no finge una autorización');
    }

    public function test_confirmar_desde_la_lista(): void
    {
        $this->admin();
        $cohorte = $this->cohorte();
        $p = $this->preinscrito($cohorte);

        $this->pestana($cohorte)->callTableAction('confirmar', $p)->assertHasNoTableActionErrors();

        $this->assertSame('confirmado', $p->fresh()->status);
        $this->assertNotNull($p->fresh()->confirmed_at);
    }

    public function test_inscribir_solo_aparece_con_la_cohorte_abierta(): void
    {
        $this->admin();
        $cohorte = $this->cohorte();
        $p = $this->preinscrito($cohorte);

        $this->pestana($cohorte)->assertTableActionHidden('inscribir', $p);

        app(PreinscripcionService::class)->abrirCohorte($cohorte);

        $this->pestana($cohorte->fresh())->assertTableActionVisible('inscribir', $p);
    }

    public function test_abrir_la_cohorte_desde_la_pestana_avisa_y_luego_se_inscribe(): void
    {
        $this->admin();
        UserCategory::firstOrCreate(['slug' => 'externo'], ['name' => 'Externo', 'position' => 2]);
        $cohorte = $this->cohorte();
        $p = $this->preinscrito($cohorte, ['email' => 'ana@test.co']);

        $this->pestana($cohorte)->callTableAction('abrir')->assertHasNoTableActionErrors();

        $this->assertSame('abierta', $cohorte->fresh()->status);
        $this->assertDatabaseHas('notification_logs', ['key' => 'curso.cohorte_abierta', 'to' => 'ana@test.co']);

        $this->pestana($cohorte->fresh())->callTableAction('inscribir', $p)->assertHasNoTableActionErrors();

        $this->assertSame('inscrito', $p->fresh()->status);
        $this->assertSame(1, $cohorte->fresh()->inscritos());
    }

    public function test_el_menu_avisa_de_los_preinscritos_sin_atender(): void
    {
        $this->admin();
        $cohorte = $this->cohorte();
        $this->preinscrito($cohorte);
        app(PreinscripcionService::class)->confirmar($this->preinscrito($cohorte));

        $this->assertSame('1', \App\Filament\Resources\CourseEditions\CourseEditionResource::getNavigationBadge());
    }

    public function test_las_reglas_de_la_preinscripcion_quedan_documentadas(): void
    {
        $this->admin();

        $this->get('/admin/reglas')
            ->assertOk()
            ->assertSee('uno se preinscribe')
            ->assertSee('no ocupa cupo');
    }
}
