<?php

namespace App\Support;

use App\Models\Setting;
use App\Services\Booking\BookingException;
use Illuminate\Support\Carbon;

/**
 * El laboratorio entero, cerrado a las reservas.
 *
 * No es un cierre de agenda (Operación → Ausencias y cierres): ese quita la
 * cobertura de unas fechas, pero quien reserva sin acompañamiento —un espacio
 * autónomo, una herramienta— seguía pudiendo. Esto es el interruptor para
 * cuando pasa algo —una obra, un corte de luz, una emergencia— y nadie debe
 * reservar nada hasta que se levante: se dice por qué, a todo el que entra.
 *
 * Con fecha de reapertura se levanta solo; sin ella, hasta que alguien lo
 * apague. Las reservas que ya existían no se tocan: cancelarlas o no es una
 * decisión de cada caso, y se hace desde la lista.
 */
class BloqueoDeReservas
{
    public const CLAVE = 'reservas.bloqueo';

    /** @return array{activo:bool,motivo:?string,hasta:?string,desde:?string,por:?string} */
    public static function estado(): array
    {
        $guardado = Setting::get(self::CLAVE);

        return array_merge(
            ['activo' => false, 'motivo' => null, 'hasta' => null, 'desde' => null, 'por' => null],
            is_array($guardado) ? $guardado : [],
        );
    }

    public static function activo(): bool
    {
        $e = self::estado();

        if (! $e['activo']) {
            return false;
        }

        $hasta = self::hasta();

        return $hasta === null || now()->lt($hasta);
    }

    public static function motivo(): string
    {
        return trim((string) self::estado()['motivo']) ?: 'Por una situación en el laboratorio.';
    }

    public static function hasta(): ?Carbon
    {
        $hasta = self::estado()['hasta'];

        return filled($hasta) ? Carbon::parse($hasta) : null;
    }

    /** «hasta el lunes 6 de octubre a las 8:00», en la hora del laboratorio. */
    public static function hastaLegible(): ?string
    {
        $hasta = self::hasta()?->timezone(config('fabos.lab.timezone'))->locale('es');

        return $hasta?->isoFormat('dddd D [de] MMMM [a las] H:mm');
    }

    /** Una marca que cambia cada vez que se activa o se edita: el modal vuelve a salir. */
    public static function version(): string
    {
        $e = self::estado();

        return substr(md5(($e['desde'] ?? '') . '|' . ($e['motivo'] ?? '') . '|' . ($e['hasta'] ?? '')), 0, 10);
    }

    public static function activar(string $motivo, ?Carbon $hasta, ?string $por): void
    {
        Setting::put(self::CLAVE, [
            'activo' => true,
            'motivo' => trim($motivo),
            'hasta'  => $hasta?->utc()->toIso8601String(),
            'desde'  => now()->utc()->toIso8601String(),
            'por'    => $por,
        ], 'reservas');
    }

    public static function levantar(): void
    {
        Setting::put(self::CLAVE, array_merge(self::estado(), ['activo' => false]), 'reservas');
    }

    /**
     * Si el bloqueo alcanza algo que empieza en `$desde`.
     *
     * Es un periodo, de ahora a la reapertura: lo que empieza después se
     * puede reservar ya —el laboratorio se reabre y la gente quiere su
     * turno—. Sin fecha de reapertura, alcanza a todo. Sin `$desde`, se
     * pregunta por ahora mismo: registrar una llegada, por ejemplo.
     */
    public static function cubre(?\Carbon\CarbonInterface $desde = null): bool
    {
        if (! self::activo()) {
            return false;
        }

        $reabre = self::hasta();

        return $reabre === null || ($desde ?? now())->lt($reabre);
    }

    /** @throws BookingException si lo que empieza en `$desde` cae en el bloqueo */
    public static function exigirAbierto(?\Carbon\CarbonInterface $desde = null): void
    {
        if (! self::cubre($desde)) {
            return;
        }

        $cuando = self::hastaLegible();

        throw new BookingException(
            'Las reservas del laboratorio están bloqueadas: ' . rtrim(self::motivo(), '.') . '.'
            . ($cuando ? ' Puedes reservar a partir del ' . $cuando . '.' : ' Te avisaremos cuando se reabran.')
        );
    }
}
