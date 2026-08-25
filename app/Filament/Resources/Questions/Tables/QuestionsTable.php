<?php

namespace App\Filament\Resources\Questions\Tables;

use App\Models\Question;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class QuestionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Lo que lleva más tiempo esperando, primero. Una pregunta sin
            // responder envejece mal: quien la hizo deja de contar con esto.
            ->defaultSort('created_at', 'asc')
            ->modifyQueryUsing(fn ($query) => $query->withCount('respuestasPublicadas'))
            ->columns([
                TextColumn::make('title')
                    ->label('Pregunta')
                    ->wrap()
                    ->searchable()
                    ->description(fn (Question $record) => str($record->body)->limit(110)),

                TextColumn::make('status')
                    ->label('Estado')
                    ->formatStateUsing(fn ($state) => Question::ESTADOS[$state] ?? $state)
                    ->badge()
                    ->color(fn ($state) => $state === 'abierta' ? 'warning' : 'success'),

                TextColumn::make('user.name')
                    ->label('Quién preguntó')
                    ->toggleable(),

                TextColumn::make('area.name')
                    ->label('Área')
                    ->placeholder('sin clasificar')
                    ->toggleable(),

                TextColumn::make('asset.name')
                    ->label('Equipo')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('created_at')
                    ->label('Esperando desde')
                    ->since()
                    ->sortable(),

                TextColumn::make('vistas')
                    ->label('Vistas')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(Question::ESTADOS)
                    ->default('abierta'),

                SelectFilter::make('area')
                    ->label('Área')
                    ->relationship('area', 'name')
                    ->preload(),
            ])
            ->recordActions([
                // Se responde en el sitio: alli esta el borrador de la IA y el
                // texto tal como lo va a leer quien pregunto.
                Action::make('responder')
                    ->label(fn (Question $record) => $record->status === 'abierta' ? 'Responder' : 'Ver')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color(fn (Question $record) => $record->status === 'abierta' ? 'primary' : 'gray')
                    ->url(fn (Question $record) => route('preguntas.show', $record))
                    ->openUrlInNewTab(),
            ])
            ->emptyStateHeading('Ninguna pregunta todavía')
            ->emptyStateDescription('Cuando alguien pregunte desde el sitio, aparecerá aquí.');
    }
}
