<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * La fecha de corte de nómina de un mes (§5).
 *
 * Nómina la fija cada mes, normalmente entre el 14 y el 17. Un mes sin fecha
 * escrita corta el día de la configuración (`fabos.overtime.dia_de_corte`).
 * De ella salen dos cosas: qué jornadas entran en el reporte de ese mes y el
 * periodo contra el que se mide el tope de 48 horas.
 */
class CorteDeNomina extends Model
{
    protected $table = 'cortes_de_nomina';

    protected $fillable = ['mes', 'fecha'];

    protected function casts(): array
    {
        return ['mes' => 'date', 'fecha' => 'date'];
    }

    /** @var array<string,string> mes Y-m-01 => fecha Y-m-d, mientras dura la petición */
    private static array $cache = [];

    public static function olvidar(): void
    {
        self::$cache = [];
    }

    protected static function booted(): void
    {
        static::saved(fn () => self::olvidar());
        static::deleted(fn () => self::olvidar());
    }

    /** El día de corte del mes de esa fecha, a las 00:00 en la hora del laboratorio. */
    public static function fechaDe(CarbonInterface $cualquierDiaDelMes): Carbon
    {
        $tz = config('fabos.lab.timezone');
        $mes = Carbon::parse($cualquierDiaDelMes->format('Y-m-01'), $tz);
        $clave = $mes->toDateString();

        if (! array_key_exists($clave, self::$cache)) {
            $escrita = self::query()->whereDate('mes', $clave)->value('fecha');
            self::$cache[$clave] = $escrita
                ? Carbon::parse($escrita)->toDateString()
                : $mes->copy()->day(min((int) config('fabos.overtime.dia_de_corte', 15), $mes->daysInMonth))->toDateString();
        }

        return Carbon::parse(self::$cache[$clave], $tz)->startOfDay();
    }

    /**
     * El corte que está corriendo: el de este mes si todavía no pasó, y si
     * ya pasó, el del siguiente. El día del corte todavía es de ese corte.
     */
    public static function vigente(?CarbonInterface $hoy = null): Carbon
    {
        $hoy = ($hoy ? Carbon::instance($hoy) : now())->copy()->setTimezone(config('fabos.lab.timezone'));
        $este = self::fechaDe($hoy);

        return $hoy->copy()->startOfDay()->lte($este)
            ? $este
            : self::fechaDe($hoy->copy()->startOfMonth()->addMonthNoOverflow());
    }

    /** El nombre del ciclo: «noviembre de 2026». */
    public static function nombreDelCiclo(CarbonInterface $corte): string
    {
        return Carbon::instance($corte)->locale('es')->isoFormat('MMMM [de] YYYY');
    }
}
