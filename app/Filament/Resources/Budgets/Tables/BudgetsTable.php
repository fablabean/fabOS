<?php

namespace App\Filament\Resources\Budgets\Tables;

use App\Models\Budget;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class BudgetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('year', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->label('Presupuesto')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (Budget $r) => $r->area?->name ?? 'todo el laboratorio'),

                TextColumn::make('year')->label('Vigencia')->sortable(),

                TextColumn::make('kind')
                    ->label('Clase')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Budget::TIPOS[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'venta' ? 'info' : 'gray'),

                TextColumn::make('amount')
                    ->label('Aprobado')
                    ->alignEnd()
                    ->weight('medium')
                    ->state(fn (Budget $r) => self::formato((int) $r->amount))
                    ->description(fn (Budget $r) => $r->esDeVenta() ? 'meta del año' : 'lo que asignó la Universidad'),

                TextColumn::make('comprometido')
                    ->label('Comprometido')
                    ->alignEnd()
                    ->color('warning')
                    // En un presupuesto de venta nada se compromete: no hay
                    // solicitudes de compra contra una meta de ingresos.
                    ->state(fn (Budget $r) => $r->esDeVenta() ? '—' : self::formato($r->comprometido()))
                    ->description(fn (Budget $r) => $r->esDeVenta() ? null : 'aprobado, sin llegar'),

                TextColumn::make('ejecutado')
                    ->label('Ejecutado')
                    ->alignEnd()
                    ->state(fn (Budget $r) => self::formato($r->ejecutado()))
                    // Se distingue lo que el sistema puede demostrar de lo que
                    // alguien anoto como arranque: son cosas distintas y
                    // mezclarlas quita valor a las dos.
                    ->description(fn (Budget $r) => $r->opening_executed > 0
                        ? 'incluye ' . self::formato((int) $r->opening_executed) . ' de arranque'
                        : ($r->esDeVenta() ? 'lo facturado' : 'ya recibido')),

                TextColumn::make('disponible')
                    ->label('Disponible')
                    ->alignEnd()
                    ->weight('bold')
                    ->state(fn (Budget $r) => self::formato($r->disponible()))
                    ->color(fn (Budget $r) => $r->esDeVenta()
                        // En uno de venta, quedar corto no es bueno: lo verde
                        // es haber llegado.
                        ? ($r->disponible() <= 0 ? 'success' : 'warning')
                        : ($r->disponible() > 0 ? 'success' : 'danger'))
                    ->description(fn (Budget $r) => $r->esDeVenta()
                        ? $r->porcentajeUsado() . '% de la meta'
                        : $r->porcentajeUsado() . '% usado'),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Budget::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state) => $state === 'vigente' ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('kind')->label('Clase')->options(Budget::TIPOS),
                SelectFilter::make('status')->label('Estado')->options(Budget::ESTADOS),
            ])
            ->recordActions([
                // De donde salen las cifras: sin este atajo hay que saber que
                // el camino es «Compras → nueva solicitud → elegir este
                // presupuesto», y quien no lo sabe se queda mirando un cero.
                Action::make('solicitudes')
                    ->label('Ver sus solicitudes')
                    ->iconButton()
                    ->tooltip('Ver las solicitudes de compra de este presupuesto')
                    ->icon('heroicon-o-clipboard-document-list')
                    ->color('gray')
                    ->visible(fn (Budget $r) => ! $r->esDeVenta())
                    ->url(fn (Budget $r) => '/admin/purchase-requests?tableFilters[budget_id][value]=' . $r->id),

                Action::make('pedir')
                    ->label('Nueva solicitud')
                    ->iconButton()
                    ->tooltip('Pedir algo contra este presupuesto')
                    ->icon('heroicon-o-plus-circle')
                    ->color('gray')
                    ->visible(fn (Budget $r) => ! $r->esDeVenta() && $r->status === 'vigente')
                    ->url(fn (Budget $r) => '/admin/purchase-requests/create'),

                EditAction::make()->iconButton()->tooltip('Editar'),
            ])
            // La equivalencia, a la vista: el presupuesto se habla en pesos y
            // el laboratorio cobra en FabCoins, y sin la tasa delante hay que
            // ir a buscarla para entender cualquiera de las dos cifras.
            // El resumen de arriba ya explica de donde salen las cifras: aqui
            // solo queda lo que no dice, para no decir dos veces lo mismo en
            // una pantalla.
            ->description(sprintf(
                'Aprobada compromete; recibida ejecuta. Lo de antes del sistema se anota como '
                . '«ejecutado de arranque» al editar. Todo en pesos, que es como se habla con la '
                . 'Universidad: 1 %s = %s%s.',
                config('fabos.currency.code'),
                config('fabos.money.symbol'),
                number_format((int) config('fabos.currency.peso_rate'), 0, ',', '.'),
            ));
    }

    private static function pesos(string $campo, string $titulo): TextColumn
    {
        return TextColumn::make($campo)
            ->label($titulo)
            ->alignEnd()
            ->formatStateUsing(fn (?int $state) => self::formato((int) $state));
    }

    private static function formato(int $pesos): string
    {
        return config('fabos.money.symbol') . number_format($pesos, 0, ',', '.');
    }
}
