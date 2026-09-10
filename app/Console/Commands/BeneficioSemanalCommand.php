<?php

namespace App\Console\Commands;

use App\Services\Money\BeneficioSemanal;
use App\Support\Settings;
use Illuminate\Console\Command;

class BeneficioSemanalCommand extends Command
{
    protected $signature = 'fabos:beneficio-semanal
                            {--semana= : Semana ISO a abonar, formato AAAA-Wss (por defecto, la actual)}
                            {--simular : Muestra a quién se le completaría el saldo sin escribir nada}';

    protected $description = 'Completa hasta el tope el saldo semanal de FabCoins de quien tiene correo aliado';

    public function handle(BeneficioSemanal $beneficio): int
    {
        $semana = $this->option('semana') ?: BeneficioSemanal::semana();

        if (! preg_match('/^\d{4}-W\d{2}$/', $semana)) {
            $this->error('La semana debe tener el formato AAAA-Wss, por ejemplo 2026-W37.');

            return self::FAILURE;
        }

        if (! Settings::beneficioActivo() && ! $this->option('simular')) {
            $this->info('El beneficio semanal está apagado: se enciende en Finanzas → Beneficio semanal.');

            return self::SUCCESS;
        }

        $r = $beneficio->aplicar($semana, (bool) $this->option('simular'));

        $unidades = config('fabos.currency.minor_units');
        $moneda = config('fabos.currency.code');

        foreach ($r['filas'] as $fila) {
            if ($fila['abono'] > 0 || $this->getOutput()->isVerbose()) {
                $this->line(sprintf(
                    '  %-40s saldo %8s  %s %s',
                    $fila['persona']->email,
                    number_format($fila['saldo'] / $unidades, 2, ',', '.'),
                    $fila['abono'] > 0 ? '+' . number_format($fila['abono'] / $unidades, 2, ',', '.') : 'completo',
                    $fila['abono'] > 0 ? $moneda : '',
                ));
            }
        }

        $this->info(sprintf(
            '%s %s: %d con derecho, %d abonos por %s %s, %d ya estaban completos.',
            $this->option('simular') ? 'Simulación de la semana' : 'Beneficio de la semana',
            $r['semana'], $r['personas'], $r['abonos'],
            number_format($r['total'] / $unidades, 2, ',', '.'), $moneda, $r['completas'],
        ));

        return self::SUCCESS;
    }
}
