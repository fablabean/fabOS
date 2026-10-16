<?php

namespace App\Http\Controllers;

use App\Models\Pagina;
use App\Services\Analitica\MetricasDePagina;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** El informe de una página del sitio, en pantalla y en PDF para el cliente (§20). */
class MetricasDePaginaController extends Controller
{
    public function __construct(private MetricasDePagina $metricas) {}

    public function ver(Request $request, Pagina $pagina)
    {
        return view('paginas.metricas', $this->datos($request, $pagina) + ['paraPdf' => false]);
    }

    public function pdf(Request $request, Pagina $pagina)
    {
        $datos = $this->datos($request, $pagina) + ['paraPdf' => true, 'logo' => Settings::logoParaPdf()];
        $archivo = 'metricas-' . $pagina->slug . '-' . $datos['hasta']->format('Y-m-d') . '.pdf';

        $pdf = Pdf::loadView('paginas.metricas', $datos)->setPaper('letter');

        return response()->streamDownload(fn () => print($pdf->output()), $archivo, ['Content-Type' => 'application/pdf']);
    }

    /** @return array<string,mixed> */
    private function datos(Request $request, Pagina $pagina): array
    {
        abort_unless($request->user()?->can('update', $pagina), 403);

        $tz = config('fabos.lab.timezone');
        $dias = (int) $request->query('dias', 30);
        $dias = array_key_exists($dias, MetricasDePagina::PERIODOS) ? $dias : 30;

        // O un rango a mano, para cuadrar con la campaña del cliente.
        try {
            $desde = $request->filled('desde') ? Carbon::parse($request->query('desde'), $tz)->startOfDay() : null;
            $hasta = $request->filled('hasta') ? Carbon::parse($request->query('hasta'), $tz)->endOfDay() : null;
        } catch (\Throwable) {
            $desde = $hasta = null;
        }

        $hasta ??= now($tz)->endOfDay();
        $desde ??= $hasta->copy()->subDays($dias - 1)->startOfDay();

        if ($desde->gt($hasta) || $desde->diffInDays($hasta) > 400) {
            $desde = $hasta->copy()->subDays(29)->startOfDay();
        }

        return $this->metricas->calcular($pagina, $desde, $hasta) + [
            'dias'     => $dias,
            'consulta' => array_filter(['dias' => $request->query('dias'), 'desde' => $request->query('desde'), 'hasta' => $request->query('hasta')]),
            'fecha'    => Str::ucfirst(now($tz)->translatedFormat('j \d\e F \d\e Y')),
        ];
    }
}
