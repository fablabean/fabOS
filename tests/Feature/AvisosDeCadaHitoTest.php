<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\NotificationPreference;
use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A quien pidió el proyecto se le avisa cuando pasa algo con él (§11).
 *
 * Lo que se defiende aquí es que el aviso salga **siempre que el hecho ocurre**,
 * venga por donde venga: antes vivía en dos botones del listado, y un proyecto
 * se podía cerrar en silencio editando la ficha o subiendo el informe final.
 */
class AvisosDeCadaHitoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);
    }

    private function proyectos(): ProjectService
    {
        return app(ProjectService::class);
    }

    private function jefa(): User
    {
        $u = User::create(['name' => 'Jefa', 'email' => 'jefa' . uniqid() . '@lab.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        return $u->fresh();
    }

    /** Un proyecto con cliente externo, responsable y su informe cargado. */
    private function proyecto(array $datos = []): Project
    {
        $proyecto = Project::create(array_merge([
            'name'          => 'Señalética',
            'stage'         => 'brief',
            'status'        => 'activo',
            'source'        => 'formulario',
            'client_kind'   => 'externo',
            'contact_name'  => 'Marcela Ruiz',
            'contact_email' => 'marcela@cliente.co',
            'lead_id'       => $this->jefa()->id,
        ], $datos));

        return $proyecto->fresh();
    }

    private function avisos(string $clave): \Illuminate\Database\Eloquent\Collection
    {
        return NotificationLog::where('key', $clave)->get();
    }

    // ------------------------------------------------------------- en máquina

    public function test_al_entrar_en_ejecucion_le_llega_a_quien_pidio(): void
    {
        $proyecto = $this->proyecto();

        $this->proyectos()->moverA($proyecto, 'ejecucion', exigirCompuertas: false);

        $aviso = NotificationLog::where('key', 'proyecto.en_ejecucion')
            ->where('to', 'marcela@cliente.co')
            ->firstOrFail();

        $this->assertSame('enviado', $aviso->status);
        $this->assertStringContainsString('Empezamos a fabricar', $aviso->subject);
        $this->assertStringContainsString('/proyectos/' . $proyecto->id . '/propuesta', $aviso->body);
        $this->assertStringContainsString('signature=', $aviso->body);
    }

    public function test_el_aviso_le_llega_tambien_al_responsable_interno(): void
    {
        $proyecto = $this->proyecto();

        $this->proyectos()->moverA($proyecto, 'ejecucion', exigirCompuertas: false);

        // Se entera de que su proyecto avanzó aunque el cambio lo haya hecho otro.
        $this->assertTrue(
            NotificationLog::where('key', 'proyecto.en_ejecucion')
                ->where('to', $proyecto->lead->email)
                ->where('status', 'enviado')
                ->exists()
        );
    }

    public function test_si_quien_pidio_es_el_responsable_no_le_llega_dos_veces(): void
    {
        $jefa = $this->jefa();
        $proyecto = $this->proyecto(['contact_email' => $jefa->email, 'lead_id' => $jefa->id]);

        $this->proyectos()->moverA($proyecto, 'ejecucion', exigirCompuertas: false);

        $this->assertCount(1, $this->avisos('proyecto.en_ejecucion'));
    }

    // ---------------------------------------------------------- el cierre

    public function test_cerrar_por_etapa_avisa_aunque_nadie_pulse_el_boton_del_aviso(): void
    {
        $proyecto = $this->proyecto(['stage' => 'pago']);

        $this->proyectos()->moverA($proyecto, 'cierre', exigirCompuertas: false);

        $aviso = NotificationLog::where('key', 'proyecto.cerrado')
            ->where('to', 'marcela@cliente.co')
            ->firstOrFail();

        $this->assertSame('enviado', $aviso->status);
        $this->assertStringContainsString('recoger lo tuyo', $aviso->body);
        $this->assertSame('cerrado', $proyecto->fresh()->status);
    }

    public function test_el_informe_final_cierra_el_proyecto_y_ya_no_lo_hace_en_silencio(): void
    {
        // Era el peor de los cuatro caminos silenciosos: lo fabricado se quedaba
        // en un estante esperando a alguien que no sabía que tenía que venir.
        $proyecto = $this->proyecto(['stage' => 'ejecucion']);

        $proyecto->documents()->create(['kind' => 'informe', 'title' => 'Informe final']);

        $this->assertSame('cierre', $proyecto->fresh()->stage);
        $this->assertTrue(
            NotificationLog::where('key', 'proyecto.cerrado')
                ->where('to', 'marcela@cliente.co')
                ->where('status', 'enviado')
                ->exists()
        );
    }

    public function test_el_aviso_de_cierre_se_puede_apagar_al_mover(): void
    {
        $proyecto = $this->proyecto(['stage' => 'pago']);

        $this->proyectos()->moverA($proyecto, 'cierre', exigirCompuertas: false, avisar: false);

        $this->assertSame('cerrado', $proyecto->fresh()->status);
        $this->assertCount(0, $this->avisos('proyecto.cerrado'));
    }

    public function test_el_texto_del_cierre_se_puede_escribir_a_mano(): void
    {
        $proyecto = $this->proyecto(['stage' => 'pago']);

        $this->proyectos()->moverA(
            $proyecto,
            'cierre',
            exigirCompuertas: false,
            mensajeDeCierre: 'Pasa por tus letreros de 8 a 5, pregunta por Andrés.',
        );

        $this->assertStringContainsString(
            'pregunta por Andrés',
            NotificationLog::where('key', 'proyecto.cerrado')->where('to', 'marcela@cliente.co')->firstOrFail()->body
        );
    }

    // ------------------------------------------------------ pausa y descarte

    public function test_pausar_avisa_con_el_motivo(): void
    {
        $proyecto = $this->proyecto();

        $this->proyectos()->pausar($proyecto, 'Esperamos que llegue el acrílico.');

        $aviso = NotificationLog::where('key', 'proyecto.pausado')->where('to', 'marcela@cliente.co')->firstOrFail();

        $this->assertStringContainsString('Esperamos que llegue el acrílico', $aviso->body);
        // No está cancelado, y el correo lo dice.
        $this->assertStringContainsString('No está cancelado', $aviso->body);
    }

    public function test_descartar_avisa_con_el_motivo(): void
    {
        $proyecto = $this->proyecto();

        $this->proyectos()->descartar($proyecto, 'El área no consiguió presupuesto.', 'perdido');

        $aviso = NotificationLog::where('key', 'proyecto.descartado')->where('to', 'marcela@cliente.co')->firstOrFail();

        // Enterarse por el silencio es peor que un «no».
        $this->assertStringContainsString('no consiguió presupuesto', $aviso->body);
        $this->assertSame('perdido', $proyecto->fresh()->status);
    }

    // --------------------------------------------------- lo que no debe pasar

    public function test_retroceder_de_etapa_no_avisa_a_nadie(): void
    {
        $proyecto = $this->proyecto(['stage' => 'ejecucion']);

        $this->proyectos()->moverA($proyecto, 'brief');

        $this->assertCount(0, $this->avisos('proyecto.en_ejecucion'));
        $this->assertCount(0, $this->avisos('proyecto.cerrado'));
    }

    public function test_las_etapas_internas_no_generan_correo(): void
    {
        // El brief y el contrato no le dicen nada a quien espera su pieza, y un
        // correo por cada uno enseña a ignorarlos todos.
        $proyecto = $this->proyecto(['stage' => 'contrato']);

        $this->proyectos()->moverA($proyecto, 'brief', exigirCompuertas: false);

        $this->assertSame(0, NotificationLog::where('key', 'like', 'proyecto.%')->count());
    }

    public function test_sin_correo_de_contacto_el_cambio_de_etapa_se_hace_igual(): void
    {
        // Un aviso que no sale no puede tumbar la operación que lo provocó.
        $proyecto = $this->proyecto(['contact_email' => null, 'lead_id' => null]);

        $this->proyectos()->moverA($proyecto, 'ejecucion', exigirCompuertas: false);

        $this->assertSame('ejecucion', $proyecto->fresh()->stage);
        $this->assertCount(0, $this->avisos('proyecto.en_ejecucion'));
    }

    public function test_quien_apago_el_aviso_no_lo_recibe(): void
    {
        $cliente = User::create(['name' => 'Marcela', 'email' => 'marcela@cliente.co', 'status' => 'activo']);
        NotificationPreference::create([
            'user_id' => $cliente->id, 'key' => 'proyecto.en_ejecucion', 'email' => false,
        ]);
        $proyecto = $this->proyecto();

        $this->proyectos()->moverA($proyecto, 'ejecucion', exigirCompuertas: false);

        $aviso = NotificationLog::where('key', 'proyecto.en_ejecucion')
            ->where('user_id', $cliente->id)
            ->firstOrFail();

        $this->assertSame('omitido', $aviso->status);
        $this->assertStringContainsString('eligió no recibirlo', $aviso->reason);
    }

    public function test_el_aviso_de_cierre_sigue_siendo_de_los_que_no_se_apagan(): void
    {
        // Enterarse de que ya puedes pasar a recoger lo tuyo no es opcional.
        $plantilla = \App\Models\NotificationTemplate::where('key', 'proyecto.cerrado')->firstOrFail();

        $this->assertTrue($plantilla->is_essential);
    }
}
