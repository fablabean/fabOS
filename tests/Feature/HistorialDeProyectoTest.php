<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\RelationManagers\ChangesRelationManager;
use App\Models\Project;
use App\Models\ProjectChange;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El historial de un proyecto: quién lo tuvo a cargo y por qué etapas y
 * estados pasó. Un proyecto cambió de responsable y no quedó dónde ver de
 * quién había sido.
 */
class HistorialDeProyectoTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_quedan_anotados_el_responsable_la_etapa_y_el_estado_con_quien_los_cambio(): void
    {
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $edwin = User::create(['name' => 'Edwin Quintero', 'email' => 'edwin@lab.co', 'status' => 'activo']);
        $michael = User::create(['name' => 'Michael Torres', 'email' => 'michael@lab.co', 'status' => 'activo']);

        // Llega con un responsable: queda anotado, sin nadie que lo haya hecho.
        $p = Project::create(['name' => 'Premiación torneos internos', 'lead_id' => $edwin->id]);
        $inicio = $p->changes()->firstOrFail();
        $this->assertSame(['responsable', null, 'Edwin Quintero', null], [$inicio->field, $inicio->from_value, $inicio->to_value, $inicio->user_name]);

        $jefa = $this->jefa();
        $p = $p->fresh();   // como en el editor: cargado de la base, con su etapa
        $p->update(['lead_id' => $michael->id]);
        $p->update(['stage' => 'propuesta']);
        $p->update(['status' => 'pausado']);
        $p->update(['name' => 'Otro nombre']);   // esto no es historial

        $cambios = $p->changes()->get()->reverse()->values();
        $this->assertCount(4, $cambios);
        $this->assertSame(['responsable', 'Edwin Quintero', 'Michael Torres', 'Jefa'], [$cambios[1]->field, $cambios[1]->from_value, $cambios[1]->to_value, $cambios[1]->user_name]);
        $this->assertSame(['etapa', 'Idea', 'Propuesta'], [$cambios[2]->field, $cambios[2]->from_value, $cambios[2]->to_value]);
        $this->assertSame('estado', $cambios[3]->field);
        $this->assertSame($jefa->id, $cambios[3]->user_id);

        // Si quien lo tuvo ya no está en el sistema, su nombre sigue leyéndose.
        $edwin->delete();
        $this->assertSame('Edwin Quintero', ProjectChange::find($cambios[1]->id)->from_value);

        // Y se ve en la pestaña Historial del editor.
        Livewire::test(ChangesRelationManager::class, ['ownerRecord' => $p->fresh(), 'pageClass' => EditProject::class])
            ->assertOk()
            ->assertSee('Michael Torres')
            ->assertSee('Edwin Quintero')
            ->assertSee('Responsable');
    }
}
