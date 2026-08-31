<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Si el sitio se sirve por https, sus enlaces también (§19).
 *
 * Detrás del túnel de Cloudflare la petición llega a nginx en **http**, y
 * Laravel construye sus direcciones desde ahí: la página viaja cifrada pero
 * pide sus hojas de estilo y su javascript en claro. El navegador los bloquea
 * por contenido mixto y la pantalla sale sin estilos —sin un solo error que
 * mirar en el servidor, porque el fallo ocurre en el navegador de quien mira—.
 */
class EnlacesSegurosTest extends TestCase
{
    use RefreshDatabase;

    public function test_con_app_url_https_los_enlaces_salen_en_https(): void
    {
        config(['app.url' => 'https://fablabean.com']);

        // Como en el arranque de la aplicación.
        (new \App\Providers\AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', URL::to('/equipos'));
        $this->assertStringStartsWith('https://', asset('css/app.css'));
    }

    /**
     * Y el javascript de Livewire llega a PHP.
     *
     * Livewire lo sirve desde una **ruta**, no desde un fichero, y esa ruta
     * termina en «.js». El bloque de estáticos de nginx atrapa todo lo que
     * acabe así y lo busca en el disco: no está, devuelve 404, y PHP nunca se
     * entera. El panel carga sin javascript —se ve en negro— y en el registro
     * del servidor no hay nada, porque Laravel no llegó a ver la petición.
     *
     * El orden importa: en nginx gana la primera expresión que encaja, así que
     * la excepción tiene que ir **antes** que el bloque de estáticos.
     */
    public function test_nginx_deja_pasar_el_javascript_de_livewire(): void
    {
        $conf = file_get_contents(base_path('docker/aplicacion/nginx.conf'));

        $livewire = strpos($conf, 'location ~ ^/livewire');
        $estaticos = strpos($conf, 'location ~* \.(?:css|js|');

        $this->assertNotFalse($livewire, 'Falta la excepción para las rutas de Livewire.');
        $this->assertNotFalse($estaticos, 'No encontré el bloque de estáticos.');
        $this->assertLessThan(
            $estaticos,
            $livewire,
            'La excepción de Livewire tiene que ir antes del bloque de estáticos: en nginx '
            . 'gana la primera expresión que encaja.',
        );
    }

    /** En local, donde se sirve por http, no se fuerza nada. */
    public function test_en_local_por_http_no_se_fuerza(): void
    {
        config(['app.url' => 'http://localhost']);

        $this->assertStringStartsWith('http://', URL::to('/equipos'));
    }
}
