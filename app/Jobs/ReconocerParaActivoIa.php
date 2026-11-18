<?php

namespace App\Jobs;

use App\Services\Ia\Inventario\ReconocedorDeFotos;
use App\Services\Ia\Inventario\ServidorDeIaDelFablab;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Activo IA: lo que tarda —mandarle la foto al servidor de IA del FabLab y
 * esperar— se hace aquí, en la cola, y no en la página.
 *
 * Una foto con varios objetos son muchas consultas seguidas al PGX y puede
 * tardar minutos; Cloudflare corta a los 100 s, y la persona veía «Error al
 * cargar la página» aunque el servidor terminara bien. Ahora la página encola
 * esto, responde al instante y pregunta cada pocos segundos si ya está.
 *
 * El resultado se deja en la caché con el id; la página lo recoge y arma la
 * conversación. Aquí no se toca la base: lo que se registra (las consultas)
 * lo hace la página, que sabe quién es la persona.
 */
class ReconocerParaActivoIa implements ShouldQueue
{
    use Queueable;

    public const COLA = 'activo-ia';

    /** Seis fotos con muchos objetos: holgado, pero con final. */
    public int $timeout = 1700;

    /** Si falla no se repite solo: la persona decide con «Reintentar». */
    public int $tries = 1;

    public bool $failOnTimeout = true;

    /**
     * @param  'una'|'varias'|'otros'  $tipo
     * @param  array<string,mixed>  $datos
     */
    public function __construct(public string $id, public string $tipo, public array $datos)
    {
        $this->onConnection(self::conexion());
        $this->onQueue(self::COLA);
    }

    /**
     * En producción va por su propia conexión (redis-ia), con su trabajador:
     * así una foto de cuatro minutos no detiene los correos ni los anuncios,
     * y la cola no la da por perdida a los 6 minutos. En desarrollo y en las
     * pruebas, la conexión de siempre.
     */
    public static function conexion(): string
    {
        $porDefecto = (string) config('queue.default');

        return $porDefecto === 'redis' ? 'redis-ia' : $porDefecto;
    }

    public static function clave(string $id): string
    {
        return 'activo-ia:reconocimiento:' . $id;
    }

    public static function marcarEnEspera(string $id): void
    {
        Cache::put(self::clave($id), ['estado' => 'esperando'], now()->addHours(2));
    }

    /** @return array<string,mixed>|null */
    public static function resultado(string $id): ?array
    {
        return Cache::get(self::clave($id));
    }

    public static function olvidar(string $id): void
    {
        Cache::forget(self::clave($id));
    }

    public function handle(ReconocedorDeFotos $reconocedor): void
    {
        $resultado = match ($this->tipo) {
            'una' => $this->una($reconocedor),
            'varias' => $this->varias($reconocedor),
            'otros' => $this->otros($reconocedor),
        };

        Cache::put(self::clave($this->id), ['estado' => 'listo'] + $resultado, now()->addHours(2));
    }

    /** Si la cola lo mata (tiempo agotado), que la página no espere para siempre. */
    public function failed(?Throwable $e): void
    {
        Cache::put(self::clave($this->id), [
            'estado' => 'listo',
            'error' => self::error($e ?? new \RuntimeException('El reconocimiento no terminó.')),
        ], now()->addHours(2));
    }

    /** @return array{mensaje:string,conexion:bool} */
    public static function error(Throwable $e): array
    {
        return ['mensaje' => $e->getMessage(), 'conexion' => $e instanceof ConnectionException];
    }

    /** El error otra vez como excepción, para explicarlo igual que antes. */
    public static function excepcion(array $error): Throwable
    {
        return ($error['conexion'] ?? false)
            ? new ConnectionException((string) $error['mensaje'])
            : new \RuntimeException((string) $error['mensaje']);
    }

    /** @return array<string,mixed> */
    private function una(ReconocedorDeFotos $reconocedor): array
    {
        try {
            return ['tarjetas' => $reconocedor->reconocer($this->datos['foto'])];
        } catch (Throwable $e) {
            report($e);

            return ['error' => self::error($e)];
        }
    }

    /** @return array<string,mixed> */
    private function varias(ReconocedorDeFotos $reconocedor): array
    {
        $fotos = $this->datos['fotos'];
        $porFoto = [];
        $errores = [];

        foreach ($fotos as $n => $foto) {
            try {
                $tarjetas = $reconocedor->reconocer($foto);
            } catch (Throwable $e) {
                report($e);
                $errores[$n] = self::error($e)['mensaje'];
                $porFoto[] = [];

                continue;
            }

            // Cada tarjeta sabe de qué foto viene.
            $total = count($tarjetas);
            $porFoto[] = array_map(fn ($t, $i) => array_replace_recursive($t, ['objeto' => [
                'foto' => $n + 1,
                'indice' => $t['objeto']['indice'] ?? $i + 1,
                'total' => $t['objeto']['total'] ?? $total,
            ]]), $tarjetas, array_keys($tarjetas));
        }

        // La huella de cada foto entera: apoya la decisión de si es la misma escena.
        $enteras = [];
        foreach ($fotos as $n => $foto) {
            try {
                $enteras[$n] = app(ServidorDeIaDelFablab::class)
                    ->analizar(Storage::disk('local')->path($foto['ruta']), conPropuesta: false)['huella'];
            } catch (Throwable) {
                // Sin ella se decide solo por los objetos en común.
            }
        }

        return [
            'por_foto' => $porFoto,
            'errores' => $errores,
            'relacion' => $reconocedor->relacionarFotos($porFoto, $enteras),
        ];
    }

    /** @return array<string,mixed> */
    private function otros(ReconocedorDeFotos $reconocedor): array
    {
        try {
            return ['nuevas' => $reconocedor->buscarOtros($this->datos['foto'], $this->datos['revisados'], (int) $this->datos['ya_hay'])];
        } catch (Throwable $e) {
            report($e);

            return ['error' => self::error($e)];
        }
    }
}
