<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Models\NotificationLog;
use App\Models\Project;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Database\Seeders\NotificationTemplateSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pedir un proyecto desde la web (§11).
 *
 * Lo que se pierde hoy no son los proyectos grandes: son las ideas que llegan
 * un domingo y nunca se anotan. El formulario las anota y crea la cuenta con la
 * que quien pide podrá seguirlas —una diferencia deliberada con el proyecto que
 * anota el laboratorio, que sigue sin exigir cuenta a nadie—.
 */
class SolicitudDeProyectoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(NotificationTemplateSeeder::class);

        UserCategory::firstOrCreate(
            ['slug' => 'invitado'],
            ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1],
        );
    }

    /** @return array<string,mixed> */
    private function solicitud(array $cambios = []): array
    {
        return array_merge([
            'titulo'       => 'Señalética para el edificio de Bienestar',
            'resumen'      => 'Necesitamos veinte letreros en acrílico para señalizar el edificio.',
            'entregables'  => "20 letreros en acrílico\nLos archivos de corte",
            'nombre'       => 'Steban Gómez',
            'correo'       => 'steban@ejemplo.co',
            'telefono'     => '3001234567',
            'organizacion' => 'Bienestar Universitario',
        ], $cambios);
    }

    private function jefa(): User
    {
        $u = User::create([
            'name' => 'Jefa ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    // ------------------------------------------------------------ el formulario

    public function test_el_formulario_es_publico(): void
    {
        $this->get(route('proyectos.solicitar'))
            ->assertOk()
            ->assertSee('Proponer un proyecto');
    }

    public function test_una_solicitud_crea_el_proyecto_en_idea(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud())
            ->assertRedirect(route('proyectos.solicitar'));

        $p = Project::where('name', 'Señalética para el edificio de Bienestar')->firstOrFail();

        $this->assertSame('idea', $p->stage, 'Es una solicitud, no un compromiso.');
        $this->assertSame('activo', $p->status);
        $this->assertSame('formulario', $p->source);
        $this->assertSame('Bienestar Universitario', $p->organization);
        $this->assertNotNull($p->code);
    }

    /**
     * Aquí sí se crea cuenta, y es deliberado: quien escribe por la web va a
     * querer seguir su proyecto, y sin cuenta no hay dónde seguirlo.
     */
    public function test_una_solicitud_crea_la_cuenta_de_quien_pide(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());

        $persona = User::where('email', 'steban@ejemplo.co')->firstOrFail();

        $this->assertSame('Steban Gómez', $persona->name);
        $this->assertSame($persona->id, Project::first()->requested_by);
    }

    /**
     * Rellenar un formulario público no puede ser la forma de conseguir acceso
     * a las máquinas. Para eso está el certifab.
     */
    public function test_la_cuenta_nace_sin_permiso_de_reservar(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());

        $persona = User::where('email', 'steban@ejemplo.co')->firstOrFail();

        $this->assertFalse((bool) $persona->category?->can_reserve);
    }

    /** Dos cuentas con el mismo correo partirían el historial en dos. */
    public function test_si_ya_tiene_cuenta_se_reutiliza(): void
    {
        $ya = User::create([
            'name' => 'Steban Gómez', 'email' => 'steban@ejemplo.co', 'status' => 'activo',
        ]);

        $this->post(route('proyectos.solicitar.store'), $this->solicitud());

        $this->assertSame(1, User::where('email', 'steban@ejemplo.co')->count());
        $this->assertSame($ya->id, Project::first()->requested_by);
    }

    public function test_lo_que_escribio_queda_como_entregables(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());

        $p = Project::first();

        $this->assertCount(2, $p->deliverables);
        $this->assertSame('20 letreros en acrílico', $p->deliverables->first()->title);
    }

    public function test_se_le_avisa_que_llego(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());

        $this->assertDatabaseHas('notification_logs', [
            'key'    => 'proyecto.recibido',
            'status' => 'enviado',
        ]);
    }

    // ---------------------------------------------------------------- frenos

    public function test_un_resumen_de_dos_palabras_no_pasa(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud(['resumen' => 'algo']))
            ->assertSessionHasErrors('resumen');

        $this->assertDatabaseCount('projects', 0);
    }

    /** La trampa para robots: nadie la ve, nadie debería llenarla. */
    public function test_el_campo_trampa_frena_la_solicitud(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud(['sitio_web' => 'http://spam']))
            ->assertSessionHasErrors('sitio_web');

        $this->assertDatabaseCount('projects', 0);
        $this->assertDatabaseMissing('users', ['email' => 'steban@ejemplo.co']);
    }

    /** El contador del menú es lo que hace que alguien mire. */
    public function test_el_menu_cuenta_las_solicitudes_sin_responder(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());

        $this->assertSame(
            '1',
            \App\Filament\Resources\Projects\ProjectResource::getNavigationBadge(),
        );

        Project::first()->update(['proposal_sent_at' => now()]);

        $this->assertNull(
            \App\Filament\Resources\Projects\ProjectResource::getNavigationBadge(),
            'Respondida deja de contar.',
        );
    }

    /** Y quien pidió ve el proyecto en su cuenta: por eso se le creó. */
    public function test_los_proyectos_salen_en_mi_cuenta(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());

        $p = Project::first();
        $p->update(['proposal_sent_at' => now()]);

        $this->actingAs(User::where('email', 'steban@ejemplo.co')->firstOrFail())
            ->get(route('home'))
            ->assertOk()
            ->assertSee('Mis proyectos')
            ->assertSee($p->code)
            ->assertSee('Ver la propuesta');
    }

    // ------------------------------------------------------------ la respuesta

    public function test_la_propuesta_se_manda_con_su_enlace(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());
        $p = Project::first();

        $this->jefa();

        Livewire::test(ListProjects::class)
            ->callAction(TestAction::make('propuesta')->table($p), ['mensaje' => 'Lo vimos con el equipo.'])
            ->assertHasNoActionErrors();

        $aviso = NotificationLog::where('key', 'proyecto.propuesta')->firstOrFail();

        $this->assertSame('enviado', $aviso->status);
        $this->assertNotNull($p->fresh()->proposal_sent_at, 'Sin esto no se ve a quién se dejó esperando.');
    }

    /**
     * El enlace del correo tiene que funcionar sin haber entrado: obligar a
     * iniciar sesión antes de leer la propuesta es la forma más segura de que
     * no se lea.
     */
    public function test_el_enlace_firmado_abre_la_propuesta_sin_sesion(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());
        $p = Project::first();

        $enlace = URL::temporarySignedRoute('proyectos.propuesta', now()->addDays(60), ['project' => $p->id]);

        $this->get($enlace)
            ->assertOk()
            ->assertSee($p->name)
            ->assertSee('20 letreros en acrílico');
    }

    public function test_sin_firma_y_sin_sesion_no_se_ve(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());
        $p = Project::first();

        $this->get(route('proyectos.propuesta', $p))->assertForbidden();
    }

    /** Y con la sesión de quien pidió, para cuando el correo se pierda. */
    public function test_quien_pidio_la_ve_desde_su_cuenta(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());
        $p = Project::first();

        $this->actingAs(User::where('email', 'steban@ejemplo.co')->firstOrFail())
            ->get(route('proyectos.propuesta', $p))
            ->assertOk()
            ->assertSee($p->name);
    }

    /** Pero no la de otra persona. */
    public function test_un_tercero_no_ve_la_propuesta(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());
        $p = Project::first();

        $otro = User::create([
            'name' => 'Otro', 'email' => 'otro@ejemplo.co', 'status' => 'activo',
        ]);

        $this->actingAs($otro)
            ->get(route('proyectos.propuesta', $p))
            ->assertForbidden();
    }

    public function test_un_enlace_caducado_ya_no_abre(): void
    {
        $this->post(route('proyectos.solicitar.store'), $this->solicitud());
        $p = Project::first();

        $enlace = URL::temporarySignedRoute('proyectos.propuesta', now()->addMinute(), ['project' => $p->id]);

        $this->travel(2)->minutes();

        $this->get($enlace)->assertForbidden();
    }
}
