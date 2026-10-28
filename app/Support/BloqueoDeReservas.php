<?php

namespace App\Support;

use App\Models\Bloqueo;
use App\Services\Booking\BookingException;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * El laboratorio entero, cerrado a las reservas.
 *
 * No es un cierre de agenda (Operación → Ausencias y cierres): ese quita la
 * cobertura de unas fechas, pero quien reserva sin acompañamiento —un espacio
 * autónomo, una herramienta— seguía pudiendo. Esto cierra todo: una obra, un
 * corte de luz, una emergencia, o «el viernes no abrimos».
 *
 * Cada bloqueo (App\Models\Bloqueo) va de una fecha a otra, y puede haber
 * varios. Desde ya o programado: el viernes entero, o la próxima semana de
 * tal hora a tal otra. Antes de empezar se avisa en el sitio con una ventana
 * que se cierra, y se sigue reservando para los demás días; mientras dura,
 * no se reserva nada que caiga dentro y sale el aviso de bloqueo.
 *
 * Sin fecha de fin, hasta que alguien lo levante. Las reservas que ya
 * existían no se tocan: cancelarlas o no es una decisión de cada caso, y se
 * hace desde la lista.
 */
class BloqueoDeReservas
{
    /**
     * Una consulta por petición, no una por pregunta: el aviso sale en cada
     * página. Se guarda en la aplicación, que nace con cada petición, y
     * atada al segundo, porque en los tests el reloj viaja.
     */
    private const MEMORIA = 'bloqueos-de-reservas.vigentes';

    /**
     * Páginas de documentos —firmar o ver un acta, una requisición, un lote
     * compartido, verificar un certificado, la asistencia o la encuesta de
     * una actividad—: quien llega ahí no viene a reservar, y el aviso estorba.
     */
    private const SIN_AVISO = [
        'actas.*', 'compras.*', 'lotes.compartido*', 'publico.verificar',
        'asistencia*', 'encuesta*',
    ];

    /** Si en esta página sale el aviso del bloqueo. */
    public static function seAvisaAqui(): bool
    {
        return ! request()->routeIs(...self::SIN_AVISO);
    }

    /** @return Collection<int,Bloqueo> en curso y por venir, por fecha */
    public static function vigentes(): Collection
    {
        $ahora = now()->getTimestamp();
        $m = app()->bound(self::MEMORIA) ? app(self::MEMORIA) : null;

        if ($m === null || $m[0] !== $ahora) {
            $m = [$ahora, Bloqueo::query()->vigentes()->get()];
            app()->instance(self::MEMORIA, $m);
        }

        return $m[1];
    }

    public static function olvidar(): void
    {
        app()->forgetInstance(self::MEMORIA);
    }

    /** El que está en curso ahora, si hay. */
    public static function actual(): ?Bloqueo
    {
        return self::vigentes()->first(fn (Bloqueo $b) => $b->enCurso());
    }

    /** Los que vienen y ya se están avisando. */
    public static function porVenir(): Collection
    {
        return self::vigentes()->filter(fn (Bloqueo $b) => ! $b->enCurso() && $b->avisar_desde->lte(now()))->values();
    }

    public static function activo(): bool
    {
        return self::actual() !== null;
    }

    public static function motivo(): string
    {
        return self::textoDe(self::actual());
    }

    public static function textoDe(?Bloqueo $b): string
    {
        return trim((string) $b?->motivo) ?: 'Por una situación en el laboratorio.';
    }

    public static function hasta(): ?Carbon
    {
        return self::actual()?->hasta;
    }

    /** «lunes 6 de octubre a las 8:00», en la hora del laboratorio. */
    public static function hastaLegible(): ?string
    {
        return self::legible(self::hasta());
    }

    public static function legible(?CarbonInterface $momento): ?string
    {
        return $momento?->copy()->timezone(config('fabos.lab.timezone'))->locale('es')->isoFormat('dddd D [de] MMMM [a las] H:mm');
    }

    /** Una marca que cambia con lo que se avisa: si cambia, la ventana vuelve a salir. */
    public static function version(): string
    {
        $avisos = self::porVenir()->push(self::actual())->filter();

        return substr(md5($avisos->map(fn (Bloqueo $b) => $b->id . '|' . $b->updated_at . '|' . ($b->enCurso() ? 'ya' : 'viene'))->implode(',')), 0, 10);
    }

    /** Desde ya. Como el interruptor de siempre. */
    public static function activar(string $motivo, ?Carbon $hasta, ?string $por): Bloqueo
    {
        return self::programar($motivo, now(), $hasta, null, $por);
    }

    /**
     * Para unas fechas. Sin `$avisarDesde`, se avisa desde ya.
     */
    public static function programar(
        string $motivo,
        CarbonInterface $desde,
        ?CarbonInterface $hasta,
        ?CarbonInterface $avisarDesde,
        ?string $por,
        bool $diasCompletos = false,
    ): Bloqueo {
        $avisar = $avisarDesde ?? now();

        // En UTC: Eloquent guarda la hora tal cual, sin mirar la zona, y la
        // medianoche de Bogotá quedaba como medianoche UTC.
        $utc = fn (?CarbonInterface $m) => $m?->copy()->utc();

        return Bloqueo::create([
            'motivo'         => trim($motivo),
            'desde'          => $utc($desde),
            'hasta'          => $utc($hasta),
            'avisar_desde'   => $utc($avisar->gt($desde) ? $desde : $avisar),
            'dias_completos' => $diasCompletos,
            'por'            => $por,
        ]);
    }

    /** Levanta el que está en curso. */
    public static function levantar(): void
    {
        self::actual()?->update(['levantado_at' => now()]);
    }

    /** El bloqueo que alcanza algo de `$desde` a `$hasta`, si hay. */
    public static function elQueCubre(?CarbonInterface $desde = null, ?CarbonInterface $hasta = null): ?Bloqueo
    {
        $desde ??= now();

        return self::vigentes()->first(fn (Bloqueo $b) => $b->alcanza($desde, $hasta));
    }

    /**
     * Si un bloqueo alcanza algo de `$desde` a `$hasta`.
     *
     * Lo que queda fuera de los bloqueos se reserva normal, también mientras
     * uno está en curso: el laboratorio se reabre y la gente quiere su turno.
     * Sin `$desde`, se pregunta por ahora mismo: registrar una llegada.
     */
    public static function cubre(?CarbonInterface $desde = null, ?CarbonInterface $hasta = null): bool
    {
        return self::elQueCubre($desde, $hasta) !== null;
    }

    /** @throws BookingException si lo que va de `$desde` a `$hasta` cae en un bloqueo */
    public static function exigirAbierto(?CarbonInterface $desde = null, ?CarbonInterface $hasta = null): void
    {
        $b = self::elQueCubre($desde, $hasta);

        if ($b === null) {
            return;
        }

        $cuando = self::legible($b->hasta);

        throw new BookingException(
            'Las reservas del laboratorio están bloqueadas ' . $b->cuando() . ': ' . rtrim(self::textoDe($b), '.') . '.'
            . ($cuando ? ' Puedes reservar a partir del ' . $cuando . ', o elegir otro día.' : ' Te avisaremos cuando se reabran.')
        );
    }
}
