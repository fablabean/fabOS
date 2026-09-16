<?php

namespace Tests\Feature;

use App\Models\Supply;
use App\Models\User;
use App\Services\Auth\TwoFactorService;
use App\Services\Ia\GeneradorDeIlustraciones;
use App\Support\FactoresDeSesion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Ilustraciones del catálogo, generadas (§14).
 *
 * Lo que se cuida aquí no es que la imagen salga bonita, sino las dos cosas que
 * convertirían esto en un problema:
 *
 *  · **Que una imagen inventada se presente como foto.** Un cliente de fuera
 *    que ve una gorra generada y recibe otra cosa tiene razón en reclamar.
 *  · **Que tape una foto de verdad.** La foto real siempre gana; si no, el
 *    catálogo iría empeorando solo a medida que alguien genera.
 *
 * Y una tercera, menos visible: que sin clave no estorbe. Una instalación que
 * no use Gemini tiene que seguir funcionando igual.
 */
class IlustracionesGeneradasTest extends TestCase
{
    use RefreshDatabase;

    /** Un PNG de un pixel, que es lo que devolvería Gemini en pequeño. */
    private function pngFalso(): string
    {
        return base64_encode(base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        ));
    }

    private function conClave(): void
    {
        config([
            'fabos.ilustraciones.clave' => 'una-clave',
            'fabos.ilustraciones.modelo' => 'gemini-2.5-flash-image',
            'fabos.ilustraciones.max_por_dia' => 30,
            'fabos.ilustraciones.timeout' => 10,
        ]);
    }

    private function respuestaConImagen(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [[
                'content' => ['parts' => [
                    ['text' => 'Aquí tienes la imagen'],
                    ['inlineData' => ['mimeType' => 'image/png', 'data' => $this->pngFalso()]],
                ]],
            ]],
        ])]);
    }

    // ---------------------------------------------------------------
    // Que no estorbe
    // ---------------------------------------------------------------

    public function test_sin_clave_no_hace_nada(): void
    {
        config(['fabos.ilustraciones.clave' => null]);

        $generador = app(GeneradorDeIlustraciones::class);

        $this->assertFalse($generador->estaActivo());
        $this->assertNull($generador->generar('un llavero'));
    }

    /** Si Google no contesta, se avisa y ya: esto no tumba ninguna pantalla. */
    public function test_si_google_no_contesta_devuelve_nada_sin_reventar(): void
    {
        $this->conClave();
        Http::fake(fn () => throw new ConnectionException('sin red'));

        $this->assertNull(app(GeneradorDeIlustraciones::class)->generar('un llavero'));
    }

    /** Una respuesta sin imagen tampoco revienta nada. */
    public function test_una_respuesta_sin_imagen_no_revienta(): void
    {
        $this->conClave();
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [['text' => 'No puedo generar eso']]]]],
        ])]);

        $this->assertNull(app(GeneradorDeIlustraciones::class)->generar('un llavero'));
    }

    // ---------------------------------------------------------------
    // Que funcione
    // ---------------------------------------------------------------

    public function test_genera_y_guarda_la_ilustracion(): void
    {
        Storage::fake('public');
        $this->conClave();
        $this->respuestaConImagen();

        $ruta = app(GeneradorDeIlustraciones::class)->generar('Un llavero metálico grabado');

        $this->assertNotNull($ruta);
        $this->assertStringStartsWith('ilustraciones/', $ruta);
        Storage::disk('public')->assertExists($ruta);
    }

    /**
     * Se le prohíbe expresamente inventar texto y logos.
     *
     * Un logo inventado sobre un producto es lo más parecido a suplantar una
     * marca que puede hacer un catálogo.
     */
    public function test_pide_producto_aislado_y_sin_marcas(): void
    {
        Storage::fake('public');
        $this->conClave();
        $this->respuestaConImagen();

        app(GeneradorDeIlustraciones::class)->generar('Una gorra bordada');

        Http::assertSent(function ($peticion) {
            $texto = $peticion['contents'][0]['parts'][0]['text'];

            return str_contains($texto, 'Una gorra bordada')
                && str_contains($texto, 'Sin texto')
                && str_contains($texto, 'sin logotipos')
                && str_contains($texto, 'Sin personas');
        });
    }

    /**
     * El tipo se lee de la respuesta, no se supone.
     *
     * Un modelo devuelve PNG y otro JPEG. Dárselo mal al optimizador solo se
     * nota el día que la optimización falla y se guarda el original con la
     * extensión equivocada — es decir, cuando nadie está mirando.
     */
    public function test_respeta_el_tipo_de_imagen_que_devuelve_el_modelo(): void
    {
        Storage::fake('public');
        $this->conClave();

        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [['content' => ['parts' => [
                ['inlineData' => ['mimeType' => 'image/jpeg', 'data' => $this->pngFalso()]],
            ]]]],
        ])]);

        $ruta = app(GeneradorDeIlustraciones::class)->generar('Un llavero');

        $this->assertNotNull($ruta);
        Storage::disk('public')->assertExists($ruta);
    }

    /** La cuota diaria frena un botón pulsado en bucle. */
    public function test_la_cuota_del_dia_frena(): void
    {
        Storage::fake('public');
        $this->conClave();
        config(['fabos.ilustraciones.max_por_dia' => 2]);
        $this->respuestaConImagen();

        $generador = app(GeneradorDeIlustraciones::class);

        $this->assertSame(2, $generador->quedanHoy());
        $this->assertNotNull($generador->generar('uno'));
        $this->assertNotNull($generador->generar('dos'));

        $this->assertSame(0, $generador->quedanHoy());
        $this->assertNull($generador->generar('tres'), 'La tercera no debería salir.');
    }

    // ---------------------------------------------------------------
    // Que no se confunda con una foto
    // ---------------------------------------------------------------

    /**
     * La foto real siempre gana.
     *
     * Y no por un `if` que alguien tiene que acordarse de escribir: la
     * ilustración vive en su propia columna, así que subir una foto la tapa
     * sola. Si mañana se retira la foto, la ilustración vuelve a verse.
     */
    public function test_la_foto_real_siempre_gana(): void
    {
        $cosa = Supply::create([
            'name' => 'Llavero', 'unit' => 'unidad', 'stock' => 1, 'is_active' => true,
            'ilustracion_path' => 'ilustraciones/inventada.webp',
        ]);

        $this->assertTrue($cosa->imagen()['esIlustracion']);
        $this->assertTrue($cosa->tieneIlustracion());

        $cosa->update(['photo_path' => 'fotos/de-verdad.webp']);
        $cosa->refresh();

        $this->assertFalse($cosa->imagen()['esIlustracion']);
        $this->assertStringContainsString('de-verdad', $cosa->imagen()['url']);
        $this->assertFalse($cosa->tieneIlustracion());

        // No se borró: si se retira la foto, vuelve a haber algo que enseñar.
        $this->assertNotNull($cosa->ilustracion_path);
    }

    /** Y en la tienda sale con su sello, que no depende de nadie. */
    public function test_en_la_tienda_sale_marcada_como_ilustracion(): void
    {
        Supply::create([
            'name' => 'Llavero grabado', 'unit' => 'unidad', 'stock' => 3,
            'is_active' => true, 'is_public' => true, 'last_cost' => 9000,
            'ilustracion_path' => 'ilustraciones/inventada.webp',
        ]);

        $this->get('/tienda')
            ->assertOk()
            ->assertSee('Llavero grabado')
            // La ETIQUETA, no el nombre de la clase: el CSS del sello esta
            // siempre en la pagina y buscarlo daria positivo aunque no se
            // pintara ningun sello.
            ->assertSee('<span class="sello-ilustracion">imagen de referencia</span>', false);
    }

    /**
     * El aviso va DENTRO del bloque de estilos.
     *
     * Estuvo fuera y nadie lo vio venir: el navegador lo pintaba como texto al
     * pie de la tienda, y el sello se quedaba sin colocar —salia debajo de la
     * foto, diminuto— porque sus reglas nunca se aplicaron.
     */
    public function test_el_estilo_del_sello_va_dentro_del_bloque_de_estilos(): void
    {
        $vista = resource_path('views/tienda/publica.blade.php');
        $html = file_get_contents($vista);

        $abre = strpos($html, '<style>');
        $cierra = strpos($html, '</style>');
        $regla = strpos($html, '.sello-ilustracion{');

        $this->assertNotFalse($regla, 'Falta la regla del sello.');
        $this->assertGreaterThan($abre, $regla);
        $this->assertLessThan($cierra, $regla, 'El CSS quedó fuera de <style> y se lee como texto.');
    }

    /**
     * La ficha enseña la ilustración que hay.
     *
     * Estuvo invisible: quien abría la ficha veía «Foto» vacío y concluía que
     * no había imagen, mientras la tienda enseñaba una. Dos pantallas diciendo
     * cosas distintas sobre lo mismo es como se pierde la confianza en las dos.
     */
    public function test_la_ficha_del_panel_ensena_la_ilustracion(): void
    {
        $quien = User::create([
            'name' => 'Jefa', 'email' => uniqid().'@lab.co', 'status' => 'activo',
        ]);
        $quien->assignRole(Role::findOrCreate(User::ROL_SUPERADMIN, 'web'));

        $cosa = Supply::create([
            'name' => 'Figura de Meli', 'unit' => 'unidad', 'stock' => 1,
            'kind' => 'producto', 'is_active' => true,
            'ilustracion_path' => 'ilustraciones/inventada.webp',
            'ilustracion_generada_el' => now(),
        ]);

        $factores = app(TwoFactorService::class);
        $secreto = $factores->generarSecreto($quien);
        $factores->confirmar($quien, app(Google2FA::class)->getCurrentOtp($secreto));

        $this->actingAs($quien->fresh())
            ->withSession([FactoresDeSesion::CLAVE_PRUEBAS => ['correo' => true, 'app' => true]])
            ->get('/admin/supplies/'.$cosa->id.'/edit')
            ->assertOk()
            ->assertSee('Imagen de referencia generada')
            ->assertSee('Sube una foto arriba y la reemplaza.');
    }

    /** Con foto de verdad, ningún sello. */
    public function test_con_foto_de_verdad_no_sale_ningun_sello(): void
    {
        Supply::create([
            'name' => 'Llavero grabado', 'unit' => 'unidad', 'stock' => 3,
            'is_active' => true, 'is_public' => true, 'last_cost' => 9000,
            'photo_path' => 'fotos/de-verdad.webp',
            'ilustracion_path' => 'ilustraciones/inventada.webp',
        ]);

        $this->get('/tienda')
            ->assertOk()
            ->assertSee('Llavero grabado')
            ->assertDontSee('<span class="sello-ilustracion">', false);
    }
}
