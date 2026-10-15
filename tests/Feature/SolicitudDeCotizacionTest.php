<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseRequests\Pages\CreatePurchaseRequest;
use App\Filament\Resources\PurchaseRequests\Pages\ListPurchaseRequests;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Purchasing\PurchasingService;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pedirle a la Universidad que cotice (§13).
 *
 * Una solicitud de compra dice desde el principio si ya trae la cotización
 * o si se le pide a compras que la haga; en ese caso sale —en pantalla, en
 * PDF y por correo— en el formato de la Universidad, el ADF-228-f5.
 */
class SolicitudDeCotizacionTest extends TestCase
{
    use RefreshDatabase;

    private function administrador(): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = User::create(['name' => 'Michael Torres', 'email' => 'mstorres@lab.co', 'status' => 'activo', 'phone' => '3001234567']);
        $u->assignRole(User::ROL_ADMINISTRADOR);

        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u->fresh();
    }

    /** Una solicitud por cotizar, como queda al crearla desde el panel. */
    private function porCotizar(User $quien): PurchaseRequest
    {
        Livewire::withQueryParams(['modalidad' => PurchaseRequest::POR_COTIZAR])
            ->test(CreatePurchaseRequest::class)
            ->assertSet('data.modalidad', PurchaseRequest::POR_COTIZAR)
            ->set('data.justification', 'Trapos y destornillador eléctrico para el taller')
            ->set('data.supervisor', 'Erick Hansen')
            ->set('data.process_area', 'Unidad de desarrollo FABLAB')
            ->set('data.requires_art', true)
            ->set('data.notes', 'Entregar en la sede El Nogal.')
            ->set('data.items', ['a' => [
                'description' => 'Destornillador eléctrico inalámbrico', 'specs' => "Batería de litio 4V\nCon juego de puntas",
                'brand' => 'Bosch', 'quantity' => 2, 'unit' => 'unidad', 'notes' => 'Con estuche',
            ]])
            ->call('create')
            ->assertHasNoFormErrors();

        return PurchaseRequest::with('items')->sole();
    }

    public function test_se_elige_antes_de_empezar_y_guarda_lo_que_pide_el_formato(): void
    {
        $michael = $this->administrador();

        // El botón de la lista abre el formulario ya en esa modalidad.
        Livewire::test(ListPurchaseRequests::class)->assertActionExists('pedirCotizacion');

        // Sin pedirlo, empieza como siempre: con la cotización hecha.
        Livewire::test(CreatePurchaseRequest::class)->assertSet('data.modalidad', PurchaseRequest::COTIZADA);

        $s = $this->porCotizar($michael);

        $this->assertTrue($s->pideCotizacion());
        $this->assertSame('Erick Hansen', $s->supervisor);
        $this->assertTrue($s->requires_art);
        $this->assertSame($michael->id, $s->requested_by);

        $linea = $s->items->first();
        $this->assertSame('Bosch', $linea->brand);
        $this->assertStringContainsString('Batería de litio', $linea->specs);
        $this->assertSame('Con estuche', $linea->notes);
        $this->assertSame(0, (int) $linea->unit_price, 'sin precios: eso es lo que se pide');
    }

    public function test_sale_en_el_formato_adf_228_f5_en_pantalla_y_en_pdf(): void
    {
        $s = $this->porCotizar($this->administrador());

        $this->get(route('compras.requisicion', $s))
            ->assertOk()
            ->assertSee('ADF-228-f5')
            ->assertSee('Términos de Referencia para Solicitud de Cotización')
            ->assertSee('INFORMACION DEL SOLICITANTE')
            ->assertSee('Michael Torres')
            ->assertSee('mstorres@lab.co')
            ->assertSee('3001234567')
            ->assertSee('Erick Hansen')
            ->assertSeeInOrder(['Destornillador eléctrico inalámbrico', 'Batería de litio 4V', 'Bosch'])
            ->assertSee('Entregar en la sede El Nogal.')
            ->assertDontSee('Total estimado');

        $this->get(route('compras.requisicion.pdf', $s))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertDownload('ADF-228-f5_' . $s->code . '.pdf');
    }

    public function test_al_enviarla_le_llega_a_compras_con_el_formato_de_cotizacion(): void
    {
        Mail::fake();
        Storage::fake('local');
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $michael = $this->administrador();
        $s = app(PurchasingService::class)->enviar($this->porCotizar($michael), $michael);

        $this->assertSame('enviada', $s->status);
        $this->assertNotNull($s->sent_to_at);

        Mail::assertSent(\App\Mail\PlantillaMail::class, fn ($m) => $m->hasTo('scpaguay@universidadean.edu.co')
            && $m->adjuntos[0]['nombre'] === 'ADF-228-f5_' . $s->code . '.pdf'
            && $m->hasReplyTo('mstorres@lab.co'));

        $aviso = \App\Models\NotificationLog::where('key', 'compra.solicitud_cotizacion')->sole();
        $this->assertStringContainsString('Solicitud de cotización ' . $s->code, $aviso->subject);
        $this->assertStringContainsString('ADF-228-f5', $aviso->body);
        $this->assertStringContainsString('Destornillador eléctrico inalámbrico (Bosch)', $aviso->body);
        $this->assertFalse(\App\Models\NotificationLog::where('key', 'compra.requisicion')->exists());
    }
}
