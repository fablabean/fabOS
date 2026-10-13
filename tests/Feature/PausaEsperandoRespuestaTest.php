<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Models\UserCategory;
use App\Services\Projects\ProjectService;
use Database\Seeders\NotificationTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Una pausa que espera a quien pidió el proyecto (§11).
 *
 * Si responde, el proyecto vuelve a estar activo y espera respuesta nuestra.
 * Si no, se le recuerda y, cumplido el plazo, se cierra y se archiva. Lo
 * archivado no se retoma respondiendo. Y una pausa por algo del laboratorio
 * —una pieza, una firma— no tiene plazo.
 */
class PausaEsperandoRespuestaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(NotificationTemplateSeeder::class);

        UserCategory::firstOrCreate(['slug' => 'invitado'], ['name' => 'Invitado', 'can_reserve' => false, 'rate_factor' => 1]);
    }

    private function servicio(): ProjectService
    {
        return app(ProjectService::class);
    }

    private function jefa(): User
    {
        $u = User::create(['name' => 'Jefa Lab', 'email' => uniqid() . '@test.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        return $u;
    }

    /** Pedido desde la web: quien lo pidió queda con cuenta. */
    private function proyecto(): Project
    {
        $this->post(route('proyectos.solicitar.store'), [
            'titulo' => 'Soporte de celular', 'resumen' => 'Un soporte impreso en 3D para el escritorio.',
            'cliente' => 'externo', 'nombre' => 'Andrés Malagón', 'correo' => 'andres@ejemplo.co',
        ]);

        return Project::firstOrFail();
    }

    private function cliente(Project $p): User
    {
        return User::findOrFail($p->requested_by);
    }

    public function test_pausar_esperando_respuesta_pone_plazo(): void
    {
        $p = $this->proyecto();

        $this->servicio()->pausar($p, 'Mándanos el archivo vectorial.', $this->jefa(), esperaAlCliente: true);

        $p->refresh();
        $this->assertSame('pausado', $p->status);
        $this->assertTrue($p->awaits_client);
        $this->assertTrue($p->venceLaPausaEl()->isSameDay(now()->addDays(7)));

        // Y queda dicho en la conversación, con la fecha.
        $this->assertStringContainsString('Espera respuesta de quien lo pidió', $p->comments()->reorder()->latest('id')->first()->body);
    }

    /** Una pausa por algo nuestro no tiene plazo, ni se cierra sola. */
    public function test_una_pausa_del_laboratorio_no_se_cierra_sola(): void
    {
        $p = $this->proyecto();
        $this->servicio()->pausar($p, 'Esperamos que llegue el filamento.', $this->jefa());

        $this->assertNull($p->fresh()->venceLaPausaEl());

        $this->travel(40)->days();
        $this->artisan('fabos:pausas-sin-respuesta')->assertSuccessful();

        $this->assertSame('pausado', $p->fresh()->status);
    }

    public function test_se_recuerda_una_vez_y_despues_se_cierra_y_archiva(): void
    {
        $p = $this->proyecto();
        $this->servicio()->pausar($p, 'Mándanos el archivo vectorial.', $this->jefa(), esperaAlCliente: true);

        // Al cuarto día todavía no toca nada.
        $this->travel(4)->days();
        $this->artisan('fabos:pausas-sin-respuesta')->assertSuccessful();
        $this->assertNull($p->fresh()->pause_reminded_at);

        // Dos días antes del plazo: el recordatorio, y una sola vez.
        $this->travel(1)->days();
        $this->artisan('fabos:pausas-sin-respuesta');
        $this->artisan('fabos:pausas-sin-respuesta');

        $p->refresh();
        $this->assertNotNull($p->pause_reminded_at);
        $this->assertSame('pausado', $p->status);
        $this->assertSame(1, $p->comments()->where('body', 'like', 'Recordatorio:%')->count());

        // Cumplido el plazo: cerrado y archivado, con su constancia.
        $this->travel(2)->days();
        $this->artisan('fabos:pausas-sin-respuesta');

        $p->refresh();
        $this->assertSame('cerrado', $p->status);
        $this->assertNotNull($p->closed_at);
        $this->assertTrue($p->estaCerrado());
        $this->assertStringContainsString('falta de respuesta', $p->closing_notes);
        $this->assertStringContainsString('Mándanos el archivo vectorial.', $p->closing_notes);
        $this->assertStringContainsString('se cerró y se archivó', $p->comments()->reorder()->latest('id')->first()->body);
    }

    /** Si responde, vuelve a estar activo y queda esperando respuesta nuestra. */
    public function test_si_quien_lo_pidio_responde_vuelve_a_esperar_respuesta(): void
    {
        $p = $this->proyecto();
        $this->servicio()->pausar($p, 'Mándanos el archivo vectorial.', $this->jefa(), esperaAlCliente: true);

        $this->travel(3)->days();

        $this->actingAs($this->cliente($p))
            ->post(route('proyectos.comentar', $p), ['body' => 'Aquí va el archivo, perdón la demora.'])
            ->assertSessionHasNoErrors();

        $p = Project::findOrFail($p->id);
        $this->assertSame('activo', $p->status);
        $this->assertFalse($p->awaits_client);
        $this->assertNull($p->paused_at);
        $this->assertNotNull($p->esperaRespuestaDesde(), 'lo último lo dijo quien lo pidió');

        // Y ya no se cierra, por mucho que pase.
        $this->travel(30)->days();
        $this->artisan('fabos:pausas-sin-respuesta');
        $this->assertSame('activo', $p->fresh()->status);
    }

    /** Lo que diga el laboratorio no lo saca de la pausa. */
    public function test_una_respuesta_del_laboratorio_no_lo_reactiva(): void
    {
        $p = $this->proyecto();
        $jefa = $this->jefa();
        $this->servicio()->pausar($p, 'Mándanos el archivo vectorial.', $jefa, esperaAlCliente: true);

        $this->servicio()->comentar($p, '¿Pudiste revisar?', $jefa);

        $this->assertSame('pausado', $p->fresh()->status);
    }

    /** Archivado no se retoma respondiendo: hay que pedir otro o ir al laboratorio. */
    public function test_archivado_no_se_reactiva_con_una_respuesta(): void
    {
        $p = $this->proyecto();
        $this->servicio()->pausar($p, 'Mándanos el archivo vectorial.', $this->jefa(), esperaAlCliente: true);
        $this->travel(8)->days();
        $this->artisan('fabos:pausas-sin-respuesta');

        $this->assertSame('cerrado', $p->fresh()->status);
        $antes = $p->comments()->count();

        $this->actingAs($this->cliente($p))
            ->from(route('proyectos.propuesta', $p))
            ->post(route('proyectos.comentar', $p), ['body' => 'Ya tengo el archivo, ¿seguimos?'])
            ->assertSessionHasErrors();

        $this->assertSame('cerrado', $p->fresh()->status);
        $this->assertSame($antes, $p->comments()->count());

        // Y su página se lo dice, con lo que puede hacer.
        $this->actingAs($this->cliente($p))
            ->get(route('proyectos.propuesta', $p))
            ->assertOk()
            ->assertSee('Este proyecto está cerrado')
            ->assertSee('pide un proyecto nuevo')
            ->assertSee('acércate al laboratorio');
    }

    // ------------------------------- de activo a pausa, cuando no contesta

    /**
     * Activo, se le escribió y no contestó en una semana: pasa solo a pausa
     * esperando respuesta, y desde ahí corre el plazo de siempre.
     */
    public function test_activo_sin_respuesta_pasa_a_pausa_y_empieza_el_ciclo(): void
    {
        $p = $this->proyecto();
        $this->servicio()->comentar($p, '¿De qué tamaño lo necesitas?', $this->jefa());

        $this->travel(6)->days();
        $this->artisan('fabos:pausas-sin-respuesta');
        $this->assertSame('activo', $p->fresh()->status, 'a los seis días todavía no');

        $this->travel(1)->days();
        $this->artisan('fabos:pausas-sin-respuesta');

        $p->refresh();
        $this->assertSame('pausado', $p->status);
        $this->assertTrue($p->awaits_client);
        $this->assertNotNull($p->venceLaPausaEl());
        $this->assertNull($p->pause_reminded_at, 'el recordatorio no sale el mismo día que la pausa');

        // Y el ciclo: recordatorio a los cinco días de la pausa, cierre a los siete.
        $this->travel(5)->days();
        $this->artisan('fabos:pausas-sin-respuesta');
        $this->assertNotNull($p->fresh()->pause_reminded_at);
        $this->assertSame('pausado', $p->fresh()->status);

        $this->travel(2)->days();
        $this->artisan('fabos:pausas-sin-respuesta');
        $this->assertSame('cerrado', $p->fresh()->status);
    }

    /** Si lo último lo dijo quien lo pidió, la respuesta la debemos nosotros: no se pausa. */
    public function test_si_la_respuesta_la_debe_el_laboratorio_no_se_pausa(): void
    {
        $p = $this->proyecto();
        $this->servicio()->comentar($p, '¿De qué tamaño lo necesitas?', $this->jefa());
        $this->servicio()->comentar($p, 'De 15 centímetros.', $this->cliente($p));

        $this->travel(20)->days();
        $this->artisan('fabos:pausas-sin-respuesta');

        $this->assertSame('activo', $p->fresh()->status);
    }

    /** Un proyecto recién pedido al que nadie le ha escrito tampoco: el silencio es nuestro. */
    public function test_un_proyecto_sin_tocar_no_se_pausa(): void
    {
        $p = $this->proyecto();

        $this->travel(20)->days();
        $this->artisan('fabos:pausas-sin-respuesta');

        $this->assertSame('activo', $p->fresh()->status);
    }

    /**
     * En ejecución el laboratorio escribe para informar, no para preguntar:
     * pausar y cerrar por eso un proyecto que se está fabricando sería romperlo.
     */
    public function test_lo_que_esta_en_ejecucion_no_se_pausa_solo(): void
    {
        $p = $this->proyecto();
        $p->update(['stage' => 'ejecucion']);
        $this->servicio()->comentar($p, 'Ya está impreso, falta el acabado.', $this->jefa());

        $this->travel(30)->days();
        $this->artisan('fabos:pausas-sin-respuesta');

        $this->assertSame('activo', $p->fresh()->status);
    }
}
