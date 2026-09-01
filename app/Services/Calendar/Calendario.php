<?php

namespace App\Services\Calendar;

use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Escribe calendarios en iCalendar, el formato que todos entienden (§8).
 *
 * Outlook, Google y el del teléfono leen esto sin que haya que integrarse con
 * ninguno: es un archivo de texto con una forma acordada desde 1998. La
 * alternativa —hablar con la API de cada uno— exige credenciales, permisos de
 * administrador y mantener tres integraciones para el mismo evento.
 *
 * Se usa de dos maneras:
 *
 *  · **Un archivo suelto**, para «añadir esto a mi calendario». Se descarga y
 *    se acabó: si la reserva cambia, la copia del calendario no se entera.
 *  · **Una suscripción**, para el calendario de quien trabaja aquí. El
 *    calendario vuelve a pedirlo cada pocas horas, así que lo que cambia aquí
 *    aparece allá solo.
 */
class Calendario
{
    /** Un solo evento, para descargar. */
    public function deUnaReserva(Reservation $reserva): string
    {
        return $this->envolver([$this->evento($reserva)]);
    }

    /**
     * Todo lo de una persona: lo que reservó y lo que atiende.
     *
     * Las dos cosas en el mismo calendario a propósito. Quien asesora necesita
     * ver su turno junto a sus propias reservas; en dos calendarios distintos,
     * el choque entre ambos no lo ve nadie.
     *
     * @param  Collection<int,Reservation>  $reservas
     */
    public function deUnaPersona(User $persona, Collection $reservas): string
    {
        return $this->envolver(
            $reservas->map(fn (Reservation $r) => $this->evento($r, $persona))->all(),
            'fabOS · ' . $persona->name,
        );
    }

    private function evento(Reservation $reserva, ?User $paraQuien = null): array
    {
        $atiende = $paraQuien
            && $reserva->reservable_type === User::class
            && $reserva->reservable_id === $paraQuien->id;

        $que = $reserva->esAsesoria()
            ? ($reserva->sobreQue() ?? 'Asesoría')
            : ($reserva->reservable?->name ?? 'Reserva');

        $titulo = $reserva->esAsesoria()
            ? ($atiende ? 'Asesoría · ' . $que : 'Asesoría de ' . $que)
            : $que;

        $descripcion = collect([
            $reserva->purpose,
            $atiende ? 'Atiendes a ' . ($reserva->user?->name ?? 'alguien') . '.' : null,
            $reserva->esAsesoria() && ! $atiende
                ? 'Te acompaña ' . ($reserva->reservable?->name ?? 'el equipo') . '.'
                : null,
        ])->filter()->implode(' ');

        return [
            // Estable: si el calendario vuelve a pedir la lista, reconoce el
            // evento y lo actualiza en vez de duplicarlo.
            'uid'   => 'reserva-' . $reserva->id . '@' . parse_url((string) config('app.url'), PHP_URL_HOST),
            'desde' => $reserva->starts_at,
            'hasta' => $reserva->ends_at,
            'titulo' => $titulo,
            'descripcion' => $descripcion,
            'lugar' => config('fabos.lab.name'),
            // Una cancelada se manda igual, marcada: si desapareciera sin más,
            // el calendario de quien ya la tenía la seguiría enseñando.
            'cancelado' => in_array($reserva->status, ['cancelada', 'rechazada'], true),
            // La hora de la última modificación: es lo que mira el calendario
            // para saber cuál de las dos versiones es la nueva.
            'sello' => $reserva->updated_at ?? $reserva->created_at,
        ];
    }

    /** @param array<int,array<string,mixed>> $eventos */
    private function envolver(array $eventos, ?string $nombre = null): string
    {
        $lineas = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//fabOS//' . $this->texto((string) config('fabos.lab.name')) . '//ES',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        if ($nombre) {
            $lineas[] = 'X-WR-CALNAME:' . $this->texto($nombre);
            $lineas[] = 'X-WR-TIMEZONE:' . config('fabos.lab.timezone');
        }

        foreach ($eventos as $e) {
            $lineas = array_merge($lineas, [
                'BEGIN:VEVENT',
                'UID:' . $e['uid'],
                'DTSTAMP:' . $this->momento($e['sello']),
                'DTSTART:' . $this->momento($e['desde']),
                'DTEND:' . $this->momento($e['hasta']),
                'SUMMARY:' . $this->texto($e['titulo']),
                'LOCATION:' . $this->texto($e['lugar']),
                'STATUS:' . ($e['cancelado'] ? 'CANCELLED' : 'CONFIRMED'),
            ]);

            if ($e['descripcion'] !== '') {
                $lineas[] = 'DESCRIPTION:' . $this->texto($e['descripcion']);
            }

            $lineas[] = 'END:VEVENT';
        }

        $lineas[] = 'END:VCALENDAR';

        // Fin de línea CRLF: lo pide la norma, y Outlook es de los que lo
        // cumplen a rajatabla —con \n se traga el archivo entero sin un error—.
        return implode("\r\n", array_map($this->plegar(...), $lineas)) . "\r\n";
    }

    /** En UTC, con la Z al final: el calendario lo pasa a la hora de quien mira. */
    private function momento($cuando): string
    {
        return $cuando->copy()->utc()->format('Ymd\THis\Z');
    }

    /** Las comas, los puntos y coma y las barras van escapados; los saltos, como \n. */
    private function texto(string $valor): string
    {
        return str_replace(
            ['\\', "\n", ',', ';'],
            ['\\\\', '\\n', '\\,', '\\;'],
            trim($valor),
        );
    }

    /**
     * Ninguna línea pasa de 75 octetos: lo exige la norma.
     *
     * Se corta contando BYTES y no letras, que es donde falla lo obvio: una
     * «ó» ocupa dos, y partirla por la mitad deja el archivo ilegible justo en
     * los nombres en español.
     */
    private function plegar(string $linea): string
    {
        if (strlen($linea) <= 75) {
            return $linea;
        }

        $partes = [];
        $actual = '';

        foreach (mb_str_split($linea) as $letra) {
            // 74 y no 75: la continuación lleva un espacio delante.
            if (strlen($actual) + strlen($letra) > ($partes === [] ? 75 : 74)) {
                $partes[] = $actual;
                $actual = '';
            }

            $actual .= $letra;
        }

        $partes[] = $actual;

        return implode("\r\n ", $partes);
    }
}
