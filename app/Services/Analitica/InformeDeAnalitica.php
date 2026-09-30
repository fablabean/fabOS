<?php

namespace App\Services\Analitica;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lo que se lee en el tablero de analítica (§20).
 *
 * Una advertencia que va también en la documentación: el visitante es por
 * día. La huella cambia cada medianoche a propósito —es lo que la hace
 * anónima—, así que quien vuelve mañana cuenta otra vez. «Visitantes» en un
 * mes es la suma de los de cada día, no personas distintas en el mes.
 */
class InformeDeAnalitica
{
    public function __construct(private Carbon $desde, private Carbon $hasta) {}

    public static function ultimosDias(int $dias): self
    {
        $hoy = now(config('fabos.lab.timezone'))->startOfDay();

        return new self($hoy->copy()->subDays($dias - 1), $hoy);
    }

    /** @return array{visitantes:int, vistas:int, conversiones:int, desde_ia:int, rastreos_ia:int, por_visitante:float} */
    public function cifras(): array
    {
        $visitantes = (int) $this->visitas()->selectRaw('count(distinct (dia, visitante)) as n')->value('n');
        $vistas = (int) $this->visitas()->count();

        return [
            'visitantes'    => $visitantes,
            'vistas'        => $vistas,
            'conversiones'  => (int) $this->eventos()->where('origen', 'servidor')->count(),
            'desde_ia'      => (int) $this->visitas()->where('canal', 'ia')->count(),
            'rastreos_ia'   => (int) $this->rastreos()->where('familia', 'ia')->count(),
            'por_visitante' => $visitantes ? round($vistas / $visitantes, 1) : 0,
        ];
    }

    /** @return Collection<int, object{dia:string, visitantes:int, vistas:int}> todos los días del periodo, también los vacíos */
    public function porDia(): Collection
    {
        $datos = $this->visitas()
            ->selectRaw('dia, count(distinct visitante) as visitantes, count(*) as vistas')
            ->groupBy('dia')
            ->get()
            ->keyBy(fn ($f) => Carbon::parse($f->dia)->toDateString());

        $dias = collect();

        for ($d = $this->desde->copy(); $d->lte($this->hasta); $d->addDay()) {
            $f = $datos->get($d->toDateString());
            $dias->push((object) [
                'dia'        => $d->toDateString(),
                'visitantes' => (int) ($f->visitantes ?? 0),
                'vistas'     => (int) ($f->vistas ?? 0),
            ]);
        }

        return $dias;
    }

    public function paginas(int $cuantas = 15): Collection
    {
        return $this->visitas()
            ->selectRaw('ruta, count(*) as vistas, count(distinct (dia, visitante)) as visitantes')
            ->groupBy('ruta')
            ->orderByDesc('vistas')
            ->limit($cuantas)
            ->get();
    }

    /** Por dónde entra la gente: la primera página que ve, viniendo de fuera. */
    public function entradas(int $cuantas = 10): Collection
    {
        return $this->visitas()
            ->where('canal', '!=', 'interno')
            ->selectRaw('ruta, count(*) as entradas')
            ->groupBy('ruta')
            ->orderByDesc('entradas')
            ->limit($cuantas)
            ->get();
    }

    public function canales(): Collection
    {
        return $this->visitas()
            ->where('canal', '!=', 'interno')
            ->selectRaw('canal, count(*) as entradas')
            ->groupBy('canal')
            ->orderByDesc('entradas')
            ->get();
    }

    public function fuentes(int $cuantas = 15): Collection
    {
        return $this->visitas()
            ->where('canal', '!=', 'interno')
            ->selectRaw('fuente, canal, count(*) as entradas')
            ->groupBy('fuente', 'canal')
            ->orderByDesc('entradas')
            ->limit($cuantas)
            ->get();
    }

    public function campanas(): Collection
    {
        return $this->visitas()
            ->whereNotNull('utm_campaign')
            ->selectRaw('utm_campaign, utm_source, count(*) as entradas')
            ->groupBy('utm_campaign', 'utm_source')
            ->orderByDesc('entradas')
            ->limit(15)
            ->get();
    }

    public function dispositivos(): Collection
    {
        return $this->visitas()
            ->selectRaw('dispositivo, count(distinct (dia, visitante)) as visitantes')
            ->groupBy('dispositivo')
            ->orderByDesc('visitantes')
            ->get();
    }

    /** Los pasos más comunes de una página a otra dentro del sitio. */
    public function recorridos(int $cuantos = 15): Collection
    {
        return $this->visitas()
            ->whereNotNull('desde')
            ->whereColumn('desde', '!=', 'ruta')
            ->selectRaw('desde, ruta, count(*) as veces')
            ->groupBy('desde', 'ruta')
            ->orderByDesc('veces')
            ->limit($cuantos)
            ->get();
    }

    /**
     * El embudo de cada actividad: la vieron → empezaron el formulario →
     * se inscribieron (o quedaron en lista de espera).
     */
    public function embudos(): Collection
    {
        $vieron = $this->visitas()
            ->where('ruta', 'like', '/actividad/%')
            ->where('ruta', 'not like', '/actividad/%/%')
            ->selectRaw('ruta, count(distinct (dia, visitante)) as n')
            ->groupBy('ruta')
            ->pluck('n', 'ruta');

        $eventos = $this->eventos()
            ->where('ruta', 'like', '/actividad/%')
            ->selectRaw('ruta, tipo, count(distinct (dia, coalesce(visitante, id::text))) as n')
            ->groupBy('ruta', 'tipo')
            ->get()
            ->groupBy('ruta');

        $codigos = $vieron->keys()->merge($eventos->keys())->unique()
            ->map(fn ($r) => substr($r, strlen('/actividad/')));

        $nombres = \App\Models\CourseEdition::whereIn('code', $codigos)->with('course')->get()->keyBy('code');

        return $vieron->keys()->merge($eventos->keys())->unique()
            ->map(function (string $ruta) use ($vieron, $eventos, $nombres) {
                $porTipo = ($eventos->get($ruta) ?? collect())->pluck('n', 'tipo');
                $codigo = substr($ruta, strlen('/actividad/'));

                return (object) [
                    'ruta'        => $ruta,
                    'nombre'      => $nombres->get($codigo)?->nombre() ?? $codigo,
                    'vieron'      => (int) ($vieron[$ruta] ?? 0),
                    'formulario'  => (int) ($porTipo['formulario'] ?? 0),
                    'inscritos'   => (int) ($porTipo['inscripcion'] ?? 0),
                    'en_espera'   => (int) ($porTipo['lista_espera'] ?? 0),
                ];
            })
            ->sortByDesc('vieron')
            ->values();
    }

    /** Las conversiones por tipo y por el canal por el que había llegado esa persona ese día. */
    public function conversiones(): Collection
    {
        return DB::table('analitica_eventos as e')
            ->where('e.origen', 'servidor')
            ->whereBetween('e.dia', [$this->desde->toDateString(), $this->hasta->toDateString()])
            ->selectRaw("e.tipo, coalesce((
                select v.canal from analitica_visitas v
                where v.dia = e.dia and v.visitante = e.visitante and v.canal <> 'interno'
                order by v.id limit 1
            ), 'sin registro') as canal, count(*) as n")
            ->groupBy('e.tipo', 'canal')
            ->orderByDesc('n')
            ->get();
    }

    public function rastreadores(): Collection
    {
        return $this->rastreos()
            ->selectRaw('bot, familia, count(*) as visitas, max(created_at) as ultima')
            ->groupBy('bot', 'familia')
            ->orderByDesc('visitas')
            ->get();
    }

    public function desde(): Carbon
    {
        return $this->desde;
    }

    public function hasta(): Carbon
    {
        return $this->hasta;
    }

    // ------------------------------------------------------------ por dentro

    private function visitas()
    {
        return DB::table('analitica_visitas')->whereBetween('dia', [$this->desde->toDateString(), $this->hasta->toDateString()]);
    }

    private function eventos()
    {
        return DB::table('analitica_eventos')->whereBetween('dia', [$this->desde->toDateString(), $this->hasta->toDateString()]);
    }

    private function rastreos()
    {
        return DB::table('analitica_rastreos')->whereBetween('dia', [$this->desde->toDateString(), $this->hasta->toDateString()]);
    }
}
