<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\Project;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Projects\ProjectException;
use App\Services\Projects\ProjectService;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Avisar a quien pidió el proyecto de que hay novedades (§11).
 *
 * Lo que respondía el laboratorio en la conversación no avisaba a nadie. Un
 * comentario que nadie lee es igual que no haberlo escrito.
 */
class AvisoDeNovedadesTest extends TestCase
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

    private function jefa(): User
    {
        $u = User::create(['name' => 'Jefa Lab', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        return $u;
    }

    /** Un proyecto pedido desde la web: quien lo pidió queda con cuenta. */
    private function proyecto(array $cambios = []): Project
    {
        $this->post(route('proyectos.solicitar.store'), array_merge([
            'titulo'       => 'Señalética para el edificio de Bienestar',
            'resumen'      => 'Necesitamos veinte letreros en acrílico.',
            'entregables'  => "20 letreros en acrílico",
            'cliente'      => 'externo',
            'nombre'       => 'Steban Gómez',
            'correo'       => 'steban@ejemplo.co',
            'telefono'     => '3001234567',
            'organizacion' => 'Bienestar Universitario',
        ], $cambios));

        return Project::firstOrFail();
    }

    public function test_el_aviso_le_llega_a_quien_pidio_con_el_enlace(): void
    {
        $p = $this->proyecto();
        $jefa = $this->jefa();

        app(ProjectService::class)->comentar($p, 'Movemos la entrega una semana.', $jefa);
        $aviso = app(ProjectService::class)->avisarNovedades($p, $jefa, 'Movemos la entrega una semana.');

        $this->assertSame('enviado', $aviso->status);
        $this->assertSame('steban@ejemplo.co', $aviso->to);
        $this->assertStringContainsString('Movemos la entrega', $aviso->body);
        $this->assertStringContainsString('Jefa Lab', $aviso->body);
        $this->assertStringContainsString('/proyectos/' . $p->id . '/propuesta', $aviso->body);
        $this->assertStringContainsString('signature=', $aviso->body);

        // Y queda anotado con cuenta: quien pidió por la web la tiene.
        $this->assertNotNull($aviso->user_id);
        $this->assertSame($aviso->id, app(ProjectService::class)->ultimoAvisoDeNovedades($p)->id);
    }

    /** El enlace del correo abre la propuesta y la conversación sin entrar. */
    public function test_el_enlace_del_aviso_abre_la_conversacion_sin_sesion(): void
    {
        $p = $this->proyecto();
        $jefa = $this->jefa();

        app(ProjectService::class)->comentar($p, 'Movemos la entrega una semana.', $jefa);
        $aviso = app(ProjectService::class)->avisarNovedades($p, $jefa);

        preg_match('#https?://\S+/proyectos/\d+/propuesta\S*#', $aviso->body, $m);
        $this->assertNotEmpty($m, 'el correo tiene que traer el enlace firmado');

        $this->get($m[0])
            ->assertOk()
            ->assertSee('Movemos la entrega una semana.');
    }

    /** Sin cuenta también: el laboratorio anota proyectos de quien no entra. */
    public function test_sin_cuenta_se_manda_igual_al_correo_de_contacto(): void
    {
        $jefa = $this->jefa();

        $p = Project::create([
            'name' => 'Trofeos', 'summary' => 'Diez trofeos', 'stage' => 'idea', 'status' => 'activo',
            'contact_name' => 'Lina Ruiz', 'contact_email' => 'lina@fuera.co',
            'requested_by' => $jefa->id, 'lead_id' => $jefa->id,
        ]);

        $aviso = app(ProjectService::class)->avisarNovedades($p, $jefa, 'Ya tenemos el diseño.');

        $this->assertSame('enviado', $aviso->status);
        $this->assertSame('lina@fuera.co', $aviso->to);
        $this->assertNull($aviso->user_id);
        $this->assertStringContainsString('Hola Lina', $aviso->body);
    }

    // ------------------------------------------------------------ el botón

    private function entra(User $u): void
    {
        $servicio = app(\App\Services\Auth\TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(\PragmaRX\Google2FA\Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([\App\Support\FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);
    }

    /** Desde la conversación, al lado de «Responder», con el último comentario prellenado. */
    public function test_el_boton_de_la_conversacion_avisa(): void
    {
        $p = $this->proyecto();
        $jefa = $this->jefa();
        $p->update(['lead_id' => $jefa->id]);

        app(ProjectService::class)->comentar($p, 'Movemos la entrega una semana.', $jefa);

        $this->entra($jefa);

        \Livewire\Livewire::test(\App\Filament\Resources\Projects\RelationManagers\CommentsRelationManager::class, [
            'ownerRecord' => $p,
            'pageClass'   => \App\Filament\Resources\Projects\Pages\EditProject::class,
        ])
            ->mountAction(\Filament\Actions\Testing\TestAction::make('avisarNovedades')->table())
            ->assertSchemaStateSet(['mensaje' => 'Movemos la entrega una semana.'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $aviso = NotificationLog::where('key', 'proyecto.novedades')->firstOrFail();

        $this->assertSame('enviado', $aviso->status);
        $this->assertSame('steban@ejemplo.co', $aviso->to);
        $this->assertStringContainsString('Movemos la entrega', $aviso->body);
    }

    /** Y desde la cabecera de la ficha, con un texto propio. */
    public function test_el_boton_de_la_ficha_avisa(): void
    {
        $p = $this->proyecto();
        $jefa = $this->jefa();
        $p->update(['lead_id' => $jefa->id]);

        $this->entra($jefa);

        \Livewire\Livewire::test(\App\Filament\Resources\Projects\Pages\EditProject::class, ['record' => $p->id])
            ->callAction('avisarNovedades', ['mensaje' => 'Ya está listo el diseño para que lo apruebes.'])
            ->assertHasNoActionErrors();

        $aviso = NotificationLog::where('key', 'proyecto.novedades')->firstOrFail();

        $this->assertStringContainsString('Ya está listo el diseño', $aviso->body);
    }

    public function test_sin_correo_de_contacto_lo_dice(): void
    {
        $jefa = $this->jefa();

        $p = Project::create([
            'name' => 'Trofeos', 'summary' => 'Diez trofeos', 'stage' => 'idea', 'status' => 'activo',
            'lead_id' => $jefa->id,
        ]);

        $this->expectException(ProjectException::class);
        $this->expectExceptionMessage('no tiene correo de contacto');

        app(ProjectService::class)->avisarNovedades($p, $jefa);

        $this->assertSame(0, NotificationLog::where('key', 'proyecto.novedades')->count());
    }
}
