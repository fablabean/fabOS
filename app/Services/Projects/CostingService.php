<?php

namespace App\Services\Projects;

use App\Models\Asset;
use App\Models\Project;
use App\Models\ProjectCost;
use App\Models\ProjectTimeLog;
use App\Models\PurchaseRequest;
use App\Models\Reservation;
use App\Models\ReservationSupply;
use Illuminate\Support\Collection;

/**
 * Cuánto costó de verdad un proyecto (§11, §12).
 *
 * Cuatro fuentes, y cada una responde una pregunta distinta:
 *
 *  - **Máquina.** Las reservas cargadas al proyecto, valoradas con la tarifa
 *    interna. No es plata que salió de caja: es capacidad que el laboratorio
 *    dejó de tener disponible para otros. Ignorarla haría parecer gratis lo que
 *    ocupó la láser tres días.
 *  - **Material.** Lo que se consumió, al costo con que se compró. Esto sí es
 *    plata que salió y no vuelve.
 *  - **Compras.** Lo pedido específicamente para el proyecto.
 *  - **Gente.** Las horas del equipo, a la tarifa de referencia del laboratorio.
 *  - **Asociados.** Lo que se gastó por fuera y no pasa por ninguna de las
 *    anteriores: la factura de un tercero, un flete, el alquiler de un equipo
 *    que no tenemos. Sin esta fuente el margen sale bonito y falso.
 *
 * Todo se presenta en **pesos**. Los FabCoins asignan capacidad interna; el
 * informe de un proyecto se lee fuera del laboratorio, donde los FabCoins no
 * significan nada. La conversión usa la tasa configurada, y por eso el costo de
 * máquina es una **valoración**, no un desembolso: conviene decirlo al mostrarlo.
 */
class CostingService
{
    /**
     * @return array{
     *   maquina:int, material:int, compras:int, gente:int, asociados:int, total:int,
     *   acordado:int, estimado:int, referencia:int, contra:string,
     *   margen:int, margen_pct:?float,
     *   detalle:array<string,Collection>
     * }
     */
    public function costear(Project $proyecto): array
    {
        $reservas = $this->reservas($proyecto);
        $materiales = $this->materiales($proyecto);
        $compras = $this->compras($proyecto);
        $horas = $this->horas($proyecto);
        $otros = $this->asociados($proyecto);

        $maquina = $this->enPesos((int) $reservas->sum('costo_menor'));
        $material = (int) $materiales->sum('costo');
        $comprado = (int) $compras->sum('costo');
        $gente = (int) $horas->sum(fn (ProjectTimeLog $l) => $l->costo());
        $asociados = (int) $otros->sum('amount');

        $total = $maquina + $material + $comprado + $gente + $asociados;

        $acordado = (int) $proyecto->agreed_value;
        $estimado = (int) $proyecto->estimated_value;

        // Contra lo firmado si ya se firmó, y si no contra lo cotizado. Medir
        // una propuesta contra cero la pintaria en perdida desde el primer dia.
        $referencia = $proyecto->valorDeReferencia();

        return [
            'maquina'    => $maquina,
            'material'   => $material,
            'compras'    => $comprado,
            'gente'      => $gente,
            'asociados'  => $asociados,
            'total'      => $total,
            'acordado'   => $acordado,
            'estimado'   => $estimado,
            'referencia' => $referencia,
            'contra'     => $acordado > 0 ? 'acordado' : ($estimado > 0 ? 'estimado' : 'nada'),
            'margen'     => $referencia - $total,
            'margen_pct' => $referencia > 0 ? round(($referencia - $total) / $referencia * 100, 1) : null,
            'detalle'    => [
                'reservas'   => $reservas,
                'materiales' => $materiales,
                'compras'    => $compras,
                'horas'      => $horas,
                'asociados'  => $otros,
            ],
        ];
    }

    /** Lo que se gastó por fuera: facturas de terceros, fletes, alquileres. */
    private function asociados(Project $proyecto): Collection
    {
        return ProjectCost::where('project_id', $proyecto->id)
            ->orderBy('incurred_on')
            ->get();
    }

    /**
     * Tiempo de máquina cargado al proyecto.
     *
     * Se toma el costo liquidado de la reserva —lo que realmente se usó—, no lo
     * estimado al reservar: un bloque de cuatro horas que se cerró en una hora
     * costó una.
     *
     * Y se le **resta el material**, porque la liquidación de la reserva ya lo
     * incluye a precio de tienda y aquí se cuenta aparte a costo de compra.
     * Sin esta resta, cada gramo de filamento aparecería dos veces y el
     * proyecto se vería más caro de lo que fue.
     */
    private function reservas(Project $proyecto): Collection
    {
        $reservas = Reservation::query()
            ->where('project_id', $proyecto->id)
            ->where('reservable_type', Asset::class)
            ->whereIn('status', ['completada', 'en_curso'])
            ->with('user')
            ->get();

        $equipos = Asset::whereIn('id', $reservas->pluck('reservable_id'))->get()->keyBy('id');

        $materialPorReserva = ReservationSupply::whereIn('reservation_id', $reservas->pluck('id'))
            ->get()
            ->groupBy('reservation_id')
            ->map(fn (Collection $lineas) => (int) $lineas->sum(fn (ReservationSupply $m) => $m->totalMenor()));

        return $reservas->map(fn (Reservation $r) => [
            'reserva'     => $r,
            'equipo'      => $equipos[$r->reservable_id]?->name ?? 'Equipo eliminado',
            'cuando'      => $r->starts_at,
            'costo_menor' => max(0, (int) ($r->actual_cost_minor ?? $r->estimated_cost_minor)
                - (int) ($materialPorReserva[$r->id] ?? 0)),
        ]);
    }

    /**
     * Material consumido, al costo de compra.
     *
     * Se valora con `last_cost` y no con el precio de la tienda: para el
     * proyecto interesa lo que costó reponerlo, no lo que se le cobraría a un
     * tercero por venderlo.
     */
    private function materiales(Project $proyecto): Collection
    {
        $reservaIds = Reservation::where('project_id', $proyecto->id)->pluck('id');

        return ReservationSupply::query()
            ->whereIn('reservation_id', $reservaIds)
            ->with('supply')
            ->get()
            ->map(fn (ReservationSupply $m) => [
                'insumo'   => $m->supply?->name ?? 'Insumo eliminado',
                'cantidad' => (float) $m->quantity,
                'unidad'   => $m->supply?->unit ?? '',
                'costo'    => (int) round((float) $m->quantity * (int) ($m->supply?->last_cost ?? 0)),
            ]);
    }

    /** Compras hechas para el proyecto. Cuenta lo recibido, no lo pedido. */
    private function compras(Project $proyecto): Collection
    {
        return PurchaseRequest::query()
            ->where('project_id', $proyecto->id)
            ->whereIn('status', ['aprobada', 'en_compra', 'recibida_parcial', 'recibida'])
            ->with('items')
            ->get()
            ->map(fn (PurchaseRequest $s) => [
                'solicitud' => $s,
                'estado'    => PurchaseRequest::ESTADOS[$s->status] ?? $s->status,
                'costo'     => $s->status === 'aprobada' || $s->status === 'en_compra'
                    ? 0                        // aprobada pero sin llegar: aún no costó
                    : $s->recibidoEnPesos(),
                'pendiente' => $s->pendienteEnPesos(),
            ]);
    }

    private function horas(Project $proyecto): Collection
    {
        return ProjectTimeLog::where('project_id', $proyecto->id)
            ->with('user')
            ->orderBy('worked_on')
            ->get();
    }

    /** FabCoins → pesos, con la tasa configurada. */
    private function enPesos(int $menor): int
    {
        $unidades = (int) config('fabos.currency.minor_units');
        $tasa = (int) config('fabos.currency.peso_rate');

        return (int) round($menor / $unidades * $tasa);
    }
}
