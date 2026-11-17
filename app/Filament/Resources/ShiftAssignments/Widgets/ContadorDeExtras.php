<?php

namespace App\Filament\Resources\ShiftAssignments\Widgets;

use App\Models\ShiftAssignment;
use App\Models\User;
use App\Services\Staffing\OvertimeService;
use Filament\Widgets\Widget;
use Illuminate\Support\Carbon;

/**
 * El contador de horas extras del periodo, encima de las jornadas (§5).
 *
 * El periodo va de un corte de nómina al siguiente (ver CorteDeNomina), que es como
 * lo liquida la nómina. Por persona dice cuánto lleva en el corte, en la
 * semana y hoy, contra los tres topes —2 h al día, 12 a la semana, 48 en el
 * periodo—, y marca lo que ya se pasó. El control sigue siendo preventivo (no
 * deja programar por encima); esto es para verlo y para liquidar.
 */
class ContadorDeExtras extends Widget
{
    protected string $view = 'filament.jornadas.contador-de-extras';

    protected int | string | array $columnSpan = 'full';

    protected static bool $isLazy = false;

    /** Un día cualquiera del periodo que se mira; nulo es el actual. */
    public ?string $dia = null;

    public function irA(string $dia): void
    {
        $this->dia = $dia;
    }

    protected function getViewData(): array
    {
        $tz = config('fabos.lab.timezone');
        $fecha = $this->dia ? Carbon::parse($this->dia, $tz)->setTime(12, 0) : now($tz);
        [$desde, $hasta] = OvertimeService::periodoDe($fecha);

        // Quien tiene jornada en el laboratorio, y quien tuvo extras en el
        // corte aunque ya no tenga jornada: lo que se hizo hay que pagarlo.
        $conExtras = ShiftAssignment::query()
            ->where('counts_as_overtime', true)
            ->whereBetween('starts_at', [$desde->copy()->utc(), $hasta->copy()->utc()])
            ->distinct()
            ->pluck('user_id');

        $personas = User::query()
            ->where(fn ($q) => $q
                ->whereIn('id', $conExtras)
                ->orWhere(fn ($e) => $e->where('status', 'activo')->whereHas('workSchedules')))
            ->orderBy('name')
            ->get();

        // En un corte pasado, «hoy» y «esta semana» son los de su último día.
        $referencia = $hasta->isPast() ? $hasta->copy()->setTime(12, 0) : $fecha;

        /*
         * Para nómina: lo hecho que cada quien todavía no ha reportado, y lo
         * que reportó con este corte. Lo reporta cada persona desde «Mis
         * horas extras»; aquí se ve quién falta.
         */
        $pendientes = ShiftAssignment::query()->pendientesDeReporte()
            ->where('starts_at', '<=', $hasta->copy()->utc())
            ->get()->groupBy('user_id')->map(fn ($g) => $g->sum(fn (ShiftAssignment $s) => $s->minutos()));
        $reportadas = \App\Models\ReporteDeExtras::query()->vigentes()
            ->whereDate('corte', $hasta->toDateString())
            ->get()->groupBy('user_id')->map(fn ($g) => (int) $g->sum('minutos'));

        $filas = app(OvertimeService::class)->resumen($personas, $referencia)
            ->map(fn (array $f) => $f + [
                'por_reportar' => (int) ($pendientes[$f['persona']->id] ?? 0),
                'reportado'    => (int) ($reportadas[$f['persona']->id] ?? 0),
                'flexible'     => $f['persona']->tieneContratoFlexible(),
            ]);

        return [
            'filas'      => $filas,
            'desde'      => $desde,
            'hasta'      => $hasta,
            'esActual'   => ! $hasta->isPast() && ! $desde->isFuture(),
            'anterior'   => $desde->copy()->subDay()->toDateString(),
            'siguiente'  => $hasta->copy()->addDay()->toDateString(),
            'topeDia'    => (int) config('fabos.overtime.max_dia_minutos'),
            'topeSemana' => (int) config('fabos.overtime.max_semana_minutos'),
            'topeMes'    => (int) config('fabos.overtime.max_mes_minutos'),
        ];
    }
}
