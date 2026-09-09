<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\ListProjects;
use App\Models\NotificationLog;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Projects\AcuerdoDeServicio;
use App\Services\Projects\ProjectService;
use App\Support\FactoresDeSesion;
use App\Support\Settings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El acuerdo de servicio que redacta el sistema (§11).
 *
 * Sale de lo que el proyecto ya sabe sobre la base del laboratorio, se ve
 * entero antes de generarlo, y queda como contrato del proyecto para
 * enviarse como cualquier otro.
 */
class AcuerdoDeServicioTest extends TestCase
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

    /** Un proyecto aceptado, con quien firma y sus entregables. */
    private function aceptado(): Project
    {
        $this->post(route('proyectos.solicitar.store'), [
            'nombre' => 'Marcela Ruiz', 'correo' => 'marcela@cliente.co', 'titulo' => 'Señalética',
            'resumen' => 'Veinte letreros en acrílico para señalizar el edificio.', 'cliente' => 'externo',
        ]);

        $p = Project::firstOrFail();
        $p->update([
            'lead_id' => $this->jefa()->id,
            'client_person_kind' => 'juridica', 'client_legal_name' => 'Acrílicos del Norte S.A.S.',
            'client_document_type' => 'NIT', 'client_document' => '900.123.456-7', 'client_representative' => 'Marcela Ruiz',
            'agreed_value' => 2_500_000, 'due_on' => '2026-10-15',
        ]);
        $p->deliverables()->create(['title' => 'Veinte letreros', 'detail' => 'acrílico de 5 mm', 'position' => 1]);

        app(ProjectService::class)->enviarPropuesta($p->fresh(), [
            'estimated_value' => 2_000_000, 'due_on' => '2026-10-15', 'mensaje' => 'Podemos empezar la otra semana.',
        ]);
        app(ProjectService::class)->aceptarPropuesta($p->fresh(), User::where('email', 'marcela@cliente.co')->firstOrFail());

        return $p->fresh();
    }

    public function test_los_datos_sugeridos_salen_del_proyecto(): void
    {
        $p = $this->aceptado();

        $datos = app(AcuerdoDeServicio::class)->datosSugeridos($p);

        $this->assertSame('Veinte letreros en acrílico para señalizar el edificio.', $datos['objeto']);
        $this->assertStringContainsString('Veinte letreros: acrílico de 5 mm', $datos['entregables']);
        $this->assertSame(2_500_000, $datos['valor']);
        $this->assertSame('2026-10-15', $datos['entrega']);
        $this->assertStringContainsString('1. Objeto.', $datos['clausulas']);
    }

    public function test_el_acuerdo_lleva_lo_del_proyecto_en_sus_clausulas(): void
    {
        $p = $this->aceptado();
        $acuerdo = app(AcuerdoDeServicio::class);

        $html = $acuerdo->render($p, $acuerdo->datosSugeridos($p));

        $this->assertStringContainsString('Acrílicos del Norte S.A.S.', $html);
        $this->assertStringContainsString('NIT 900.123.456-7', $html);
        $this->assertStringContainsString($p->code, $html);
        $this->assertStringContainsString('Veinte letreros en acrílico', $html);
        $this->assertStringContainsString('$2.500.000 pesos colombianos', $html);
        $this->assertStringContainsString('15 de octubre de 2026', $html);
        $this->assertStringContainsString('9. Vigencia.', $html);
        $this->assertStringContainsString('Jefa', $html, 'firma quien responde por el proyecto');
        $this->assertStringNotContainsString('{cliente}', $html, 'no queda ninguna llave sin rellenar');
    }

    /** La base la escribe el laboratorio; lo de cada proyecto se corrige a mano. */
    public function test_la_base_se_cambia_y_cada_acuerdo_se_corrige(): void
    {
        $p = $this->aceptado();
        Setting::put(Settings::ACUERDO_CLAUSULAS, 'Única. {cliente} encarga a {laboratorio} lo siguiente: {objeto}', 'proyectos');

        $this->assertStringStartsWith('Única.', AcuerdoDeServicio::clausulasBase());

        $acuerdo = app(AcuerdoDeServicio::class);
        $datos = $acuerdo->datosSugeridos($p);
        $datos['objeto'] = 'diez trofeos impresos en 3D';

        $html = $acuerdo->render($p, $datos);

        $this->assertStringContainsString('encarga a', $html);
        $this->assertStringContainsString('diez trofeos impresos en 3D', $html);
        $this->assertStringNotContainsString('9. Vigencia.', $html);
    }

    public function test_generar_deja_un_pdf_como_contrato_del_proyecto(): void
    {
        $p = $this->aceptado();
        $acuerdo = app(AcuerdoDeServicio::class);

        $doc = $acuerdo->generar($p, $acuerdo->datosSugeridos($p), $p->lead);

        $this->assertSame('contrato', $doc->kind);
        $this->assertSame('Acuerdo de servicio ' . $p->code, $doc->title);
        $this->assertTrue(Storage::disk('local')->exists($doc->file_path));
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($doc->file_path));

        // Y se manda como cualquier contrato.
        app(ProjectService::class)->enviarContrato($p->fresh(), $doc, null, $p->lead);
        $this->assertNotNull($p->fresh()->contract_sent_at);
    }

    /** La vista previa enseña lo escrito en el formulario, solo al equipo. */
    public function test_la_vista_previa_ensena_lo_escrito(): void
    {
        $p = $this->aceptado();
        $datos = app(AcuerdoDeServicio::class)->datosSugeridos($p) + ['project_id' => $p->id];
        $datos['objeto'] = 'un texto que solo está en el formulario';

        Cache::put('acuerdo:prueba', $datos, now()->addHour());

        $this->get(route('panel.acuerdo', ['project' => $p, 'token' => 'prueba']))
            ->assertOk()
            ->assertSee('un texto que solo está en el formulario')
            ->assertSee('Vista previa');

        $this->get(route('panel.acuerdo', ['project' => $p, 'token' => 'no-existe']))->assertNotFound();

        $cliente = User::where('email', 'marcela@cliente.co')->firstOrFail();
        $this->actingAs($cliente)->get(route('panel.acuerdo', ['project' => $p, 'token' => 'prueba']))->assertForbidden();
    }

    /** Desde el panel: generar y enviar en un paso. */
    public function test_desde_el_panel_se_genera_y_se_envia(): void
    {
        $p = $this->aceptado();
        $datos = app(AcuerdoDeServicio::class)->datosSugeridos($p);

        Livewire::test(ListProjects::class)
            ->callAction(TestAction::make('contrato')->table($p), [
                'origen'  => 'generar',
                'titulo'  => 'Acuerdo de servicio ' . $p->code,
                'mensaje' => 'Revísalo con calma.',
            ] + $datos)
            ->assertHasNoActionErrors();

        $p->refresh();

        $this->assertNotNull($p->contract_sent_at);
        $doc = $p->documents()->where('kind', 'contrato')->firstOrFail();
        $this->assertTrue(Storage::disk('local')->exists($doc->file_path));
        $this->assertTrue(NotificationLog::where('key', 'proyecto.contrato')->exists());
    }
}
