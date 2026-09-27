<?php

namespace App\Filament\Resources\WorkOrders\Pages;

use App\Filament\Resources\WorkOrders\WorkOrderResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWorkOrder extends CreateRecord
{
    protected static string $resource = WorkOrderResource::class;

    /**
     * Una orden abierta que saca el equipo de servicio lo detiene de verdad.
     *
     * Creada desde aquí solo se guardaba la marca: el equipo seguía operativo
     * y reservable, aunque la orden dijera lo contrario. Ahora pasa lo mismo
     * que al reportar una falla desde el QR: el equipo queda en mantenimiento
     * y se avisa a quien tenga reservas. Cerrar la orden lo devuelve.
     */
    protected function afterCreate(): void
    {
        $orden = $this->record;

        if (! $orden->stops_equipment || ! in_array($orden->status, \App\Models\WorkOrder::ABIERTAS, true)) {
            return;
        }

        if (! $orden->down_since) {
            $orden->forceFill(['down_since' => now()])->save();
        }

        if ($orden->asset) {
            app(\App\Services\Maintenance\MaintenanceService::class)->detener($orden->asset, $orden->reported_issue);
        }
    }
}
