<?php

namespace App\Filament\Resources\Assets\Tables;

use App\Models\Asset;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class AssetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultGroup('area.name')
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Equipo')
                    ->searchable()
                    ->sortable()
                    ->weight('medium')
                    // El QR se imprime y se pega en la máquina: identificarla
                    // en el listado es más útil que ver el token completo.
                    ->description(fn (Asset $record) => $record->asset_tag ?: ($record->pool_key ? "grupo: {$record->pool_key}" : null)),

                TextColumn::make('riskFamily.name')
                    ->label('Familia de riesgo')
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('kind')
                    ->label('Tipo')
                    ->formatStateUsing(fn ($state) => Asset::TIPOS[$state] ?? $state)
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn ($state) => Asset::ESTADOS[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'operativo'         => 'success',
                        'mantenimiento'     => 'warning',
                        'fuera_de_servicio' => 'danger',
                        default             => 'gray',
                    })
                    ->sortable(),

                IconColumn::make('is_reservable')
                    ->label('Reservable')
                    ->boolean()
                    ->sortable(),

                IconColumn::make('unattended_use')
                    ->label('Desatendido')
                    ->boolean()
                    ->tooltip('El trabajo corre sin la persona presente')
                    ->toggleable(),

                TextColumn::make('autonomous_minutes')
                    ->label('Autonomía')
                    ->formatStateUsing(fn ($state) => $state ? self::duracion($state) : 'requiere check')
                    ->tooltip('Hasta cuánto puede reservar quien tiene certifab, sin visto bueno del responsable')
                    ->toggleable(),

                TextColumn::make('location.name')
                    ->label('Ubicación')
                    ->placeholder('sin asignar')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('dependencies_count')
                    ->label('Depende de')
                    ->counts('dependencies')
                    ->badge()
                    ->color('warning')
                    ->formatStateUsing(fn ($state) => $state ?: '—')
                    ->tooltip('Equipos que deben estar operativos para poder usarlo')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('area')
                    ->label('Área')
                    ->relationship('area', 'name')
                    ->preload()
                    ->multiple(),

                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(Asset::ESTADOS),

                SelectFilter::make('kind')
                    ->label('Tipo')
                    ->options(Asset::TIPOS),

                TernaryFilter::make('is_reservable')
                    ->label('Reservable')
                    ->placeholder('Todos')
                    ->trueLabel('Solo reservables')
                    ->falseLabel('Solo accesorios'),

                TernaryFilter::make('unattended_use')
                    ->label('Uso desatendido')
                    ->placeholder('Todos')
                    ->trueLabel('Solo desatendidos')
                    ->falseLabel('Solo presenciales'),

                TrashedFilter::make(),
            ])
            ->headerActions([
                Action::make('etiquetas')
                    ->label('Hoja de etiquetas QR')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->url(fn () => route('etiquetas'))
                    ->openUrlInNewTab()
                    ->tooltip('Los QR para pegar en cada máquina, listos para imprimir'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                ]),
            ]);
    }

    private static function duracion(int $minutos): string
    {
        if ($minutos < 60) {
            return "{$minutos} min";
        }

        $horas = intdiv($minutos, 60);
        $resto = $minutos % 60;

        return $resto ? "{$horas} h {$resto} min" : "{$horas} h";
    }
}
