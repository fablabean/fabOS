<?php

namespace App\Services;

use App\Models\ParticipanteEnVivo;
use App\Models\Setting;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Una presentación en vivo: la agenda la lleva el profesor y la siguen todos;
 * las preguntas las vota quien tenga el módulo abierto.
 *
 * Vive en la caché (Redis), no en la base: es el estado de una sesión de clase
 * y se va solo a los dos días. Los votos son anónimos: cada navegador se
 * identifica con un número al azar que inventa él mismo, sin cuenta.
 *
 * El módulo corre aislado (sin la sesión de fabOS), así que el profesor no se
 * reconoce por su cuenta sino por un enlace con una clave que genera el panel.
 */
class EnVivo
{
    private const DURA = 172800;

    public function c(): Repository
    {
        return Cache::store(config('fabos.envivo.almacen', 'redis'));
    }

    public function existe(string $sala): bool
    {
        return array_key_exists($sala, (array) config('fabos.envivo.salas', []));
    }

    /** @return array<string,int> pregunta => cuántas opciones */
    public function preguntas(string $sala): array
    {
        return (array) config("fabos.envivo.salas.$sala.preguntas", []);
    }

    public function ronda(string $sala): int
    {
        return (int) $this->c()->get("envivo:$sala:ronda", 1);
    }

    // ------------------------------------------------------------ el profesor

    public function claveDeProfesor(string $sala): string
    {
        $clave = Str::random(32);
        $this->c()->put("envivo:profe:$clave", $sala, now()->addHours(14));

        return $clave;
    }

    public function esProfe(string $sala, ?string $clave): bool
    {
        return filled($clave) && preg_match('/^[A-Za-z0-9]{32}$/', $clave)
            && $this->c()->get("envivo:profe:$clave") === $sala;
    }

    // ------------------------------------------------------------------ votos

    public function votar(string $sala, string $quien, string $pregunta, int $opcion): bool
    {
        $opciones = $this->preguntas($sala)[$pregunta] ?? 0;
        if ($opcion < 0 || $opcion >= $opciones) {
            return false;
        }

        $c = $this->c();
        $base = "envivo:$sala:" . $this->ronda($sala) . ":$pregunta";
        $antes = $c->get("$base:quien:$quien");

        if ($antes !== null && (int) $antes === $opcion) {
            return true;
        }

        $c->put("$base:quien:$quien", $opcion, self::DURA);
        // Cambiar de opinión mueve el voto: no suma dos.
        if ($antes !== null) {
            $c->decrement("$base:" . (int) $antes);
        }
        $c->add("$base:$opcion", 0, self::DURA);
        $c->increment("$base:$opcion");
        app(HistorialEnVivo::class)->programar($sala);

        return true;
    }

    public function reiniciarVotos(string $sala): void
    {
        $this->c()->put("envivo:$sala:ronda", $this->ronda($sala) + 1, self::DURA * 7);
    }

    /** @return array<string, list<int>> */
    public function votos(string $sala): array
    {
        $c = $this->c();
        $ronda = $this->ronda($sala);
        $votos = [];

        foreach ($this->preguntas($sala) as $p => $n) {
            for ($i = 0; $i < $n; $i++) {
                $votos[$p][] = max(0, (int) $c->get("envivo:$sala:$ronda:$p:$i", 0));
            }
        }

        return $votos;
    }

    /** @return array<string,int> lo que votó este navegador */
    private function mios(string $sala, string $quien): array
    {
        $c = $this->c();
        $ronda = $this->ronda($sala);
        $mios = [];

        foreach (array_keys($this->preguntas($sala)) as $p) {
            $v = $c->get("envivo:$sala:$ronda:$p:quien:$quien");
            if ($v !== null) {
                $mios[$p] = (int) $v;
            }
        }

        return $mios;
    }

    // ------------------------------------------------------------ conectados

    /**
     * Quién tiene la presentación abierta, por tramos de 10 segundos: cada
     * navegador pregunta cada pocos segundos y cuenta una vez por tramo.
     */
    private function anotarVivo(string $sala, string $quien): void
    {
        $c = $this->c();
        $tramo = intdiv(time(), 10);

        if ($c->add("envivo:$sala:vivo:$tramo:$quien", 1, 30)) {
            $c->add("envivo:$sala:vivos:$tramo", 0, 30);
            $c->increment("envivo:$sala:vivos:$tramo");
        }
    }

    public function vivos(string $sala): int
    {
        $tramo = intdiv(time(), 10);

        return max((int) $this->c()->get("envivo:$sala:vivos:$tramo", 0), (int) $this->c()->get("envivo:$sala:vivos:" . ($tramo - 1), 0));
    }

    // --------------------------------------------------------------- agenda

    /** @return array{inicio:?int,listos:array<string,int>,min:array<string,int>} */
    public function agenda(string $sala): array
    {
        return $this->c()->get("envivo:$sala:agenda") ?? ['inicio' => null, 'listos' => [], 'min' => []];
    }

    /**
     * La agenda la escribe solo el profesor, entera: cuándo empezó, qué
     * bloques cerró y cuántos minutos tiene cada uno.
     */
    public function guardarAgenda(string $sala, array $agenda): void
    {
        $id = fn ($k) => is_string($k) && preg_match('/^[a-z0-9-]{1,40}$/', $k);
        $listos = collect($agenda['listos'] ?? [])->filter(fn ($v, $k) => $id($k) && is_numeric($v))->map(fn ($v) => (int) $v)->take(40)->all();
        $min = collect($agenda['min'] ?? [])->filter(fn ($v, $k) => $id($k) && is_numeric($v))->map(fn ($v) => max(1, min(240, (int) $v)))->take(40)->all();
        $inicio = isset($agenda['inicio']) && is_numeric($agenda['inicio']) ? (int) $agenda['inicio'] : null;

        $this->c()->put("envivo:$sala:agenda", ['inicio' => $inicio, 'listos' => $listos, 'min' => $min], self::DURA);
        app(HistorialEnVivo::class)->programar($sala);
    }

    public function reiniciarAgenda(string $sala): void
    {
        $this->c()->forget("envivo:$sala:agenda");
        $this->c()->forget("envivo:$sala:seccion");
        $this->c()->forget("envivo:$sala:revelados");
    }

    /**
     * La sección que el profesor tiene en pantalla: los celulares que lo
     * siguen pasan a ella, y ahí votan.
     */
    public function guardarSeccion(string $sala, ?string $seccion): void
    {
        if ($seccion !== null && preg_match('/^[a-z0-9-]{1,40}$/', $seccion)) {
            $this->c()->put("envivo:$sala:seccion", $seccion, self::DURA);
        }
    }

    /** Lo que el profesor ya mostró (partes que empiezan tapadas). */
    public function revelar(string $sala, string $id): void
    {
        if (preg_match('/^[a-z0-9-]{1,40}$/', $id)) {
            $ya = $this->revelados($sala);
            $ya[] = $id;
            $this->c()->put("envivo:$sala:revelados", array_values(array_unique(array_slice($ya, -40))), self::DURA);
        }
    }

    /** @return list<string> */
    public function revelados(string $sala): array
    {
        return (array) $this->c()->get("envivo:$sala:revelados", []);
    }

    public function seccion(string $sala): ?string
    {
        return $this->c()->get("envivo:$sala:seccion");
    }

    // ----------------------------------------------------------------- muro

    /** Lo que se escribe en una tarjeta del muro, y cuánto cabe. */
    public const CAMPOS_MURO = ['nombre' => 60, 'trabajo' => 80, 'problema' => 280, 'aquien' => 80, 'cuanto' => 40, 'gustaria' => 280];

    public function tieneMuro(string $sala): bool
    {
        return (bool) config("fabos.envivo.salas.$sala.muro", false);
    }

    /**
     * Publica (o reemplaza) la tarjeta de este navegador. Se escribe con
     * candado: treinta personas publicando a la vez no se pisan.
     */
    public function aportar(string $sala, string $quien, array $campos): bool
    {
        $tarjeta = [];
        foreach (self::CAMPOS_MURO as $campo => $max) {
            $tarjeta[$campo] = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($campos[$campo] ?? ''))), 0, $max);
        }
        if ($tarjeta['problema'] === '') {
            return false;
        }

        return $this->conCandado($sala, function (array $muro) use ($quien, $tarjeta) {
            $id = substr(hash('sha256', $quien), 0, 12);
            $muro[$id] = $tarjeta + ['id' => $id, 't' => $muro[$id]['t'] ?? (int) floor(microtime(true) * 1000), 'quien' => $quien];

            return count($muro) > 80 ? null : $muro;
        });
    }

    public function quitarDelMuro(string $sala, string $id): void
    {
        $this->conCandado($sala, function (array $muro) use ($id) {
            unset($muro[$id]);

            return $muro;
        });
    }

    public function vaciarMuro(string $sala): void
    {
        $this->conCandado($sala, fn () => []);
        $this->c()->forget("envivo:$sala:destacado");
    }

    public function destacar(string $sala, ?string $id): void
    {
        $id && preg_match('/^[a-f0-9]{12}$/', $id)
            ? $this->c()->put("envivo:$sala:destacado", $id, self::DURA)
            : $this->c()->forget("envivo:$sala:destacado");
        $this->c()->add("envivo:$sala:muro:version", 0, self::DURA * 7);
        $this->c()->increment("envivo:$sala:muro:version");
    }

    /** @return array{version:int, tarjetas:list<array>, destacado:?string} */
    public function muro(string $sala): array
    {
        $tarjetas = collect($this->c()->get("envivo:$sala:muro", []))
            ->sortBy('t')
            ->map(fn ($t) => collect($t)->except('quien')->all())
            ->values()->all();

        return ['version' => $this->versionMuro($sala), 'tarjetas' => $tarjetas, 'destacado' => $this->c()->get("envivo:$sala:destacado")];
    }

    private function versionMuro(string $sala): int
    {
        return (int) $this->c()->get("envivo:$sala:muro:version", 0);
    }

    private function miTarjeta(string $sala, string $quien): ?array
    {
        $t = $this->c()->get("envivo:$sala:muro", [])[substr(hash('sha256', $quien), 0, 12)] ?? null;

        return $t ? collect($t)->except('quien')->all() : null;
    }

    /** @param callable(array):?array $cambio  devuelve el muro nuevo, o null si no se acepta */
    private function conCandado(string $sala, callable $cambio): bool
    {
        return (bool) $this->c()->lock("envivo:$sala:muro:candado", 5)->block(4, function () use ($sala, $cambio) {
            $nuevo = $cambio($this->c()->get("envivo:$sala:muro", []));
            if ($nuevo === null) {
                return false;
            }
            $this->c()->put("envivo:$sala:muro", $nuevo, self::DURA);
            $this->c()->add("envivo:$sala:muro:version", 0, self::DURA * 7);
            $this->c()->increment("envivo:$sala:muro:version");
            app(HistorialEnVivo::class)->programar($sala);

            return true;
        });
    }

    // ------------------------------------------------ quién es (ID del curso)

    /** Dónde se guarda el LinkedIn del profesor (se edita en el panel). */
    public const LINKEDIN = 'envivo.linkedin';

    /** El curso al que pertenece la sala (el ID sirve en todas sus salas), o null si no pide nombre. */
    public function grupo(string $sala): ?string
    {
        $g = config("fabos.envivo.salas.$sala.registro");

        return is_string($g) && $g !== '' ? $g : null;
    }

    /**
     * Quien escribe su nombre recibe un ID. El mismo navegador conserva el
     * suyo mientras dure la sesión: volver a escribir el nombre lo corrige.
     */
    public function registrar(string $sala, string $quien, string $nombre): ?ParticipanteEnVivo
    {
        $grupo = $this->grupo($sala);
        $nombre = mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags($nombre))), 0, 80);
        if (! $grupo || mb_strlen($nombre) < 2) {
            return null;
        }

        $ya = $this->participanteDe($sala, $quien);
        if ($ya) {
            $ya->update(['nombre' => $nombre]);

            return $ya;
        }

        // Un tope por día, por si alguien insiste con un programa.
        $tope = "envivo:$sala:registros:" . now()->format('Ymd');
        $this->c()->add($tope, 0, self::DURA);
        if ($this->c()->increment($tope) > 400) {
            return null;
        }

        $p = ParticipanteEnVivo::nuevo($grupo, $sala, $nombre);
        $this->c()->put("envivo:$sala:soy:$quien", $p->codigo, self::DURA);

        return $p;
    }

    /** Quien ya tiene su ID lo escribe y vuelve a ser él (otro día, otro navegador). */
    public function recuperar(string $sala, string $quien, ?string $codigo): ?ParticipanteEnVivo
    {
        $codigo = ParticipanteEnVivo::limpiarCodigo($codigo);
        $grupo = $this->grupo($sala);
        $p = $codigo && $grupo ? ParticipanteEnVivo::where('grupo', $grupo)->where('codigo', $codigo)->first() : null;
        if ($p) {
            $this->c()->put("envivo:$sala:soy:$quien", $p->codigo, self::DURA);
        }

        return $p;
    }

    public function participanteDe(string $sala, ?string $quien): ?ParticipanteEnVivo
    {
        $codigo = $quien ? $this->c()->get("envivo:$sala:soy:$quien") : null;

        return $codigo ? ParticipanteEnVivo::where('codigo', $codigo)->first() : null;
    }

    // ---------------------------------------------------------------- estado

    /**
     * @param  ?int  $versionMuro  la que ya tiene el navegador: si es la misma,
     *                             el muro no viaja (cada pocos segundos, treinta veces).
     */
    public function estado(string $sala, ?string $quien, ?string $clave = null, ?int $versionMuro = null, ?int $versionTweets = null): array
    {
        if ($quien) {
            $this->anotarVivo($sala, $quien);
        }

        $muro = null;
        if ($this->tieneMuro($sala) && $versionMuro !== $this->versionMuro($sala)) {
            $muro = $this->muro($sala) + ['mia' => $quien ? $this->miTarjeta($sala, $quien) : null];
        }

        // Los tweets, como el muro: solo viajan cuando cambian.
        $tweets = null;
        $muroTweets = app(TweetsEnVivo::class);
        if ($muroTweets->activo($sala) && $versionTweets !== $muroTweets->version($sala)) {
            $tweets = $muroTweets->muro($sala, $quien);
        }

        return [
            'muro' => $muro,
            'tweets' => $tweets,
            'ronda' => $this->ronda($sala),
            'votos' => $this->votos($sala),
            'mios' => $quien ? $this->mios($sala, $quien) : [],
            'vivos' => $this->vivos($sala),
            'agenda' => $this->agenda($sala),
            'seccion' => $this->seccion($sala),
            'revelados' => $this->revelados($sala),
            'profe' => $this->esProfe($sala, $clave),
            'registro' => $this->grupo($sala) !== null,
            'linkedin' => $this->grupo($sala) !== null ? (Setting::get(self::LINKEDIN) ?: null) : null,
            'ahora' => (int) floor(microtime(true) * 1000),
        ];
    }
}
