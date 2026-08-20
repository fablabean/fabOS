<?php

namespace App\Filament\Resources\Certifabs\Pages;

use App\Filament\Resources\Certifabs\CertifabResource;
use App\Models\Certifab;
use App\Services\Notifications\NotificationService;
use Filament\Resources\Pages\CreateRecord;

class CreateCertifab extends CreateRecord
{
    /**
     * Quien otorga queda registrado solo. No se pide en el formulario porque
     * no debe poder falsearse: el certifab lo respalda una persona concreta.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['granted_by'] = auth()->id();
        $data['granted_at'] = $data['granted_at'] ?? now();

        return $data;
    }

    /**
     * Avisarle a la persona es parte de otorgar: una habilitación que nadie
     * sabe que tiene no le sirve para reservar.
     */
    protected function afterCreate(): void
    {
        /** @var Certifab $certifab */
        $certifab = $this->record;

        if (! $certifab->user) {
            return;
        }

        app(NotificationService::class)->enviar('certifab.otorgado', $certifab->user, [
            'alcance' => $certifab->asset?->name ?? $certifab->riskFamily?->name ?? 'el equipo',
            'nivel'   => $certifab->level,
            'codigo'  => $certifab->public_code,
            'enlace'  => route('publico.verificar', $certifab->public_code),
        ], $certifab);
    }

    protected static string $resource = CertifabResource::class;
}
