<?php

namespace App\Services\Maintenance;

use App\Models\Asset;
use App\Models\MaintenancePlan;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Mantenimiento de los equipos (§8).
 *
 * La regla que sostiene el módulo: **abrir una orden con paro saca el equipo de
 * la agenda de inmediato**. No basta con anotarlo en algún lado — si el sistema
 * sigue dejando reservar una máquina averiada, la gente llega y se encuentra el
 * problema, que es justo lo que se quería evitar.
 */
class MaintenanceService
{
    public function __construct(private NotificationService $avisos) {}

    /** Reporta una falla. Cualquiera puede hacerlo escaneando el QR (§8). */
    public function reportarFalla(
        Asset $equipo,
        User $quien,
        string $problema,
        bool $detieneElEquipo = false,
        string $prioridad = 'normal',
    ): WorkOrder {
        return DB::transaction(function () use ($equipo, $quien, $problema, $detieneElEquipo, $prioridad) {
            $orden = WorkOrder::create([
                'asset_id'        => $equipo->id,
                'kind'            => 'correctivo',
                'status'          => 'abierta',
                'priority'        => $prioridad,
                'reported_issue'  => $problema,
                'reported_by'     => $quien->id,
                'stops_equipment' => $detieneElEquipo,
                'down_since'      => $detieneElEquipo ? now() : null,
            ]);

            if ($detieneElEquipo) {
                $this->detener($equipo, $problema);
            }

            return $orden;
        });
    }

    /** Saca el equipo de servicio y avisa qué reservas quedan afectadas. */
    public function detener(Asset $equipo, ?string $motivo = null): Collection
    {
        $equipo->forceFill(['status' => 'mantenimiento'])->save();

        // No se cancelan solas: quién reagenda es decisión de la coordinación.
        // Pero avisar sí es automático: enterarse al llegar al laboratorio, con
        // el viaje hecho, es exactamente lo que hay que evitar.
        $afectadas = Reservation::query()
            ->where('reservable_type', Asset::class)
            ->where('reservable_id', $equipo->id)
            ->whereIn('status', Reservation::BLOQUEANTES)
            ->where('ends_at', '>', now())
            ->with('user')
            ->get();

        foreach ($afectadas as $reserva) {
            if (! $reserva->user) {
                continue;
            }

            $this->avisos->enviarUnaVez('reserva.equipo_en_mantenimiento', $reserva->user, $reserva, [
                'equipo' => $equipo->name,
                'fecha'  => $reserva->starts_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y'),
                'inicio' => $reserva->starts_at->timezone(config('fabos.lab.timezone'))->format('H:i'),
                'motivo' => $motivo ?? 'mantenimiento del equipo',
            ]);
        }

        return $afectadas;
    }

    /** Cierra la orden y devuelve el equipo a servicio si estaba detenido. */
    public function cerrar(WorkOrder $orden, string $trabajoRealizado, ?array $respuestas = null): WorkOrder
    {
        return DB::transaction(function () use ($orden, $trabajoRealizado, $respuestas) {
            $orden->update([
                'status'            => 'cerrada',
                'work_done'         => $trabajoRealizado,
                'checklist_answers' => $respuestas ?? $orden->checklist_answers,
                'up_since'          => $orden->down_since ? now() : null,
                'closed_at'         => now(),
            ]);

            $equipo = $orden->asset;

            // Solo vuelve a servicio si no queda otra orden con paro abierta:
            // dos averías simultáneas no se curan cerrando una.
            $otroParo = WorkOrder::where('asset_id', $equipo->id)
                ->whereKeyNot($orden->id)
                ->where('stops_equipment', true)
                ->whereIn('status', WorkOrder::ABIERTAS)
                ->exists();

            if ($orden->stops_equipment && ! $otroParo && $equipo->status === 'mantenimiento') {
                $equipo->forceFill(['status' => 'operativo'])->save();
            }

            return $orden->refresh();
        });
    }

    /**
     * Genera las órdenes preventivas que ya tocan.
     *
     * Se apoya en la última orden cerrada de cada plan: si nunca se ha hecho,
     * toca ya. Es lo que convierte un plan escrito en trabajo real.
     */
    public function generarPreventivas(?Carbon $hasta = null): int
    {
        $limite = $hasta ?? now();
        $creadas = 0;

        foreach (MaintenancePlan::where('is_active', true)->get() as $plan) {
            foreach ($plan->equipos() as $equipo) {
                if ($this->yaTieneAbierta($plan, $equipo)) {
                    continue;
                }

                if (! $this->toca($plan, $equipo, $limite)) {
                    continue;
                }

                WorkOrder::create([
                    'asset_id'            => $equipo->id,
                    'maintenance_plan_id' => $plan->id,
                    'kind'                => 'preventivo',
                    'status'              => 'abierta',
                    'reported_issue'      => $plan->name,
                    // El formulario se congela con la orden: dentro de dos años
                    // seguirá mostrando el que realmente se usó.
                    'checklist_snapshot'  => $plan->checklist,
                    'due_at'              => $limite,
                ]);

                $creadas++;
            }
        }

        return $creadas;
    }

    /** Órdenes abiertas de un equipo, para mostrarlas al escanear su QR. */
    public function abiertasDe(Asset $equipo): Collection
    {
        return WorkOrder::where('asset_id', $equipo->id)
            ->whereIn('status', WorkOrder::ABIERTAS)
            ->latest()
            ->get();
    }

    private function yaTieneAbierta(MaintenancePlan $plan, Asset $equipo): bool
    {
        return WorkOrder::where('maintenance_plan_id', $plan->id)
            ->where('asset_id', $equipo->id)
            ->whereIn('status', WorkOrder::ABIERTAS)
            ->exists();
    }

    private function toca(MaintenancePlan $plan, Asset $equipo, Carbon $limite): bool
    {
        $ultima = WorkOrder::where('maintenance_plan_id', $plan->id)
            ->where('asset_id', $equipo->id)
            ->where('status', 'cerrada')
            ->latest('closed_at')
            ->first();

        // Nunca se le ha hecho: toca ya.
        if (! $ultima?->closed_at) {
            return true;
        }

        if ($plan->every_days) {
            return $ultima->closed_at->addDays($plan->every_days)->lessThanOrEqualTo($limite);
        }

        if ($plan->every_usage_minutes) {
            return $this->usoDesde($equipo, $ultima->closed_at) >= $plan->every_usage_minutes;
        }

        return false;
    }

    /** Minutos de uso real acumulados desde una fecha, según las reservas. */
    private function usoDesde(Asset $equipo, Carbon $desde): int
    {
        return (int) Reservation::query()
            ->where('reservable_type', Asset::class)
            ->where('reservable_id', $equipo->id)
            ->where('status', 'completada')
            ->where('checked_out_at', '>=', $desde->copy()->utc())
            ->get()
            ->sum(fn (Reservation $r) => $r->checked_in_at && $r->checked_out_at
                ? $r->checked_in_at->diffInMinutes($r->checked_out_at)
                : 0);
    }
}
