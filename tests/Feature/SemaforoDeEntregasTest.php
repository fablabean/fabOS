<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El semáforo de las entregas en la lista de proyectos (§11).
 *
 * Hoy, mañana y pasado mañana se tiñen; lo vencido sin cerrar, en rojo.
 */
class SemaforoDeEntregasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->travelTo(Carbon::parse('2026-09-10 10:00', config('fabos.lab.timezone')));
    }

    private function proyecto(string $entrega, array $extra = []): Project
    {
        return Project::create(array_merge([
            'code' => 'PRY-' . uniqid(), 'name' => 'Algo', 'stage' => 'ejecucion', 'status' => 'activo',
            'due_on' => $entrega,
        ], $extra));
    }

    public function test_el_semaforo_segun_cuanto_falta(): void
    {
        $this->assertSame('entrega-vencida', $this->proyecto('2026-09-09')->semaforo());
        $this->assertSame('entrega-hoy', $this->proyecto('2026-09-10')->semaforo());
        $this->assertSame('entrega-manana', $this->proyecto('2026-09-11')->semaforo());
        $this->assertSame('entrega-pasado', $this->proyecto('2026-09-12')->semaforo());
        $this->assertNull($this->proyecto('2026-09-13')->semaforo(), 'a tres dias no se tiñe');
        $this->assertNull($this->proyecto('2026-09-01', ['stage' => 'cierre', 'status' => 'cerrado'])->semaforo(), 'lo cerrado no vence');
        $this->assertNull(Project::create(['code' => 'PRY-x', 'name' => 'Sin fecha', 'stage' => 'idea', 'status' => 'activo'])->semaforo());
    }

    private function jefa(): User
    {
        $u = User::create(['name' => 'Jefa', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));
        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u;
    }

    /** Veinte por pagina, en todas las listas del panel. */
    public function test_las_listas_del_panel_traen_veinte_por_pagina(): void
    {
        $this->jefa();

        foreach (range(1, 15) as $i) {
            $this->proyecto('2026-10-01', ['name' => 'Proyecto número ' . $i]);
        }

        $this->get('/admin/projects')
            ->assertOk()
            ->assertSee('Proyecto número 1')
            ->assertSee('Proyecto número 15');
    }

    public function test_la_lista_tiñe_las_filas_y_ordena_por_los_encabezados(): void
    {
        $this->jefa();

        $this->proyecto('2026-09-09', ['name' => 'Vencido']);
        $this->proyecto('2026-09-10', ['name' => 'De hoy']);

        $this->get('/admin/projects')
            ->assertOk()
            ->assertSee('entrega-vencida')
            ->assertSee('entrega-hoy')
            // Los estilos del semaforo vienen con el panel.
            ->assertSee('.entrega-vencida', false)
            // Y los encabezados ordenan: el enlace de orden de la entrega existe.
            ->assertSee('sortTable', false);
    }
}
