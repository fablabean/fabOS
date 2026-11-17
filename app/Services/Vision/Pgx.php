<?php

namespace App\Services\Vision;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * El servicio `vision` del PGX, el servidor de IA del laboratorio.
 *
 * El PGX no guarda nada: recibe la foto, responde y la olvida. Lo que haya que
 * recordar lo guarda fabOS. Una sola clase para hablarle, así el inventario la
 * reutiliza cuando suba en vez de armar su propia conexión.
 */
class Pgx
{
    public static function configurado(): bool
    {
        return (string) config('fabos.pgx.url') !== '';
    }

    /**
     * Cuántas personas hay en la foto y la huella de cada rostro.
     *
     * @return array{personas:int,con_rostro:int,detalle:list<array>,rostros_sin_persona:list<array>,umbral_misma_persona:float}
     */
    public function personas(string $jpeg, bool $rostros = true): array
    {
        $respuesta = $this->cliente()
            ->attach('foto', $jpeg, 'captura.jpg', ['Content-Type' => 'image/jpeg'])
            ->post('/vision/personas', ['rostros' => $rostros ? 'true' : 'false']);

        if (! $respuesta->successful()) {
            throw new RuntimeException('El PGX respondió ' . $respuesta->status() . ' a /vision/personas.');
        }

        return $respuesta->json();
    }

    private function cliente(): \Illuminate\Http\Client\PendingRequest
    {
        if (! self::configurado()) {
            throw new RuntimeException('Falta la dirección del PGX (IA_URL o PGX_URL en el .env).');
        }

        $cliente = Http::baseUrl(config('fabos.pgx.url'))
            ->timeout(config('fabos.pgx.timeout'))
            ->acceptJson();

        $clave = config('fabos.pgx.clave');

        return $clave ? $cliente->withToken($clave) : $cliente;
    }
}
