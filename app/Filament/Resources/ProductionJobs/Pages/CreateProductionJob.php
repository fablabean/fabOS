<?php

namespace App\Filament\Resources\ProductionJobs\Pages;

use App\Filament\Resources\ProductionJobs\ProductionJobResource;
use App\Services\Shop\ProductionService;
use Filament\Resources\Pages\CreateRecord;

class CreateProductionJob extends CreateRecord
{
    protected static string $resource = ProductionJobResource::class;

    /** El código se deriva: consecutivo por año y legible por teléfono. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['code'] = $data['code'] ?: app(ProductionService::class)->siguienteCodigo();

        return $data;
    }
}
