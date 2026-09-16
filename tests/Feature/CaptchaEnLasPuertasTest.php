<?php

namespace Tests\Feature;

use App\Services\Auth\Turnstile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * El captcha delante de lo que manda correo (§5).
 *
 * El límite por correo y por IP frena a una persona insistiendo. No frena a un
 * bot con mil direcciones pidiendo cada una su primer código: ninguna pasa del
 * tope, y el laboratorio acaba mandando miles de correos que nadie pidió,
 * pagándolos y quemando su reputación de envío.
 *
 * Lo que se cuida aquí son las dos cosas que se rompen en silencio: que ninguna
 * puerta pública se quede sin captcha, y que el captcha no sea capaz de dejar
 * al laboratorio sin poder entrar.
 */
class CaptchaEnLasPuertasTest extends TestCase
{
    use RefreshDatabase;

    private function conClaves(): void
    {
        config([
            'fabos.turnstile.site_key' => '1x00000000000000000000AA',
            'fabos.turnstile.secret_key' => '1x0000000000000000000000000000000AA',
        ]);
    }

    // ---------------------------------------------------------------
    // Que no estorbe cuando no está configurado
    // ---------------------------------------------------------------

    /**
     * Sin claves, deja pasar y no pinta nada.
     *
     * Es la salvaguarda del despliegue: una variable de entorno que falte no
     * puede dejar al laboratorio sin poder entrar, ni obligar a tener cuenta de
     * Cloudflare para levantar una instalación nueva.
     */
    public function test_sin_claves_no_estorba(): void
    {
        config(['fabos.turnstile.site_key' => null, 'fabos.turnstile.secret_key' => null]);

        $turnstile = app(Turnstile::class);

        $this->assertFalse($turnstile->estaActivo());
        $this->assertTrue($turnstile->verificar(null), 'Sin claves tiene que dejar pasar.');

        // Y el formulario no enseña el widget ni carga el script de Cloudflare.
        $html = $this->get(route('login'))->assertOk()->getContent();

        $this->assertStringNotContainsString('cf-turnstile', $html);
        $this->assertStringNotContainsString('challenges.cloudflare.com', $html);
    }

    public function test_con_claves_el_formulario_de_ingreso_pinta_el_widget(): void
    {
        $this->conClaves();

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('cf-turnstile')
            ->assertSee('challenges.cloudflare.com/turnstile/v0/api.js');
    }

    /**
     * La página del código lleva DOS formularios —entrar y reenviar— y por
     * tanto dos widgets, pero el script se carga una sola vez.
     */
    public function test_la_pagina_del_codigo_lleva_dos_widgets_y_un_solo_script(): void
    {
        $this->conClaves();

        $html = $this->get(route('login.code', ['email' => 'quien@ejemplo.co']))
            ->assertOk()
            ->getContent();

        $this->assertSame(2, substr_count($html, 'class="cf-turnstile"'));
        $this->assertSame(1, substr_count($html, 'turnstile/v0/api.js'));
    }

    // ---------------------------------------------------------------
    // Que de verdad frene
    // ---------------------------------------------------------------

    public function test_sin_token_no_se_manda_el_codigo(): void
    {
        Mail::fake();
        $this->conClaves();

        $this->from(route('login'))
            ->post(route('login.send'), ['email' => 'quien@ejemplo.co'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        Mail::assertNothingOutgoing();
    }

    public function test_con_un_token_que_cloudflare_rechaza_tampoco(): void
    {
        Mail::fake();
        $this->conClaves();

        Http::fake([
            'challenges.cloudflare.com/*' => Http::response([
                'success' => false, 'error-codes' => ['invalid-input-response'],
            ]),
        ]);

        $this->from(route('login'))
            ->post(route('login.send'), [
                'email' => 'quien@ejemplo.co',
                'cf-turnstile-response' => 'lo-que-sea',
            ])
            ->assertSessionHasErrors('email');

        Mail::assertNothingOutgoing();
    }

    public function test_con_un_token_bueno_pasa(): void
    {
        $this->conClaves();

        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        $this->assertTrue(app(Turnstile::class)->verificar('un-token', '10.0.0.1'));

        // Y se le manda a Cloudflare lo que hace falta para comprobarlo.
        Http::assertSent(fn ($peticion) => $peticion['response'] === 'un-token'
            && $peticion['remoteip'] === '10.0.0.1'
            && $peticion['secret'] === config('fabos.turnstile.secret_key'));
    }

    /**
     * Si Cloudflare no contesta, se deja pasar.
     *
     * Va contra el instinto y es deliberado: un captcha que falla cerrado
     * convierte cualquier corte de red en «nadie puede entrar al laboratorio»,
     * y esta red bloquea cosas de forma habitual. Debajo sigue el límite por
     * correo y por IP, que es el que protege una puerta concreta.
     */
    public function test_si_cloudflare_no_contesta_no_deja_a_nadie_fuera(): void
    {
        $this->conClaves();

        Http::fake(fn () => throw new ConnectionException('sin red'));

        $this->assertTrue(
            app(Turnstile::class)->verificar('un-token'),
            'Un corte de red no puede cerrar la puerta del laboratorio.',
        );
    }

    /** Un 500 de Cloudflare es lo mismo que no poder preguntar. */
    public function test_un_error_de_cloudflare_tampoco_cierra_la_puerta(): void
    {
        $this->conClaves();

        Http::fake(['challenges.cloudflare.com/*' => Http::response('', 500)]);

        $this->assertTrue(app(Turnstile::class)->verificar('un-token'));
    }

    // ---------------------------------------------------------------
    // Que no se quede ninguna puerta fuera
    // ---------------------------------------------------------------

    /**
     * El inventario de puertas protegidas, escrito.
     *
     * Esta es la prueba que importa a largo plazo: una puerta nueva que mande
     * correo y nazca sin captcha no se nota mirando la pantalla —funciona
     * perfectamente— y solo se descubre cuando ya se está usando para mandar
     * correo a desconocidos.
     */
    public function test_todas_las_puertas_publicas_que_mandan_correo_llevan_captcha(): void
    {
        $esperadas = [
            'login.send',              // pide el código: manda correo
            'login.verify',            // prueba códigos: seis dígitos
            'login.code.enviar',       // reenvía: manda correo
            'carnet.login',            // la otra puerta de ingreso
            'proyectos.solicitar.store', // crea cuenta al vuelo y avisa
            'practicas.postular.store',  // manda correo
            'tienda.cotizar',            // crea cuenta al vuelo y avisa
        ];

        foreach ($esperadas as $nombre) {
            $ruta = Route::getRoutes()->getByName($nombre);

            $this->assertNotNull($ruta, "No existe la ruta «{$nombre}».");

            $tiene = collect($ruta->gatherMiddleware())
                ->contains(fn (string $m) => str_starts_with($m, 'captcha'));

            $this->assertTrue($tiene, "La ruta «{$nombre}» se quedó sin captcha.");
        }
    }

    // ---------------------------------------------------------------
    // Que el token no sirva en otra puerta
    // ---------------------------------------------------------------

    /**
     * Un token de una pantalla no vale en otra.
     *
     * Sin esto, el captcha se resuelve UNA vez en el formulario de ingreso y
     * ese token sirve para mandar mil postulaciones a prácticas: las siete
     * puertas quedarían protegidas por un solo captcha. Cloudflare graba la
     * acción dentro del token al emitirlo, así que no se puede cambiar después.
     */
    public function test_un_token_de_otra_pantalla_no_vale(): void
    {
        $this->conClaves();

        Http::fake(['challenges.cloudflare.com/*' => Http::response([
            'success' => true,
            'action' => 'login_send',
        ])]);

        $turnstile = app(Turnstile::class);

        $this->assertTrue($turnstile->verificar('un-token', null, 'login_send'));
        $this->assertFalse(
            $turnstile->verificar('un-token', null, 'practicas_postular_store'),
            'Un token emitido en el ingreso no puede valer para postular.',
        );
    }

    /**
     * Si falta la acción en una de las dos puntas, no se cierra la puerta.
     *
     * Un formulario al que se le olvide el `data-action` emite tokens con la
     * acción vacía. Ahí conviene degradar —seguir funcionando sin esa
     * comprobación— y no dejar a nadie fuera por un atributo que falta en una
     * plantilla. Lo que impide que ese olvido pase desapercibido es la prueba
     * de abajo, no un fallo en la cara de quien intenta entrar.
     */
    public function test_sin_accion_declarada_sigue_funcionando(): void
    {
        $this->conClaves();

        Http::fake(['challenges.cloudflare.com/*' => Http::response([
            'success' => true, 'action' => '',
        ])]);

        $this->assertTrue(app(Turnstile::class)->verificar('un-token', null, 'login_send'));
    }

    /**
     * El token tiene que venir de nuestro dominio.
     *
     * Cloudflare ya limita el widget a los dominios declarados; esto es la
     * segunda vuelta, por si en la consola se añade uno más y nadie se entera.
     */
    public function test_un_token_de_otro_dominio_no_vale(): void
    {
        $this->conClaves();
        config(['app.url' => 'https://fablabean.com']);

        Http::fake(['challenges.cloudflare.com/*' => Http::response([
            'success' => true, 'hostname' => 'sitio-de-otro.com',
        ])]);

        $this->assertFalse(app(Turnstile::class)->verificar('un-token'));
    }

    public function test_un_token_de_nuestro_dominio_si_vale(): void
    {
        $this->conClaves();
        config(['app.url' => 'https://fablabean.com']);

        Http::fake(['challenges.cloudflare.com/*' => Http::response([
            'success' => true, 'hostname' => 'fablabean.com',
        ])]);

        $this->assertTrue(app(Turnstile::class)->verificar('un-token'));
    }

    /** Sin APP_URL no se comprueba: mejor no comprobar que cerrar la puerta. */
    public function test_sin_app_url_no_se_comprueba_el_dominio(): void
    {
        $this->conClaves();
        config(['app.url' => '']);

        Http::fake(['challenges.cloudflare.com/*' => Http::response([
            'success' => true, 'hostname' => 'lo-que-sea.com',
        ])]);

        $this->assertTrue(app(Turnstile::class)->verificar('un-token'));
    }

    /**
     * Cada puerta pinta SU acción, y coincide con la ruta a la que envía.
     *
     * Es la prueba que sostiene todo lo anterior: si una plantilla se queda sin
     * `data-action`, o lo tiene mal escrito, el sitio sigue funcionando y esa
     * puerta se queda sin la protección sin que nada lo diga.
     */
    public function test_cada_puerta_declara_su_propia_accion(): void
    {
        $this->conClaves();

        $puertas = [
            ['url' => route('login'), 'acciones' => ['login.send']],
            ['url' => route('login.code', ['email' => 'quien@ejemplo.co']), 'acciones' => ['login.verify', 'login.code.enviar']],
            ['url' => route('proyectos.solicitar'), 'acciones' => ['proyectos.solicitar.store']],
        ];

        foreach ($puertas as $puerta) {
            $html = $this->get($puerta['url'])->assertOk()->getContent();

            foreach ($puerta['acciones'] as $ruta) {
                $esperada = Turnstile::accionDe($ruta);

                $this->assertStringContainsString(
                    'data-action="'.$esperada.'"',
                    $html,
                    "La puerta {$puerta['url']} no declara la acción de «{$ruta}».",
                );
            }
        }
    }

    /** El nombre de la acción cabe en lo que Cloudflare admite. */
    public function test_la_accion_se_limpia_para_cloudflare(): void
    {
        $this->assertSame('login_send', Turnstile::accionDe('login.send'));
        $this->assertSame('proyectos_solicitar_store', Turnstile::accionDe('proyectos.solicitar.store'));
        $this->assertNull(Turnstile::accionDe(null));

        // Turnstile corta en 32 caracteres y solo admite letras, numeros,
        // guion y guion bajo.
        $larga = Turnstile::accionDe(str_repeat('ruta.larga.', 10));
        $this->assertLessThanOrEqual(32, strlen($larga));
        $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]+$/', $larga);
    }

    /**
     * El mensaje se ata al campo que ese formulario tiene de verdad.
     *
     * Un error de validación sobre un campo que no existe en la pantalla no se
     * pinta en ningún sitio: el formulario se recargaría vacío, sin decir nada,
     * y quien lo usa no sabría qué hacer.
     */
    public function test_el_error_se_pinta_en_el_campo_que_ese_formulario_tiene(): void
    {
        $this->conClaves();

        // El de proyectos llama «correo» a su casilla, no «email».
        $this->from(route('proyectos.solicitar'))
            ->post(route('proyectos.solicitar.store'), [
                'nombre' => 'Quien pide', 'correo' => 'quien@ejemplo.co',
                'titulo' => 'Algo', 'resumen' => 'Lo que sea', 'cliente' => 'externo',
            ])
            ->assertSessionHasErrors('correo');
    }
}
