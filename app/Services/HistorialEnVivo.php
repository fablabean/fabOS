<?php

namespace App\Services;

use App\Jobs\GuardarSesionEnVivo;
use App\Models\SesionEnVivo;
use Illuminate\Support\Carbon;

/**
 * Lo que pasa en una sesión en vivo vive en la caché y se va a los dos días.
 * Esto lo copia a la base: cada vez que alguien vota, publica o el profesor
 * mueve la agenda, queda un guardado programado para el minuto siguiente
 * (uno solo por minuto, aunque voten treinta a la vez).
 *
 * La copia suma, no reemplaza: si la caché ya venció, lo guardado se queda.
 */
class HistorialEnVivo
{
    /** Cada cuánto, como mucho, se copia una sala. */
    public const CADA = 60;

    public function __construct(private EnVivo $envivo) {}

    public function programar(string $sala): void
    {
        if ($this->envivo->c()->add("envivo:$sala:por-guardar", 1, self::CADA - 5)) {
            GuardarSesionEnVivo::dispatch($sala)->delay(now()->addSeconds(self::CADA));
        }
    }

    public function guardar(string $sala): ?SesionEnVivo
    {
        if (! $this->envivo->existe($sala)) {
            return null;
        }

        $c = $this->envivo->c();
        $agenda = $this->envivo->agenda($sala);
        $ronda = $this->envivo->ronda($sala);
        $votos = $this->envivo->votos($sala);
        $muro = collect((array) $c->get("envivo:$sala:muro", []))->map(fn ($t) => collect($t)->except('quien')->all())->all();
        $tweets = (array) $c->get("envivo:$sala:tweets", []);
        $hayVotos = collect($votos)->flatten()->sum() > 0;

        if (! $agenda['inicio'] && ! $hayVotos && ! $muro && ! $tweets) {
            return null;
        }

        $zona = config('fabos.lab.timezone');
        $fecha = ($agenda['inicio'] ? Carbon::createFromTimestampMs($agenda['inicio']) : now())->setTimezone($zona)->toDateString();

        $s = SesionEnVivo::firstOrNew(['sala' => $sala, 'fecha' => $fecha]);
        $d = $s->datos ?? [];

        if ($agenda['inicio']) {
            $d['inicio'] = $agenda['inicio'];
            $d['listos'] = $agenda['listos'];
            $d['min'] = $agenda['min'];
        }
        if ($hayVotos) {
            $d['votos'][(string) $ronda] = $votos;
        }
        // Por id: una tarjeta o un tweet que ya no está en la caché se queda.
        $d['muro'] = array_replace($d['muro'] ?? [], $muro);
        $d['tweets'] = array_replace($d['tweets'] ?? [], $tweets);
        $d['pico'] = max((int) ($d['pico'] ?? 0), $this->envivo->vivos($sala));

        $s->datos = $d;
        $s->save();

        return $s;
    }
}
