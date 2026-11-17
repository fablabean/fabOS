<?php

namespace Tests\Feature;

use App\Filament\Resources\Zonas\Pages\EditZona;
use App\Filament\Resources\Zonas\RelationManagers\ModulosRelationManager;
use App\Models\Course;
use App\Models\CourseEdition;
use App\Models\Enrollment;
use App\Models\Modulo;
use App\Models\User;
use App\Models\Zona;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Las zonas de conocimiento: material por tema, con su subdominio, y módulos
 * que son presentaciones de una sola página.
 */
class ZonasDeConocimientoTest extends TestCase
{
    use RefreshDatabase;

    private const HTML = '<!doctype html><title>Robots</title><h1>Lámina 1</h1><script>window.x=1</script>';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** La dirección en el subdominio de la zona, con el dominio de las rutas. */
    private function sub(string $camino): string
    {
        return 'http://robotica.' . config('fabos.zonas.dominio') . '/' . $camino;
    }

    private function zona(array $datos = []): Zona
    {
        return Zona::create($datos + ['slug' => 'robotica', 'nombre' => 'Robótica', 'publicada' => true]);
    }

    private function modulo(Zona $zona, array $datos = []): Modulo
    {
        Storage::disk('local')->put('zonas/robots.html', self::HTML);

        return $zona->modulos()->create($datos + [
            'slug' => 'robots', 'titulo' => 'Robots que se mueven', 'html_path' => 'zonas/robots.html',
            'acceso' => 'publico', 'publicado' => true,
        ]);
    }

    public function test_nace_con_conoce_el_fablab_anclado_y_el_enlace_de_siempre_sigue(): void
    {
        $m = Modulo::where('slug', 'conoce-el-fablab')->firstOrFail();
        $this->assertTrue($m->destacado);
        $this->assertSame('conocimiento', $m->zona->slug);

        // La migración copió el archivo al disco de verdad, no al falso.
        $this->get('/conoce-el-fablab')->assertOk()->assertSee('FABLAB', false);
    }

    public function test_un_modulo_publico_se_abre_aislado_por_ruta_y_por_subdominio(): void
    {
        $zona = $this->zona();
        $this->modulo($zona);

        $this->get('/zonas/robotica')->assertOk()->assertSee('Robots que se mueven');

        $r = $this->get('/zonas/robotica/robots')->assertOk()->assertSee('Lámina 1', false);
        $this->assertStringContainsString('sandbox allow-scripts', $r->headers->get('Content-Security-Policy'));
        $this->assertStringNotContainsString('allow-same-origin', $r->headers->get('Content-Security-Policy'));

        $this->get($this->sub(''))->assertOk()->assertSee('Robots que se mueven');
        $this->get($this->sub('robots'))->assertOk()->assertSee('Lámina 1', false);

        // Desde el subdominio, los enlaces del sitio van al dominio principal.
        $this->get($this->sub(''))->assertSee(rtrim(config('app.url'), '/') . '/zonas', false);
    }

    public function test_lo_que_pide_cuenta_se_abre_en_el_dominio_principal(): void
    {
        $zona = $this->zona();
        $this->modulo($zona, ['acceso' => 'cuenta']);

        $this->get($this->sub('robots'))->assertRedirect(route('zonas.modulo', [$zona, 'robots']));
        $this->get('/zonas/robotica/robots')->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create(['status' => 'activo']))
            ->get('/zonas/robotica/robots')->assertOk()->assertSee('Lámina 1', false);
    }

    public function test_solo_inscritos_ve_quien_esta_en_el_curso(): void
    {
        $area = \App\Models\Area::create(['name' => 'Robótica', 'slug' => 'robotica']);
        $curso = Course::create(['name' => 'Robótica básica', 'slug' => 'robotica-basica', 'area_id' => $area->id, 'level' => 'byte', 'hours' => 4, 'is_active' => true, 'is_public' => true]);
        $zona = $this->zona();
        $this->modulo($zona, ['acceso' => 'inscritos', 'course_id' => $curso->id]);

        $fuera = User::factory()->create(['status' => 'activo']);
        $this->actingAs($fuera)->get('/zonas/robotica/robots')->assertForbidden()->assertSee('Robótica básica');

        $dentro = User::factory()->create(['status' => 'activo']);
        $edicion = CourseEdition::create(['course_id' => $curso->id, 'code' => 'ED-1', 'capacity' => 10, 'status' => 'abierta', 'is_self_paced' => true]);
        Enrollment::create(['course_edition_id' => $edicion->id, 'user_id' => $dentro->id, 'status' => 'inscrito']);
        $this->actingAs($dentro)->get('/zonas/robotica/robots')->assertOk();
    }

    public function test_sin_publicar_solo_lo_ve_el_equipo(): void
    {
        $zona = $this->zona();
        $this->modulo($zona, ['publicado' => false]);

        $this->get('/zonas/robotica/robots')->assertNotFound();
        $this->get('/zonas/robotica')->assertOk()->assertDontSee('Robots que se mueven');

        $equipo = User::factory()->create(['status' => 'activo']);
        $equipo->assignRole(Role::findOrCreate(User::ROL_CONSULTOR, 'web'));
        $this->actingAs($equipo)->get('/zonas/robotica/robots')->assertOk();
        $this->actingAs($equipo)->get('/zonas/robotica')->assertSee('Sin publicar');
    }

    public function test_el_administrador_sube_un_modulo_desde_el_panel(): void
    {
        $jefa = User::factory()->create(['name' => 'Jefa', 'status' => 'activo']);
        $jefa->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));
        app(\App\Services\Auth\MatrizDeAccesos::class)->sincronizar();
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($jefa);
        $servicio->confirmar($jefa, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($jefa->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        $zona = $this->zona();

        $this->get(\App\Filament\Resources\Zonas\ZonaResource::getUrl('edit', ['record' => $zona]))->assertOk();

        $archivo = \Illuminate\Http\UploadedFile::fake()->createWithContent('robots.html', self::HTML);

        Livewire::test(ModulosRelationManager::class, ['ownerRecord' => $zona, 'pageClass' => EditZona::class])
            ->assertOk()
            ->callTableAction('create', data: [
                'titulo' => 'Robots que se mueven', 'slug' => 'robots', 'acceso' => 'publico',
                'publicado' => true, 'html_path' => $archivo,
            ])
            ->assertHasNoTableActionErrors();

        $m = $zona->modulos()->firstOrFail();
        $this->assertTrue(Storage::disk('local')->exists($m->html_path));
        $this->get('/zonas/robotica/robots')->assertOk()->assertSee('Lámina 1', false);
    }

    public function test_un_subdominio_reservado_no_se_acepta(): void
    {
        $this->assertContains('www', Zona::RESERVADOS);
        $this->assertContains('admin', Zona::RESERVADOS);

        // «www» no es una zona: sigue siendo el sitio.
        $this->get('http://www.' . config('fabos.zonas.dominio') . '/')->assertOk();
    }
}
