<?php

namespace App\Services;

use App\Jobs\ClasificarTweetEnVivo;

/**
 * El muro de tweets de una presentación en vivo: cada participante (con su
 * ID) escribe mensajes cortos y la IA del FabLab, metida dentro del
 * programa, le pone a cada uno un tono, un hashtag y una respuesta.
 *
 * Es la muestra de «la IA adentro» frente a «la IA para programar». Vive en
 * la caché, como el resto de la sesión. La IA trabaja en la cola, de a uno,
 * para no ahogar el servidor del FabLab cuando toda la sala publica a la vez.
 */
class TweetsEnVivo
{
    private const DURA = 172800;

    public const LARGO = 140;

    public const POR_PERSONA = 3;

    public const MAXIMO = 150;

    /** Los tonos que puede elegir la IA. */
    public const TONOS = ['entusiasmo', 'curiosidad', 'duda', 'preocupación', 'humor', 'otro'];

    public function __construct(private EnVivo $envivo) {}

    public function activo(string $sala): bool
    {
        return (bool) config("fabos.envivo.salas.$sala.tweets", false);
    }

    /**
     * Publica un tweet de quien tiene ID. Devuelve el error para mostrar, o
     * null si quedó publicado (y en la cola de la IA).
     */
    public function publicar(string $sala, string $quien, string $texto): ?string
    {
        $p = $this->envivo->participanteDe($sala, $quien);
        if (! $p) {
            return 'Primero recibe tu ID: escribe tu nombre arriba.';
        }
        $texto = mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags($texto))), 0, self::LARGO);
        if (mb_strlen($texto) < 3) {
            return 'Escribe algo (hasta ' . self::LARGO . ' caracteres).';
        }

        $id = substr(bin2hex(random_bytes(6)), 0, 12);
        $error = null;
        $ok = $this->conCandado($sala, function (array $todos) use ($p, $texto, $id, &$error) {
            if (count($todos) >= self::MAXIMO) {
                $error = 'El muro está lleno.';

                return null;
            }
            if (collect($todos)->where('codigo', $p->codigo)->count() >= self::POR_PERSONA) {
                $error = 'Ya publicaste ' . self::POR_PERSONA . '. Deja espacio para los demás.';

                return null;
            }
            $todos[$id] = [
                'id' => $id,
                'codigo' => $p->codigo,
                'nombre' => strtok($p->nombre, ' ') ?: $p->nombre,
                'texto' => $texto,
                't' => (int) floor(microtime(true) * 1000),
                'estado' => 'pensando',
            ];

            return $todos;
        });
        if (! $ok) {
            return $error ?? 'No se pudo publicar. Intenta de nuevo.';
        }

        ClasificarTweetEnVivo::dispatch($sala, $id);

        return null;
    }

    public function tweet(string $sala, string $id): ?array
    {
        return $this->todos($sala)[$id] ?? null;
    }

    /** Lo que respondió la IA (o que no pudo). */
    public function resultado(string $sala, string $id, ?array $r): void
    {
        $this->conCandado($sala, function (array $todos) use ($id, $r) {
            if (! isset($todos[$id])) {
                return $todos;
            }
            $todos[$id] = $r
                ? array_merge($todos[$id], ['estado' => 'listo'], array_intersect_key($r, array_flip(['tono', 'hashtag', 'respuesta'])))
                : array_merge($todos[$id], ['estado' => 'error']);

            return $todos;
        });
    }

    public function quitar(string $sala, string $id): void
    {
        $this->conCandado($sala, function (array $todos) use ($id) {
            unset($todos[$id]);

            return $todos;
        });
    }

    public function vaciar(string $sala): void
    {
        $this->conCandado($sala, fn () => []);
    }

    public function version(string $sala): int
    {
        return (int) $this->envivo->c()->get("envivo:$sala:tweets:version", 0);
    }

    /** @return array{version:int, lista:list<array>, mios:int} */
    public function muro(string $sala, ?string $quien): array
    {
        $mio = $quien ? $this->envivo->participanteDe($sala, $quien)?->codigo : null;
        $lista = collect($this->todos($sala))->sortByDesc('t')
            ->map(fn ($t) => collect($t)->except('codigo')->all() + ['mio' => $mio !== null && $t['codigo'] === $mio])
            ->values()->all();

        return ['version' => $this->version($sala), 'lista' => $lista, 'mios' => collect($lista)->where('mio', true)->count()];
    }

    private function todos(string $sala): array
    {
        return (array) $this->envivo->c()->get("envivo:$sala:tweets", []);
    }

    /** @param callable(array):?array $cambio */
    private function conCandado(string $sala, callable $cambio): bool
    {
        $c = $this->envivo->c();

        return (bool) $c->lock("envivo:$sala:tweets:candado", 5)->block(4, function () use ($c, $sala, $cambio) {
            $nuevo = $cambio((array) $c->get("envivo:$sala:tweets", []));
            if ($nuevo === null) {
                return false;
            }
            $c->put("envivo:$sala:tweets", $nuevo, self::DURA);
            $c->add("envivo:$sala:tweets:version", 0, self::DURA * 7);
            $c->increment("envivo:$sala:tweets:version");

            return true;
        });
    }
}
