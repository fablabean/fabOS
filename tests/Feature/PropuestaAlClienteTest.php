<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La propuesta le llega al cliente, y la acepta el cliente (§11).
 *
 * Lo que se arregla aquí lo reportó alguien con la frase que más cuesta
 * responder: «no pude aceptar la propuesta que me mandaron».
 *
 * La causa: un proyecto que **anota el propio laboratorio** —el que llega por
 * teléfono o por correo— queda a nombre de quien lo anotó, y `requested_by`
 * apunta entonces a alguien del equipo, no al cliente. La propuesta se mandaba
 * a `requested_by` si existía, así que en esos proyectos el correo se lo
 * llevaba el colaborador y el cliente no recibía nada. Y si el cliente llegaba
 * a la página con su cuenta, el recuadro para aceptar no aparecía y la página
 * no explicaba por qué.
 */
class PropuestaAlClienteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);
    }

    private function colaborador(): User
    {
        $u = User::create([
            'name' => 'Quien anota', 'email' => 'colaborador@lab.co', 'status' => 'activo',
        ]);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        return $u->fresh();
    }

    /** Un proyecto anotado por el laboratorio: a nombre de quien lo anotó. */
    private function proyectoAnotado(User $colaborador, array $cambios = []): Project
    {
        $proyecto = app(ProjectService::class)->registrarIdea(array_merge([
            'name'          => 'Señalética para el auditorio',
            'summary'       => 'Diez piezas cortadas en láser.',
            'contact_name'  => 'Marcela',
            'contact_email' => 'marcela@cliente.co',
            'client_kind'   => 'externo',
        ], $cambios), $colaborador);

        $proyecto->update(['lead_id' => $colaborador->id]);

        return $proyecto->fresh();
    }

    /**
     * El correo va al cliente, no a quien anotó el proyecto.
     *
     * Es el fallo entero: el laboratorio daba la propuesta por mandada y el
     * cliente nunca la había visto.
     */
    public function test_la_propuesta_le_llega_al_cliente_y_no_a_quien_la_anoto(): void
    {
        $colaborador = $this->colaborador();
        $proyecto = $this->proyectoAnotado($colaborador);

        $this->assertSame(
            $colaborador->id,
            $proyecto->requested_by,
            'un proyecto anotado por el laboratorio queda a su nombre: es la trampa que causó todo',
        );

        app(ProjectService::class)->enviarPropuesta($proyecto, ['estimated_value' => 2_000_000]);

        $avisos = NotificationLog::where('key', 'proyecto.propuesta')->get();

        $this->assertCount(1, $avisos);
        $this->assertSame('marcela@cliente.co', $avisos->first()->to);
    }

    /** Si el correo de contacto tiene cuenta, el aviso queda en su bitácora. */
    public function test_si_el_cliente_tiene_cuenta_el_aviso_es_suyo(): void
    {
        $colaborador = $this->colaborador();

        $marcela = User::create([
            'name' => 'Marcela', 'email' => 'marcela@cliente.co', 'status' => 'activo',
        ]);

        app(ProjectService::class)->enviarPropuesta(
            $this->proyectoAnotado($colaborador),
            ['estimated_value' => 2_000_000],
        );

        $aviso = NotificationLog::where('key', 'proyecto.propuesta')->firstOrFail();

        $this->assertSame($marcela->id, $aviso->user_id);
    }

    /** Y el cliente, entrando con su cuenta, puede aceptarla. */
    public function test_el_cliente_acepta_con_su_cuenta_aunque_el_proyecto_no_figure_a_su_nombre(): void
    {
        $colaborador = $this->colaborador();
        $proyecto = $this->proyectoAnotado($colaborador);

        $marcela = User::create([
            'name' => 'Marcela', 'email' => 'marcela@cliente.co', 'status' => 'activo',
        ]);

        app(ProjectService::class)->enviarPropuesta($proyecto, ['estimated_value' => 2_000_000]);

        $this->actingAs($marcela)
            ->get(route('proyectos.propuesta', $proyecto))
            ->assertOk()
            ->assertSee('Acepto la propuesta');

        $this->actingAs($marcela)
            ->post(route('proyectos.aceptar', $proyecto), ['nota' => 'Seguimos.'])
            ->assertRedirect();

        $this->assertNotNull($proyecto->fresh()->accepted_at);
    }

    /**
     * Quien anotó el proyecto NO acepta por el cliente, aunque figure como
     * quien lo pidió. Es la regla de siempre; lo que fallaba es que
     * `requested_by` la esquivaba sin que nadie lo hubiera decidido.
     */
    public function test_quien_lo_anoto_no_acepta_en_nombre_del_cliente(): void
    {
        $colaborador = $this->colaborador();
        $proyecto = $this->proyectoAnotado($colaborador);

        app(ProjectService::class)->enviarPropuesta($proyecto, ['estimated_value' => 2_000_000]);

        $this->actingAs($colaborador)
            ->post(route('proyectos.aceptar', $proyecto))
            ->assertForbidden();

        $this->assertNull($proyecto->fresh()->accepted_at);
    }

    /**
     * Y cuando no se puede aceptar desde ahí, la página lo dice.
     *
     * Antes el recuadro desaparecía sin más, y quien lo miraba solo podía
     * concluir que la propuesta no se dejaba aceptar.
     */
    public function test_la_pagina_explica_por_donde_se_acepta(): void
    {
        $colaborador = $this->colaborador();
        $proyecto = $this->proyectoAnotado($colaborador);

        app(ProjectService::class)->enviarPropuesta($proyecto, ['estimated_value' => 2_000_000]);

        $this->actingAs($colaborador)
            ->get(route('proyectos.propuesta', $proyecto))
            ->assertOk()
            ->assertDontSee('Acepto la propuesta')
            ->assertSee('La propuesta la acepta quien la pidió')
            ->assertSee('marcela@cliente.co');
    }

    /** Lo que ya funcionaba sigue igual: una solicitud de la web no cambia. */
    public function test_una_solicitud_de_la_web_sigue_llegandole_a_quien_la_escribio(): void
    {
        $this->post(route('proyectos.solicitar.store'), [
            'nombre'  => 'Steban',
            'correo'  => 'steban@ejemplo.co',
            'titulo'  => 'Prototipo de carcasa',
            'resumen' => 'Necesitamos diez piezas para una feria del mes entrante.',
            'cliente' => 'externo',
        ]);

        $proyecto = Project::firstOrFail();
        $proyecto->update(['lead_id' => $this->colaborador()->id]);

        app(ProjectService::class)->enviarPropuesta($proyecto->fresh(), ['estimated_value' => 500_000]);

        $aviso = NotificationLog::where('key', 'proyecto.propuesta')->firstOrFail();

        $this->assertSame('steban@ejemplo.co', $aviso->to);
    }
}
