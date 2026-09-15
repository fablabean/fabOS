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
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
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
                ->label('Crear los presupuestos del año')
                ->icon('heroicon-o-chart-pie')
                ->color('gray')
                ->modalHeading('Crear los presupuestos con lo que cuesta la lista')
                ->modalDescription('Uno por rubro, que es como se pide y como se ejecuta. Nacen en BORRADOR: son una propuesta para conversar con la Universidad, no plata asignada.')
                ->modalSubmitActionLabel('Crear los presupuestos')
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

                    CheckboxList::make('rubros')
                        ->label('Qué rubros')
                        ->options(fn (Get $get) => self::rubrosDelAno((int) ($get('ano') ?? Wish::anoPorDefecto())))
                        ->default(fn (Get $get) => array_keys(self::rubrosDelAno((int) ($get('ano') ?? Wish::anoPorDefecto()))))
                        ->required()
                        ->bulkToggleable()
                        ->helperText('Cada uno nace con su propio monto, con impuesto incluido, y se llama como el rubro: ese nombre es lo que permite comparar un año con el siguiente.'),

                    Select::make('area_id')
                        ->label('Área')
                        ->options(fn () => Area::orderBy('name')->pluck('name', 'id')->all())
                        ->placeholder('Todo el laboratorio')
                        ->helperText('Deja vacío si los presupuestos no van partidos por área.'),
                ])
                ->action(function (array $data) {
                    // El «sin rubro» viaja como cadena vacia por el formulario;
                    // en el resumen es nulo, que es lo que espera el servicio.
                    $rubros = array_map(
                        fn (string $rubro) => $rubro === '' ? null : $rubro,
                        $data['rubros'] ?? [],
                    );

                    $creados = app(ListaDeDeseos::class)->presupuestarPorRubro(
                        (int) $data['ano'],
                        $rubros,
                        $data['area_id'] ? Area::find($data['area_id']) : null,
                    );

                    if ($creados->isEmpty()) {
                        Notification::make()
                            ->title('No se creó ninguno')
                            ->body('Los rubros elegidos no tienen deseos pendientes ese año.')
                            ->warning()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title($creados->count() === 1
                            ? 'Un presupuesto en borrador'
                            : $creados->count() . ' presupuestos en borrador')
                        ->body('Revísalos y déjalos vigentes cuando la Universidad los confirme.')
                        ->success()
                        ->send();

                    $this->redirect(BudgetResource::getUrl('index'));
                }),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            ResumenDeDeseos::class,
        ];
    }

    /**
     * Los rubros del año con lo que cuesta cada uno, para elegirlos sabiendo.
     *
     * La etiqueta lleva el monto y los que quedaron sin cotizar: elegir a
     * ciegas y descubrir la cifra después obliga a deshacer.
     *
     * @return array<string, string>
     */
    private static function rubrosDelAno(int $ano): array
    {
        $simbolo = config('fabos.money.symbol');
        $opciones = [];

        foreach (Wish::resumenDelAno($ano)['rubros'] as $fila) {
            $etiqueta = sprintf(
                '%s · %s%s (%d %s)',
                $fila['rubro'] ?? Wish::SIN_RUBRO,
                $simbolo,
                number_format($fila['conImpuesto'], 0, ',', '.'),
                $fila['cuantos'],
                $fila['cuantos'] === 1 ? 'deseo' : 'deseos',
            );

            if ($fila['sinEstimar'] > 0) {
                $etiqueta .= sprintf(' · %d sin cotizar', $fila['sinEstimar']);
            }

            // La clave vacia es «sin rubro»: un checkbox no puede tener nulo por
            // valor, y se traduce de vuelta al crear.
            $opciones[$fila['rubro'] ?? ''] = $etiqueta;
        }

        return $opciones;
    }
}
