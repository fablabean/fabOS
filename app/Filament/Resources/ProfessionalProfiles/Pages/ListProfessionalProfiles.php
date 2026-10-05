<?php

namespace App\Filament\Resources\ProfessionalProfiles\Pages;

use App\Filament\Resources\ProfessionalProfiles\ProfessionalProfileResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListProfessionalProfiles extends ListRecords
{
    protected static string $resource = ProfessionalProfileResource::class;

    public function getSubheading(): ?string
    {
        return 'Quién puede trabajar con el laboratorio. De aquí sale la hoja que se le manda a compras de la Universidad para inscribirlo como proveedor.';
    }

    protected function getHeaderActions(): array
    {
        return [
            /*
             * Para alguien de fuera que los va a contactar: lo justo para
             * escribirles —nombre, perfil, correo, celular— y por qué se
             * comparten. La hoja con cédula y banco es la de compras, y esa no
             * sale de aquí.
             */
            \Filament\Actions\Action::make('compartir')
                ->label('Compartir perfiles (PDF)')
                ->icon('heroicon-o-share')
                ->color('gray')
                ->modalHeading('Compartir perfiles con un externo')
                ->modalDescription('Solo nombre, perfil, correo y celular, con el membrete del laboratorio. Nada de documentos, banco ni datos tributarios.')
                ->modalSubmitActionLabel('Bajar el PDF')
                ->schema([
                    \Filament\Forms\Components\Select::make('alcance')
                        ->label('Cuáles')
                        ->options([
                            'propuesto' => 'Los propuestos',
                            'todos'     => 'Todos, menos los descartados',
                        ])
                        ->default('propuesto')
                        ->required(),
                    ...\App\Filament\Resources\ProfessionalProfiles\Tables\ProfessionalProfilesTable::camposParaCompartir(),
                ])
                ->action(function (array $data) {
                    $servicio = app(\App\Services\Personas\PerfilesParaCompartir::class);
                    $perfiles = $servicio->cuales($data['alcance']);

                    if ($perfiles->isEmpty()) {
                        \Filament\Notifications\Notification::make()
                            ->title('No hay perfiles para compartir')
                            ->body($data['alcance'] === 'propuesto' ? 'Ningún perfil está como «Propuesto».' : 'Todos están descartados.')
                            ->warning()
                            ->send();

                        return null;
                    }

                    return $servicio->pdf($perfiles, $data['razon'], $data['para'] ?? null);
                }),
            CreateAction::make()->label('Añadir un perfil'),
        ];
    }
}
