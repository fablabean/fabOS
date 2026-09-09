<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Models\NotificationLog;
use App\Models\Project;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Projects\ProjectService;
use App\Support\FactoresDeSesion;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Al cerrar, se le avisa al cliente que puede pasar por lo suyo (§11).
 *
 * Cerrar era una cosa interna: el cliente se enteraba cuando alguien se
 * acordaba de escribirle. Ahora el aviso va en el mismo paso, con un texto
 * sugerido que quien cierra corrige a su gusto.
 */
class CierreDeProyectoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);
    }

    private function jefa(): User
    {
        $u = User::create(['name' => 'Jefa', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    /** Entregado y a la espera del pago, con su informe: listo para cerrarse. */
    private function porCerrar(): Project
    {
        $this->post(route('proyectos.solicitar.store'), [
            'nombre' => 'Marcela Ruiz', 'correo' => 'marcela@cliente.co', 'titulo' => 'Señalética',
            'resumen' => 'Veinte letreros en acrílico.', 'cliente' => 'externo',
        ]);

        $p = Project::firstOrFail();
        $p->update(['lead_id' => $this->jefa()->id]);

        // El informe abre el cierre por si solo; aqui se quiere el proyecto
        // entregado, con el informe ya cargado, y el cierre pendiente.
        $p->documents()->create(['kind' => 'informe', 'title' => 'Informe final']);
        $p->fresh()->update(['stage' => 'pago', 'status' => 'activo', 'closed_at' => null]);

        return $p->fresh();
    }

    public function test_el_aviso_de_cierre_le_llega_al_cliente_con_el_texto_y_el_enlace(): void
    {
        $p = $this->porCerrar();
        $jefa = $p->lead;

        $aviso = app(ProjectService::class)->avisarCierre($p, $jefa, 'Ya puedes pasar por tus letreros, de 8 a 5.');

        $this->assertSame('enviado', $aviso->status);
        $this->assertSame('marcela@cliente.co', $aviso->to);
        $this->assertStringContainsString('está listo', $aviso->subject);
        $this->assertStringContainsString('Ya puedes pasar por tus letreros', $aviso->body);
        $this->assertStringContainsString('/proyectos/' . $p->id . '/propuesta', $aviso->body);
        $this->assertStringContainsString('signature=', $aviso->body);

        // Y queda en la conversación, para que se sepa qué se le dijo.
        $this->assertStringContainsString('pasar por tus letreros', $p->comments()->reorder('id', 'desc')->first()->body);
    }

    public function test_sin_texto_va_el_sugerido(): void
    {
        $p = $this->porCerrar();

        $sugerido = app(ProjectService::class)->mensajeDeCierreSugerido($p);
        $aviso = app(ProjectService::class)->avisarCierre($p, $p->lead);

        $this->assertStringContainsString('Señalética', $sugerido);
        $this->assertStringContainsString('recoger lo tuyo', $sugerido);
        $this->assertStringContainsString('recoger lo tuyo', $aviso->body);
    }

    /** Desde el panel: pasar a cierre cierra y avisa en un paso, con el texto corregido. */
    public function test_al_pasar_a_cierre_desde_el_panel_se_avisa(): void
    {
        $p = $this->porCerrar();

        Livewire::test(ListProjects::class)
            ->removeTableFilters()
            ->callAction(TestAction::make('avanzar')->table($p), [
                'avisar'  => true,
                'mensaje' => 'Tus letreros están listos. Pasa cuando quieras.',
            ])
            ->assertHasNoActionErrors();

        $p->refresh();

        $this->assertSame('cierre', $p->stage);
        $this->assertSame('cerrado', $p->status);

        $aviso = NotificationLog::where('key', 'proyecto.cerrado')->firstOrFail();
        $this->assertSame('enviado', $aviso->status);
        $this->assertStringContainsString('Pasa cuando quieras', $aviso->body);
    }

    /** Y si se apaga el interruptor, se cierra sin escribirle. */
    public function test_se_puede_cerrar_sin_avisar(): void
    {
        $p = $this->porCerrar();

        Livewire::test(ListProjects::class)
            ->removeTableFilters()
            ->callAction(TestAction::make('mover')->table($p), ['etapa' => 'cierre', 'avisar' => false])
            ->assertHasNoActionErrors();

        $this->assertSame('cerrado', $p->fresh()->status);
        $this->assertFalse(NotificationLog::where('key', 'proyecto.cerrado')->exists());
    }
}
