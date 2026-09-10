<?php

namespace Tests\Feature;

use App\Filament\Resources\Projects\Pages\EditProject;
use App\Filament\Resources\Projects\RelationManagers\PaymentsRelationManager;
use App\Mail\PlantillaMail;
use App\Models\NotificationLog;
use App\Models\Project;
use App\Models\ProjectPayment;
use App\Models\Setting;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Projects\PagosDeProyecto;
use App\Services\Projects\ProjectException;
use App\Support\FactoresDeSesion;
use App\Support\Settings;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Los pagos de un proyecto por QR (§11).
 *
 * Se pide con el valor y el QR del banco; el cliente responde con el
 * comprobante, su nombre y su documento; el laboratorio valida o devuelve.
 */
class PagosDeProyectoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        // El QR del banco, subido una vez para todo el laboratorio.
        Storage::disk('local')->put('pagos/qr.png', 'png');
        Setting::put(Settings::PAGOS_QR, 'pagos/qr.png', 'finanzas');
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

    private function proyecto(): Project
    {
        $this->post(route('proyectos.solicitar.store'), [
            'nombre' => 'Marcela Ruiz', 'correo' => 'marcela@cliente.co', 'titulo' => 'Señalética',
            'resumen' => 'Veinte letreros en acrílico para el edificio.', 'cliente' => 'externo',
        ]);

        $p = Project::firstOrFail();
        $p->update(['lead_id' => $this->jefa()->id, 'agreed_value' => 2_500_000]);

        return $p->fresh();
    }

    public function test_pedir_un_pago_le_llega_al_cliente_con_el_qr_y_queda_en_la_conversacion(): void
    {
        $p = $this->proyecto();

        $pago = app(PagosDeProyecto::class)->pedir($p, 1_250_000, 'Anticipo del 50 %', 'Con esto compramos el acrílico.', $p->lead);

        $this->assertSame(ProjectPayment::SOLICITADO, $pago->status);
        $this->assertSame('Anticipo del 50 % · $1.250.000', $pago->titulo());
        $this->assertTrue($pago->is($p->fresh()->pagoPendiente()));

        $aviso = NotificationLog::where('key', 'proyecto.pago_solicitado')->firstOrFail();
        $this->assertSame('marcela@cliente.co', $aviso->to);
        $this->assertStringContainsString('$1.250.000', $aviso->body);
        $this->assertStringContainsString('#pago', $aviso->body);
        $this->assertStringContainsString('Con esto compramos', $aviso->body);

        Mail::assertSent(PlantillaMail::class, fn (PlantillaMail $m) => count($m->adjuntos) === 1 && $m->adjuntos[0]['ruta'] === 'pagos/qr.png');

        $this->assertStringContainsString('Pedimos el pago de Anticipo', $p->comments()->reorder('id', 'desc')->first()->body);
    }

    public function test_sin_qr_no_se_pide(): void
    {
        $p = $this->proyecto();
        Setting::put(Settings::PAGOS_QR, '', 'finanzas');

        $this->expectException(ProjectException::class);
        app(PagosDeProyecto::class)->pedir($p, 1000, null, null, $p->lead);
    }

    /** El cliente paga y responde desde su página, por el enlace del correo. */
    public function test_el_cliente_responde_con_el_comprobante_su_nombre_y_su_documento(): void
    {
        $p = $this->proyecto();
        $pago = app(PagosDeProyecto::class)->pedir($p, 1_250_000, null, null, $p->lead);

        // La página, por el enlace firmado, enseña el valor, el QR y el formulario.
        $enlace = URL::temporarySignedRoute('proyectos.propuesta', now()->addDay(), ['project' => $p->id]);
        $this->app['auth']->forgetGuards();
        $this->get($enlace)->assertOk()
            ->assertSee('$1.250.000')
            ->assertSee(route('pagos.qr'), false)
            ->assertSee('Enviar el comprobante');

        $urlPagar = URL::temporarySignedRoute('proyectos.pagar', now()->addDay(), ['project' => $p->id, 'payment' => $pago->id]);

        $this->post($urlPagar, [
            'comprobante' => UploadedFile::fake()->image('pago.jpg', 600, 800),
            'nombre'      => 'Marcela Ruiz Gómez',
            'documento'   => '52.123.456',
        ])->assertSessionHasNoErrors();

        $pago->refresh();

        $this->assertSame(ProjectPayment::ENVIADO, $pago->status);
        $this->assertSame('Marcela Ruiz Gómez', $pago->payer_name);
        $this->assertSame('52.123.456', $pago->payer_document);
        $this->assertNotNull($pago->receipt_path);
        $this->assertTrue(Storage::disk('local')->exists($pago->receipt_path));

        // Queda en la conversación, con el comprobante pegado, y se le avisa a quien responde.
        $ultimo = $p->comments()->reorder('id', 'desc')->first();
        $this->assertStringContainsString('Envié el comprobante', $ultimo->body);
        $this->assertCount(1, $ultimo->adjuntos);
        $this->assertTrue(NotificationLog::where('key', 'proyecto.comprobante_recibido')->where('user_id', $p->lead_id)->exists());

        // Sin comprobante, no hay respuesta.
        $this->post($urlPagar, ['nombre' => 'X Y', 'documento' => '123'])->assertSessionHasErrors('comprobante');
    }

    public function test_el_laboratorio_valida_o_devuelve_desde_la_ficha(): void
    {
        $p = $this->proyecto();
        $pago = app(PagosDeProyecto::class)->pedir($p, 1_250_000, null, null, $p->lead);
        app(PagosDeProyecto::class)->enviarComprobante($pago, UploadedFile::fake()->image('pago.jpg'), 'Marcela Ruiz', '52123456');

        // Devuelto: vuelve a esperar comprobante, con el motivo, y el cliente recibe el QR otra vez.
        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $p, 'pageClass' => EditProject::class])
            ->callAction(TestAction::make('rechazar')->table($pago->fresh()), ['motivo' => 'La captura está cortada.'])
            ->assertHasNoActionErrors();

        $this->assertSame(ProjectPayment::RECHAZADO, $pago->fresh()->status);
        $this->assertTrue($pago->fresh()->esperaComprobante());
        $this->assertStringContainsString('cortada', NotificationLog::where('key', 'proyecto.pago_rechazado')->firstOrFail()->body);

        // Otro comprobante, y validado.
        app(PagosDeProyecto::class)->enviarComprobante($pago->fresh(), UploadedFile::fake()->image('pago2.jpg'), 'Marcela Ruiz', '52123456');

        Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $p, 'pageClass' => EditProject::class])
            ->callAction(TestAction::make('validar')->table($pago->fresh()), ['nota' => 'Llegó completo.'])
            ->assertHasNoActionErrors();

        $pago->refresh();
        $this->assertSame(ProjectPayment::VALIDADO, $pago->status);
        $this->assertSame($p->lead_id, $pago->validated_by);
        $this->assertNull($p->fresh()->pagoPendiente(), 'validado ya no espera nada');
        $this->assertTrue(NotificationLog::where('key', 'proyecto.pago_validado')->exists());
        $this->assertStringContainsString('validado', $p->comments()->reorder('id', 'desc')->first()->body);
    }

    /** Desde la lista se pide en un clic, y la fila avisa de lo pendiente. */
    public function test_desde_la_lista_se_pide_y_se_ve_lo_pendiente(): void
    {
        $p = $this->proyecto();

        Livewire::test(\App\Filament\Resources\Projects\Pages\ListProjects::class)
            ->callAction(TestAction::make('pedirPago')->table($p), ['valor' => 2_500_000, 'concepto' => 'Saldo final'])
            ->assertHasNoActionErrors();

        $this->assertSame(1, $p->payments()->count());

        $this->get('/admin/projects')->assertOk()->assertSee('pago pedido, sin comprobante');
    }

    /** La página de Finanzas: el QR se sube una vez y se ve lo que espera. */
    public function test_la_pagina_de_pagos_sube_el_qr_y_lista_lo_pendiente(): void
    {
        $p = $this->proyecto();
        app(PagosDeProyecto::class)->pedir($p, 1_250_000, 'Anticipo', null, $p->lead);

        Livewire::test(\App\Filament\Pages\Pagos::class)
            ->fillForm([
                'qr'            => UploadedFile::fake()->image('bancolombia.png', 400, 400),
                'instrucciones' => 'Paga con la app de Bancolombia y responde con el comprobante.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertStringStartsWith('pagos/', Settings::qrDePagos());
        $this->assertTrue(Storage::disk('local')->exists(Settings::qrDePagos()));
        $this->assertSame('Paga con la app de Bancolombia y responde con el comprobante.', Settings::instruccionesDePago());

        $this->get('/admin/pagos')->assertOk()->assertSee('Anticipo')->assertSee($p->code);
        $this->get(route('pagos.qr'))->assertOk();
    }
}
