<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Los días hábiles del laboratorio: de lunes a viernes, sin festivos (§11).
 *
 * Sirve para medir plazos de respuesta. «Un día» a secas haría que lo que
 * llega el viernes por la tarde ya estuviera vencido el sábado, cuando no hay
 * nadie que lo pueda contestar: la alarma sonaría todos los lunes y dejaría
 * de mirarse.
 *
 * Los festivos son los de Colombia y se calculan —no se guardan en una
 * tabla—: los fijos, los que la ley Emiliani corre al lunes siguiente y los
 * que cuelgan de la Pascua. Una tabla habría que acordarse de llenarla cada
 * enero, y el año que se olvide nadie lo nota hasta que la alarma miente.
 */
class DiasHabiles
{
    /** Los que caen siempre en su fecha: [mes, día]. */
    private const FIJOS = [[1, 1], [5, 1], [7, 20], [8, 7], [12, 8], [12, 25]];

    /** Los que se corren al lunes siguiente si no caen en lunes. */
    private const AL_LUNES = [[1, 6], [3, 19], [6, 29], [8, 15], [10, 12], [11, 1], [11, 11]];

    /** Días desde el domingo de Pascua, y si se corren al lunes. */
    private const DE_PASCUA = [[-3, false], [-2, false], [39, true], [60, true], [68, true]];

    /** @var array<int,array<string,true>> */
    private static array $festivos = [];

    public static function esHabil(DateTimeInterface $dia): bool
    {
        $dia = self::local($dia);

        return ! $dia->isWeekend() && ! isset(self::festivosDe($dia->year)[$dia->toDateString()]);
    }

    /**
     * Cuándo vence un plazo de un día hábil que empezó en `$desde`.
     *
     * La misma hora del día hábil siguiente. Lo que llega fuera de día hábil
     * —un sábado, un festivo— empieza a contar al abrir el siguiente: el
     * plazo es para contestar, y no se le puede descontar el tiempo en que no
     * había quien contestara.
     */
    public static function vence(DateTimeInterface $desde): CarbonImmutable
    {
        $inicio = self::local($desde);

        if (! self::esHabil($inicio)) {
            $inicio = self::siguienteHabil($inicio)->startOfDay();
        }

        $hora = $inicio->format('H:i:s');

        return self::siguienteHabil($inicio)->setTimeFromTimeString($hora);
    }

    /** Si ya se pasó el día hábil de plazo que empezó en `$desde`. */
    public static function vencio(DateTimeInterface $desde, ?DateTimeInterface $ahora = null): bool
    {
        return self::vence($desde)->lessThanOrEqualTo($ahora ?? now());
    }

    /**
     * El instante más reciente cuyo plazo ya está vencido.
     *
     * Para preguntarle a la base «lo que espera desde antes de esto» sin
     * traer cada fila a calcularle el plazo: la misma hora del día hábil
     * anterior.
     */
    public static function corte(?DateTimeInterface $ahora = null): CarbonImmutable
    {
        $ahora = self::local($ahora ?? now());
        $dia = $ahora;

        // Fuera de día hábil nada vence: manda el final del último hábil.
        if (! self::esHabil($dia)) {
            while (! self::esHabil($dia)) {
                $dia = $dia->subDay();
            }
            $ahora = $dia->endOfDay();
        }

        $anterior = $ahora->subDay();
        while (! self::esHabil($anterior)) {
            $anterior = $anterior->subDay();
        }

        return $anterior->setTimeFromTimeString($ahora->format('H:i:s'));
    }

    private static function siguienteHabil(CarbonImmutable $dia): CarbonImmutable
    {
        do {
            $dia = $dia->addDay();
        } while (! self::esHabil($dia));

        return $dia;
    }

    private static function local(DateTimeInterface $instante): CarbonImmutable
    {
        return CarbonImmutable::instance($instante)->setTimezone(config('fabos.lab.timezone'));
    }

    /** @return array<string,true> las fechas festivas del año, como Y-m-d */
    private static function festivosDe(int $año): array
    {
        if (isset(self::$festivos[$año])) {
            return self::$festivos[$año];
        }

        $tz = config('fabos.lab.timezone');
        $alLunes = fn (CarbonImmutable $d) => $d->isMonday() ? $d : $d->next(CarbonImmutable::MONDAY);
        $fechas = [];

        foreach (self::FIJOS as [$mes, $dia]) {
            $fechas[] = CarbonImmutable::create($año, $mes, $dia, 0, 0, 0, $tz);
        }

        foreach (self::AL_LUNES as [$mes, $dia]) {
            $fechas[] = $alLunes(CarbonImmutable::create($año, $mes, $dia, 0, 0, 0, $tz));
        }

        $pascua = self::pascua($año, $tz);

        foreach (self::DE_PASCUA as [$dias, $corre]) {
            $fecha = $pascua->addDays($dias);
            $fechas[] = $corre ? $alLunes($fecha) : $fecha;
        }

        return self::$festivos[$año] = array_fill_keys(
            array_map(fn (CarbonImmutable $f) => $f->toDateString(), $fechas),
            true,
        );
    }

    /** El domingo de Pascua (algoritmo de Butcher): no depende de extensiones. */
    private static function pascua(int $año, string $tz): CarbonImmutable
    {
        $a = $año % 19;
        $b = intdiv($año, 100);
        $c = $año % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($año, $mes, $dia, 0, 0, 0, $tz);
    }
}
