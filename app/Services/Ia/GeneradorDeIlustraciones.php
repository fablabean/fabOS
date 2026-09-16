<?php

namespace App\Services\Ia;

use App\Services\Media\OptimizadorDeImagen;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Ilustraciones del catálogo, generadas (§14).
 *
 * Decenas de cosas del catálogo no tienen foto, y una ficha sin imagen se salta
 * al mirar. Fotografiar cada producto es trabajo real y no siempre hay producto
 * que fotografiar —muchos se fabrican por encargo—, así que entre «nada» y «una
 * imagen que ayuda a entender de qué va», esto es lo segundo.
 *
 * ## Qué NO es esto
 *
 * **No es un generador de fotos de producto.** Lo que sale de aquí va a una
 * columna aparte y se enseña siempre marcado como ilustración, y en cuanto haya
 * una foto de verdad deja de verse. Un cliente de fuera que ve una gorra
 * generada y recibe otra cosa tiene razón en reclamar; el sello es lo que
 * separa «te ayudo a imaginarlo» de «esto es lo que te llega».
 *
 * Gemini y no el proveedor que ya se usa para texto: Claude no genera imágenes.
 * Es un tercero más, y por eso se limita igual que el otro —cuota diaria y
 * tiempo de espera corto— y se apaga solo si falta la clave.
 *
 * ## Por qué falla en silencio hacia el lado bueno
 *
 * Si no hay clave, si Google no contesta o si devuelve algo que no es una
 * imagen, esto devuelve `null` y quien llamó enseña un aviso. Nunca revienta la
 * pantalla: es una ayuda para llenar el catálogo, no una pieza de la que
 * dependa que alguien pueda comprar.
 */
class GeneradorDeIlustraciones
{
    private const API = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /** Dónde se guardan. Disco público: se enseñan en la tienda. */
    private const CARPETA = 'ilustraciones';

    public function __construct(private OptimizadorDeImagen $optimizador) {}

    public function estaActivo(): bool
    {
        return filled(config('fabos.ilustraciones.clave'));
    }

    /**
     * Cuántas quedan hoy.
     *
     * La cuota no es por dinero: es el freno que impide que un descuido —un
     * botón pulsado en bucle, un bulk sobre doscientas filas— gaste la cuenta
     * de un tirón. Mismo patrón que la IA de texto (§20).
     */
    public function quedanHoy(): int
    {
        return max(0, (int) config('fabos.ilustraciones.max_por_dia') - $this->gastadasHoy());
    }

    /**
     * Genera la ilustración y devuelve su ruta en el disco público.
     *
     * Devuelve `null` cuando no se pudo —sin clave, sin cuota, sin red, o si
     * Google contestó algo que no es una imagen—. Quien llama lo dice en
     * pantalla; nada de esto tumba una página.
     */
    public function generar(string $descripcion): ?string
    {
        if (! $this->estaActivo()) {
            return null;
        }

        if ($this->quedanHoy() < 1) {
            Log::info('Ilustración no generada: cuota del día agotada');

            return null;
        }

        try {
            $respuesta = Http::timeout((int) config('fabos.ilustraciones.timeout'))
                ->post(self::API.config('fabos.ilustraciones.modelo').':generateContent?key='
                    .config('fabos.ilustraciones.clave'), [
                        'contents' => [[
                            'parts' => [['text' => $this->instrucciones($descripcion)]],
                        ]],
                    ]);
        } catch (\Throwable $e) {
            Log::warning('No se pudo generar la ilustración', ['error' => $e->getMessage()]);

            return null;
        }

        if ($respuesta->failed()) {
            Log::warning('El generador de ilustraciones respondió con error', [
                'estado' => $respuesta->status(),
            ]);

            return null;
        }

        // La cuota se gasta al PEDIR, no al guardar: si la petición salió, ya
        // se pagó, aunque la respuesta no traiga nada aprovechable.
        $this->apuntarGasto();

        $imagen = $this->imagenDe($respuesta->json());

        if ($imagen === null) {
            Log::warning('La respuesta del generador no traía ninguna imagen');

            return null;
        }

        return $this->guardar($imagen);
    }

    /**
     * Lo que se le pide, y lo que se le prohíbe.
     *
     * Dos reglas que no son de estilo. **Nada de texto ni logos**, porque un
     * logo inventado sobre un producto es lo más parecido a suplantar una
     * marca que puede hacer un catálogo. Y **producto aislado sobre fondo
     * neutro**, que además de verse como catálogo evita inventar un entorno
     * —una tienda, unas manos, un local— que no es el de este laboratorio.
     */
    private function instrucciones(string $descripcion): string
    {
        return <<<TXT
        Genera una imagen de catálogo del siguiente producto de un laboratorio
        de fabricación digital:

        {$descripcion}

        Requisitos:
        - Un solo objeto, centrado, sobre fondo neutro y liso.
        - Luz suave y uniforme, como una foto de catálogo de producto.
        - Sin texto de ningún tipo, sin logotipos, sin marcas y sin escritura.
        - Sin personas, sin manos y sin escenarios.
        - Aspecto realista del material que corresponda, sin exagerar acabados.
        TXT;
    }

    /**
     * Saca la imagen de la respuesta.
     *
     * Gemini devuelve las partes mezcladas —puede venir texto antes— así que
     * se recorren buscando la primera que traiga datos de imagen en vez de
     * asumir que está en la posición cero.
     */
    private function imagenDe(?array $json): ?string
    {
        foreach ($json['candidates'][0]['content']['parts'] ?? [] as $parte) {
            $datos = $parte['inlineData']['data'] ?? $parte['inline_data']['data'] ?? null;

            if (blank($datos)) {
                continue;
            }

            $binario = base64_decode($datos, true);

            // `strict` arriba: un base64 corrupto devuelve false en vez de
            // basura, y guardar basura daria un fichero que el navegador
            // enseña roto sin que nada haya dado error.
            if ($binario !== false && $binario !== '') {
                return $binario;
            }
        }

        return null;
    }

    /**
     * Se guarda por el mismo optimizador que el resto de imágenes del sitio.
     *
     * Lo que llega de Gemini es un PNG grande, y en la tienda se ve en una
     * tarjeta: sin pasar por aquí pesaría diez veces lo que hace falta. Se
     * envuelve en un `UploadedFile` en vez de duplicar la lógica de encoger y
     * convertir, que es la que ya se corrigió una vez cuando las fotos de
     * teléfono tumbaban las subidas.
     */
    private function guardar(string $binario): ?string
    {
        $temporal = tempnam(sys_get_temp_dir(), 'ilu');

        if ($temporal === false) {
            return null;
        }

        file_put_contents($temporal, $binario);

        try {
            // El ultimo argumento marca el fichero como «de prueba»: sin el,
            // `UploadedFile` exige que venga de una subida HTTP de verdad.
            $archivo = new UploadedFile($temporal, 'ilustracion.png', 'image/png', null, true);

            return $this->optimizador->guardar($archivo, self::CARPETA);
        } catch (\Throwable $e) {
            Log::warning('No se pudo guardar la ilustración', ['error' => $e->getMessage()]);

            return null;
        } finally {
            @unlink($temporal);
        }
    }

    private function clave(): string
    {
        return 'ia:ilustraciones:'.now(config('fabos.lab.timezone'))->toDateString();
    }

    private function gastadasHoy(): int
    {
        return (int) Cache::get($this->clave(), 0);
    }

    private function apuntarGasto(): void
    {
        Cache::put($this->clave(), $this->gastadasHoy() + 1, now()->addDay());
    }
}
