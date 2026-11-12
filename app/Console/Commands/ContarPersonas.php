<?php

namespace App\Console\Commands;

use App\Services\Camaras\ConteoDePersonas;
use App\Services\Vision\Pgx;
use App\Support\Camaras;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Cada minuto: un cuadro de cada cámara al PGX, y el conteo del día al día.
 *
 * La foto no se guarda en ningún lado: va de la cámara al PGX en memoria. Si
 * una cámara o el PGX no responden, se anota el error para la pantalla y se
 * sigue con la siguiente; el minuto que viene se vuelve a intentar.
 */
class ContarPersonas extends Command
{
    protected $signature = 'fabos:contar-personas {--cerrar-dia : borra las huellas de los días pasados}';

    protected $description = 'Cuenta las personas que ven las cámaras, con el PGX';

    public function handle(ConteoDePersonas $conteo, Pgx $pgx): int
    {
        if ($this->option('cerrar-dia')) {
            $borradas = $conteo->cerrarDiasPasados(now());
            $this->info("Huellas borradas: {$borradas}");

            return self::SUCCESS;
        }

        if (! config('fabos.conteo.activo') || ! Pgx::configurado()) {
            return self::SUCCESS;
        }

        foreach (Camaras::todas() as $camara) {
            $n = $camara['numero'];

            try {
                $foto = Camaras::fotoParaContar($n);

                if ($foto === null) {
                    throw new \RuntimeException('La cámara no responde.');
                }

                $lectura = $conteo->registrar($n, $pgx->personas($foto), now());
                Cache::put("camara.{$n}.conteo", $lectura + ['at' => now()->toIso8601String()], now()->addMinutes(10));
                Cache::forget("camara.{$n}.conteo_error");

                $this->line("{$camara['nombre']}: {$lectura['personas']} personas, {$lectura['nuevas']} nuevas hoy");
            } catch (Throwable $e) {
                Cache::put("camara.{$n}.conteo_error", $e->getMessage(), now()->addMinutes(10));
                $this->warn("{$camara['nombre']}: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
