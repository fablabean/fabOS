<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Iot\Pantalla;
use App\Services\Iot\DibujoDePantalla;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lo que necesitan las pantallas e-paper de los salones (docs/PANTALLAS-API.md).
 *
 * La pantalla despierta —por el botón o a la hora que se le dijo—, pregunta
 * con `GET /estado` contando su batería, y solo si cambió algo baja la imagen
 * con `GET /imagen`. Después vuelve a dormir los segundos que diga
 * `despertar_en`. Lo que más batería gasta es el wifi y redibujar: por eso
 * nunca se redibuja si no cambió nada.
 */
class PantallaController extends Controller
{
    public function __construct(private DibujoDePantalla $dibujo) {}

    public function estado(Request $request): JsonResponse
    {
        $pantalla = $this->pantalla($request);

        if ($pantalla instanceof JsonResponse) {
            return $pantalla;
        }

        $datos = $request->validate([
            'bateria_pct' => ['nullable', 'integer', 'between:0,100'],
            'bateria_mv' => ['nullable', 'integer', 'between:0,10000'],
            'cargando' => ['nullable', 'boolean'],
            'rssi' => ['nullable', 'integer', 'between:-150,0'],
            'firmware' => ['nullable', 'string', 'max:40'],
            'motivo' => ['nullable', 'in:boton,programado,inicio'],
            'version' => ['nullable', 'string', 'max:64'],
        ]);

        $version = $this->dibujo->version($pantalla);
        $despertar = $this->dibujo->despertarEn($pantalla);

        $pantalla->forceFill([
            'visto_at' => now(),
            'bateria_pct' => $datos['bateria_pct'] ?? $pantalla->bateria_pct,
            'bateria_mv' => $datos['bateria_mv'] ?? $pantalla->bateria_mv,
            'cargando' => array_key_exists('cargando', $datos) ? (bool) $datos['cargando'] : $pantalla->cargando,
            'rssi' => $datos['rssi'] ?? $pantalla->rssi,
            'firmware' => $datos['firmware'] ?? $pantalla->firmware,
            'motivo' => $datos['motivo'] ?? null,
            'despertar_en' => $despertar,
        ])->saveQuietly();

        $c = $this->dibujo->contenido($pantalla);
        $m = $c['mostrada'];

        return response()->json([
            'salon' => $pantalla->salon,
            'version' => $version,
            // Si lo que tiene la pantalla ya es esto, no hay que bajar ni redibujar.
            'cambio' => ($datos['version'] ?? null) !== $version,
            'despertar_en' => $despertar,
            'imagen' => [
                'url' => route('api.pantalla.imagen'),
                'formato' => 'bits',
                'ancho' => DibujoDePantalla::ANCHO,
                'alto' => DibujoDePantalla::ALTO,
                'bytes' => DibujoDePantalla::ANCHO * DibujoDePantalla::ALTO / 8,
            ],
            'actividad' => $m ? [
                'etiqueta' => $c['etiqueta'],
                'titulo' => $m->titulo,
                'responsable' => $m->responsable,
                'inicia_at' => $m->inicia_at->copy()->timezone(config('fabos.lab.timezone'))->toIso8601String(),
                'minutos' => $m->minutos,
            ] : null,
        ]);
    }

    public function imagen(Request $request): Response
    {
        $pantalla = $this->pantalla($request);

        if ($pantalla instanceof JsonResponse) {
            return $pantalla;
        }

        $version = $this->dibujo->version($pantalla);
        $pantalla->forceFill(['version_mostrada' => $version])->saveQuietly();

        $encabezados = [
            'X-Version' => $version,
            'X-Despertar-En' => (string) $this->dibujo->despertarEn($pantalla),
            'ETag' => '"' . $version . '"',
            'Cache-Control' => 'no-store',
        ];

        if ($request->query('formato') === 'png') {
            return response($this->dibujo->png($pantalla), 200, $encabezados + ['Content-Type' => 'image/png']);
        }

        return response($this->dibujo->bits($pantalla), 200, $encabezados + ['Content-Type' => 'application/octet-stream']);
    }

    private function pantalla(Request $request): Pantalla|JsonResponse
    {
        $pantalla = Pantalla::porClave($request->bearerToken());

        if (! $pantalla || ! $pantalla->activa) {
            return response()->json([
                'error' => 'clave_invalida',
                'mensaje' => 'La clave no corresponde a ninguna pantalla activa. Genera una nueva en el panel.',
            ], 401);
        }

        return $pantalla;
    }
}
