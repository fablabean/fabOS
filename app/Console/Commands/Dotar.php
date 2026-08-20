<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Money\ChargeService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Dotación periódica de FabCoins (§12).
 *
 * Cada categoría define cuánto recibe su gente. La clave de idempotencia lleva
 * el periodo, así que correrlo dos veces el mismo mes no abona dos veces: eso
 * permite reintentarlo sin miedo si el planificador falló o si alguien lo
 * ejecuta a mano para comprobar algo.
 *
 * Solo alcanza a quien está activo y tiene categoría con dotación: una cuenta
 * dada de baja no debería seguir recibiendo saldo.
 */
class Dotar extends Command
{
    protected $signature = 'fabos:dotar
                            {--periodo= : Periodo a abonar, formato AAAA-MM (por defecto, el mes en curso)}
                            {--simular : Muestra a quién le tocaría sin escribir nada}';

    protected $description = 'Abona la dotación del periodo a quienes corresponde';

    public function handle(ChargeService $cobros): int
    {
        $periodo = $this->option('periodo')
            ?: Carbon::now(config('fabos.lab.timezone'))->format('Y-m');

        if (! preg_match('/^\d{4}-\d{2}$/', $periodo)) {
            $this->error('El periodo debe tener el formato AAAA-MM.');

            return self::FAILURE;
        }

        $gente = User::query()
            ->where('status', 'activo')
            ->whereHas('category', fn ($q) => $q->where('allowance_minor', '>', 0))
            ->with('category')
            ->get();

        if ($gente->isEmpty()) {
            $this->info('Ninguna categoría activa tiene dotación configurada.');

            return self::SUCCESS;
        }

        $unidades = config('fabos.currency.minor_units');
        $moneda = config('fabos.currency.code');
        $abonado = 0;
        $repetidos = 0;

        foreach ($gente as $persona) {
            $importe = (int) $persona->category->allowance_minor;

            if ($this->option('simular')) {
                $this->line(sprintf(
                    '  %-40s %10s %s',
                    $persona->email,
                    number_format($importe / $unidades, 2, ',', '.'),
                    $moneda,
                ));
                $abonado += $importe;

                continue;
            }

            $transaccion = $cobros->dotar($persona, $importe, $periodo);

            // Si ya existía, la clave de idempotencia devolvió la anterior.
            $transaccion?->wasRecentlyCreated
                ? $abonado += $importe
                : $repetidos++;
        }

        $this->info(sprintf(
            '%s: %d personas, %s %s%s.',
            $this->option('simular') ? "Simulación del periodo {$periodo}" : "Dotación {$periodo} aplicada",
            $gente->count(),
            number_format($abonado / $unidades, 2, ',', '.'),
            $moneda,
            $repetidos ? " ({$repetidos} ya estaban abonadas)" : '',
        ));

        return self::SUCCESS;
    }
}
