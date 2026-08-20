<?php

namespace App\Services\Reports;

use App\Models\Asset;
use App\Models\Budget;
use App\Models\Certifab;
use App\Models\LedgerEntry;
use App\Models\Reservation;
use App\Models\Sale;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El informe de cierre que recibe la Universidad (§17).
 *
 * Dos criterios de fondo:
 *
 *  - **Se mide el uso real, no el reservado.** Las horas cuentan desde la
 *    llegada hasta la salida, no desde el bloque que alguien apartó. Un informe
 *    que sumara lo reservado haría ver un laboratorio lleno aunque nadie
 *    hubiera venido.
 *  - **Los números salen de los mismos datos que operan el laboratorio.** No
 *    hay una tabla de estadísticas que alguien tenga que alimentar: si el
 *    informe se desvía de la realidad, es que la realidad cambió.
 */
class ReportService
{
    public function generar(Carbon $desde, Carbon $hasta): PeriodReport
    {
        $desde = $desde->copy()->utc();
        $hasta = $hasta->copy()->utc();

        return new PeriodReport(
            desde: $desde,
            hasta: $hasta,
            uso: $this->uso($desde, $hasta),
            personas: $this->personas($desde, $hasta),
            formacion: $this->formacion($desde, $hasta),
            mantenimiento: $this->mantenimiento($desde, $hasta),
            finanzas: $this->finanzas($desde, $hasta),
            compras: $this->compras(),
            porArea: $this->porArea($desde, $hasta),
            equiposMasUsados: $this->equiposMasUsados($desde, $hasta),
        );
    }

    /** El mes calendario que contiene una fecha, en hora del laboratorio. */
    public function mesDe(Carbon $fecha): array
    {
        $tz = config('fabos.lab.timezone');
        $inicio = $fecha->copy()->timezone($tz)->startOfMonth();

        return [$inicio, $inicio->copy()->endOfMonth()];
    }

    /** @return array<string,mixed> */
    private function uso(Carbon $desde, Carbon $hasta): array
    {
        $reservas = $this->reservasDelPeriodo($desde, $hasta)->get();

        $completadas = $reservas->where('status', 'completada');

        return [
            'reservas'           => $reservas->count(),
            'completadas'        => $completadas->count(),
            'no_show'            => $reservas->where('status', 'no_show')->count(),
            'canceladas'         => $reservas->where('status', 'cancelada')->count(),
            'minutos_reservados' => (int) $reservas
                ->whereIn('status', ['completada', 'no_show'])
                ->sum(fn (Reservation $r) => $r->starts_at->diffInMinutes($r->ends_at)),
            'minutos_usados'     => (int) $completadas->sum(fn (Reservation $r) => $this->minutosReales($r)),
            'con_acompanante'    => $reservas->whereNotNull('supervisor_id')->count(),
        ];
    }

    /** @return array<string,mixed> */
    private function personas(Carbon $desde, Carbon $hasta): array
    {
        $reservaron = $this->reservasDelPeriodo($desde, $hasta)
            ->where('status', 'completada')
            ->distinct()
            ->pluck('user_id');

        $porCategoria = User::query()
            ->whereIn('users.id', $reservaron)
            ->leftJoin('user_categories', 'user_categories.id', '=', 'users.user_category_id')
            ->selectRaw("COALESCE(user_categories.name, 'Sin categoría') AS categoria, COUNT(*) AS total")
            ->groupBy('categoria')
            ->pluck('total', 'categoria');

        return [
            'atendidas'     => $reservaron->count(),
            'nuevas'        => User::whereBetween('created_at', [$desde, $hasta])->count(),
            'por_categoria' => $porCategoria,
        ];
    }

    /** @return array<string,mixed> */
    private function formacion(Carbon $desde, Carbon $hasta): array
    {
        $otorgados = Certifab::whereBetween('granted_at', [$desde, $hasta])->get();

        return [
            'certifabs'   => $otorgados->count(),
            'personas'    => $otorgados->pluck('user_id')->unique()->count(),
            'por_nivel'   => $otorgados->groupBy('level')->map->count(),
        ];
    }

    /** @return array<string,mixed> */
    private function mantenimiento(Carbon $desde, Carbon $hasta): array
    {
        $abiertas = WorkOrder::whereBetween('created_at', [$desde, $hasta])->get();
        $cerradas = WorkOrder::whereBetween('closed_at', [$desde, $hasta])->get();

        return [
            'abiertas'     => $abiertas->count(),
            'correctivas'  => $abiertas->where('kind', 'correctivo')->count(),
            'preventivas'  => $abiertas->where('kind', 'preventivo')->count(),
            'cerradas'     => $cerradas->count(),
            'minutos_paro' => (int) $cerradas->sum(fn (WorkOrder $o) => $o->minutosDeParo() ?? 0),
            'sin_resolver' => WorkOrder::whereIn('status', WorkOrder::ABIERTAS)->count(),
        ];
    }

    /**
     * Movimiento de FabCoins del periodo.
     *
     * Se lee de los asientos y no de un acumulado: es la misma fuente con la
     * que se calculan los saldos, así que el informe no puede contradecirlos.
     *
     * @return array<string,mixed>
     */
    private function finanzas(Carbon $desde, Carbon $hasta): array
    {
        $porTipo = fn (string $tipo, string $codigoCuenta, string $direccion) => (int) LedgerEntry::query()
            ->join('ledger_transactions as t', 't.id', '=', 'ledger_entries.ledger_transaction_id')
            ->join('ledger_accounts as a', 'a.id', '=', 'ledger_entries.ledger_account_id')
            ->where('t.kind', $tipo)
            ->where('a.code', $codigoCuenta)
            ->where('ledger_entries.direction', $direccion)
            ->whereBetween('t.occurred_at', [$desde, $hasta])
            ->sum('ledger_entries.amount_minor');

        $ventas = Sale::where('status', 'pagada')
            ->whereBetween('paid_at', [$desde, $hasta])
            ->get();

        return [
            'emitido'   => $porTipo('dotacion', 'sistema:emision', 'D')
                + $porTipo('bonificacion', 'sistema:emision', 'D')
                + $porTipo('recarga', 'sistema:emision', 'D'),
            'causado'   => $porTipo('liquidacion', 'sistema:ingreso', 'C'),
            'ventas'    => (int) $ventas->sum('total_minor'),
            'n_ventas'  => $ventas->count(),
            'retenido'  => (int) DB::table('ledger_entries as e')
                ->join('ledger_accounts as a', 'a.id', '=', 'e.ledger_account_id')
                ->where('a.code', 'sistema:garantias')
                ->selectRaw("COALESCE(SUM(CASE WHEN e.direction = 'C' THEN e.amount_minor ELSE -e.amount_minor END), 0) AS s")
                ->value('s'),
        ];
    }

    /** @return array<string,mixed> */
    private function compras(): array
    {
        $presupuestos = Budget::where('status', 'vigente')->get();

        return [
            'presupuestos' => $presupuestos,
            'aprobado'     => (int) $presupuestos->sum('amount'),
            'comprometido' => (int) $presupuestos->sum(fn (Budget $b) => $b->comprometido()),
            'ejecutado'    => (int) $presupuestos->sum(fn (Budget $b) => $b->ejecutado()),
        ];
    }

    /** Horas de uso real por área: dónde se concentra la actividad. */
    private function porArea(Carbon $desde, Carbon $hasta): Collection
    {
        $reservas = $this->reservasDelPeriodo($desde, $hasta)
            ->where('status', 'completada')
            ->get();

        $equipos = Asset::with('area')->whereIn('id', $reservas->pluck('reservable_id'))->get()->keyBy('id');

        return $reservas
            ->groupBy(fn (Reservation $r) => $equipos[$r->reservable_id]?->area?->name ?? 'Sin área')
            ->map(fn (Collection $grupo) => [
                'reservas' => $grupo->count(),
                'minutos'  => (int) $grupo->sum(fn (Reservation $r) => $this->minutosReales($r)),
                'personas' => $grupo->pluck('user_id')->unique()->count(),
            ])
            ->sortByDesc('minutos');
    }

    private function equiposMasUsados(Carbon $desde, Carbon $hasta, int $tope = 10): Collection
    {
        $reservas = $this->reservasDelPeriodo($desde, $hasta)
            ->where('status', 'completada')
            ->get();

        $equipos = Asset::whereIn('id', $reservas->pluck('reservable_id'))->get()->keyBy('id');

        return $reservas
            ->groupBy('reservable_id')
            ->map(fn (Collection $grupo, $id) => [
                'nombre'   => $equipos[$id]?->name ?? 'Equipo eliminado',
                'reservas' => $grupo->count(),
                'minutos'  => (int) $grupo->sum(fn (Reservation $r) => $this->minutosReales($r)),
            ])
            ->sortByDesc('minutos')
            ->take($tope)
            ->values();
    }

    private function reservasDelPeriodo(Carbon $desde, Carbon $hasta)
    {
        // Solo reservas de equipos: el bloque del acompañante es la sombra de
        // otra reserva y contarlo duplicaría las horas.
        return Reservation::query()
            ->where('reservable_type', Asset::class)
            ->whereBetween('starts_at', [$desde, $hasta]);
    }

    private function minutosReales(Reservation $r): int
    {
        if ($r->checked_in_at && $r->checked_out_at) {
            return (int) $r->checked_in_at->diffInMinutes($r->checked_out_at);
        }

        // Sin marcas de reloj se cuenta lo reservado: es lo único que se sabe,
        // y omitirlo haría desaparecer horas que sí ocuparon el equipo.
        return (int) $r->starts_at->diffInMinutes($r->ends_at);
    }
}
