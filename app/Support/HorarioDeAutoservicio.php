<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\Booking\BookingException;
use Carbon\CarbonInterface;

/**
 * Las horas en que una persona puede reservar por su cuenta.
 *
 * Cuando la demanda aprieta, la coordinación acota el autoservicio —«de 1 a 6
 * de la tarde»— sin tocar la jornada ni los horarios del equipo. Se elige a
 * qué se aplica: máquinas, asesorías, espacios, herramientas. Solo alcanza lo
 * que la persona reserva desde su cuenta; lo que se agenda desde el panel no
 * pasa por aquí, porque quien lo hace ya está decidiendo la excepción.
 *
 * La reserva entera tiene que caber: empezar desde la hora de apertura y
 * terminar a más tardar a la de cierre, el mismo día.
 */
class HorarioDeAutoservicio
{
    public const CLAVE = 'reservas.horario_autoservicio';

    /** tipo => [etiqueta en el panel, cómo se dice en el aviso] */
    public const TIPOS = [
        'maquinas'     => ['Máquinas', 'las máquinas se reservan'],
        'asesorias'    => ['Asesorías', 'las asesorías se agendan'],
        'espacios'     => ['Espacios', 'los espacios se reservan'],
        'herramientas' => ['Herramientas', 'las herramientas se prestan'],
    ];

    /** @return array{desde:string,hasta:string,aplica:array<int,string>} */
    public static function estado(): array
    {
        $guardado = Setting::get(self::CLAVE);
        $guardado = is_array($guardado) ? $guardado : [];

        // La primera versión tenía un solo interruptor, y era para máquinas.
        if (! isset($guardado['aplica'])) {
            $guardado['aplica'] = ! empty($guardado['activo']) ? ['maquinas'] : [];
        }

        return [
            'desde'  => $guardado['desde'] ?? '13:00',
            'hasta'  => $guardado['hasta'] ?? '18:00',
            'aplica' => array_values(array_intersect(array_keys(self::TIPOS), (array) $guardado['aplica'])),
        ];
    }

    /** Si limita ese tipo; sin tipo, si limita alguno. */
    public static function activo(?string $tipo = null): bool
    {
        $aplica = self::estado()['aplica'];

        return $tipo === null ? $aplica !== [] : in_array($tipo, $aplica, true);
    }

    public static function desde(): string
    {
        return self::estado()['desde'];
    }

    public static function hasta(): string
    {
        return self::estado()['hasta'];
    }

    /** @param array<int,string> $aplica */
    public static function guardar(array $aplica, string $desde, string $hasta): void
    {
        Setting::put(self::CLAVE, [
            'desde'  => substr($desde, 0, 5),
            'hasta'  => substr($hasta, 0, 5),
            'aplica' => array_values(array_intersect(array_keys(self::TIPOS), $aplica)),
        ], 'reservas');
    }

    /** «de 13:00 a 18:00» */
    public static function legible(): string
    {
        return 'de ' . self::desde() . ' a ' . self::hasta();
    }

    /** «Por ahora las asesorías se agendan de 13:00 a 18:00.» */
    public static function aviso(string $tipo): string
    {
        return 'Por ahora ' . self::TIPOS[$tipo][1] . ' ' . self::legible() . '.';
    }

    public static function permite(string $tipo, CarbonInterface $desde, CarbonInterface $hasta): bool
    {
        if (! self::activo($tipo)) {
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
    public static function exigir(string $tipo, CarbonInterface $desde, CarbonInterface $hasta): void
    {
        if (self::permite($tipo, $desde, $hasta)) {
            return;
        }

        throw new BookingException(
            rtrim(self::aviso($tipo), '.') . ': tiene que empezar y terminar dentro de ese horario. '
            . 'Si necesitas otra hora, pídela al equipo del laboratorio.'
        );
    }
}
