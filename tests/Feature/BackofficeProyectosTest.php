<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Models\Project;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Projects\ProjectService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Las pantallas de Proyectos y el tablero (§11). */
class BackofficeProyectosTest extends TestCase
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

        $this->actingAs($u->fresh())->withSession(['segundo_factor_verificado' => true]);

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

    public function test_el_listado_de_proyectos_carga(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $p = $this->proyecto($admin);

        $this->entra($admin)->get('/admin/projects')
            ->assertOk()
            ->assertSee($p->code)
            ->assertSee('Señalética para el campus')
            ->assertSee('Bienestar Universitario');
    }

    public function test_avanzar_sin_la_compuerta_avisa_que_falta(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $p = $this->proyecto();   // sin responsable

        $this->entra($admin);

        Livewire::test(ListProjects::class)
            ->callAction(TestAction::make('avanzar')->table($p));

        // El error se muestra como notificación y el proyecto no se mueve.
        $this->assertSame('idea', $p->fresh()->stage);
    }

    public function test_avanzar_con_todo_en_su_sitio(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $p = $this->proyecto($admin);

        $this->entra($admin);

        Livewire::test(ListProjects::class)
            ->callAction(TestAction::make('avanzar')->table($p))
            ->assertHasNoActionErrors();

        $this->assertSame('propuesta', $p->fresh()->stage);
    }

    public function test_el_tablero_muestra_kanban_y_gantt(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $p = $this->proyecto($admin);

        $p->tasks()->createMany([
            ['title' => 'Cortar piezas', 'status' => 'en_curso', 'starts_on' => '2026-09-01', 'due_on' => '2026-09-05'],
            ['title' => 'Entrega final', 'status' => 'por_hacer', 'starts_on' => '2026-09-10', 'is_milestone' => true],
            ['title' => 'Sin fechas', 'status' => 'por_hacer'],
        ]);

        $this->actingAs($admin)
            ->get(route('proyectos.tablero', $p))
            ->assertOk()
            ->assertSee('Tablero')
            ->assertSee('Cronograma')
            ->assertSee('Cortar piezas')
            ->assertSee('Sin fechas')          // vive en el tablero
            ->assertSee('01/09/2026');          // y el rango del Gantt
    }

    public function test_una_tarjeta_se_mueve_de_columna_desde_el_tablero(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $p = $this->proyecto($admin);
        $tarea = $p->tasks()->create(['title' => 'Cortar piezas']);

        $this->actingAs($admin)
            ->post(route('proyectos.tarea.mover', $tarea), ['estado' => 'hecha'])
            ->assertRedirect();

        $this->assertSame('hecha', $tarea->fresh()->status);
        $this->assertSame(100, $tarea->fresh()->progress);
    }

    public function test_el_tablero_muestra_el_costeo_y_el_margen(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $p = $this->proyecto($admin);
        $p->update(['agreed_value' => 1_000_000]);
        $p->timeLogs()->create(['worked_on' => now()->toDateString(), 'hours' => 10]);

        $this->actingAs($admin)
            ->get(route('proyectos.tablero', $p))
            ->assertOk()
            ->assertSee('Costeo')
            ->assertSee('$450.000')     // 10 h a la tarifa de referencia
            ->assertSee('$550.000');    // margen contra el millón acordado
    }

    public function test_una_reserva_se_carga_a_un_proyecto_desde_el_backoffice(): void
    {
        $admin = $this->conRol(User::ROL_ADMINISTRADOR);
        $p = $this->proyecto($admin);

        $reserva = \App\Models\Reservation::create([
            'reservable_type' => \App\Models\Asset::class,
            'reservable_id'   => \App\Models\Asset::create([
                'name' => 'Equipo ' . uniqid(), 'kind' => 'fijo', 'status' => 'operativo',
            ])->id,
            'user_id'   => $admin->id,
            'status'    => 'completada',
            'starts_at' => now()->subHours(2),
            'ends_at'   => now()->subHour(),
        ]);

        $this->entra($admin);

        Livewire::test(\App\Filament\Resources\Reservations\Pages\ListReservations::class)
            ->callAction(TestAction::make('proyecto')->table($reserva), ['project_id' => $p->id])
            ->assertHasNoActionErrors();

        $this->assertSame($p->id, $reserva->fresh()->project_id);
    }

    public function test_el_tablero_es_solo_del_backoffice(): void
    {
        $p = $this->proyecto();

        $this->actingAs($this->conRol())
            ->get(route('proyectos.tablero', $p))
            ->assertForbidden();

        $this->actingAs($this->conRol(User::ROL_CONSULTOR))
            ->get(route('proyectos.tablero', $p))
            ->assertOk();
    }

    public function test_el_tablero_dice_que_falta_para_avanzar(): void
    {
        $p = $this->proyecto();   // sin responsable

        $this->actingAs($this->conRol(User::ROL_CONSULTOR))
            ->get(route('proyectos.tablero', $p))
            ->assertOk()
            ->assertSee('Para avanzar')
            ->assertSee('responsable');
    }
}
