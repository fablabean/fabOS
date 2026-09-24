<?php

namespace App\Services\Money;

use App\Models\Setting;
use App\Support\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Cuántos pesos vale un dólar, hoy (§12, §15).
 *
 * La TRM es la tasa oficial que publica la Superintendencia Financiera y
 * cambia todos los días. Hasta ahora el sistema llevaba una cifra escrita en
 * la configuración —4.100— con un comentario reconociendo que era un supuesto,
 * y quien abría una compra en dólares la corregía a mano o no la corregía. Un
 * programa que se vende en dólares no se puede cotizar con la tasa del año
 * pasado.
 *
 * Tres capas, y cada una existe porque la de arriba puede faltar:
 *
 *  1. **La del día**, pedida a los datos abiertos del Estado y guardada en
 *     memoria hasta mañana. Una consulta al día, no una por pantalla.
 *  2. **La última que sirvió**, guardada en los ajustes. Si hoy no hay red,
 *     ayer sigue siendo mucho mejor que un número de hace un año.
 *  3. **La de la configuración**, que es la que había. Solo para una
 *     instalación recién montada que todavía no ha visto una tasa real.
 *
 * Nunca lanza. Una tasa es un dato de apoyo: que no se pueda pedir no debería
 * dejar sin abrir la ficha de un curso.
 */
class TasaDeCambio
{
    /** Los datos abiertos del Estado publican la serie de la TRM. */
    private const FUENTE = 'https://www.datos.gov.co/resource/32sa-8pi3.json';

    public function pesosPorDolar(): float
    {
        return Cache::remember('trm:' . now(config('fabos.lab.timezone'))->toDateString(), now()->addDay(), function () {
            // Si ya se consiguió hoy, no se vuelve a preguntar. La memoria es
            // por proceso y aquí hay varios —la web, la cola, la consola—;
            // guardada, la consulta es una al día de verdad y no una por cada
            // uno que arranque.
            if ($this->esDeHoy()) {
                return $this->ultimaConocida();
            }

            $delDia = $this->consultar();

            if ($delDia !== null) {
                // Se guarda para mañana: si mañana no hay red, esta sirve.
                Setting::put(Settings::TRM_ULTIMA, [
                    'valor'  => $delDia,
                    'cuando' => now()->toIso8601String(),
                ], 'finanzas');

                return $delDia;
            }

            return $this->ultimaConocida() ?? (float) config('fabos.money.usd_rate');
        });
    }

    /** De cuándo es la que se está usando, para poder decirlo. */
    public function deCuando(): ?\Illuminate\Support\Carbon
    {
        $guardada = Setting::get(Settings::TRM_ULTIMA);

        return is_array($guardada) && filled($guardada['cuando'] ?? null)
            ? \Illuminate\Support\Carbon::parse($guardada['cuando'])
            : null;
    }

    /** Si lo que se usa es la tasa real o el supuesto de la configuración. */
    public function esReal(): bool
    {
        return $this->ultimaConocida() !== null;
    }

    /** Si la guardada es de hoy: entonces no hay nada que preguntar. */
    private function esDeHoy(): bool
    {
        $tz = config('fabos.lab.timezone');

        return $this->ultimaConocida() !== null
            && $this->deCuando()?->timezone($tz)->isSameDay(now($tz));
    }

    private function ultimaConocida(): ?float
    {
        $guardada = Setting::get(Settings::TRM_ULTIMA);
        $valor = is_array($guardada) ? (float) ($guardada['valor'] ?? 0) : 0;

        return $valor > 0 ? $valor : null;
    }

    /**
     * La del día, o nula si no se pudo.
     *
     * Con tiempo de espera corto a propósito: esto corre al pintar un
     * formulario, y cinco segundos esperando una tasa es una pantalla que
     * parece rota.
     */
    private function consultar(): ?float
    {
        try {
            $r = Http::timeout(4)
                ->get(self::FUENTE, [
                    '$order' => 'vigenciadesde DESC',
                    '$limit' => 1,
                ]);

            if ($r->failed()) {
                Log::warning('TRM: no se pudo consultar', ['estado' => $r->status()]);

                return null;
            }

            $valor = (float) ($r->json('0.valor') ?? 0);

            return $valor > 0 ? $valor : null;
        } catch (\Throwable $e) {
            Log::warning('TRM: falló la consulta', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
