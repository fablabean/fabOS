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
    /** Cuándo se abrió de verdad, si es una orden de algo que ya pasó. */
    private ?\Illuminate\Support\Carbon $abiertaEl = null;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! empty($data['retroactiva']) && filled($data['abierta_el'] ?? null)) {
            $this->abiertaEl = \Illuminate\Support\Carbon::parse($data['abierta_el']);

            // Ya pasó: no detiene el equipo hoy. El paro, si lo hubo, se
            // anota con sus fechas en «Tiempos».
            $data['stops_equipment'] = false;

            if ($data['status'] === 'cerrada' && blank($data['closed_at'] ?? null)) {
                $data['closed_at'] = $data['abierta_el'];
            }
        }

        unset($data['retroactiva'], $data['abierta_el']);

        return $data;
    }

    protected function handleRecordCreation(array $data): \Illuminate\Database\Eloquent\Model
    {
        $orden = new \App\Models\WorkOrder($data);

        if ($this->abiertaEl) {
            // Con created_at puesto, Eloquent no lo pisa con la hora actual.
            $orden->created_at = $this->abiertaEl;
        }

        $orden->save();

        return $orden;
    }

    protected function afterCreate(): void
    {
        $orden = $this->record;

        // Los videos marcados «A la ficha» quedan en el equipo.
        \App\Filament\Componentes\RecursosDeMantenimiento::llevarALaFicha($orden);

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
