<?php

namespace App\Console\Commands;

use App\Services\Camaras\AvisosDeCamara;
use App\Services\Camaras\ConteoDePersonas;
use App\Services\Vision\Pgx;
use App\Support\Camaras;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Escucha a una cámara y cuenta personas solo cuando ella dice que las ve.
 *
 * Mientras la cámara avisa «hay personas», se toma un cuadro cada
 * `cada_segundos` y se le pasa al PGX: así, si dos entran juntas y una tapa a
 * la otra, uno o dos segundos después la de atrás ya muestra la cara. Sin
 * personas no se manda nada.
 *
 * Si la cámara no da avisos (ONVIF apagado, o se cayó la red), no se queda
 * ciega: mientras tanto toma un cuadro por minuto, como antes.
 *
 * Lo arranca y lo reinicia fabos:escuchar-camaras. Se cierra solo a la hora
 * (`--max-time`) para no acumular memoria; el supervisor lo vuelve a abrir.
 */
class EscucharCamara extends Command
{
    protected $signature = 'fabos:escuchar-camara {numero} {--max-time=3600}';

    protected $description = 'Cuenta personas cuando la cámara avisa que las ve';

    public function handle(ConteoDePersonas $conteo): int
    {
        $n = (int) $this->argument('numero');
        $camara = Camaras::una($n);

        if (! $camara || ! config('fabos.conteo.activo') || ! Pgx::configurado()) {
            $this->warn("Cámara {$n}: sin conteo (no existe, conteo apagado o sin PGX).");

            return self::SUCCESS;
        }

        $tema = config('fabos.conteo.disparo') === 'movimiento' ? 'movimiento' : 'personas';
        $cada = max(0.5, (float) config('fabos.conteo.cada_segundos'));
        $cola = max(1.0, (float) config('fabos.conteo.segundos_despues'));

        $avisos = new AvisosDeCamara($camara['url'], (int) ($camara['onvif'] ?? 2020));
        $fin = microtime(true) + (int) $this->option('max-time');
        $renovar = 0.0;
        $hasta = 0.0;      // se fotografía hasta este instante
        $ultimaFoto = 0.0;
        $sinAvisosDesde = null;
        $mostrandoCero = true;

        $this->line("{$camara['nombre']}: escuchando avisos de {$tema}.");

        while (microtime(true) < $fin) {
            $activo = microtime(true) < $hasta;

            try {
                if (microtime(true) >= $renovar) {
                    $avisos->suscribir();
                    $renovar = microtime(true) + 480;
                }

                foreach ($avisos->esperar($activo ? 1 : 5) as $aviso) {
                    if ($aviso['tema'] !== $tema) {
                        continue;
                    }

                    // «Ya no hay»: una foto más, por si alguien quedaba tapado.
                    $hasta = microtime(true) + ($aviso['valor'] ? $cola : 1);
                }

                $sinAvisosDesde = null;
                Cache::put("camara.{$n}.escucha", now()->toIso8601String(), now()->addMinutes(2));
            } catch (Throwable $e) {
                $sinAvisosDesde ??= microtime(true);
                $avisos->olvidarSuscripcion();
                $renovar = 0.0;
                $this->warn("{$camara['nombre']}: sin avisos ({$e->getMessage()}).");
                sleep(min(10, 2 + (int) ((microtime(true) - $sinAvisosDesde) / 10)));
            }

            $ahora = microtime(true);
            $sinAvisos = $sinAvisosDesde !== null && $ahora - $sinAvisosDesde > 60;
            $toca = ($ahora < $hasta && $ahora - $ultimaFoto >= $cada)
                || ($sinAvisos && $ahora - $ultimaFoto >= 60);

            if ($toca) {
                $ultimaFoto = $ahora;
                $mostrandoCero = false;

                try {
                    $l = $conteo->fotografiar($n);
                    $this->line(now()->format('H:i:s')." {$camara['nombre']}: {$l['personas']} personas, {$l['identificadas']} con cara, {$l['nuevas']} nuevas");
                } catch (Throwable $e) {
                    $this->warn("{$camara['nombre']}: {$e->getMessage()}");
                }
            } elseif (! $mostrandoCero && $ahora >= $hasta && ! $sinAvisos) {
                // Ya no hay nadie: la pantalla no se queda con la última cifra.
                $conteo->anotarLoQueVe($n, ['personas' => 0, 'identificadas' => 0, 'sin_identificar' => 0, 'nuevas' => 0]);
                $mostrandoCero = true;
            }
        }

        return self::SUCCESS;
    }
}
