<?php

namespace App\Filament\Resources\Publicaciones\Pages;

use App\Filament\Resources\Publicaciones\AccionesDelFlujo;
use App\Filament\Resources\Publicaciones\PublicacionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPublicacion extends EditRecord
{
    protected static string $resource = PublicacionResource::class;

    public function getSubheading(): ?string
    {
        $p = $this->getRecord();

        return $p->nombreDelEstado()
            . ($p->aprobador ? ' · aprobada por ' . $p->aprobador->name . ' el ' . $p->aprobada_at?->timezone(config('fabos.lab.timezone'))->format('d/m H:i') : '')
            . ($p->enlace ? ' · ' . $p->enlace : '');
    }

    protected function getHeaderActions(): array
    {
        return [
            ...array_map(
                // Al cambiar de estado, la ficha se recarga: los botones y el
                // aviso de arriba dependen del estado.
                fn ($accion) => $accion->after(fn () => $this->redirect(PublicacionResource::getUrl('edit', ['record' => $this->getRecord()]))),
                AccionesDelFlujo::todas(),
            ),
            DeleteAction::make(),
        ];
    }
}
