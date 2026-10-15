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

    // -------------------------------------------- completar el inventario

    /**
     * Lo que se escribe en el acta le queda al equipo en su ficha, si le
     * faltaba: la primera acta completa el inventario y las siguientes ya
     * salen llenas. Lo que el inventario ya decía no se toca.
     */
    public function test_el_acta_completa_la_ficha_del_equipo_sin_pisar_lo_que_tenia(): void
    {
        $erick = $this->persona('Erick', '80187390', User::ROL_ADMINISTRADOR);
        $area = Area::firstOrCreate(['slug' => 'robots'], ['name' => 'Robots']);
        $go2 = Asset::create([
            'area_id' => $area->id, 'name' => 'Robot Unitree Go2', 'kind' => 'fijo', 'status' => 'operativo',
            'brand' => 'Unitree', 'is_reservable' => true, 'min_minutes' => 30, 'autonomous_minutes' => 60, 'max_minutes' => 720,
        ]);

        $acta = $this->acta($erick, $erick);
        $acta->items()->create([
            'asset_id' => $go2->id, 'descripcion' => 'Robot perro', 'placa' => '60777',
            'marca' => 'OTRA MARCA', 'modelo' => 'GO2', 'serie' => 'SN-123', 'estado' => 'B',
        ]);

        $go2->refresh();
        $this->assertSame('60777', $go2->asset_tag);
        $this->assertSame('GO2', $go2->model);
        $this->assertSame('SN-123', $go2->serial);
        $this->assertSame('Unitree', $go2->brand, 'la marca ya estaba: no se pisa');

        // La próxima acta de ese equipo ya sale llena.
        $this->assertSame('60777', \App\Models\ActaItem::desdeElActivo($go2)['placa']);

        // Y una placa que ya es de otro equipo no se copia.
        $otro = Asset::create([
            'area_id' => $area->id, 'name' => 'Otro robot', 'kind' => 'fijo', 'status' => 'operativo',
            'is_reservable' => true, 'min_minutes' => 30, 'autonomous_minutes' => 60, 'max_minutes' => 720,
        ]);
        $acta->items()->create(['asset_id' => $otro->id, 'descripcion' => 'Otro', 'placa' => '60777', 'estado' => 'B']);

        $this->assertNull($otro->fresh()->asset_tag);
    }

    // ------------------------------------------------- dentro de la U y devoluciones

    /** Un acta con dos activos, firmada por las dos partes. */
    private function actaFirmada(User $entrega, User $recibe, string $tipo = 'prestamo_fuera'): Acta
    {
        $acta = $this->acta($entrega, $recibe, $tipo);
        $acta->items()->create(['descripcion' => 'Control del robot', 'placa' => '60509', 'marca' => 'Unitree', 'estado' => 'B']);

        $this->servicio()->enviarAFirma($acta, $entrega);

        foreach (['entrega', 'recibe'] as $parte) {
            $this->servicio()->firmar($acta->fresh(), $parte, $this->firma());
        }

        return $acta->fresh();
    }

    public function test_un_movimiento_dentro_de_la_universidad_no_lleva_la_autorizacion_de_planta_fisica(): void
    {
        $erick = $this->persona('Erick Hansen Gómez', '80187390', User::ROL_ADMINISTRADOR);
        $ana = $this->persona('Ana Ruiz', '52000111');

        $dentro = $this->acta($erick, $ana, collect(array_keys(Acta::TIPOS))->first(fn ($t) => ! in_array($t, Acta::FUERA_DE_LA_U, true)));
        $this->assertFalse($dentro->saleDeLaUniversidad());

        $this->entra($erick);

        $this->get(route('actas.ver', $dentro))
            ->assertOk()
            ->assertSee('NO APLICA')
            ->assertSee('no requiere la firma de autorización de Planta Física')
            ->assertDontSee('Wilmer Palencia');

        // El que sale de la Universidad sigue llevándola.
        $this->get(route('actas.ver', $this->acta($erick, $ana)))
            ->assertOk()
            ->assertSee('Wilmer Palencia')
            ->assertDontSee('NO APLICA');
    }

    public function test_la_devolucion_se_registra_activo_por_activo_con_el_estado_en_que_volvio(): void
    {
        $erick = $this->persona('Erick Hansen Gómez', '80187390', User::ROL_ADMINISTRADOR);
        $ana = $this->persona('Ana Ruiz', '52000111');
        $acta = $this->actaFirmada($erick, $ana);

        [$robot, $control] = $acta->items()->reorder('id')->get()->all();

        $this->assertTrue($acta->admiteDevolucion());
        $this->assertSame('Sin devolver', $acta->resumenDeDevolucion());

        $this->entra($erick);

        $this->get(route('actas.devolver', $acta))
            ->assertOk()
            ->assertSee('Robot perro')
            ->assertSee('Control del robot');

        // Vuelve solo el robot, y vuelve peor.
        $respuesta = $this->post(route('actas.devolver.guardar', $acta), [
            'fecha' => now(config('fabos.lab.timezone'))->toDateString(),
            'observaciones' => 'Llegó sin la maleta.',
            'firma' => $this->firma(),
            'items' => [
                $robot->id   => ['vuelve' => '1', 'estado' => 'R', 'nota' => 'Rayón en la carcasa'],
                $control->id => ['estado' => 'B'],
            ],
        ]);

        $devolucion = $acta->devoluciones()->first();
        $this->assertNotNull($devolucion);
        $respuesta->assertRedirect(route('actas.devolucion.ver', $devolucion));

        $this->assertMatchesRegularExpression('/^DEV-\d{4}-0001$/', $devolucion->code);
        $this->assertSame('Ana Ruiz', $devolucion->devuelve_nombre);
        $this->assertSame('Erick Hansen Gómez', $devolucion->recibe_nombre);
        $this->assertNotNull($devolucion->firmo_at);
        Storage::disk('local')->assertExists($devolucion->firma_path);
        Storage::disk('local')->assertExists($devolucion->pdf_path);

        $robot->refresh();
        $this->assertTrue($robot->yaVolvio());
        $this->assertSame('R', $robot->estado_devolucion);
        $this->assertTrue($robot->volvioPeor());
        $this->assertFalse($control->fresh()->yaVolvio());

        $acta->refresh();
        $this->assertSame('Volvieron 1 de 2', $acta->resumenDeDevolucion());
        $this->assertTrue($acta->admiteDevolucion());

        $this->get(route('actas.devolucion.ver', $devolucion))
            ->assertOk()
            ->assertSee('DEVOLUCIÓN DE ACTIVOS')
            ->assertSee('ROBOT PERRO')
            ->assertSee('Rayón en la carcasa')
            ->assertSee('Llegó sin la maleta.')
            ->assertSee('Queda un activo por regresar')
            ->assertSee('Control del robot')
            ->assertSee('Enviar a Planta Física');

        $this->get(route('actas.devolucion.pdf', $devolucion))->assertOk()->assertHeader('content-type', 'application/pdf');

        // Después vuelve el control: el acta queda devuelta del todo.
        $this->post(route('actas.devolver.guardar', $acta), [
            'fecha' => now(config('fabos.lab.timezone'))->toDateString(),
            'firma' => $this->firma(),
            'items' => [
                // El robot ya volvió: marcarlo otra vez no lo devuelve dos veces.
                $robot->id   => ['vuelve' => '1', 'estado' => 'M'],
                $control->id => ['vuelve' => '1', 'estado' => 'B'],
            ],
        ])->assertRedirect();

        $acta->refresh();
        $this->assertSame(2, $acta->devoluciones()->count());
        $this->assertSame('Devuelta', $acta->resumenDeDevolucion());
        $this->assertFalse($acta->admiteDevolucion());
        $this->assertSame('R', $robot->fresh()->estado_devolucion);

        $this->get(route('actas.devolver', $acta))->assertOk()->assertSee('Ya volvió todo');
    }

    public function test_no_se_devuelve_lo_que_no_se_ha_entregado_ni_sin_firma_ni_sin_marcar_nada(): void
    {
        $erick = $this->persona('Erick Hansen Gómez', '80187390', User::ROL_ADMINISTRADOR);
        $ana = $this->persona('Ana Ruiz', '52000111');

        $this->entra($erick);

        // Un borrador no tiene pantalla de devolución.
        $borrador = $this->acta($erick, $ana);
        $this->get(route('actas.devolver', $borrador))->assertNotFound();

        try {
            $this->servicio()->registrarDevolucion($borrador, $erick, [$borrador->items()->first()->id => ['estado' => 'B']], now(), null, $this->firma());
            $this->fail('no debía dejar');
        } catch (ActaException $e) {
            $this->assertStringContainsString('no está firmada', $e->getMessage());
        }

        $acta = $this->actaFirmada($erick, $ana);
        $item = $acta->items()->reorder('id')->first();
        $hoy = now(config('fabos.lab.timezone'))->toDateString();
        $desde = route('actas.devolver', $acta);

        // Sin firma.
        $this->from($desde)->post(route('actas.devolver.guardar', $acta), [
            'fecha' => $hoy, 'items' => [$item->id => ['vuelve' => '1', 'estado' => 'B']],
        ])->assertRedirect($desde)->assertSessionHasErrors('firma');

        // Sin marcar nada.
        $this->from($desde)->post(route('actas.devolver.guardar', $acta), [
            'fecha' => $hoy, 'firma' => $this->firma(), 'items' => [$item->id => ['estado' => 'B']],
        ])->assertRedirect($desde)->assertSessionHasErrors('items');

        // Con una fecha que todavía no llega.
        $this->from($desde)->post(route('actas.devolver.guardar', $acta), [
            'fecha' => now()->addDays(3)->toDateString(), 'firma' => $this->firma(),
            'items' => [$item->id => ['vuelve' => '1', 'estado' => 'B']],
        ])->assertRedirect($desde)->assertSessionHasErrors('fecha');

        $this->assertSame(0, $acta->devoluciones()->count());

        // Y nadie de fuera del equipo.
        $this->entra($ana);
        $this->get($desde)->assertForbidden();
        $this->post(route('actas.devolver.guardar', $acta), [
            'fecha' => $hoy, 'firma' => $this->firma(), 'items' => [$item->id => ['vuelve' => '1', 'estado' => 'B']],
        ])->assertForbidden();
    }

    public function test_la_devolucion_se_le_avisa_a_planta_fisica_solo_si_habia_salido_de_la_universidad(): void
    {
        $erick = $this->persona('Erick Hansen Gómez', '80187390', User::ROL_ADMINISTRADOR);
        $ana = $this->persona('Ana Ruiz', '52000111');

        $devolver = function (Acta $acta) use ($erick) {
            return $this->servicio()->registrarDevolucion(
                $acta, $erick,
                $acta->items->mapWithKeys(fn ($i) => [$i->id => ['estado' => 'B']])->all(),
                now(), null, $this->firma(),
            );
        };

        $this->entra($erick);

        // Salió de la Universidad: se puede avisar, con el formato adjunto.
        $fuera = $devolver($this->actaFirmada($erick, $ana));

        $this->from(route('actas.devolucion.ver', $fuera))
            ->post(route('actas.devolucion.enviar', $fuera))
            ->assertRedirect(route('actas.devolucion.ver', $fuera))
            ->assertSessionHasNoErrors();

        $fuera->refresh();
        $this->assertNotNull($fuera->enviada_a_planta_at);
        $this->assertSame(ConfiguracionDeActas::valor('correo_planta'), $fuera->enviada_a_planta_a);

        $aviso = NotificationLog::where('key', 'acta.devolucion_planta')->reorder()->latest('id')->first();
        $this->assertNotNull($aviso);
        $this->assertStringContainsString($fuera->code, $aviso->subject . $aviso->body);
        $this->assertStringContainsString('regresó todo', $aviso->body);

        // Dentro de la Universidad: Planta Física no la conoce.
        $interno = collect(array_keys(Acta::TIPOS))->first(fn ($t) => ! in_array($t, Acta::FUERA_DE_LA_U, true));
        $dentro = $devolver($this->actaFirmada($erick, $ana, $interno));

        $this->get(route('actas.devolucion.ver', $dentro))
            ->assertOk()
            ->assertSee('no se envía a Planta Física')
            ->assertDontSee('Enviar a Planta Física');

        $this->from(route('actas.devolucion.ver', $dentro))
            ->post(route('actas.devolucion.enviar', $dentro))
            ->assertSessionHasErrors('envio');

        $this->assertNull($dentro->fresh()->enviada_a_planta_at);
    }

    public function test_la_lista_ofrece_registrar_la_devolucion_solo_cuando_hay_algo_fuera(): void
    {
        $erick = $this->persona('Erick Hansen Gómez', '80187390', User::ROL_ADMINISTRADOR);
        $ana = $this->persona('Ana Ruiz', '52000111');

        $borrador = $this->acta($erick, $ana);
        $firmada = $this->actaFirmada($erick, $ana);

        $this->entra($erick);

        Livewire::test(ListActas::class)
            ->assertActionHidden(TestAction::make('devolucion')->table($borrador))
            ->assertActionVisible(TestAction::make('devolucion')->table($firmada))
            ->assertSee('Sin devolver');

        $this->servicio()->registrarDevolucion(
            $firmada, $erick,
            $firmada->items->mapWithKeys(fn ($i) => [$i->id => ['estado' => 'B']])->all(),
            now(), null, $this->firma(),
        );

        Livewire::test(ListActas::class)
            ->assertActionHidden(TestAction::make('devolucion')->table($firmada))
            ->assertSee('Devuelta');
    }

    public function test_quien_recibe_se_puede_crear_desde_el_acta_con_su_cedula(): void
    {
        $erick = $this->persona('Erick Hansen Gómez', '80187390', User::ROL_ADMINISTRADOR);
        $categoria = \App\Models\UserCategory::create(['slug' => 'profesor', 'name' => 'Profesor', 'can_reserve' => true, 'rate_factor' => 0.5]);
        $this->entra($erick);

        $nueva = [
            'name' => 'Laura Méndez', 'email' => 'Laura.Mendez@universidadean.edu.co',
            'user_category_id' => $categoria->id, 'document_number' => '1015999888',
        ];

        Livewire::test(CreateActa::class)
            ->callAction(TestAction::make('createOption')->schemaComponent('recibe_user_id', schema: 'form'), $nueva)
            ->assertHasNoFormErrors()
            ->assertSet('data.recibe_nombre', 'Laura Méndez')
            ->assertSet('data.recibe_cedula', '1015999888');

        $laura = User::where('email', 'laura.mendez@universidadean.edu.co')->sole();
        $this->assertSame('1015999888', $laura->document_number);
        $this->assertSame($categoria->id, $laura->user_category_id);

        // Con un correo que ya tiene cuenta se usa esa, y una cédula ajena no pasa.
        Livewire::test(CreateActa::class)
            ->callAction(TestAction::make('createOption')->schemaComponent('recibe_user_id', schema: 'form'),
                ['name' => 'Otra', 'email' => 'otra@test.co', 'user_category_id' => $categoria->id, 'document_number' => '80187390'])
            ->assertHasActionErrors(['document_number' => 'unique']);

        $this->assertSame(1, User::where('name', 'Laura Méndez')->count());
        $this->assertFalse(User::where('email', 'otra@test.co')->exists());
    }
}
