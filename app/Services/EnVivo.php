<?php

namespace App\Services;

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
    }

    public function reiniciarAgenda(string $sala): void
    {
        $this->c()->forget("envivo:$sala:agenda");
    }

    // ---------------------------------------------------------------- estado

    public function estado(string $sala, ?string $quien, ?string $clave = null): array
    {
        if ($quien) {
            $this->anotarVivo($sala, $quien);
        }

        return [
            'ronda' => $this->ronda($sala),
            'votos' => $this->votos($sala),
            'mios' => $quien ? $this->mios($sala, $quien) : [],
            'vivos' => $this->vivos($sala),
            'agenda' => $this->agenda($sala),
            'profe' => $this->esProfe($sala, $clave),
            'ahora' => (int) floor(microtime(true) * 1000),
        ];
    }
}
