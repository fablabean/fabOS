<?php

namespace App\Services\Calendar;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Sabre\VObject\Reader;

/**
 * Lo que cada persona ya tiene fuera de fabOS (§8).
 *
 * fabOS sabía de la jornada y de lo reservado aquí, pero no de las clases ni de
 * las reuniones: ofrecía franjas de asesoría a las que quien asesora no podía
 * ir, y el choque se descubría cuando ya había alguien esperando.
 *
 * La vía sin credenciales de nadie: cada persona publica su calendario de
 * Outlook y pega aquí la dirección. Es de **solo lectura y en un sentido** —una
 * URL de calendario no permite escribir de vuelta, y Microsoft no ofrece CalDAV
 * en 365—, pero es justo el sentido que faltaba.
 *
 * Dos límites que conviene tener presentes, porque no son fallos sino cómo
 * funciona lo publicado por Outlook:
 *
 *  · **Llega con retraso.** Outlook regenera esa dirección cada pocas horas.
 *    Una reunión creada esta mañana puede no aparecer hasta la tarde.
 *  · **Puede venir sin detalle.** Según cómo se publique, los eventos llegan
 *    como «ocupado» sin título. Da igual: aquí solo hace falta saber cuándo.
 */
class AgendaExterna
{
    /** Ya resueltos en esta petición: el catálogo pregunta muchas veces. */
    private array $enMemoria = [];

    public function __construct(private int $minutosDeCache = 30) {}

    /**
     * Si esta persona ya tiene algo a esa hora, fuera de fabOS.
     *
     * Ante la duda, **libre**. Un calendario que no responde no puede dejar al
     * laboratorio sin poder agendar nada: se pierde la protección, no el
     * servicio.
     */
    public function ocupadoEn(User $persona, CarbonInterface $desde, CarbonInterface $hasta): bool
    {
        return $this->ocupaciones($persona)->contains(
            // Se solapan si empieza antes de que acabe y acaba después de que
            // empiece. Tocarse por el borde no es solaparse: una reunión que
            // termina a las 10:00 no impide una asesoría a las 10:00.
            fn (array $r) => $r['desde']->lt($hasta) && $r['hasta']->gt($desde),
        );
    }

    /**
     * Las ocupaciones de las próximas semanas.
     *
     * @return Collection<int,array{desde:CarbonInterface,hasta:CarbonInterface}>
     */
    public function ocupaciones(User $persona): Collection
    {
        if (! filled($persona->external_calendar_url)) {
            return collect();
        }

        if (isset($this->enMemoria[$persona->id])) {
            return $this->enMemoria[$persona->id];
        }

        $crudo = Cache::remember(
            'agenda-externa:' . $persona->id,
            now()->addMinutes($this->minutosDeCache),
            fn () => $this->descargar($persona),
        );

        return $this->enMemoria[$persona->id] = $this->leer($crudo);
    }

    /** Vacía lo guardado, para cuando alguien cambia su dirección. */
    public function olvidar(User $persona): void
    {
        Cache::forget('agenda-externa:' . $persona->id);
        unset($this->enMemoria[$persona->id]);
    }

    /**
     * Descarga el calendario publicado.
     *
     * Con un tope de tiempo corto: esto corre mientras alguien espera a que le
     * salgan las horas disponibles, y un calendario lento no puede dejar la
     * pantalla colgada.
     */
    private function descargar(User $persona): ?string
    {
        try {
            $respuesta = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'fabOS'])
                ->get($persona->external_calendar_url);

            if (! $respuesta->successful()) {
                Log::warning('El calendario externo respondió ' . $respuesta->status(), [
                    'user_id' => $persona->id,
                ]);

                return null;
            }

            return $respuesta->body();
        } catch (\Throwable $e) {
            // Se anota sin la dirección: es un secreto, y los registros se leen
            // por encima del hombro.
            Log::warning('No se pudo leer el calendario externo: ' . $e->getMessage(), [
                'user_id' => $persona->id,
            ]);

            return null;
        }
    }

    /**
     * Convierte el ICS en intervalos ocupados.
     *
     * Las repeticiones se **expanden**: una clase semanal ocupa todos sus
     * martes, no solo el primero. Sin eso, la protección duraría una semana y
     * después mentiría en silencio, que es peor que no tenerla.
     *
     * @return Collection<int,array{desde:CarbonInterface,hasta:CarbonInterface}>
     */
    private function leer(?string $crudo): Collection
    {
        if (! filled($crudo)) {
            return collect();
        }

        $tz = config('fabos.lab.timezone');

        try {
            $calendario = Reader::read($crudo, Reader::OPTION_FORGIVING);

            // Una ventana acotada: expandir un calendario entero de eventos
            // repetidos es caro, y de aquí a un mes es lo único que se agenda.
            $desde = Carbon::now($tz)->subDay();
            $hasta = Carbon::now($tz)->addMonths(2);

            $calendario = $calendario->expand(
                new \DateTime($desde->toDateTimeString(), new \DateTimeZone($tz)),
                new \DateTime($hasta->toDateTimeString(), new \DateTimeZone($tz)),
            );
        } catch (\Throwable $e) {
            Log::warning('El calendario externo no se pudo leer: ' . $e->getMessage());

            return collect();
        }

        $ocupaciones = collect();

        foreach ($calendario->VEVENT ?? [] as $evento) {
            // Lo cancelado no ocupa, y lo marcado como «libre» tampoco: quien
            // pone «disponible» en su calendario está diciendo justo eso.
            if ((string) ($evento->STATUS ?? '') === 'CANCELLED') {
                continue;
            }

            if ((string) ($evento->TRANSP ?? '') === 'TRANSPARENT') {
                continue;
            }

            if (! isset($evento->DTSTART)) {
                continue;
            }

            $inicio = Carbon::instance($evento->DTSTART->getDateTime())->setTimezone($tz);

            $fin = isset($evento->DTEND)
                ? Carbon::instance($evento->DTEND->getDateTime())->setTimezone($tz)
                // Sin fin declarado, un evento dura lo que dure su día: es lo
                // que dice la norma para los de día completo.
                : $inicio->copy()->addDay();

            $ocupaciones->push(['desde' => $inicio, 'hasta' => $fin]);
        }

        return $ocupaciones->values();
    }
}
