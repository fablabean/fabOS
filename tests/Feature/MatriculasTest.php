<?php

namespace Tests\Feature;

use App\Filament\Resources\Matriculas\MatriculaResource;
use App\Filament\Resources\Matriculas\Pages\CreateMatricula;
use App\Models\Matricula;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Ledger\LedgerService;
use App\Services\Personas\MatriculaException;
use App\Services\Personas\MatriculaService;
use App\Support\FactoresDeSesion;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Educación Continua matricula a su gente (§5, §12).
 *
 * Lo que se defiende: que matricular cree la cuenta si no existe y reutilice
 * la que exista, que la persona reciba la subcategoría del programa y con ella
 * su bienvenida, y que al terminar vuelva a estudiante general sin perder
 * lo que tenga.
 */
class MatriculasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Setting::put(Settings::BENEFICIO_ACTIVO, true, 'finanzas');
        $this->seed(\Database\Seeders\CatalogSeeder::class);
    }

    private function programa(string $slug): UserCategory
    {
        return UserCategory::where('slug', $slug)->firstOrFail();
    }

    private function saldo(User $u): int
    {
        return app(LedgerService::class)->saldoDe($u);
    }

    private function admin(): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }
        $u = User::create(['name' => 'Admin', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(User::ROL_ADMINISTRADOR);
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u;
    }

    public function test_matricular_crea_la_cuenta_con_la_subcategoria_y_su_bienvenida(): void
    {
        $quien = User::create(['name' => 'EC', 'email' => 'ec@test.co', 'status' => 'activo']);

        $m = app(MatriculaService::class)->matricular([
            'name' => 'Ana Bootcamp', 'email' => 'Ana@Empresa.co', 'program_name' => 'Bootcamp de IoT 2026-2',
        ], $this->programa('estudiante-bootcamp'), $quien);

        $persona = $m->user;
        $this->assertSame('ana@empresa.co', $persona->email);
        $this->assertSame('estudiante-bootcamp', $persona->category->slug);
        $this->assertTrue($persona->category_confirmed, 'quien matricula sabe quién es');
        $this->assertSame('activo', $persona->status);
        $this->assertSame(1000, $this->saldo($persona), 'nace con los 10 del bootcamp');
        $this->assertSame($quien->id, $m->registered_by);
        $this->assertTrue($m->vigente());
    }

    public function test_si_ya_tenia_cuenta_se_reutiliza_y_cambia_de_categoria(): void
    {
        $ya = User::create(['name' => 'Ana', 'email' => 'ana@gmail.com', 'status' => 'activo', 'user_category_id' => $this->programa('externo')->id]);
        $this->assertSame(0, $this->saldo($ya));

        $m = app(MatriculaService::class)->matricular([
            'name' => 'Ana P.', 'email' => 'ana@gmail.com', 'program_name' => 'Diplomado en fabricación',
        ], $this->programa('estudiante-diplomado'));

        $this->assertSame($ya->id, $m->user_id);
        $this->assertSame(1, User::where('email', 'ana@gmail.com')->count());
        $this->assertSame('estudiante-diplomado', $ya->fresh()->category->slug);
        $this->assertSame(3000, $this->saldo($ya), 'la bienvenida del diplomado');
    }

    public function test_solo_se_matricula_en_categorias_de_estudiante(): void
    {
        $this->expectException(MatriculaException::class);

        app(MatriculaService::class)->matricular(
            ['name' => 'X', 'email' => 'x@test.co', 'program_name' => 'Nada'],
            $this->programa('externo'),
        );
    }

    public function test_al_terminar_vuelve_a_estudiante_general_sin_perder_el_saldo(): void
    {
        $m = app(MatriculaService::class)->matricular(
            ['name' => 'Ana', 'email' => 'ana@test.co', 'program_name' => 'Curso corto'],
            $this->programa('estudiante-curso'),
        );
        $this->assertSame(2000, $this->saldo($m->user));

        app(MatriculaService::class)->cerrar($m);

        $this->assertFalse($m->fresh()->vigente());
        $this->assertSame('estudiante', $m->user->fresh()->category->slug);
        $this->assertSame(2000, $this->saldo($m->user), 'lo que tenía se queda');
    }

    public function test_con_otro_programa_vigente_no_se_le_baja_la_categoria(): void
    {
        $curso = app(MatriculaService::class)->matricular(
            ['name' => 'Ana', 'email' => 'ana@test.co', 'program_name' => 'Curso'], $this->programa('estudiante-curso'),
        );
        $diplomado = app(MatriculaService::class)->matricular(
            ['name' => 'Ana', 'email' => 'ana@test.co', 'program_name' => 'Diplomado'], $this->programa('estudiante-diplomado'),
        );

        app(MatriculaService::class)->cerrar($curso);

        $this->assertSame('estudiante-diplomado', $diplomado->user->fresh()->category->slug);
    }

    // ------------------------------------------------------------- el panel

    public function test_educacion_continua_matricula_desde_el_panel(): void
    {
        $this->admin();

        Livewire::test(CreateMatricula::class)
            ->fillForm([
                'name' => 'Luis Diplomado', 'email' => 'luis@empresa.co',
                'user_category_id' => $this->programa('estudiante-diplomado')->id,
                'program_name' => 'Diplomado en fabricación digital', 'starts_on' => '2026-10-01', 'ends_on' => '2027-03-30',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $m = Matricula::first();
        $this->assertSame('luis@empresa.co', $m->user->email);
        $this->assertSame(3000, $this->saldo($m->user));
        $this->assertSame('Diplomado en fabricación digital', $m->program_name);
    }

    public function test_la_lista_dice_quien_esta_en_que_y_con_cuanto(): void
    {
        $this->admin();
        app(MatriculaService::class)->matricular(
            ['name' => 'Ana Bootcamp', 'email' => 'ana@test.co', 'program_name' => 'Bootcamp de IoT'], $this->programa('estudiante-bootcamp'),
        );

        $this->get('/admin/matriculas')
            ->assertOk()
            ->assertSee('Ana Bootcamp')
            ->assertSee('Bootcamp de IoT')
            ->assertSee('Estudiante · bootcamp');
    }

    public function test_es_una_seccion_propia_para_poder_abrirsela_a_educacion_continua(): void
    {
        $this->assertSame('matricula', \App\Support\Secciones::claveDe(MatriculaResource::class));
        $this->assertContains('ver.matricula', \App\Support\Secciones::permisos());
    }
}
