<?php

namespace App\Filament\Resources\Wishes\Pages;

use App\Filament\Resources\Budgets\BudgetResource;
use App\Filament\Resources\Wishes\Widgets\ResumenDeDeseos;
use App\Filament\Resources\Wishes\WishResource;
use App\Models\Area;
use App\Models\Wish;
use App\Services\Purchasing\ListaDeDeseos;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Utilities\Get;

class ListWishes extends ListRecords
{
    protected static string $resource = WishResource::class;

    public function getSubheading(): ?string
    {
        return 'Lo que el laboratorio necesita y todavía no tiene. De aquí sale el carrito cuando hay con qué, y la cifra con la que se pide el presupuesto del año siguiente.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Apuntar un deseo'),

            /*
             * El otro camino de la lista: de «lo que quisieramos» a «lo que
             * pedimos que nos asignen». Va en la pagina y no en el resumen
             * porque una accion dentro de un widget es mas dificil de encontrar
             * y de probar, y esta se usa una vez al ano: tiene que estar donde
             * se busca.
             */
            Action::make('presupuestar')
                ->label('Crear el presupuesto del año')
                ->icon('heroicon-o-chart-pie')
                ->color('gray')
                ->modalHeading('Crear el presupuesto con lo que cuesta la lista')
                ->modalDescription('Nace en BORRADOR: es una propuesta para conversar con la Universidad, no plata asignada. El monto viene de la lista y se puede corregir.')
                ->modalSubmitActionLabel('Crear el presupuesto')
                ->visible(fn () => BudgetResource::canCreate())
                ->schema([
                    Select::make('ano')
                        ->label('De qué año')
                        ->options(fn () => Wish::query()
                            ->porComprar()
                            ->distinct()
                            ->orderBy('target_year')
                            ->pluck('target_year', 'target_year')
                            ->all())
                        ->default(fn () => Wish::anoPorDefecto())
                        ->required()
                        ->live(),

                    Select::make('area_id')
                        ->label('Área')
                        ->options(fn () => Area::orderBy('name')->pluck('name', 'id')->all())
                        ->placeholder('Todo el laboratorio')
                        ->helperText('Deja vacío para presupuestar la lista entera de ese año.'),

                    TextInput::make('name')
                        ->label('Nombre del presupuesto')
                        ->required()
                        ->maxLength(255)
                        ->default(fn (Get $get) => 'Deseos ' . ($get('ano') ?? Wish::anoPorDefecto())),

                    TextInput::make('amount')
                        ->label('Monto')
                        ->numeric()
                        ->required()
                        ->prefix(config('fabos.money.symbol'))
                        // Con impuesto, que es como trabaja compras: el subtotal
                        // a secas hace creer que alcanza para mas de lo que
                        // alcanza.
                        ->default(fn (Get $get) => Wish::resumenDelAno((int) ($get('ano') ?? Wish::anoPorDefecto()))['conImpuesto'])
                        ->helperText(fn (Get $get) => self::deDondeSale((int) ($get('ano') ?? Wish::anoPorDefecto()))),
                ])
                ->action(function (array $data) {
                    $presupuesto = app(ListaDeDeseos::class)->presupuestar(
                        (int) $data['ano'],
                        $data['area_id'] ? Area::find($data['area_id']) : null,
                        $data['name'],
                        (int) $data['amount'],
                    );

                    Notification::make()
                        ->title('Presupuesto creado en borrador')
                        ->body('Revísalo y déjalo vigente cuando la Universidad lo confirme.')
                        ->success()
                        ->send();

                    $this->redirect(BudgetResource::getUrl('edit', ['record' => $presupuesto]));
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ResumenDeDeseos::class,
        ];
    }

    /** La cuenta, dicha entera: lo que entra y lo que quedó fuera. */
    private static function deDondeSale(int $ano): string
    {
        $resumen = Wish::resumenDelAno($ano);
        $simbolo = config('fabos.money.symbol');

        $frase = sprintf(
            '%d deseos · %s%s + %d%% de impuesto.',
            $resumen['cuantos'],
            $simbolo,
            number_format($resumen['estimado'], 0, ',', '.'),
            round($resumen['tasa'] * 100),
        );

        if ($resumen['sinEstimar'] > 0) {
            $frase .= sprintf(
                ' %d sin cotizar, que no están en esta cifra.',
                $resumen['sinEstimar'],
            );
        }

        return $frase;
    }
}
