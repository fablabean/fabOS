<?php

namespace App\Services\Analitica;

use App\Models\Pagina;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Las cifras de una página del sitio, para entregárselas a quien la pidió (§20).
 *
 * Una página que se hace para un cliente interno —una vicerrectoría, una
 * facultad— casi siempre existe para llevar gente a un formulario. Lo que el
 * cliente quiere saber es eso: cuántos la vieron, cuántos pulsaron cada botón
 * y desde dónde llegaron.
 *
 * Todo sale de la analítica propia, sin cookies: «personas» son visitantes
 * distintos del día —la huella cambia cada día—, así que sobre un periodo es
 * una cifra aproximada por arriba, y se dice así en el informe.
 */
class MetricasDePagina
{
    public const PERIODOS = [7 => 'Últimos 7 días', 30 => 'Últimos 30 días', 90 => 'Últimos 90 días', 365 => 'Último año'];

    /** @return array<string,mixed> */
    public function calcular(Pagina $pagina, CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $ruta = '/p/' . $pagina->slug;
        $dias = [$desde->toDateString(), $hasta->toDateString()];

        $visitas = DB::table('analitica_visitas')->where('ruta', $ruta)->whereBetween('dia', $dias);
        $clics = DB::table('analitica_eventos')->where('ruta', $ruta)->where('tipo', 'clic')->whereBetween('dia', $dias);

        // Personas: visitante distinto por día, sumado.
        $personas = (int) (clone $visitas)->selectRaw('count(distinct (dia, visitante)) as n')->value('n');
        $quienesPulsaron = (int) (clone $clics)->whereNotNull('visitante')->selectRaw('count(distinct (dia, visitante)) as n')->value('n');

        $porBoton = (clone $clics)
            ->selectRaw("detalle->>'texto' as texto, detalle->>'destino' as destino, count(*) as clics, count(distinct (dia, visitante)) as personas")
            ->groupByRaw("detalle->>'texto', detalle->>'destino'")
            ->orderByDesc('clics')
            ->get()
            ->map(fn ($f) => [
                'texto'    => $f->texto ?: '(sin texto)',
                'destino'  => $f->destino ?: '',
                'clics'    => (int) $f->clics,
                'personas' => (int) $f->personas,
                'tasa'     => $personas > 0 ? round($f->personas / $personas * 100, 1) : 0.0,
            ])
            ->all();

        $visitasPorDia = (clone $visitas)->selectRaw('dia, count(*) as visitas, count(distinct visitante) as personas')
            ->groupBy('dia')->pluck('personas', 'dia');
        $clicsPorDia = (clone $clics)->selectRaw('dia, count(*) as n')->groupBy('dia')->pluck('n', 'dia');

        $porDia = [];
        for ($d = $desde->copy()->startOfDay(); $d->lte($hasta); $d->addDay()) {
            $k = $d->toDateString();
            $porDia[] = ['dia' => $k, 'personas' => (int) ($visitasPorDia[$k] ?? 0), 'clics' => (int) ($clicsPorDia[$k] ?? 0)];
        }

        $reparto = fn (string $campo) => (clone $visitas)->selectRaw("$campo as clave, count(distinct (dia, visitante)) as n")
            ->groupBy($campo)->orderByDesc('n')->limit(8)->pluck('n', 'clave')->all();

        return [
            'pagina'         => $pagina,
            'desde'          => $desde,
            'hasta'          => $hasta,
            'vistas'         => (clone $visitas)->count(),
            'personas'       => $personas,
            'clics'          => (clone $clics)->count(),
            'pulsaron'       => $quienesPulsaron,
            'tasa'           => $personas > 0 ? round($quienesPulsaron / $personas * 100, 1) : 0.0,
            'botones'        => $porBoton,
            'porDia'         => $porDia,
            'canales'        => $reparto('canal'),
            'fuentes'        => $reparto('fuente'),
            'dispositivos'   => $reparto('dispositivo'),
        ];
    }

    /** Los canales dichos para alguien que no es del equipo. */
    public const CANALES = [
        'directo' => 'Directo (enlace pegado, QR, favoritos)', 'buscador' => 'Buscadores', 'redes' => 'Redes sociales',
        'ia' => 'Asistentes de IA', 'enlace' => 'Otros sitios', 'campaña' => 'Campañas', 'interno' => 'Desde el mismo sitio',
    ];
}
