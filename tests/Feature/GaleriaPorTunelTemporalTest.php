<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El botón del celular abre un túnel temporal (*.trycloudflare.com) para probar el QR
 * y la realidad aumentada en un teléfono. Por ahí solo debe verse la Galería
 * 3D: el panel local entra sin segundo factor.
 */
class GaleriaPorTunelTemporalTest extends TestCase
{
    use RefreshDatabase;

    private function porTunel(string $metodo, string $ruta)
    {
        return $this->call($metodo, 'https://ensayo.trycloudflare.com' . $ruta, [], [], [], [
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);
    }

    public function test_la_galeria_se_ve_por_el_tunel_con_enlaces_https_del_tunel(): void
    {
        $r = $this->porTunel('GET', '/galeria-3d/pez')->assertOk();

        $r->assertSee('https:\/\/ensayo.trycloudflare.com\/galeria-3d\/pez\/qr.svg', false);
        $this->porTunel('GET', '/galeria-3d/pez/qr.svg')->assertOk();
    }

    public function test_lo_demas_lleva_a_la_galeria(): void
    {
        foreach (['/', '/admin', '/admin/login', '/mi-cuenta', '/reservas', '/ingresar'] as $ruta) {
            $this->porTunel('GET', $ruta)->assertRedirect('https://ensayo.trycloudflare.com/galeria-3d');
        }
        $this->porTunel('POST', '/ingresar')->assertForbidden();
    }

    public function test_por_la_direccion_normal_no_cambia_nada(): void
    {
        $this->get('/reservas')->assertOk();
        $this->get('/galeria-3d')->assertOk();
    }

    public function test_abierta_como_localhost_la_ficha_explica_como_ver_el_qr(): void
    {
        $this->get('/galeria-3d')->assertSee('id="qr-local"', false)->assertSee('Para verlo en el celular');
    }
}
