<?php

namespace Tests\Feature;

use App\Filament\Resources\Actas\Pages\CreateActa;
use App\Filament\Resources\Actas\Pages\ListActas;
use App\Models\Acta;
use App\Models\Area;
use App\Models\Asset;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Activos\ActaException;
use App\Services\Activos\ActaService;
use App\Services\Auth\TwoFactorService;
use App\Support\ConfiguracionDeActas;
use App\Support\FactoresDeSesion;
use Database\Seeders\NotificationTemplateSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Las actas de movimiento de activos: el formato FRF-207-f7 (§8).
 *
 * Se preparan con el inventario, las firman en pantalla quien entrega y quien
 * recibe, y si el activo sale de la Universidad se envían a Planta Física.
 */
class ActasDeMovimientoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Storage::fake('local');
        $this->seed(NotificationTemplateSeeder::class);

        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }
    }

    private function servicio(): ActaService
    {
        return app(ActaService::class);
    }

    private function persona(string $nombre, ?string $cedula = '1020304050', ?string $rol = null): User
    {
        $u = User::create([
            'name' => $nombre, 'email' => uniqid() . '@test.co', 'status' => 'activo', 'document_number' => $cedula,
        ]);

        if ($rol) {
            $u->assignRole($rol);
        }

        return $u->fresh();
    }

    private function entra(User $u): void
    {
        $servicio = app(TwoFactorService::class);
        $secreto = $servicio->generarSecreto($u);
        $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);
    }

    private function robot(): Asset
    {
        $area = Area::firstOrCreate(['slug' => 'robots'], ['name' => 'Robots']);

        return Asset::firstOrCreate(['asset_tag' => '60508'], [
            'area_id' => $area->id, 'name' => 'Robot perro', 'kind' => 'fijo', 'status' => 'operativo',
            'brand' => 'Unitree', 'model' => 'GO1', 'serial' => 'B42D200P3PDN5KA',
            'is_reservable' => true, 'min_minutes' => 30, 'autonomous_minutes' => 60, 'max_minutes' => 720,
        ]);
    }

    private function acta(User $entrega, User $recibe, string $tipo = 'prestamo_fuera'): Acta
    {
        $acta = Acta::create([
            'tipo' => $tipo, 'fecha' => '2026-10-09', 'created_by' => $entrega->id,
            'entrega_user_id' => $entrega->id, 'entrega_nombre' => $entrega->name, 'entrega_cedula' => $entrega->document_number,
            'entrega_proceso' => 'Unidad de desarrollo FABLAB', 'entrega_sede' => 'EL NOGAL',
            'recibe_user_id' => $recibe->id, 'recibe_nombre' => $recibe->name, 'recibe_cedula' => $recibe->document_number,
            'recibe_proceso' => 'Unidad de desarrollo FABLAB', 'recibe_sede' => 'EL NOGAL',
        ]);

        $acta->items()->create(\App\Models\ActaItem::desdeElActivo($this->robot()));

        return $acta->fresh();
    }

    /** Un PNG de verdad, como el que manda el lienzo. */
    private function firma(): string
    {
        return 'data:image/png;base64,' . base64_encode(UploadedFile::fake()->image('firma.png', 60, 20)->getContent());
    }

    // ------------------------------------------------------------ el formato

    public function test_el_formato_lleva_los_datos_del_inventario_y_las_referencias_de_planta_fisica(): void
    {
        $erick = $this->persona('Erick Hansen Gómez', '80187390', User::ROL_ADMINISTRADOR);
        $acta = $this->acta($erick, $erick);

        $this->assertMatchesRegularExpression('/^ACT-\d{4}-0001$/', $acta->code);

        $this->entra($erick);

        $this->get(route('actas.ver', $acta))
            ->assertOk()
            ->assertSee('FRF-207-f7')
            ->assertSee('CONTROL PARA EL MOVIMIENTO DE ACTIVOS')
            ->assertSee('18/Mar/2014')
            ->assertSee('PRÉSTAMO FUERA LAS INSTALACIONES DE LA UNIV. EAN')
            ->assertSeeInOrder(['60508', 'ROBOT PERRO', 'UNITREE', 'GO1', 'B42D200P3PDN5KA'])
            ->assertSee('Erick Hansen Gómez')
            ->assertSee('80187390')
            ->assertSee('EL NOGAL')
            ->assertSee('Wilmer Palencia')
            ->assertSee('Infraestructura y Planta Física');

        $this->get(route('actas.pdf', $acta))->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_el_formato_es_solo_para_el_equipo(): void
    {
        $erick = $this->persona('Erick', '80187390', User::ROL_ADMINISTRADOR);
        $acta = $this->acta($erick, $erick);

        $this->get(route('actas.ver', $acta))->assertRedirect();

        $this->actingAs($this->persona('Estudiante'))->get(route('actas.ver', $acta))->assertForbidden();
    }

    // ------------------------------------------------------------ las firmas

    public function test_se_envia_a_firma_y_cada_parte_recibe_su_enlace(): void
    {
        $erick = $this->persona('Erick', '80187390', User::ROL_ADMINISTRADOR);
        $ana = $this->persona('Ana Torres', '52123456');
        $acta = $this->acta($erick, $ana);

        $this->servicio()->enviarAFirma($acta, $erick);

        $acta->refresh();
        $this->assertSame('por_firmar', $acta->status);
        $this->assertNotSame($acta->entrega_token, $acta->recibe_token);
        $this->assertSame(2, NotificationLog::where('key', 'acta.firmar')->count());
        $this->assertTrue(NotificationLog::where('key', 'acta.firmar')->where('to', $ana->email)->exists());
    }

    public function test_sin_cedula_o_sin_activos_no_se_envia_a_firma(): void
    {
        $erick = $this->persona('Erick', '80187390', User::ROL_ADMINISTRADOR);
        $sinCedula = $this->persona('Ana', null);

        try {
            $this->servicio()->enviarAFirma($this->acta($erick, $sinCedula));
            $this->fail('Se envió a firma sin la cédula de quien recibe');
        } catch (ActaException $e) {
            $this->assertStringContainsString('cédula', $e->getMessage());
        }

        $vacia = $this->acta($erick, $erick);
        $vacia->items()->delete();

        $this->expectException(ActaException::class);
        $this->servicio()->enviarAFirma($vacia->fresh());
    }

    public function test_las_dos_partes_firman_por_su_enlace_y_el_acta_queda_firmada(): void
    {
        $erick = $this->persona('Erick', '80187390', User::ROL_ADMINISTRADOR);
        $ana = $this->persona('Ana Torres', '52123456');
        $acta = $this->servicio()->enviarAFirma($this->acta($erick, $ana), $erick);

        // Quien recibe abre su enlace, sin sesión, y ve el acta.
        $this->get(route('actas.firmar', $acta->recibe_token))
            ->assertOk()
            ->assertSee('firmas como')
            ->assertSee('quien recibe')
            ->assertSee('ROBOT PERRO')
            ->assertSee('Firmar el acta');

        $this->post(route('actas.firmar.guardar', $acta->recibe_token), ['firma' => $this->firma(), 'acepto' => '1'])
            ->assertRedirect(route('actas.firmar', $acta->recibe_token));

        $acta->refresh();
        $this->assertTrue($acta->firmo('recibe'));
        $this->assertFalse($acta->firmo('entrega'));
        $this->assertSame('por_firmar', $acta->status, 'falta quien entrega');
        Storage::disk('local')->assertExists($acta->recibe_firma_path);

        $this->post(route('actas.firmar.guardar', $acta->entrega_token), ['firma' => $this->firma(), 'acepto' => '1']);

        $acta->refresh();
        $this->assertSame('firmada', $acta->status);
        Storage::disk('local')->assertExists($acta->pdf_path);

        // A quien la preparó se le avisa, y se le dice lo que sigue.
        $this->assertTrue(NotificationLog::where('key', 'acta.firmada')->where('to', $erick->email)->exists());
    }

    /** Entrega y recibe la misma persona: un enlace, y una firma para las dos casillas. */
    public function test_una_sola_persona_firma_una_vez_por_las_dos_casillas(): void
    {
        $erick = $this->persona('Erick', '80187390', User::ROL_ADMINISTRADOR);
        $acta = $this->servicio()->enviarAFirma($this->acta($erick, $erick), $erick);

        $this->assertSame(1, NotificationLog::where('key', 'acta.firmar')->count());

        $this->post(route('actas.firmar.guardar', $acta->recibe_token), ['firma' => $this->firma(), 'acepto' => '1']);

        $acta->refresh();
        $this->assertTrue($acta->firmo('entrega') && $acta->firmo('recibe'));
        $this->assertSame('firmada', $acta->status);
    }

    public function test_no_se_firma_dos_veces_ni_sin_dibujar_ni_con_un_enlace_cualquiera(): void
    {
        $erick = $this->persona('Erick', '80187390', User::ROL_ADMINISTRADOR);
        $ana = $this->persona('Ana', '52123456');
        $acta = $this->servicio()->enviarAFirma($this->acta($erick, $ana), $erick);
        $url = route('actas.firmar.guardar', $acta->recibe_token);

        // Sin firma, o con algo que no es un PNG.
        $this->from(route('actas.firmar', $acta->recibe_token))->post($url, ['acepto' => '1'])->assertSessionHasErrors('firma');
        $this->from(route('actas.firmar', $acta->recibe_token))->post($url, ['firma' => 'data:image/png;base64,' . base64_encode('no soy una imagen'), 'acepto' => '1'])
            ->assertSessionHasErrors('firma');
        $this->assertFalse($acta->fresh()->firmo('recibe'));

        // Sin marcar que la revisó.
        $this->from(route('actas.firmar', $acta->recibe_token))->post($url, ['firma' => $this->firma()])->assertSessionHasErrors('acepto');

        $this->post($url, ['firma' => $this->firma(), 'acepto' => '1']);
        $primera = $acta->fresh()->recibe_firma_path;

        // Otra vez: no la reemplaza.
        $this->from(route('actas.firmar', $acta->recibe_token))->post($url, ['firma' => $this->firma(), 'acepto' => '1'])->assertSessionHasErrors('firma');
        $this->assertSame($primera, $acta->fresh()->recibe_firma_path);

        // Un enlace inventado no abre nada.
        $this->get(route('actas.firmar', str_repeat('a', 48)))->assertNotFound();
    }

    public function test_un_acta_anulada_ya_no_se_firma(): void
    {
        $erick = $this->persona('Erick', '80187390', User::ROL_ADMINISTRADOR);
        $ana = $this->persona('Ana', '52123456');
        $acta = $this->servicio()->enviarAFirma($this->acta($erick, $ana), $erick);
        $token = $acta->recibe_token;

        $this->servicio()->anular($acta);

        // El enlace deja de existir.
        $this->get(route('actas.firmar', $token))->assertNotFound();
        $this->assertSame('anulada', $acta->fresh()->status);
    }

    // -------------------------------------------------------- Planta Física

    public function test_firmada_y_saliendo_de_la_universidad_se_envia_a_planta_fisica(): void
    {
        $erick = $this->persona('Erick', '80187390', User::ROL_ADMINISTRADOR);
        $acta = $this->servicio()->enviarAFirma($this->acta($erick, $erick), $erick);
        $this->servicio()->firmar($acta, 'recibe', $this->firma());

        $aviso = $this->servicio()->enviarAPlantaFisica($acta->fresh(), $erick);

        $this->assertSame('wopalencia@universidadean.edu.co', $aviso->to);
        $this->assertStringContainsString('FRF-207-f7', $aviso->subject);

        $acta->refresh();
        $this->assertSame('enviada', $acta->status);
        $this->assertSame('wopalencia@universidadean.edu.co', $acta->enviada_a_planta_a);
        $this->assertSame($erick->id, $acta->enviada_a_planta_por);

        Mail::assertSent(\App\Mail\PlantillaMail::class, fn ($m) => $m->hasTo('wopalencia@universidadean.edu.co')
            && count($m->adjuntos) === 1
            && $m->adjuntos[0]['nombre'] === 'FRF-207-f7_' . $acta->code . '.pdf');
    }

    public function test_sin_las_dos_firmas_no_se_envia_a_planta_fisica(): void
    {
        $erick = $this->persona('Erick', '80187390', User::ROL_ADMINISTRADOR);
        $ana = $this->persona('Ana', '52123456');
        $acta = $this->servicio()->enviarAFirma($this->acta($erick, $ana), $erick);
        $this->servicio()->firmar($acta, 'recibe', $this->firma());

        $this->expectException(ActaException::class);
        $this->servicio()->enviarAPlantaFisica($acta->fresh(), $erick);
    }

    /** Un préstamo dentro de la Universidad no pasa por Planta Física. */
    public function test_lo_que_no_sale_de_la_universidad_no_se_envia(): void
    {
        $erick = $this->persona('Erick', '80187390', User::ROL_ADMINISTRADOR);
        $acta = $this->servicio()->enviarAFirma($this->acta($erick, $erick, 'prestamo_dentro'), $erick);
        $this->servicio()->firmar($acta, 'recibe', $this->firma());

        $this->assertSame('firmada', $acta->fresh()->status);
        $this->assertFalse($acta->saleDeLaUniversidad());

        $this->expectException(ActaException::class);
        $this->servicio()->enviarAPlantaFisica($acta->fresh(), $erick);
    }

    /** El correo de Planta Física se cambia desde el panel, sin desplegar. */
    public function test_el_correo_de_planta_fisica_es_configurable(): void
    {
        ConfiguracionDeActas::guardar(['correo_planta' => 'planta@universidadean.edu.co', 'autoriza_nombre' => 'Otra Persona']);

        $erick = $this->persona('Erick', '80187390', User::ROL_ADMINISTRADOR);
        $acta = $this->servicio()->enviarAFirma($this->acta($erick, $erick), $erick);
        $this->servicio()->firmar($acta, 'recibe', $this->firma());

        $this->assertSame('planta@universidadean.edu.co', $this->servicio()->enviarAPlantaFisica($acta->fresh(), $erick)->to);

        // Y lo que no se tocó sigue con su valor de fábrica.
        $this->assertSame('EL NOGAL', ConfiguracionDeActas::valor('sede'));
    }

    // ------------------------------------------------------------- el panel

    public function test_se_prepara_desde_el_panel_con_los_datos_del_inventario(): void
    {
        $erick = $this->persona('Erick Hansen Gómez', '80187390', User::ROL_ADMINISTRADOR);
        $ana = $this->persona('Ana Torres', '52123456');
        $robot = $this->robot();
        $this->entra($erick);

        Livewire::test(CreateActa::class)
            // Quien entrega viene puesto: quien la prepara.
            ->assertSet('data.entrega_user_id', $erick->id)
            ->assertSet('data.entrega_nombre', 'Erick Hansen Gómez')
            ->assertSet('data.entrega_cedula', '80187390')
            ->assertSet('data.entrega_sede', 'EL NOGAL')
            ->set('data.tipo', 'prestamo_fuera')
            // Elegir a quien recibe carga su nombre y su cédula.
            ->set('data.recibe_user_id', $ana->id)
            ->assertSet('data.recibe_nombre', 'Ana Torres')
            ->assertSet('data.recibe_cedula', '52123456')
            ->set('data.items', ['a' => \App\Models\ActaItem::desdeElActivo($robot)])
            ->call('create')
            ->assertHasNoFormErrors();

        $acta = Acta::sole();
        $this->assertSame('borrador', $acta->status);
        $this->assertSame($erick->id, $acta->created_by);
        $this->assertSame('60508', $acta->items->first()->placa);
        $this->assertSame('B42D200P3PDN5KA', $acta->items->first()->serie);

        // Desde la lista se envía a firma.
        Livewire::test(ListActas::class)
            ->assertSee($acta->code)
            ->callAction(TestAction::make('enviarAFirma')->table($acta))
            ->assertHasNoActionErrors();

        $this->assertSame('por_firmar', $acta->fresh()->status);
    }

    /** El botón de Planta Física solo aparece cuando toca. */
    public function test_el_boton_de_planta_fisica_solo_sale_firmada_y_si_sale_de_la_universidad(): void
    {
        $erick = $this->persona('Erick', '80187390', User::ROL_ADMINISTRADOR);
        $fuera = $this->acta($erick, $erick);
        $dentro = $this->acta($erick, $erick, 'prestamo_dentro');
        $this->entra($erick);

        Livewire::test(ListActas::class)
            ->assertActionHidden(TestAction::make('enviarAPlanta')->table($fuera));

        foreach ([$fuera, $dentro] as $acta) {
            $this->servicio()->enviarAFirma($acta, $erick);
            $this->servicio()->firmar($acta->fresh(), 'recibe', $this->firma());
        }

        Livewire::test(ListActas::class)
            ->assertActionVisible(TestAction::make('enviarAPlanta')->table($fuera->fresh()))
            ->assertActionHidden(TestAction::make('enviarAPlanta')->table($dentro->fresh()))
            ->callAction(TestAction::make('enviarAPlanta')->table($fuera->fresh()))
            ->assertHasNoActionErrors();

        $this->assertSame('enviada', $fuera->fresh()->status);
    }
}
