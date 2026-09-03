<?php

namespace Tests\Feature;

use App\Models\NotificationLog;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Projects\ProjectException;
use App\Services\Projects\ProjectService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * De la propuesta al contrato, y con quién se firma (§11).
 *
 * Tres cosas que faltaban cuando un cliente aceptó de verdad:
 *
 *  · La propuesta y su aceptación no aparecían en la conversación del
 *    proyecto: se abría «Conversación» y estaba vacía.
 *  · La ficha no decía «aceptada» ni ofrecía mandar el contrato.
 *  · El proyecto no sabía si firma una persona o una empresa, ni con qué
 *    documento: se preguntaba por WhatsApp al redactar.
 */
class ContratoYPersonaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');
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

    /** Un proyecto pedido por la web, con propuesta enviada. */
    private function conPropuesta(array $extra = []): Project
    {
        $this->post(route('proyectos.solicitar.store'), array_merge([
            'nombre' => 'Marcela Ruiz', 'correo' => 'marcela@cliente.co', 'titulo' => 'Señalética',
            'resumen' => 'Veinte letreros en acrílico para señalizar el edificio.', 'cliente' => 'externo',
        ], $extra));

        $p = Project::firstOrFail();
        $p->update(['lead_id' => $this->jefa()->id]);

        app(ProjectService::class)->enviarPropuesta($p->fresh(), [
            'estimated_value' => 2_000_000, 'due_on' => '2026-10-15', 'mensaje' => 'Podemos empezar la otra semana.',
        ]);

        return $p->fresh();
    }

    // ---------------------------------------------------- la conversacion

    public function test_la_propuesta_enviada_queda_en_la_conversacion(): void
    {
        $p = $this->conPropuesta();

        $hilo = $p->comments()->orderBy('id')->get();

        $this->assertCount(1, $hilo);
        $this->assertSame('laboratorio', $hilo[0]->side);
        $this->assertStringContainsString('Enviamos la propuesta', $hilo[0]->body);
        $this->assertStringContainsString('marcela@cliente.co', $hilo[0]->body);
        $this->assertStringContainsString('2.000.000', $hilo[0]->body);
        $this->assertStringContainsString('Podemos empezar', $hilo[0]->body);
    }

    public function test_la_aceptacion_queda_en_la_conversacion_aunque_no_diga_nada(): void
    {
        $p = $this->conPropuesta();
        $cliente = User::where('email', 'marcela@cliente.co')->firstOrFail();

        app(ProjectService::class)->aceptarPropuesta($p, $cliente);

        $ultimo = $p->comments()->latest('id')->first();

        $this->assertSame('cliente', $ultimo->side);
        $this->assertStringContainsString('Acepté la propuesta', $ultimo->body);
    }

    // ------------------------------------------------------- el contrato

    public function test_el_contrato_se_manda_al_cliente_y_queda_anotado(): void
    {
        $p = $this->conPropuesta();
        $cliente = User::where('email', 'marcela@cliente.co')->firstOrFail();
        app(ProjectService::class)->aceptarPropuesta($p, $cliente);

        Storage::disk('local')->put('proyectos/contrato.pdf', '%PDF-1.4 prueba');
        $doc = $p->documents()->create(['kind' => 'contrato', 'title' => 'Contrato PRY', 'file_path' => 'proyectos/contrato.pdf']);

        $r = app(ProjectService::class)->enviarContrato($p->fresh(), $doc, 'Revísalo con calma.', $this->jefa());

        $this->assertNotNull($r->contract_sent_at);

        $aviso = NotificationLog::where('key', 'proyecto.contrato')->firstOrFail();
        $this->assertSame($cliente->id, $aviso->user_id);
        $this->assertStringContainsString('/proyectos/' . $p->id . '/documentos/' . $doc->id, $aviso->body);

        $this->assertStringContainsString('Enviamos el contrato', $p->comments()->latest('id')->first()->body);
    }

    public function test_sin_aceptar_no_se_manda_contrato(): void
    {
        $p = $this->conPropuesta();
        $doc = $p->documents()->create(['kind' => 'contrato', 'title' => 'Contrato', 'file_path' => 'x.pdf']);

        $this->expectException(ProjectException::class);
        $this->expectExceptionMessageMatches('/aceptada/');

        app(ProjectService::class)->enviarContrato($p, $doc);
    }

    /** El enlace del correo descarga el contrato; sin firma ni sesión, no. */
    public function test_el_contrato_se_descarga_con_el_enlace_firmado(): void
    {
        $p = $this->conPropuesta();
        Storage::disk('local')->put('proyectos/contrato.pdf', '%PDF-1.4 prueba');
        $doc = $p->documents()->create(['kind' => 'contrato', 'title' => 'Contrato', 'file_path' => 'proyectos/contrato.pdf']);

        auth()->logout();

        $this->get(route('proyectos.documento', [$p, $doc]))->assertForbidden();

        $firmado = URL::temporarySignedRoute('proyectos.documento', now()->addDays(60), ['project' => $p->id, 'document' => $doc->id]);

        $this->get($firmado)->assertOk();
    }

    // ------------------------------------------------- quien firma

    public function test_la_solicitud_web_guarda_quien_firma(): void
    {
        $this->post(route('proyectos.solicitar.store'), [
            'nombre' => 'Marcela Ruiz', 'correo' => 'marcela@cliente.co', 'titulo' => 'Señalética',
            'resumen' => 'Veinte letreros en acrílico para señalizar el edificio.', 'cliente' => 'externo',
            'persona' => 'juridica', 'documento_tipo' => 'NIT', 'documento' => '900.123.456-7',
            'razon_social' => 'Acrílicos del Norte S.A.S.', 'representante' => 'Marcela Ruiz',
            'direccion' => 'Calle 12 # 34-56',
        ]);

        $p = Project::firstOrFail();

        $this->assertTrue($p->esPersonaJuridica());
        $this->assertSame('Acrílicos del Norte S.A.S. · NIT 900.123.456-7 · representante legal Marcela Ruiz', $p->quienFirma());
    }

    public function test_una_persona_natural_firma_con_su_cedula(): void
    {
        $p = Project::create([
            'name' => 'X', 'stage' => 'idea', 'status' => 'activo', 'source' => 'correo',
            'contact_name' => 'Pedro Pérez', 'client_person_kind' => 'natural',
            'client_document_type' => 'CC', 'client_document' => '52.123.456',
        ]);

        $this->assertSame('Pedro Pérez · CC 52.123.456', $p->quienFirma());
    }

    /** Sin definir, no se inventa: la ficha lo pide en vez de suponer. */
    public function test_sin_definir_no_hay_quien_firma(): void
    {
        $p = Project::create(['name' => 'X', 'stage' => 'idea', 'status' => 'activo', 'source' => 'correo', 'contact_name' => 'Pedro']);

        $this->assertNull($p->quienFirma());
    }

    // ------------------------------------------------------- la ficha

    public function test_la_ficha_dice_aceptada_y_luego_contrato_enviado(): void
    {
        $p = $this->conPropuesta();
        $cliente = User::where('email', 'marcela@cliente.co')->firstOrFail();
        app(ProjectService::class)->aceptarPropuesta($p, $cliente);

        $this->get('/admin/projects/' . $p->getKey() . '/edit')
            ->assertOk()
            ->assertSee('Aceptada')
            ->assertSee('Falta mandar el contrato');
    }
}
