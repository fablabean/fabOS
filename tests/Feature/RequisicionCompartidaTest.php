<?php

namespace Tests\Feature;

use App\Filament\Resources\PurchaseRequests\Pages\ListPurchaseRequests;
use App\Models\Budget;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\Purchasing\PurchasingService;
use App\Support\FactoresDeSesion;
use App\Services\Auth\TwoFactorService;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * La requisición compartida con compras y el carrito ya armado (§13).
 *
 * Quien recibe la requisición en el área de compras no tiene cuenta en fabOS:
 * abre un enlace, ve el documento y baja el PDF. El enlace no existe hasta
 * que alguien decide compartir, y se puede revocar.
 */
class RequisicionCompartidaTest extends TestCase
{
    use RefreshDatabase;

    private function compras(): PurchasingService
    {
        return app(PurchasingService::class);
    }

    private function persona(): User
    {
        return User::create([
            'name' => 'Persona ' . uniqid(), 'email' => uniqid() . '@test.co', 'status' => 'activo',
        ]);
    }

    private function administrador(): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }

        $u = $this->persona();
        $u->assignRole(User::ROL_ADMINISTRADOR);

        return $u->fresh();
    }

    private function entra(User $u): self
    {
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $this;
    }

    private function solicitud(?User $quien = null): PurchaseRequest
    {
        $presupuesto = Budget::create([
            'name' => 'Materiales', 'year' => 2026, 'amount' => 10_000_000, 'status' => 'vigente',
        ]);

        $carrito = $this->compras()->abrirCarrito($quien ?? $this->persona(), $presupuesto, 'Sensores para el semillero');
        // Algo que no esta en el catalogo: se pide con sus palabras, sin insumo.
        $this->compras()->agregar($carrito, 'Sensor de temperatura DS18B20', 10, 12_000, proveedor: 'Amazon');
        $carrito->update(['cart_url' => 'https://www.amazon.com/gp/cart/view.html?ref_=nav_cart&id=abc123']);

        return $carrito->fresh();
    }

    // ---------------------------------------------------------- el enlace

    public function test_sin_compartir_no_hay_enlace(): void
    {
        $s = $this->solicitud();

        $this->assertFalse($s->estaCompartida());
        $this->assertNull($s->enlaceCompartido());
    }

    public function test_compartir_da_el_mismo_enlace_las_dos_veces(): void
    {
        $s = $this->solicitud();

        // Quien ya lo mando por correo espera que el segundo sea el mismo.
        $primero = $this->compras()->compartir($s);
        $segundo = $this->compras()->compartir($s->fresh());

        $this->assertSame($primero, $segundo);
        $this->assertStringContainsString('/compras/compartida/', $primero);
        $this->assertNotNull($s->fresh()->shared_at);
    }

    public function test_el_enlace_abre_sin_sesion_y_muestra_todo(): void
    {
        $s = $this->solicitud();
        $enlace = $this->compras()->compartir($s);

        $this->get($enlace)
            ->assertOk()
            ->assertSee($s->code)
            ->assertSee('Sensor de temperatura DS18B20')
            ->assertSee('Carrito ya armado')
            ->assertSee('https://www.amazon.com/gp/cart/view.html?ref_=nav_cart&amp;id=abc123', false)
            ->assertSee('Descargar PDF');
    }

    public function test_el_enlace_deja_bajar_el_pdf(): void
    {
        $s = $this->solicitud();
        $this->compras()->compartir($s);

        $respuesta = $this->get(route('compras.compartida.pdf', $s->fresh()->share_token))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->assertStringContainsString($s->code . '.pdf', $respuesta->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $respuesta->getContent());
    }

    public function test_un_enlace_inventado_no_abre_nada(): void
    {
        $this->solicitud();

        $this->get(route('compras.compartida', str_repeat('x', 40)))->assertNotFound();
        $this->get(route('compras.compartida.pdf', str_repeat('x', 40)))->assertNotFound();
    }

    public function test_dejar_de_compartir_apaga_el_enlace_y_el_siguiente_es_otro(): void
    {
        $s = $this->solicitud();
        $viejo = $this->compras()->compartir($s);

        $this->compras()->dejarDeCompartir($s->fresh());

        $this->assertFalse($s->fresh()->estaCompartida());
        $this->get($viejo)->assertNotFound();

        $this->assertNotSame($viejo, $this->compras()->compartir($s->fresh()));
    }

    // ------------------------------------------------------- con sesión

    public function test_desde_el_panel_tambien_se_baja_el_pdf(): void
    {
        $u = $this->administrador();
        $s = $this->solicitud($u);

        $this->entra($u)->get(route('compras.requisicion.pdf', $s))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        // Y la pantalla con sesion lleva el boton para bajarlo.
        $this->entra($u)->get(route('compras.requisicion', $s))
            ->assertOk()
            ->assertSee('Descargar PDF')
            ->assertSee('Carrito ya armado');
    }

    public function test_quien_no_entra_al_backoffice_no_baja_el_pdf(): void
    {
        $s = $this->solicitud();

        $this->actingAs($this->persona())
            ->get(route('compras.requisicion.pdf', $s))
            ->assertForbidden();
    }

    public function test_la_ficha_pide_el_enlace_del_carrito_y_deja_compartir(): void
    {
        $u = $this->administrador();
        $s = $this->solicitud($u);
        $this->compras()->compartir($s);

        $this->entra($u)->get("/admin/purchase-requests/{$s->id}/edit")
            ->assertOk()
            ->assertSee('Enlace del carrito')
            ->assertSee('No tiene que estar en el catálogo')
            ->assertSee('Enlace para compras')
            ->assertSee('Dejar de compartir');
    }

    public function test_la_accion_del_listado_comparte_y_revoca(): void
    {
        $u = $this->administrador();
        $s = $this->solicitud($u);
        $this->entra($u);

        // Abrir el dialogo ya comparte: el enlace queda listo para copiar.
        Livewire::test(ListPurchaseRequests::class)
            ->mountAction(TestAction::make('compartir')->table($s))
            ->assertActionMounted(TestAction::make('compartir')->table($s));

        $this->assertTrue($s->fresh()->estaCompartida());

        Livewire::test(ListPurchaseRequests::class)
            ->callAction(TestAction::make('dejarDeCompartir')->table($s))
            ->assertHasNoActionErrors();

        $this->assertFalse($s->fresh()->estaCompartida());
    }

    // ------------------------------------------- el proveedor y su cotización

    /**
     * A quién se le compra sale en la requisición, con sus datos en su sitio
     * y no perdido en las observaciones.
     */
    public function test_la_requisicion_lleva_los_datos_del_proveedor(): void
    {
        $s = $this->solicitud();
        $s->update([
            'supplier_name' => 'Tap Publicidad S.A.S.', 'supplier_tax_id' => '901.234.567-8',
            'supplier_address' => 'Calle 72 # 10-34, Bogotá', 'supplier_contact' => 'Jonathan Rincón',
        ]);

        $this->compras()->compartir($s);

        $this->get(route('compras.compartida', $s->fresh()->share_token))
            ->assertOk()
            ->assertSeeInOrder(['Proveedor', 'Tap Publicidad S.A.S.', 'NIT', '901.234.567-8', 'Calle 72 # 10-34, Bogotá', 'Representante', 'Jonathan Rincón'])
            // Sin cotización adjunta no se ofrece bajarla.
            ->assertDontSee('Descargar la cotización');
    }

    /** Sin proveedor anotado, la requisición no deja un bloque vacío. */
    public function test_sin_proveedor_no_sale_el_bloque(): void
    {
        $s = $this->solicitud();
        $this->compras()->compartir($s);

        $this->get(route('compras.compartida', $s->fresh()->share_token))
            ->assertOk()
            ->assertDontSee('Representante');
    }

    /**
     * La cotización formal se baja desde la requisición: quien trabaja en el
     * laboratorio con su sesión, y compras por el enlace compartido. Sin una
     * de las dos, no.
     */
    public function test_la_cotizacion_se_baja_con_permiso_y_no_sin_el(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('compras/cotizaciones/abc.pdf', '%PDF-1.4 cotizacion');

        $admin = $this->administrador();
        $s = $this->solicitud($admin);
        $s->update(['quote_path' => 'compras/cotizaciones/abc.pdf', 'quote_name' => 'Cotización Tap 2026.pdf']);

        // Sin sesión y sin enlace compartido: no.
        $this->get(route('compras.cotizacion', $s))->assertRedirect();

        // Con sesión del laboratorio: se baja, con el código de la solicitud en el nombre.
        $this->entra($admin)
            ->get(route('compras.cotizacion', $s))
            ->assertOk()
            ->assertDownload($s->code . '_cotizacion.pdf');

        $this->entra($admin)
            ->get(route('compras.requisicion', $s))
            ->assertOk()
            ->assertSee('Descargar la cotización')
            ->assertSee($s->code . '_cotizacion.pdf');

        // Compartida: compras también la baja.
        $this->compras()->compartir($s);
        $token = $s->fresh()->share_token;

        auth()->logout();
        $this->get(route('compras.compartida.cotizacion', $token))->assertOk()->assertDownload($s->code . '_cotizacion.pdf');

        // Con un enlace que no existe, no.
        $this->get(route('compras.compartida.cotizacion', str_repeat('x', 40)))->assertNotFound();
    }

    public function test_sin_cotizacion_adjunta_no_hay_nada_que_bajar(): void
    {
        $admin = $this->administrador();
        $s = $this->solicitud($admin);

        $this->entra($admin)->get(route('compras.cotizacion', $s))->assertNotFound();
    }

    // ------------------------------------------------ enviar es mandársela a alguien

    /**
     * «Enviar» marcaba la solicitud como enviada y no se la mandaba a nadie.
     * Ahora le llega por correo a quien la tramita, con la requisición en PDF.
     */
    public function test_enviar_le_manda_la_requisicion_a_compras(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $admin = $this->administrador();
        $s = $this->solicitud($admin);
        $s->update(['supplier_name' => 'Tap Publicidad S.A.S.', 'supplier_tax_id' => '901.234.567-8']);

        $s = $this->compras()->enviar($s, $admin);

        $this->assertSame('enviada', $s->status);
        $this->assertSame('scpaguay@universidadean.edu.co', $s->sent_to_email);
        $this->assertSame('CAROLINA PAGUAY PARDO', $s->sent_to_name);
        $this->assertNotNull($s->sent_to_at);
        // Mandarla es decidir compartirla: lleva el enlace para verla.
        $this->assertTrue($s->estaCompartida());

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\PlantillaMail::class, fn ($m) => $m->hasTo('scpaguay@universidadean.edu.co')
            && count($m->adjuntos) === 1
            && $m->adjuntos[0]['nombre'] === $s->code . '.pdf'
            // «Responder» le llega a quien la pidió, no al buzón que nadie lee.
            && $m->hasReplyTo($admin->email, $admin->name));

        $aviso = \App\Models\NotificationLog::where('key', 'compra.requisicion')->sole();
        $this->assertStringContainsString($s->code, $aviso->subject);
        $this->assertStringContainsString('Hola CAROLINA', $aviso->body);
        $this->assertStringContainsString('Tap Publicidad S.A.S.', $aviso->body);
        $this->assertStringContainsString('Sensor de temperatura DS18B20', $aviso->body);
        $this->assertStringContainsString($s->enlaceCompartido(), $aviso->body);
    }

    /** Con cotización adjunta, va en el mismo correo. */
    public function test_la_cotizacion_viaja_con_la_requisicion(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Storage::fake('local');
        \Illuminate\Support\Facades\Storage::disk('local')->put('compras/cotizaciones/abc.pdf', '%PDF-1.4');
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $admin = $this->administrador();
        $s = $this->solicitud($admin);
        $s->update(['quote_path' => 'compras/cotizaciones/abc.pdf', 'quote_name' => 'cotiza.pdf']);

        $s = $this->compras()->enviar($s, $admin);

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\PlantillaMail::class, fn ($m) => count($m->adjuntos) === 2
            && $m->adjuntos[1]['nombre'] === $s->code . '_cotizacion.pdf');
    }

    /** A quién se envían se cambia desde el panel; y una ya enviada se puede volver a mandar. */
    public function test_el_destinatario_es_configurable_y_se_puede_reenviar(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $admin = $this->administrador();
        $s = $this->compras()->enviar($this->solicitud($admin), $admin);
        $this->assertSame('scpaguay@universidadean.edu.co', $s->sent_to_email);

        $this->entra($admin);

        Livewire::test(ListPurchaseRequests::class)
            ->callAction('configuracion', ['nombre' => 'Otra Persona', 'correo' => 'otra@universidadean.edu.co'])
            ->assertHasNoActionErrors()
            // «Enviar» ya no sale: está enviada. Sale «volver a enviar».
            ->assertActionHidden(TestAction::make('enviar')->table($s))
            ->callAction(TestAction::make('reenviarACompras')->table($s))
            ->assertHasNoActionErrors();

        $s->refresh();
        $this->assertSame('otra@universidadean.edu.co', $s->sent_to_email);
        $this->assertSame('Otra Persona', $s->sent_to_name);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\PlantillaMail::class, fn ($m) => $m->hasTo('otra@universidadean.edu.co'));
    }

    /** Si el correo no sale, la solicitud lo dice en vez de darlo por enviado. */
    public function test_si_el_correo_no_sale_no_se_da_por_entregada(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        // Sin sembrar las plantillas: el aviso queda omitido.
        \App\Models\NotificationTemplate::where('key', 'compra.requisicion')->delete();

        $admin = $this->administrador();
        $s = $this->compras()->enviar($this->solicitud($admin), $admin);

        $this->assertSame('enviada', $s->status);
        $this->assertNull($s->sent_to_at);
    }

    // ------------------------------------------------------------ las copias

    /**
     * Por defecto van en copia los superadmins, y solo ellos: una requisición
     * en copia a todo el equipo es un correo que casi todos aprenden a ignorar.
     */
    public function test_por_defecto_se_copia_solo_a_los_superadmins(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $admin = $this->administrador();
        $jefe = User::create(['name' => 'Súper', 'email' => 'super@lab.co', 'status' => 'activo']);
        $jefe->assignRole(Role::findOrCreate(User::ROL_SUPERADMIN, 'web'));
        // Ni los administradores, ni quien no coordina, ni un superadmin inactivo.
        $this->persona();
        $inactivo = User::create(['name' => 'Ex', 'email' => 'ex@lab.co', 'status' => 'inactivo']);
        $inactivo->assignRole(User::ROL_SUPERADMIN);

        $s = $this->compras()->enviar($this->solicitud($admin), $admin);

        $this->assertSame('super@lab.co', $s->sent_cc);

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\PlantillaMail::class, fn ($m) => $m->hasTo('scpaguay@universidadean.edu.co')
            && $m->hasCc('super@lab.co') && ! $m->hasCc($admin->email) && ! $m->hasCc('ex@lab.co'));
    }

    /** Se marca y se desmarca persona por persona, y quien sale del equipo deja de recibir copia. */
    public function test_se_elige_a_quien_del_equipo_se_copia(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $admin = $this->administrador();
        $jefe = User::create(['name' => 'Súper', 'email' => 'super@lab.co', 'status' => 'activo']);
        $jefe->assignRole(Role::findOrCreate(User::ROL_SUPERADMIN, 'web'));
        $this->entra($admin);

        // La pantalla abre con los superadmins marcados.
        Livewire::test(ListPurchaseRequests::class)
            ->mountAction('configuracion')
            ->assertSchemaStateSet(['copiar_a' => [$jefe->id]])
            // Se desmarca al superadmin y se marca al administrador.
            ->setActionData(['copiar_a' => [$admin->id]])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $this->assertSame([mb_strtolower($admin->email)], \App\Support\ConfiguracionDeCompras::copias());

        // Cambiar el nombre desde otro sitio no borra a quién se copia.
        \App\Support\ConfiguracionDeCompras::guardar(['nombre' => 'Otra', 'correo' => 'otra@universidadean.edu.co']);
        $this->assertSame([mb_strtolower($admin->email)], \App\Support\ConfiguracionDeCompras::copias());

        // Y si deja de estar activo, deja de recibir copia sin que nadie lo quite.
        $admin->update(['status' => 'inactivo']);
        $this->assertSame([], \App\Support\ConfiguracionDeCompras::copias());
    }

    /** Se pueden sumar otros correos, y apagar la copia al equipo. */
    public function test_las_copias_se_configuran(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->seed(\Database\Seeders\NotificationTemplateSeeder::class);

        $admin = $this->administrador();
        $this->entra($admin);

        Livewire::test(ListPurchaseRequests::class)
            ->callAction('configuracion', [
                'nombre' => 'CAROLINA PAGUAY PARDO', 'correo' => 'scpaguay@universidadean.edu.co',
                'copiar_a' => [],
                // Como salen al pegarlos: con comas, saltos de línea, mayúsculas y el principal repetido.
                'copias' => "Planeacion@universidadean.edu.co,\nfinanzas@universidadean.edu.co; scpaguay@universidadean.edu.co",
            ])
            ->assertHasNoActionErrors();

        $this->assertSame(
            ['planeacion@universidadean.edu.co', 'finanzas@universidadean.edu.co'],
            \App\Support\ConfiguracionDeCompras::copias(),
            'sin el equipo, sin repetir al destinatario principal',
        );

        $s = $this->compras()->enviar($this->solicitud($admin), $admin);

        $this->assertSame('planeacion@universidadean.edu.co, finanzas@universidadean.edu.co', $s->sent_cc);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\PlantillaMail::class, fn ($m) => $m->hasCc('finanzas@universidadean.edu.co') && ! $m->hasCc($admin->email));

        // Un correo mal escrito no se guarda.
        Livewire::test(ListPurchaseRequests::class)
            ->callAction('configuracion', ['nombre' => 'X', 'correo' => 'x@ean.co', 'copias' => 'esto-no-es-un-correo'])
            ->assertHasActionErrors(['copias']);
    }
}
