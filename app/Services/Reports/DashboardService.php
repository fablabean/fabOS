<?php

namespace App\Services\Reports;

use App\Models\Asset;
use App\Models\Budget;
use App\Models\ProductionJob;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\Reservation;
use App\Models\Supply;
use App\Models\WorkOrder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * El tablero de indicadores (§17).
 *
 * Distinto del informe de cierre: aquel mira un periodo cerrado y se le entrega
 * a la Universidad; este mira **ahora** y sirve para trabajar. La diferencia
 * práctica es que aquí no hay cifras bonitas sino cosas que exigen que alguien
 * haga algo hoy: un equipo detenido, un insumo agotado, una compra esperando
 * firma, un encargo vencido.
 *
 * Todo se calcula al abrir. Un tablero que dependiera de un proceso nocturno
 * mostraría el laboratorio de ayer, que es justo lo que no sirve.
 */
class DashboardService
{
    /** @return array<string,mixed> */
    public function ahora(): array
    {
        $tz = config('fabos.lab.timezone');
        $hoy = Carbon::now($tz);

        // A UTC antes de comparar. «Hoy» es un día de Bogotá, pero la columna
        // guarda instantes: sin convertir, entre las 19:00 y la medianoche la
        // consulta preguntaría por el día equivocado. Es la trampa número uno
        // de este proyecto y vuelve a aparecer en cada consulta nueva.
        $reservasHoy = Reservation::query()
            ->where('reservable_type', Asset::class)
            ->whereBetween('starts_at', [
                $hoy->copy()->startOfDay()->utc(),
                $hoy->copy()->endOfDay()->utc(),
            ])
            ->get();

        return [
            'equipos_total'    => Asset::where('is_reservable', true)->count(),
            'en_mantenimiento' => Asset::where('status', 'mantenimiento')->count(),
            'en_uso'           => Reservation::where('status', 'en_curso')->count(),
            'reservas_hoy'     => $reservasHoy->count(),
            'pendientes_hoy'   => $reservasHoy->where('status', 'confirmada')->count(),
            'personas_hoy'     => $reservasHoy->pluck('user_id')->unique()->count(),
        ];
    }

    /**
     * Lo que exige que alguien haga algo.
     *
     * Cada fila lleva a dónde ir: un tablero que dice «hay 3 problemas» sin
     * decir dónde obliga a buscarlos, y entonces nadie los busca.
     *
     * @return Collection<int,array{titulo:string,detalle:string,cuantos:int,url:?string,tono:string}>
     */
    public function alertas(): Collection
    {
        $alertas = collect();

        $detenidos = Asset::where('status', 'mantenimiento')->count();
        if ($detenidos) {
            $alertas->push([
                'titulo'  => 'Equipos fuera de servicio',
                'detalle' => 'No se pueden reservar mientras la orden siga abierta.',
                'cuantos' => $detenidos,
                'url'     => '/admin/work-orders',
                'tono'    => 'danger',
            ]);
        }

        $vencidas = WorkOrder::whereIn('status', WorkOrder::ABIERTAS)
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->count();
        if ($vencidas) {
            $alertas->push([
                'titulo'  => 'Mantenimientos vencidos',
                'detalle' => 'Preventivas que ya debían haberse hecho.',
                'cuantos' => $vencidas,
                'url'     => '/admin/work-orders',
                'tono'    => 'warning',
            ]);
        }

        $bajoMinimos = Supply::where('is_active', true)
            ->whereNotNull('reorder_point')
            ->whereColumn('stock', '<=', 'reorder_point')
            ->count();
        if ($bajoMinimos) {
            $alertas->push([
                'titulo'  => 'Insumos bajo mínimos',
                'detalle' => 'Hay un carrito de reposición que los arma solos.',
                'cuantos' => $bajoMinimos,
                'url'     => '/admin/supplies',
                'tono'    => 'warning',
            ]);
        }

        $porAprobar = PurchaseRequest::where('status', 'enviada')->count();
        if ($porAprobar) {
            $alertas->push([
                'titulo'  => 'Compras esperando aprobación',
                'detalle' => 'Mientras no se aprueben, nadie las tramita.',
                'cuantos' => $porAprobar,
                'url'     => '/admin/purchase-requests',
                'tono'    => 'warning',
            ]);
        }

        $encargosVencidos = ProductionJob::whereIn('status', ProductionJob::EN_COLA)
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', now(config('fabos.lab.timezone'))->toDateString())
            ->count();
        if ($encargosVencidos) {
            $alertas->push([
                'titulo'  => 'Encargos pasados de fecha',
                'detalle' => 'Se prometió una entrega que ya venció.',
                'cuantos' => $encargosVencidos,
                'url'     => '/admin/production-jobs',
                'tono'    => 'danger',
            ]);
        }

        $porCotizar = ProductionJob::where('status', 'solicitado')->count();
        if ($porCotizar) {
            $alertas->push([
                'titulo'  => 'Encargos sin cotizar',
                'detalle' => 'Nadie puede aceptar lo que no tiene precio.',
                'cuantos' => $porCotizar,
                'url'     => '/admin/production-jobs',
                'tono'    => 'info',
            ]);
        }

        $solicitudes = Reservation::where('status', 'solicitada')->count();
        if ($solicitudes) {
            $alertas->push([
                'titulo'  => 'Reservas esperando visto bueno',
                'detalle' => 'No bloquean el equipo hasta que se aprueben.',
                'cuantos' => $solicitudes,
                'url'     => '/admin/reservations',
                'tono'    => 'info',
            ]);
        }

        $sinResponsable = Project::where('status', 'activo')->whereNull('lead_id')->count();
        if ($sinResponsable) {
            $alertas->push([
                'titulo'  => 'Proyectos sin responsable',
                'detalle' => 'Sin responsable no avanzan de etapa.',
                'cuantos' => $sinResponsable,
                'url'     => '/admin/projects',
                'tono'    => 'warning',
            ]);
        }

        return $alertas;
    }

    /**
     * Uso real por semana, para ver la tendencia.
     *
     * @return Collection<int,array{semana:string,minutos:int,sesiones:int}>
     */
    public function tendencia(int $semanas = 8): Collection
    {
        $tz = config('fabos.lab.timezone');
        $desde = Carbon::now($tz)->startOfWeek()->subWeeks($semanas - 1);

        $reservas = Reservation::query()
            ->where('reservable_type', Asset::class)
            ->where('status', 'completada')
            ->where('starts_at', '>=', $desde->copy()->utc())
            ->get();

        return collect(range(0, $semanas - 1))->map(function (int $i) use ($desde, $tz, $reservas) {
            $inicio = $desde->copy()->addWeeks($i);
            $fin = $inicio->copy()->endOfWeek();

            $delTramo = $reservas->filter(
                fn (Reservation $r) => $r->starts_at->timezone($tz)->between($inicio, $fin)
            );

            return [
                'semana'   => $inicio->format('d/m'),
                'sesiones' => $delTramo->count(),
                'minutos'  => (int) $delTramo->sum(fn (Reservation $r) => $r->checked_in_at && $r->checked_out_at
                    ? $r->checked_in_at->diffInMinutes($r->checked_out_at)
                    : $r->starts_at->diffInMinutes($r->ends_at)),
            ];
        });
    }

    /** El pulso del dinero y de las compras, sin abrir cada módulo. */
    public function finanzas(): array
    {
        $presupuestos = Budget::where('status', 'vigente')->get();

        return [
            'presupuesto'  => (int) $presupuestos->sum('amount'),
            'comprometido' => (int) $presupuestos->sum(fn (Budget $b) => $b->comprometido()),
            'ejecutado'    => (int) $presupuestos->sum(fn (Budget $b) => $b->ejecutado()),
            'disponible'   => (int) $presupuestos->sum(fn (Budget $b) => $b->disponible()),
            'encargos'     => ProductionJob::whereIn('status', ProductionJob::EN_COLA)->count(),
            'proyectos'    => Project::where('status', 'activo')->count(),
        ];
    }
}
