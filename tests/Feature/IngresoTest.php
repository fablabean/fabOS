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

        $respuesta->assertRedirect('/ingresar');
        $respuesta->assertSessionHasErrors('email');

        $this->assertStringContainsString(
            'No pudimos enviar',
            session('errors')->first('email'),
        );
    }

    /**
     * El mensaje de error no puede delatar si esa dirección tiene cuenta: si
     * lo hiciera, esta pantalla serviría para averiguar quién está registrado.
     */
    public function test_el_mensaje_de_fallo_no_revela_si_la_direccion_existe(): void
    {
        Mail::shouldReceive('to')->andThrow(new TransportException('lo que sea'));

        // Una direccion existe y la otra no: el mensaje debe ser identico.
        User::factory()->create(['email' => 'existe@ejemplo.edu.co']);

        // Se afirma el texto exacto en ambos casos, que es mas fuerte que
        // compararlos entre si: si alguien cambiara el mensaje para una rama,
        // esto falla aunque las dos ramas siguieran coincidiendo.
        $esperado = 'No pudimos enviar el codigo en este momento. Vuelve a intentarlo '
            . 'en unos minutos; si sigue igual, avisa a la coordinacion del laboratorio.';

        foreach (['existe@ejemplo.edu.co', 'noexiste@ejemplo.edu.co'] as $correo) {
            $this->from('/ingresar')
                ->post('/ingresar', ['email' => $correo])
                ->assertSessionHasErrors(['email' => $esperado]);

            $this->flushSession();
        }
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
