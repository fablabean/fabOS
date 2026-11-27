<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserCategory;
use App\Services\Auth\TwoFactorService;
use App\Services\Qr\QrRenderer;
use App\Support\FactoresDeSesion;
use App\Support\Galeria3d;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * El código QR de cada figura y su experiencia de realidad aumentada.
 *
 * Lo que se defiende: que cada figura tenga su propio QR, que ese QR lleve a
 * su página de realidad aumentada con una dirección que sirva para imprimir
 * (la pública si está configurada), que esa página tenga su botón y sus
 * archivos, y que la hoja para imprimir sea del equipo y avise cuando los QR
 * todavía son de prueba.
 */
class Galeria3dQrTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Galeria3d::olvidar();
    }

    private function usuario(string $rol): User
    {
        foreach (User::ROLES_BACKOFFICE as $r) {
            Role::findOrCreate($r, 'web');
        }
        app(\App\Services\Auth\MatrizDeAccesos::class)->sincronizar();
        $cat = UserCategory::create(['slug' => 'c-' . uniqid(), 'name' => 'Estudiante', 'can_reserve' => true]);
        $u = User::create(['name' => 'Equipo', 'email' => uniqid() . '@test.co', 'status' => 'activo', 'user_category_id' => $cat->id]);
        $u->assignRole($rol);
        if ($u->segundoFactorObligatorio()) {
            $servicio = app(TwoFactorService::class);
            $secreto = $servicio->generarSecreto($u);
            $servicio->confirmar($u, app(Google2FA::class)->getCurrentOtp($secreto));
        }
        $this->actingAs($u->fresh())->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]]);

        return $u;
    }

    public function test_cada_figura_tiene_su_qr_y_lleva_a_su_realidad_aumentada(): void
    {
        config(['galeria3d.url_publica' => 'https://fablab.ejemplo.edu.co/']);
        $qr = app(QrRenderer::class);
        $vistos = [];

        foreach (Galeria3d::modelos()->keys() as $slug) {
            $esperado = "https://fablab.ejemplo.edu.co/galeria-3d/{$slug}/ra";
            $this->assertSame($esperado, Galeria3d::urlRA($slug));
            $svg = $this->get(route('galeria.qr', $slug))->assertOk()->getContent();
            $this->assertSame($qr->svg($esperado, 240, '#111111'), $svg, "El QR de {$slug} no lleva a su página de realidad aumentada");
            $vistos[] = $svg;
        }
        $this->assertSame(count($vistos), count(array_unique($vistos)), 'Dos figuras comparten QR');
        $this->assertFalse(Galeria3d::qrTemporal());
    }

    public function test_sin_direccion_publica_se_usa_la_de_la_pagina_y_se_marca_de_prueba(): void
    {
        config(['galeria3d.url_publica' => null, 'app.url' => 'http://localhost']);

        // Sin dirección pública ni APP_URL https, la de la página (aquí, la de las pruebas).
        $this->get('/galeria-3d/pez/qr.svg')->assertOk();
        $this->assertSame(request()->getSchemeAndHttpHost() . '/galeria-3d/pez/ra', Galeria3d::urlRA('pez'));
        $this->assertTrue(Galeria3d::qrTemporal());

        config(['app.url' => 'https://fablab.ejemplo.edu.co']);
        $this->assertSame('https://fablab.ejemplo.edu.co/galeria-3d/pez/ra', Galeria3d::urlRA('pez'));
        $this->assertFalse(Galeria3d::qrTemporal());
    }

    public function test_en_localhost_con_el_tunel_encendido_el_qr_usa_su_enlace(): void
    {
        config(['galeria3d.url_publica' => null, 'app.url' => 'http://localhost', 'galeria3d.tunel_celular' => 'http://tunel-celular:2000']);
        \Illuminate\Support\Facades\Http::fake([
            'tunel-celular:2000/quicktunnel' => \Illuminate\Support\Facades\Http::response(['hostname' => 'abc-def.trycloudflare.com']),
            'tunel-celular:2000/ready' => \Illuminate\Support\Facades\Http::response(['status' => 200, 'readyConnections' => 4]),
        ]);

        $r = $this->get('http://localhost/galeria-3d/pez')->assertOk();
        $this->assertSame('https://abc-def.trycloudflare.com/galeria-3d/pez/ra', Galeria3d::urlRA('pez'));
        $this->assertTrue(Galeria3d::qrAlcanzable());
        $r->assertSee('"qrAlcanzable":true', false);

        $svg = $this->get('http://localhost/galeria-3d/pez/qr.svg')->getContent();
        $this->assertSame(app(QrRenderer::class)->svg('https://abc-def.trycloudflare.com/galeria-3d/pez/ra', 240, '#111111'), $svg);
    }

    public function test_en_localhost_sin_tunel_se_explica_como_encenderlo(): void
    {
        config(['galeria3d.url_publica' => null, 'app.url' => 'http://localhost', 'galeria3d.tunel_celular' => 'http://tunel-celular:2000']);
        \Illuminate\Support\Facades\Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('apagado'));

        $this->get('http://localhost/galeria-3d/pez')
            ->assertOk()
            ->assertSee('"qrAlcanzable":false', false);
        $this->assertFalse(Galeria3d::qrAlcanzable());
    }

    /**
     * Un túnel que tiene enlace pero no está conectado a Cloudflare (la red
     * bloquea el puerto 7844, o se cayó hace rato) no se usa: ese QR llevaba
     * a una dirección que no existe y el celular daba error.
     */
    public function test_un_tunel_sin_conexion_no_da_un_qr_que_no_abre(): void
    {
        config(['galeria3d.url_publica' => null, 'app.url' => 'http://localhost', 'galeria3d.tunel_celular' => 'http://tunel-celular:2000', 'galeria3d.tunel_celular_propio' => '']);
        \Illuminate\Support\Facades\Http::fake([
            'tunel-celular:2000/quicktunnel' => \Illuminate\Support\Facades\Http::response(['hostname' => 'face-dvd-define-kirk.trycloudflare.com']),
            'tunel-celular:2000/ready' => \Illuminate\Support\Facades\Http::response(['status' => 503, 'readyConnections' => 0], 503),
        ]);

        $this->get('http://localhost/galeria-3d/pez')->assertOk()->assertSee('"qrAlcanzable":false', false);
        $this->assertNull(\App\Support\TunelCelular::enlace(true));
        $this->assertStringContainsString('no está conectado a Cloudflare', \App\Support\TunelCelular::explicacion());
    }

    /** Conectado, pero su dirección no existe en internet: tampoco se usa. */
    public function test_un_enlace_que_no_esta_en_el_dns_no_se_usa(): void
    {
        config(['galeria3d.tunel_comprobar_dns' => true, 'galeria3d.tunel_celular' => 'http://tunel-celular:2000', 'galeria3d.tunel_celular_propio' => '']);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['hostname' => 'no-existe-' . uniqid() . '.trycloudflare.com'])]);

        $this->assertNull(\App\Support\TunelCelular::enlace(true));
        $this->assertStringContainsString('todavía no existe en internet', \App\Support\TunelCelular::explicacion());
    }

    /**
     * Android sin ARCore (muchos de gama baja y media, como el Honor X8a): la
     * página trae el modo cámara, la figura sobre la imagen de la cámara.
     */
    public function test_la_realidad_aumentada_trae_el_modo_camara_para_android_sin_arcore(): void
    {
        $this->get(route('galeria.ra', 'cubo'))
            ->assertOk()
            ->assertSee('id="ra-cam"', false)
            ->assertSee('id="ra-cam-video"', false)
            ->assertSee('id="ra-android-camara"', false)
            ->assertSee('Verla con la cámara');
        $js = file_get_contents(public_path('3d/ra.js'));
        $this->assertStringContainsString('class ModoCamara', $js);
        $this->assertStringContainsString("facingMode: { ideal: 'environment' }", $js);
        $this->assertStringContainsString('#camara', file_get_contents(public_path('3d/visor.js')));
    }

    /**
     * iPhone dentro de otra app (Google, WhatsApp, Instagram): sin Vista
     * rápida, la página ofrece el modo cámara y un enlace para abrir en Safari.
     */
    public function test_la_realidad_aumentada_atiende_al_iphone_dentro_de_otras_apps(): void
    {
        $this->get(route('galeria.ra', 'pez'))
            ->assertOk()
            ->assertSee('id="ra-safari-aviso"', false)
            ->assertSee('id="ra-cam-centrar"', false);
        $js = file_get_contents(public_path('3d/ra.js'));
        $this->assertStringContainsString('AR.iosSinVistaRapida', $js);
        $this->assertStringContainsString('x-safari-https://', $js);
        $this->assertStringContainsString('DeviceOrientationEvent', $js);
    }

    /** Estable en Android con ARCore: anclas, retícula suavizada y aviso sin seguimiento. */
    public function test_la_realidad_aumentada_usa_anclas_de_arcore(): void
    {
        $js = file_get_contents(public_path('3d/ra.js'));
        $this->assertStringContainsString("optionalFeatures: ['dom-overlay', 'anchors']", $js);
        $this->assertStringContainsString('createAnchor()', $js);
        $this->assertStringContainsString('emulatedPosition', $js);
    }

    /** Carga rápida: three.js minificado y todo pedido a la vez desde el principio. */
    public function test_la_pagina_de_realidad_aumentada_pide_todo_a_la_vez(): void
    {
        $r = $this->get(route('galeria.ra', 'cubo'))->assertOk()
            ->assertSee('three.module.min.js', false)
            ->assertSee('<link rel="modulepreload" href="' . asset('3d/vendor/three-r186') . '/three.core.min.js">', false)
            ->assertSee('as="fetch" crossorigin', false);
        $this->assertFileExists(public_path('3d/vendor/three-r186/three.core.min.js'));
        $this->assertStringContainsString("from\"./three.core.min.js\"", file_get_contents(public_path('3d/vendor/three-r186/three.module.min.js')));
        $this->get(route('galeria.index'))->assertSee('three.module.min.js', false);
    }

    /**
     * Aviso de compatibilidad: la página revisa el celular antes de abrir la
     * realidad aumentada y, si no puede, explica por qué y qué se necesita.
     */
    public function test_la_realidad_aumentada_trae_el_aviso_de_compatibilidad(): void
    {
        $this->get(route('galeria.ra', 'pez'))
            ->assertOk()
            ->assertSee('id="ra-compat"', false)
            ->assertSee('id="ra-compat-abrir"', false)
            ->assertSee('¿Mi celular es compatible?')
            // los requisitos mínimos, en palabras sencillas
            ->assertSee('iOS 12 o más reciente')
            ->assertSee('Android 7.0 o más reciente')
            ->assertSee('Chrome 81 o más reciente')
            ->assertSee('ARCore')
            ->assertSee('Cámara trasera; giroscopio y acelerómetro')
            ->assertSee('Permitir el uso de la cámara');
        $js = file_get_contents(public_path('3d/ra.js'));
        foreach (['function revisarCompatibilidad', 'function mostrarCompatibilidad', "name: 'camera'", 'enumerateDevices', "'Falta un permiso'", "'No compatible'"] as $pieza) {
            $this->assertStringContainsString($pieza, $js);
        }
    }

    public function test_un_enlace_raro_del_tunel_no_se_usa(): void
    {
        config(['galeria3d.url_publica' => null, 'app.url' => 'http://localhost', 'galeria3d.tunel_celular' => 'http://tunel-celular:2000']);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['hostname' => 'sitio-malo.example.com'])]);

        $this->get('http://localhost/galeria-3d/pez')->assertOk();
        $this->assertNull(Galeria3d::enlaceDelTunel());
    }

    public function test_el_boton_del_tunel_solo_responde_en_desarrollo_y_desde_este_computador(): void
    {
        // (Lo que se prueba aquí es el permiso, no el token del formulario.)
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);

        // En pruebas (no es «local»): nada.
        $this->getJson('http://localhost/galeria-3d/tunel-celular')->assertForbidden();
        $this->postJson('http://localhost/galeria-3d/tunel-celular')->assertForbidden();

        $this->app['env'] = 'local';
        config(['galeria3d.tunel_celular' => 'http://tunel-celular:2000']);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['hostname' => 'ya-encendido.trycloudflare.com'])]);

        $this->getJson('http://localhost/galeria-3d/tunel-celular')
            ->assertOk()
            ->assertJson(['enlace' => 'https://ya-encendido.trycloudflare.com']);
        // Por el propio túnel, nunca.
        $this->call('POST', 'https://ya-encendido.trycloudflare.com/galeria-3d/tunel-celular', [], [], [], ['HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_ACCEPT' => 'application/json'])
            ->assertForbidden();
        // Ya encendido: el POST no arranca otro, devuelve el enlace.
        $this->postJson('http://localhost/galeria-3d/tunel-celular')
            ->assertOk()
            ->assertJson(['enlace' => 'https://ya-encendido.trycloudflare.com']);
    }

    public function test_en_desarrollo_la_ficha_trae_el_boton_para_encenderlo(): void
    {
        $this->app['env'] = 'local';
        config(['galeria3d.url_publica' => null, 'app.url' => 'http://localhost', 'galeria3d.tunel_celular' => '', 'galeria3d.tunel_celular_propio' => '']);

        $this->get('http://localhost/galeria-3d/pez')
            ->assertOk()
            ->assertSee('id="qr-encender"', false)
            ->assertSee('Encender el QR para el celular')
            ->assertSee('"qrAlcanzable":false', false);
    }

    public function test_el_qr_se_puede_descargar(): void
    {
        $this->get(route('galeria.qr', ['modelo' => 'pez', 'descargar' => 1]))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="qr-pez.svg"');
    }

    public function test_la_pagina_de_realidad_aumentada_de_cada_figura(): void
    {
        foreach (Galeria3d::modelos() as $slug => $m) {
            $this->get(route('galeria.ra', $slug))
                ->assertOk()
                ->assertSee($m['nombre'])
                ->assertSee('id="ra-boton"', false)
                ->assertSee('Verlo en tu espacio')
                ->assertSee(json_encode($m['urls']['usdz']), false)
                ->assertSee(json_encode($m['urls']['glb_ar']), false)
                ->assertSee('3d/ra.js', false);
        }
        $this->get('/galeria-3d/no-existe/ra')->assertNotFound();
        $this->assertFileExists(public_path('3d/ra.js'));
    }

    public function test_por_el_tunel_temporal_tambien_se_abre(): void
    {
        $this->call('GET', 'https://ensayo.trycloudflare.com/galeria-3d/pez/ra', [], [], [], ['HTTP_X_FORWARDED_PROTO' => 'https'])
            ->assertOk()
            ->assertSee('Verlo en tu espacio');
    }

    /**
     * Si la realidad aumentada no inicia (permiso de la cámara negado, o en
     * iPhone sin «Movimiento y orientación»), aparece una advertencia que
     * dice qué permiso activar y cómo, en iPhone y en Android.
     */
    public function test_si_la_ra_no_inicia_advierte_que_hay_que_activar_los_permisos(): void
    {
        $html = $this->get(route('galeria.ra', 'pez'))->assertOk()->getContent();
        foreach (['id="ra-compat-permisos"', 'role="alert"', 'Habilita los permisos del celular', 'id="ra-compat-permisos-pasos"', 'id="ra-cam-permiso"'] as $pieza) {
            $this->assertStringContainsString($pieza, $html, $pieza);
        }
        // el mapa de importación va antes de cualquier precarga de módulos
        $this->assertLessThan(strpos($html, 'rel="modulepreload"'), strpos($html, 'type="importmap"'));

        $js = file_get_contents(public_path('3d/ra.js'));
        foreach ([
            'function mostrarAvisoPermisos', 'function pasosPermisos', 'function marcarPermisoNegado',
            'Configuración del sitio web › Cámara › Permitir',          // iPhone
            'Permisos › Cámara › Permitir',                             // Android
            'Acceso a movimiento y orientación',                        // giroscopio en iPhone
            'vigilarPermisoGiro',
            // WebXR: permiso negado → advertencia; toque vencido → se sigue a Scene Viewer
            '/activation|gesture/i',
        ] as $pieza) {
            $this->assertStringContainsString($pieza, $js, $pieza);
        }
    }

    /**
     * Brave se presenta como Chrome: se reconoce por navigator.brave, no se
     * toma su WebXR bloqueado como «sin ARCore» y se le ofrece abrir en Chrome.
     */
    public function test_la_ra_reconoce_brave(): void
    {
        foreach (['ra.js', 'visor.js'] as $archivo) {
            $js = file_get_contents(public_path('3d/' . $archivo));
            $this->assertStringContainsString('const esBrave = !!navigator.brave', $js, $archivo);
            $this->assertStringContainsString('AR.android && !esBrave &&', $js, $archivo);
            $this->assertStringContainsString('(AR.xrContesto && !esBrave)', $js, $archivo);
        }
        $ra = file_get_contents(public_path('3d/ra.js'));
        $this->assertStringContainsString("tipo: 'brave'", $ra);
        $this->assertStringContainsString("tipo: 'brave-ios'", $ra);
    }

    /** Un clic en el QR de la ficha lo muestra en grande, en un diálogo que se cierra con Esc o «Cerrar». */
    public function test_el_qr_de_la_ficha_se_amplia_al_hacer_clic(): void
    {
        $html = $this->get(route('galeria.show', 'pez'))->assertOk()->getContent();
        foreach (['id="qr-ampliar"', 'aria-controls="qr-grande"', '<dialog class="qr-grande" id="qr-grande"', 'id="qr-grande-imagen"', 'id="qr-grande-cerrar"'] as $pieza) {
            $this->assertStringContainsString($pieza, $html, $pieza);
        }
        $js = file_get_contents(public_path('3d/visor.js'));
        foreach (['function abrirQrGrande', 'showModal', "\$('qr-ampliar').hidden"] as $pieza) {
            $this->assertStringContainsString(stripslashes($pieza), $js, $pieza);
        }
    }

    public function test_la_hoja_para_imprimir_trae_una_tarjeta_por_figura(): void
    {
        config(['galeria3d.url_publica' => 'https://fablab.ejemplo.edu.co']);
        $this->usuario(User::ROL_ADMINISTRADOR);

        $r = $this->get(route('galeria.panel.qr'))->assertOk();
        foreach (Galeria3d::modelos() as $slug => $m) {
            $r->assertSee($m['nombre'])->assertSee("https://fablab.ejemplo.edu.co/galeria-3d/{$slug}/ra");
        }
        $this->assertSame(Galeria3d::modelos()->count(), substr_count($r->getContent(), '<article class="tarjeta">'));
        $r->assertDontSee('Estos QR son de prueba');
    }

    public function test_la_hoja_avisa_si_los_qr_son_de_prueba(): void
    {
        config(['galeria3d.url_publica' => null, 'app.url' => 'http://localhost']);
        $this->usuario(User::ROL_ADMINISTRADOR);

        $this->get(route('galeria.panel.qr'))->assertOk()->assertSee('Estos QR son de prueba')->assertSee('GALERIA3D_URL_PUBLICA');
    }

    public function test_el_listado_del_panel_ofrece_imprimir_los_qr(): void
    {
        $this->usuario(User::ROL_ADMINISTRADOR);

        $this->get(\App\Filament\Resources\Galeria3d\Galeria3dResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Imprimir códigos QR')
            ->assertSee(route('galeria.panel.qr'), false);
    }

    public function test_la_hoja_es_solo_del_equipo_con_la_seccion(): void
    {
        $this->get(route('galeria.panel.qr'))->assertRedirect();

        $this->usuario(User::ROL_COMUNICACIONES);
        $this->get(route('galeria.panel.qr'))->assertForbidden();
    }
}
