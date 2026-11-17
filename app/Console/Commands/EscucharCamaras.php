<?php

namespace App\Console\Commands;

use App\Services\Vision\Pgx;
use App\Support\Camaras;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

/**
 * Mantiene un fabos:escuchar-camara por cada cámara, y lo levanta si se cae.
 *
 * Corre en su propio contenedor en producción (`camaras` en
 * compose.produccion.yaml), que Docker reinicia si este proceso muere. Cada
 * cámara va en un proceso aparte: una cámara que no responde no frena a las
 * demás.
 */
class EscucharCamaras extends Command
{
    protected $signature = 'fabos:escuchar-camaras';

    protected $description = 'Escucha los avisos de todas las cámaras para contar personas';

    public function handle(): int
    {
        if (! config('fabos.conteo.activo') || ! Pgx::configurado()
            || config('fabos.conteo.disparo') === 'minuto' || Camaras::todas() === []) {
            $this->line('Conteo por avisos apagado; vuelvo a mirar en cinco minutos.');
            sleep(300);

            return self::SUCCESS;
        }

        $procesos = [];
        $esperarHasta = [];

        while (true) {
            foreach (Camaras::todas() as $camara) {
                $n = $camara['numero'];
                $p = $procesos[$n] ?? null;

                if ($p && $p->running()) {
                    continue;
                }

                if ($p) {
                    // Se cerró (la hora cumplida o un fallo): se reabre, sin
                    // martillar si falla al arrancar.
                    $esperarHasta[$n] ??= time() + ($p->exitCode() === 0 ? 0 : 10);
                }

                if (time() < ($esperarHasta[$n] ?? 0)) {
                    continue;
                }

                unset($esperarHasta[$n]);
                $procesos[$n] = Process::forever()->start(
                    ['php', 'artisan', 'fabos:escuchar-camara', (string) $n],
                    fn (string $tipo, string $salida) => $this->output->write($salida),
                );
            }

            sleep(2);
        }
    }
}
