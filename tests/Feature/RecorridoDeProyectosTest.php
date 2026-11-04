<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Projects\ProjectService;
use App\Support\FactoresDeSesion;
use App\Support\RecorridoDeProyectos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Pasar de un proyecto a otro desde la ficha, y reconocerlos en la lista. */
class RecorridoDeProyectosTest extends TestCase
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

        $u = User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
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

        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $this;
    }

    private function proyecto(?User $lead = null): Project
    {
        return app(ProjectService::class)->registrarIdea([
            'name'         => 'Señalética para el campus',
            'source'       => 'whatsapp',
            'organization' => 'Bienestar Universitario',
            'lead_id'      => $lead?->id,
        ]);
    }

    /**
     * La ficha sabe cuál va antes y cuál después en la lista como se dejó, y
     * la barra lo dice con la nota interna, que es como se les llama.
     */
    public function test_la_ficha_recorre_el_listado_como_se_dejo(): void
    {
        $admin = $this->conRol(User::ROL_SUPERADMIN);
        $a = $this->proyecto($admin);
        $b = $this->proyecto($admin);
        $c = $this->proyecto($admin);
        $a->update(['notes' => 'La de Bienestar']);
        $c->update(['notes' => 'Letreros del bloque C']);

        $this->entra($admin);
        Livewire::test(ListProjects::class);

        $this->assertSame([$c->id, $b->id, $a->id], session(RecorridoDeProyectos::SESION));

        $this->get(ProjectResource::getUrl('edit', ['record' => $b]))
            ->assertOk()
            ->assertSee('recorrido-proyectos', false)
            ->assertSee('2 de 3')
            ->assertSee('Letreros del bloque C')
            ->assertSee('La de Bienestar')
            ->assertSee(ProjectResource::getUrl('edit', ['record' => $a]), false);

        // Con un filtro, el recorrido es solo lo filtrado.
        $b->update(['stage' => 'propuesta']);
        Livewire::test(ListProjects::class)->filterTable('stage', 'propuesta');
        $this->assertSame([$b->id], session(RecorridoDeProyectos::SESION));
    }

    /** Quien llega sin pasar por la lista recorre lo suyo y lo sin dueño. */
    public function test_sin_pasar_por_la_lista_recorre_lo_suyo(): void
    {
        $admin = $this->conRol(User::ROL_SUPERADMIN);
        $a = $this->proyecto($admin);
        $b = $this->proyecto();
        $this->proyecto($this->conRol(User::ROL_ADMINISTRADOR));

        $this->entra($admin);

        $this->get(ProjectResource::getUrl('edit', ['record' => $a]))
            ->assertOk()
            ->assertSee('2 de 2 tuyos y sin responsable')
            ->assertSee($b->code);
    }

    /** En la lista, debajo del nombre: la nota interna y lo último que se dijo. */
    public function test_la_lista_muestra_la_nota_y_el_ultimo_mensaje(): void
    {
        $admin = $this->conRol(User::ROL_SUPERADMIN);
        $p = $this->proyecto($admin);
        $p->update(['notes' => 'La base de la lámpara']);
        $p->comments()->create(['side' => 'cliente', 'author_name' => 'Sofía', 'body' => 'Hola, ¿la puedo recoger mañana?']);
        $this->travel(1)->minute();
        $p->comments()->create(['side' => 'laboratorio', 'user_id' => $admin->id, 'body' => 'Sí, queda lista a las 3.']);

        $this->entra($admin);

        Livewire::test(ListProjects::class)
            ->assertSee('La base de la lámpara')
            ->assertSee('Sí, queda lista a las 3.')
            ->assertDontSee('¿la puedo recoger mañana?')
            ->searchTable('lámpara')
            ->assertCanSeeTableRecords([$p]);
    }

    /** Un clic en el estado lo cambia: pausar pide el porqué y no avisa si no se quiere. */
    public function test_el_estado_se_cambia_desde_la_columna(): void
    {
        $admin = $this->conRol(User::ROL_SUPERADMIN);
        $p = $this->proyecto($admin);

        $this->entra($admin);

        Livewire::test(ListProjects::class)
            ->callAction(\Filament\Actions\Testing\TestAction::make('cambiar_estado')->table($p), ['estado' => 'pausado', 'motivo' => ''])
            ->assertHasActionErrors(['motivo' => 'required']);

        Livewire::test(ListProjects::class)
            ->callAction(\Filament\Actions\Testing\TestAction::make('cambiar_estado')->table($p), ['estado' => 'pausado', 'motivo' => 'Espera la pieza', 'avisar' => false])
            ->assertHasNoActionErrors();

        $this->assertSame('pausado', $p->fresh()->status);
        $this->assertSame('Espera la pieza', $p->fresh()->closing_notes);
    }

    /** Varios a la vez: estado y etapa; el que no cumple la compuerta se dice y no frena al resto. */
    public function test_estado_y_etapa_en_bloque(): void
    {
        $admin = $this->conRol(User::ROL_SUPERADMIN);
        $a = $this->proyecto($admin);
        $b = $this->proyecto($admin);

        $this->entra($admin);

        Livewire::test(ListProjects::class)
            ->selectTableRecords([$a->id, $b->id])
            ->callAction(\Filament\Actions\Testing\TestAction::make('estado_en_bloque')->table()->bulk(), ['estado' => 'cerrado'])
            ->assertHasNoActionErrors();

        $this->assertSame(['cerrado', 'cerrado'], [$a->fresh()->status, $b->fresh()->status]);

        $a->fresh()->update(['status' => 'activo']);
        $b->fresh()->update(['status' => 'activo', 'stage' => 'propuesta']);

        Livewire::test(ListProjects::class)
            ->selectTableRecords([$a->id, $b->id])
            ->callAction(\Filament\Actions\Testing\TestAction::make('etapa_en_bloque')->table()->bulk(), ['etapa' => 'idea', 'avisar' => false])
            ->assertHasNoActionErrors();

        $this->assertSame(['idea', 'idea'], [$a->fresh()->stage, $b->fresh()->stage]);
    }
}
