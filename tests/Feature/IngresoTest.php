<?php

namespace Tests\Feature;

use App\Models\LoginCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * El ingreso cuando el correo falla (§5).
 *
 * fabOS no tiene contraseñas: el código que llega al correo *es* la
 * autenticación. Por eso un fallo del proveedor no es un detalle de
 * infraestructura — es la puerta cerrada, y tiene que decirlo con claridad.
 *
 * Esta prueba nace de un fallo real: con la cuenta del proveedor pendiente de
 * aprobación, pedir un código devolvía «Server Error» en producción.
 */
class IngresoTest extends TestCase
{
    use RefreshDatabase;

    public function test_si_el_proveedor_rechaza_el_envio_no_sale_un_error_500(): void
    {
        Mail::shouldReceive('to')->andThrow(new TransportException('cuenta pendiente de aprobación'));

        $respuesta = $this->from('/ingresar')->post('/ingresar', [
            'email' => 'quien@ejemplo.edu.co',
        ]);

        // No es un callejon: puede que le hayan entregado un codigo en mano en
        // el laboratorio, que es justo la salida cuando el correo no funciona.
        $respuesta->assertRedirect(route('login.code', ['email' => 'quien@ejemplo.edu.co']));

        $this->assertStringContainsString(
            'No pudimos enviarte el correo',
            (string) session('status'),
        );
    }

    /**
     * Ni el destino ni el mensaje pueden delatar si esa direccion tiene cuenta:
     * si lo hicieran, esta pantalla serviria para averiguar quien esta
     * registrado probando correos.
     */
    public function test_el_fallo_no_revela_si_la_direccion_existe(): void
    {
        Mail::shouldReceive('to')->andThrow(new TransportException('lo que sea'));

        User::factory()->create(['email' => 'existe@ejemplo.edu.co', 'status' => 'activo']);

        $vistos = [];

        foreach (['existe@ejemplo.edu.co', 'noexiste@ejemplo.edu.co'] as $correo) {
            $respuesta = $this->from('/ingresar')->post('/ingresar', ['email' => $correo]);

            $vistos[] = [
                str_replace(rawurlencode($correo), 'CORREO', (string) $respuesta->headers->get('Location')),
                (string) session('status'),
            ];

            $this->flushSession();
        }

        $this->assertSame($vistos[0], $vistos[1]);
    }

    public function test_cuando_el_correo_sale_el_codigo_queda_guardado(): void
    {
        Mail::fake();

        $this->post('/ingresar', ['email' => 'quien@ejemplo.edu.co'])
            ->assertRedirect();

        $this->assertDatabaseHas('login_codes', ['email' => 'quien@ejemplo.edu.co']);
        $this->assertNotNull(LoginCode::first()->code_hash);
    }
}
