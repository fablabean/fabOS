<?php

namespace App\Filament\Resources\Courses\Tables;

use App\Models\Course;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CoursesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('level')
            ->columns([
                TextColumn::make('name')
                    ->label('Curso')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (Course $r) => $r->area?->name),

                TextColumn::make('level')
                    ->label('Nivel')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => $state),

                TextColumn::make('habilita')
                    ->label('Habilita')
                    ->state(fn (Course $r) => $r->riskFamilies->pluck('name')->implode(', ') ?: '—')
                    ->wrap()
                    ->tooltip('Familias de riesgo sobre las que otorga certifab al aprobarlo'),

                TextColumn::make('hours')->label('Horas')->alignEnd()->placeholder('—'),

                TextColumn::make('ediciones')
                    ->label('Ediciones')
                    ->alignEnd()
                    ->state(fn (Course $r) => $r->editions()->count()),

                IconColumn::make('is_public')->label('En el sitio')->boolean(),
                IconColumn::make('is_active')->label('Activo')->boolean(),
            ])
            ->filters([
                SelectFilter::make('level')->label('Nivel')->options(Course::NIVELES),
                TernaryFilter::make('is_active')->label('Activo')->default(true),
            ])
            ->recordActions([EditAction::make()]);
    }
}
