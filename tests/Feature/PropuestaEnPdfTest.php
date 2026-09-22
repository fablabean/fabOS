<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use App\Services\Projects\ProjectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La propuesta, en PDF (§11).
 *
 * Una propuesta se reenvía: al jefe que firma, al área que paga, al comité que
 * aprueba. Hasta ahora lo único que se podía mandar era el enlace de la
 * página, que va firmado, caduca, y además trae los botones de aceptar —que no
 * son de quien solo tiene que opinar—. El PDF es lo que se propuso, sin la
 * conversación ni los botones, y se abre sin sesión.
 */
class PropuestaEnPdfTest extends TestCase
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
        $u = User::create(['name' => 'Quien anota', 'email' => uniqid() . '@lab.co', 'status' => 'activo']);
        $u->assignRole(Role::findOrCreate(User::ROL_ADMINISTRADOR, 'web'));

        return $u->fresh();
    }

    private function conPropuesta(): Project
    {
        $colaborador = $this->colaborador();

        $proyecto = app(ProjectService::class)->registrarIdea([
            'name'          => 'Señalética para el auditorio',
            'summary'       => 'Diez piezas cortadas en láser.',
            'contact_name'  => 'Marcela',
            'contact_email' => 'marcela@cliente.co',
            'client_kind'   => 'externo',
        ], $colaborador);

        $proyecto->update(['lead_id' => $colaborador->id]);

        app(ProjectService::class)->enviarPropuesta($proyecto->fresh(), [
            'estimated_value' => 2_000_000,
            'due_on'          => now()->addMonth()->toDateString(),
            'entregables'     => [['title' => 'Diez señales en acrílico']],
        ]);

        return $proyecto->fresh();
    }

    private function firmada(Project $proyecto, string $ruta = 'proyectos.propuesta.pdf'): string
    {
        return URL::temporarySignedRoute($ruta, now()->addDays(30), ['project' => $proyecto->id]);
    }

    // ------------------------------------------------------------ la descarga

    public function test_se_descarga_con_el_enlace_firmado_y_sin_sesion(): void
    {
        $proyecto = $this->conPropuesta();

        $respuesta = $this->get($this->firmada($proyecto))->assertOk();

        $this->assertSame('application/pdf', $respuesta->headers->get('content-type'));
        $this->assertStringContainsString(
            'propuesta-' . strtolower($proyecto->code) . '.pdf',
            (string) $respuesta->headers->get('content-disposition'),
        );
        // Se descarga, no se abre dentro de la página: es para reenviarlo.
        $this->assertStringStartsWith('attachment', (string) $respuesta->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $respuesta->getContent());
    }

    public function test_sin_firma_ni_sesion_no_se_descarga(): void
    {
        $proyecto = $this->conPropuesta();

        $this->get(route('proyectos.propuesta.pdf', $proyecto))->assertForbidden();
    }

    /** Quien lleva el proyecto sí, con su sesión: es quien lo manda. */
    public function test_el_equipo_lo_descarga_con_su_sesion(): void
    {
        $proyecto = $this->conPropuesta();

        $this->actingAs($proyecto->lead)
            ->get(route('proyectos.propuesta.pdf', $proyecto))
            ->assertOk();
    }

    /** Y alguien de fuera que no tiene nada que ver, no. */
    public function test_un_tercero_con_cuenta_no_lo_descarga(): void
    {
        $proyecto = $this->conPropuesta();
        $ajeno = User::create(['name' => 'Ajeno', 'email' => uniqid() . '@test.co', 'status' => 'activo']);

        $this->actingAs($ajeno)
            ->get(route('proyectos.propuesta.pdf', $proyecto))
            ->assertForbidden();
    }

    // -------------------------------------------------------------- el enlace

    public function test_la_pagina_ofrece_descargarlo(): void
    {
        $proyecto = $this->conPropuesta();

        $this->actingAs($proyecto->lead)
            ->get(route('proyectos.propuesta', $proyecto))
            ->assertOk()
            ->assertSee('Descargar en PDF')
            ->assertSee(route('proyectos.propuesta.pdf', $proyecto), false);
    }

    /**
     * Quien llega por el correo no tiene sesión, así que su enlace al PDF va
     * firmado aparte: la firma cubre la URL entera y no vale para otra.
     */
    public function test_quien_llega_por_el_correo_recibe_un_enlace_firmado_al_pdf(): void
    {
        $proyecto = $this->conPropuesta();

        $respuesta = $this->get($this->firmada($proyecto, 'proyectos.propuesta'))->assertOk();

        $respuesta->assertSee('Descargar en PDF');
        $respuesta->assertSee('propuesta.pdf?expires=', false);
        $respuesta->assertSee('signature=', false);
    }
}
