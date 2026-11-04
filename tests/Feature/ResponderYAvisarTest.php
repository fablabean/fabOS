<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\RelationManagers\CommentsRelationManager;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
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

/** Responder en la conversación y avisar en el mismo paso, encendido de entrada. */
class ResponderYAvisarTest extends TestCase
{
    use RefreshDatabase;

    public function test_responder_avisa_de_entrada_y_se_puede_apagar(): void
    {
        Mail::fake();
        $this->seed(NotificationTemplateSeeder::class);

        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $jefa = User::create(['name' => 'Jefa', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $jefa->assignRole(User::ROL_ADMINISTRADOR);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($jefa);
        $servicio->confirmar($jefa, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($jefa->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        $p = app(ProjectService::class)->registrarIdea([
            'name' => 'Base lámpara', 'source' => 'whatsapp', 'organization' => 'Clase', 'lead_id' => $jefa->id,
        ]);
        $p->update(['contact_email' => 'sofia@test.co', 'contact_name' => 'Sofía']);

        $pestana = fn () => Livewire::test(CommentsRelationManager::class, ['ownerRecord' => $p->fresh(), 'pageClass' => EditProject::class]);

        $pestana()->mountAction(TestAction::make('create')->table())
            ->assertSchemaStateSet(['avisar' => true]);

        $pestana()->callAction(TestAction::make('create')->table(), ['body' => 'Ya está lista, pasa mañana.'])
            ->assertHasNoActionErrors();

        $this->assertSame(1, NotificationLog::where('key', 'proyecto.novedades')->where('to', 'sofia@test.co')->count());

        $pestana()->callAction(TestAction::make('create')->table(), ['body' => 'Nota de seguimiento.', 'avisar' => false])
            ->assertHasNoActionErrors();

        $this->assertSame(2, $p->comments()->count());
        $this->assertSame(1, NotificationLog::where('key', 'proyecto.novedades')->where('to', 'sofia@test.co')->count());
    }
}
