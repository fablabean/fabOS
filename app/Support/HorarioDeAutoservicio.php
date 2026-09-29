<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\Booking\BookingException;
use Carbon\CarbonInterface;

/**
 * Las horas en que una persona puede reservar máquinas por su cuenta.
 *
 * Cuando la demanda aprieta, la coordinación acota el autoservicio —«de 1 a 6
 * de la tarde»— sin tocar la jornada ni los horarios del equipo. Solo alcanza
 * lo que la persona reserva desde su cuenta; lo que se agenda desde el panel
 * no pasa por aquí, porque quien lo hace ya está decidiendo la excepción.
 *
 * La reserva entera tiene que caber: empezar desde la hora de apertura y
 * terminar a más tardar a la de cierre, el mismo día.
 */
class HorarioDeAutoservicio
{
    public const CLAVE = 'reservas.horario_autoservicio';

    /** @return array{activo:bool,desde:string,hasta:string} */
    public static function estado(): array
    {
        $guardado = Setting::get(self::CLAVE);

        return array_merge(
            ['activo' => false, 'desde' => '13:00', 'hasta' => '18:00'],
            is_array($guardado) ? $guardado : [],
        );
    }

    public static function activo(): bool
    {
        return (bool) self::estado()['activo'];
    }

    public static function desde(): string
    {
        return self::estado()['desde'];
    }

    public static function hasta(): string
    {
        return self::estado()['hasta'];
    }

    public static function guardar(bool $activo, string $desde, string $hasta): void
    {
        Setting::put(self::CLAVE, [
            'activo' => $activo,
            'desde'  => substr($desde, 0, 5),
            'hasta'  => substr($hasta, 0, 5),
        ], 'reservas');
    }

    /** «de 13:00 a 18:00» */
    public static function legible(): string
    {
        return 'de ' . self::desde() . ' a ' . self::hasta();
    }

    public static function permite(CarbonInterface $desde, CarbonInterface $hasta): bool
    {
        if (! self::activo()) {
            return true;
        }

        $tz = config('fabos.lab.timezone');
        $inicio = $desde->copy()->timezone($tz);
        $fin = $hasta->copy()->timezone($tz);

        return $inicio->isSameDay($fin)
            && $inicio->format('H:i') >= self::desde()
            && $fin->format('H:i') <= self::hasta();
    }

    /** @throws BookingException si la reserva se sale del horario */
    public static function exigir(CarbonInterface $desde, CarbonInterface $hasta): void
    {
        if (self::permite($desde, $hasta)) {
            return;
        }

        throw new BookingException(
            'Por ahora las máquinas se reservan ' . self::legible() . ': la reserva tiene que empezar y '
            . 'terminar dentro de ese horario. Si necesitas otra hora, pídela al equipo del laboratorio.'
        );
    }
}
