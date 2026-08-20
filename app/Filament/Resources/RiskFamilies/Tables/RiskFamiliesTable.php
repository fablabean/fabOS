<?php

namespace App\Filament\Resources\RiskFamilies\Tables;

use App\Models\Certifab;
use App\Models\RiskFamily;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RiskFamiliesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultGroup('area.name')
            ->columns([
                TextColumn::make('name')
                    ->label('Familia')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (RiskFamily $record) => $record->slug),

                TextColumn::make('required_course_level')
                    ->label('Nivel exigido')
                    ->badge()
                    ->placeholder('sin exigencia')
                    // El nivel no habilita por sí solo: es el prerrequisito.
                    // El color sube con la exigencia para leerlo de un vistazo.
                    ->color(fn (?string $state) => match ($state) {
                        'bit', 'byte' => 'success',
                        'kilo'        => 'warning',
                        default       => 'danger',
                    })
                    ->sortable(),

                IconColumn::make('requires_companion')
                    ->label('Acompañamiento')
                    ->boolean()
                    ->trueIcon('heroicon-o-user-group')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->tooltip('Si se marca, nunca se opera en solitario'),

                TextColumn::make('assets_count')
                    ->label('Equipos')
                    ->counts('assets')
                    ->badge()
                    ->color('gray')
                    ->tooltip('Cuántos activos gobierna esta regla'),

                TextColumn::make('certifabs_count')
                    ->label('Personas habilitadas')
                    ->counts('certifabs')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('safety_notes')
                    ->label('Notas de seguridad')
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('area')
                    ->label('Área')
                    ->relationship('area', 'name')
                    ->preload()
                    ->multiple(),

                SelectFilter::make('required_course_level')
                    ->label('Nivel exigido')
                    ->options(array_combine(Certifab::NIVELES, Certifab::NIVELES)),

                TernaryFilter::make('requires_companion')
                    ->label('Acompañamiento')
                    ->placeholder('Todas')
                    ->trueLabel('Solo las que lo exigen')
                    ->falseLabel('Solo las autónomas'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
