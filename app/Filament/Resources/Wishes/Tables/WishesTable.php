<?php

namespace App\Filament\Resources\Wishes\Tables;

use App\Filament\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Models\Wish;
use App\Services\Purchasing\ListaDeDeseos;
use App\Services\Purchasing\PurchasingException;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * La lista, agrupada por área y ordenada por lo que importa: primero el año más
 * cercano, dentro de él lo más prioritario, y dentro de eso lo más caro —que es
 * lo que decide si el resto cabe— (§13).
 */
class WishesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort(fn (Builder $query) => $query
                ->orderBy('target_year')
                ->orderByRaw("array_position(ARRAY['alta','media','baja'], priority)")
                ->orderByDesc('unit_price'))
            /*
             * Agrupada por rubro y no por area: la pregunta con la que se abre
             * esta pantalla en septiembre es «cuanto hay que pedir de cada
             * bolsillo», que es como la Universidad asigna la plata. El area
             * queda a un clic, para cuando la pregunta es a quien le hace falta.
             */
            ->defaultGroup(Group::make('budget_line')->label('Rubro')->collapsible())
            ->groups([
                Group::make('budget_line')->label('Rubro')->collapsible(),
                Group::make('area.name')->label('Área')->collapsible(),
                Group::make('target_year')->label('Año')->collapsible(),
            ])
            ->columns([
                TextColumn::make('description')
                    ->label('Qué se desea')
                    ->searchable()
                    ->weight('medium')
                    ->wrap()
                    ->description(fn (Wish $r) => $r->justification),

                TextColumn::make('target_year')->label('Para')->sortable(),

                TextColumn::make('budget_line')
                    ->label('Rubro')
                    ->searchable()
                    ->toggleable()
                    // Sin rubro no es un error, es algo por decidir: y hasta que
                    // se decida, esa plata no está en ningún bolsillo.
                    ->placeholder('sin decidir'),

                TextColumn::make('priority')
                    ->label('Prioridad')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Wish::PRIORIDADES[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'alta'  => 'danger',
                        'media' => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('quantity')
                    ->label('Cuánto')
                    ->alignEnd()
                    ->state(fn (Wish $r) => rtrim(rtrim(number_format((float) $r->quantity, 3, ',', '.'), '0'), ',') . ' ' . $r->unit),

                TextColumn::make('unit_price')
                    ->label('Estimado unit.')
                    ->alignEnd()
                    ->state(fn (Wish $r) => $r->unit_price === null ? null : self::pesos((int) $r->unit_price))
                    // Sin cotizar no es cero, y la tabla tiene que decirlo igual
                    // que lo dice el resumen.
                    ->placeholder('sin cotizar'),

                TextColumn::make('estimado')
                    ->label('Estimado')
                    ->alignEnd()
                    ->weight('medium')
                    ->state(fn (Wish $r) => $r->estimado() === null ? null : self::pesos($r->estimado()))
                    ->placeholder('—'),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (Wish $r) => $r->estadoLegible())
                    ->color(fn (Wish $r) => match ($r->estado()) {
                        'comprado'     => 'success',
                        'en_solicitud' => 'info',
                        'descartado'   => 'gray',
                        default        => 'warning',
                    })
                    // En qué carrito terminó, o por qué volvió: sin esto, un
                    // deseo que reaparece en la lista parece un error.
                    ->description(fn (Wish $r) => self::deDondeViene($r)),

                TextColumn::make('requestedBy.name')
                    ->label('Lo apuntó')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('target_year')
                    ->label('Para el año')
                    ->options(fn () => Wish::query()
                        ->distinct()
                        ->orderBy('target_year')
                        ->pluck('target_year', 'target_year')
                        ->all())
                    ->default(Wish::anoPorDefecto()),

                SelectFilter::make('budget_line')
                    ->label('Rubro')
                    ->options(fn () => Wish::rubrosDisponibles()),

                SelectFilter::make('area_id')->label('Área')->relationship('area', 'name'),

                SelectFilter::make('priority')->label('Prioridad')->options(Wish::PRIORIDADES),

                SelectFilter::make('estado')
                    ->label('Estado')
                    ->options(Wish::ESTADOS)
                    // Por defecto, lo que sigue esperando: la lista se abre para
                    // ver qué falta, no para repasar lo que ya llegó.
                    ->default('abierto')
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->enEstado($data['value'])
                        : $query),
            ])
            ->recordActions([
                Action::make('solicitud')
                    ->label('Ver la solicitud')
                    ->iconButton()
                    ->tooltip(fn (Wish $r) => 'Ver ' . $r->solicitud()?->code)
                    ->icon('heroicon-o-shopping-cart')
                    ->color('gray')
                    ->visible(fn (Wish $r) => (bool) $r->item)
                    ->url(fn (Wish $r) => PurchaseRequestResource::getUrl('edit', [
                        'record' => $r->item->purchase_request_id,
                    ])),

                EditAction::make()->iconButton()->tooltip('Editar'),
            ])
            ->toolbarActions([
                /*
                 * La razon de ser de la lista, y por eso fuera del grupo: lo que
                 * queda escondido detras de un menu de tres puntos no se usa.
                 */
                BulkAction::make('pasarACompra')
                    ->label('Pasar a solicitud de compra')
                    ->icon('heroicon-o-shopping-cart')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Armar un carrito con los deseos seleccionados')
                    ->modalDescription('Se crea una solicitud en BORRADOR, con una línea por deseo. No compromete presupuesto: eso pasa al aprobarla. Los que ya se pidieron se saltan.')
                    ->modalSubmitActionLabel('Armar el carrito')
                    // Al recurso, no a la politica: `create` sin registro no sabe de
                    // que seccion se habla, y quien lo sabe es el recurso. Preguntar
                    // en dos sitios distintos es como acaban contestando cosas
                    // distintas.
                    ->visible(fn () => PurchaseRequestResource::canCreate())
                    ->action(function (Collection $records, $livewire) {
                        try {
                            $carrito = app(ListaDeDeseos::class)->pasarACompra($records, auth()->user());
                        } catch (PurchasingException $e) {
                            Notification::make()
                                ->title('No se pudo')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
                        }

                        $lineas = $carrito->items()->count();

                        Notification::make()
                            ->title("Carrito {$carrito->code} con {$lineas} " . ($lineas === 1 ? 'línea' : 'líneas'))
                            ->body('Revísalo, elige contra qué presupuesto va y envíalo.')
                            ->success()
                            ->send();

                        $livewire->redirect(PurchaseRequestResource::getUrl('edit', ['record' => $carrito]));
                    })
                    ->deselectRecordsAfterCompletion(),

                BulkActionGroup::make([
                    /*
                     * Clasificar una lista ya escrita, de diez en diez. Rubro
                     * por rubro y de uno en uno es el trabajo que nadie hace, y
                     * entonces el reparto del ano siguiente sale en blanco.
                     */
                    BulkAction::make('asignarRubro')
                        ->label('Asignar rubro')
                        ->icon('heroicon-o-rectangle-stack')
                        ->color('gray')
                        ->modalHeading('A qué rubro van los deseos seleccionados')
                        ->modalDescription('Es contra qué presupuesto se pagarían. Reparte la cifra del año siguiente y hace que el carrito nazca apuntando al presupuesto correcto.')
                        ->schema([
                            Select::make('budget_line')
                                ->label('Rubro')
                                ->options(fn () => Wish::rubrosDisponibles())
                                ->searchable()
                                ->required()
                                ->createOptionForm([
                                    TextInput::make('name')
                                        ->label('Nombre del rubro')
                                        ->required()
                                        ->maxLength(120),
                                ])
                                ->createOptionUsing(fn (array $data) => $data['name']),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $records->each->update(['budget_line' => $data['budget_line']]);

                            Notification::make()
                                ->title('Van a «' . $data['budget_line'] . '»')
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    /*
                     * Pasar de ano no es un cambio de estado: es la misma cosa
                     * que se sigue deseando, un ano mas tarde. Por eso se cambia
                     * la cifra y ya, sin copiar nada.
                     */
                    BulkAction::make('pasarDeAno')
                        ->label('Pasar a otro año')
                        ->icon('heroicon-o-calendar')
                        ->color('gray')
                        ->modalHeading('Pasar los deseos seleccionados a otro año')
                        ->modalDescription('Lo que no alcanzó este año se sigue necesitando el siguiente. No se copia: es el mismo deseo, con otra fecha.')
                        ->schema([
                            TextInput::make('ano')
                                ->label('Para qué año')
                                ->numeric()
                                ->required()
                                ->default(fn () => Wish::anoPorDefecto() + 1),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $records->each->update(['target_year' => (int) $data['ano']]);

                            Notification::make()
                                ->title('Pasados a ' . $data['ano'])
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('descartar')
                        ->label('Descartar')
                        ->icon('heroicon-o-x-circle')
                        ->color('warning')
                        ->modalHeading('Descartar los deseos seleccionados')
                        // Con motivo: un deseo que desaparece sin explicacion se
                        // vuelve a apuntar el mes que viene.
                        ->modalDescription('Salen de la lista y dejan de contar para el presupuesto. No se borran: queda escrito por qué, para no volver a discutirlo.')
                        ->schema([
                            Textarea::make('motivo')
                                ->label('Por qué no')
                                ->required()
                                ->rows(2)
                                ->maxLength(255),
                        ])
                        ->action(function (Collection $records, array $data) {
                            $records->each->update([
                                'discarded_at'     => now(),
                                'discarded_reason' => $data['motivo'],
                            ]);

                            Notification::make()->title('Descartados')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    BulkAction::make('revivir')
                        ->label('Devolver a la lista')
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->color('gray')
                        ->action(function (Collection $records) {
                            $records->each->update(['discarded_at' => null, 'discarded_reason' => null]);

                            Notification::make()->title('De vuelta en la lista')->success()->send();
                        })
                        ->deselectRecordsAfterCompletion(),

                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('Todavía no hay nada apuntado')
            ->emptyStateDescription('Aquí se anota lo que hace falta y aún no se ha pedido. De esta lista salen los carritos de compra y la cifra con la que se propone el presupuesto del año siguiente.')
            ->description('Un deseo no compromete plata: es lo que se quisiera tener. Se marcan los que caben ahora y se pasan a una solicitud; el resto sigue esperando o se pasa al año siguiente.');
    }

    /** De qué solicitud viene este deseo, dicho para quien mira la fila. */
    private static function deDondeViene(Wish $deseo): ?string
    {
        $solicitud = $deseo->solicitud();

        if (! $solicitud) {
            return null;
        }

        return $deseo->estado() === 'abierto'
            ? 'volvió de ' . $solicitud->code
            : $solicitud->code;
    }

    private static function pesos(int $pesos): string
    {
        return config('fabos.money.symbol') . number_format($pesos, 0, ',', '.');
    }
}
