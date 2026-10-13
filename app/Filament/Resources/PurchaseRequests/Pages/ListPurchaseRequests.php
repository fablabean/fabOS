<?php

namespace App\Filament\Resources\PurchaseRequests\Pages;

use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Models\PurchaseRequest;
use App\Services\Purchasing\PurchasingService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListPurchaseRequests extends ListRecords
{
    protected static string $resource = PurchaseRequestResource::class;

    public function getSubheading(): ?string
    {
        return 'Lo que sale de aquí es la requisición que se le entrega al área de compras de la Universidad.';
    }

    protected function getHeaderActions(): array
    {
        return [
            /*
             * A quién se le envían. Aquí y no en un archivo: el día que cambie
             * la persona de compras no debería hacer falta desplegar nada.
             */
            Action::make('configuracion')
                ->label('Configuración')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->visible(fn () => auth()->user()?->hasAnyRole([\App\Models\User::ROL_ADMINISTRADOR, \App\Models\User::ROL_SUPERADMIN]) ?? false)
                ->modalHeading('A quién se envían las requisiciones')
                ->modalDescription('La persona de compras que las tramita. Al pulsar «Enviar» en una solicitud, le llega por correo con la requisición en PDF.')
                ->fillForm(fn () => \App\Support\ConfiguracionDeCompras::todo())
                ->schema([
                    \Filament\Forms\Components\TextInput::make('nombre')->label('Nombre')->required()->maxLength(160),
                    \Filament\Forms\Components\TextInput::make('correo')->label('Correo')->email()->required()->maxLength(160),

                    // Quién más se entera. Va como copia visible en el mismo correo.
                    // Persona por persona, y no un interruptor de todos o nadie:
                    // seis copias es un correo que cinco aprenden a ignorar.
                    \Filament\Forms\Components\CheckboxList::make('copiar_a')
                        ->label('Con copia a')
                        ->options(fn () => \App\Support\ConfiguracionDeCompras::opcionesDelEquipo())
                        ->columns(2)
                        ->bulkToggleable()
                        ->helperText('Superadmins y administradores activos. Marca a quien deba recibir copia de cada requisición; si nadie lo ha cambiado, van marcados los superadmins.'),
                    \Filament\Forms\Components\Textarea::make('copias')
                        ->label('Otros correos en copia')
                        ->rows(2)
                        ->placeholder('persona@universidadean.edu.co, otra@universidadean.edu.co')
                        ->helperText('Separados por comas o uno por línea. Reciben el mismo correo, con la requisición y la cotización.')
                        ->rule(fn () => function (string $attribute, $value, \Closure $fail) {
                            foreach (\App\Support\ConfiguracionDeCompras::correosDe($value) as $correo) {
                                if (! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                                    $fail('«' . $correo . '» no es un correo válido.');
                                }
                            }
                        }),
                ])
                ->action(function (array $data) {
                    \App\Support\ConfiguracionDeCompras::guardar($data);

                    \Filament\Notifications\Notification::make()->title('Configuración guardada')->success()->send();
                }),

            CreateAction::make()->label('Nuevo carrito'),

            // Atajo para el caso más frecuente: reponer lo que se está acabando.
            Action::make('reposicion')
                ->label('Carrito de reposición')
                ->icon('heroicon-o-sparkles')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Armar un carrito con lo que está bajo mínimos')
                ->modalDescription('Se crea un borrador con cada insumo por debajo de su punto de reposición, en cantidad suficiente para dejar colchón. Después se revisa y se ajusta.')
                ->action(function () {
                    $compras = app(PurchasingService::class);
                    $carrito = $compras->abrirCarrito(auth()->user(), null, 'Reposición de insumos bajo mínimos');
                    $n = $compras->llenarConLoQueFalta($carrito);

                    if ($n === 0) {
                        $carrito->delete();

                        Notification::make()
                            ->title('No hay nada bajo mínimos')
                            ->body('Ningún insumo está por debajo de su punto de reposición.')
                            ->success()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title("Carrito {$carrito->code} con {$n} líneas")
                        ->body('Revísalo y ajústalo antes de enviarlo.')
                        ->success()
                        ->send();

                    $this->redirect(PurchaseRequestResource::getUrl('edit', ['record' => $carrito]));
                }),
        ];
    }
}
