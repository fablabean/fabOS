<?php

namespace App\Filament\Resources\Publicaciones\Tables;

use App\Filament\Resources\Publicaciones\AccionesDelFlujo;
use App\Models\Publicacion;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class PublicacionesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('programada_para')
            ->columns([
                TextColumn::make('programada_para')
                    ->label('Sale')
                    ->dateTime('D d/m H:i')
                    ->timezone(config('fabos.lab.timezone'))
                    ->placeholder('sin fecha')
                    ->sortable()
                    ->color(fn (Publicacion $r) => $r->vaTarde() ? 'danger' : null)
                    ->description(fn (Publicacion $r) => $r->vaTarde() ? 'ya pasó su fecha' : null),

                TextColumn::make('titulo')
                    ->label('Publicación')
                    ->searchable()
                    ->weight('medium')
                    ->wrap()
                    ->description(fn (Publicacion $r) => $r->nombresDeLasRedes() ?: null),

                TextColumn::make('formato')
                    ->label('Formato')
                    ->formatStateUsing(fn (?string $state) => Publicacion::FORMATOS[$state] ?? $state)
                    ->toggleable(),

                TextColumn::make('pilar')
                    ->label('Tema')
                    ->formatStateUsing(fn (?string $state) => Publicacion::PILARES[$state] ?? $state)
                    ->toggleable(),

                TextColumn::make('estado')
                    ->label('Estado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Publicacion::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state) => Publicacion::COLORES[$state] ?? 'gray'),

                TextColumn::make('responsable.name')
                    ->label('Quién')
                    ->placeholder('sin asignar')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('estado')->label('Estado')->options(Publicacion::ESTADOS)->multiple(),
                SelectFilter::make('pilar')->label('Tema')->options(Publicacion::PILARES),
                SelectFilter::make('responsable_id')->label('Quién')->relationship('responsable', 'name'),
                TernaryFilter::make('abiertas')
                    ->label('Pendientes')
                    ->placeholder('Todas')
                    ->trueLabel('Solo lo que falta publicar')
                    ->falseLabel('Solo publicadas o descartadas')
                    ->queries(
                        true: fn ($q) => $q->abiertas(),
                        false: fn ($q) => $q->whereIn('estado', Publicacion::CERRADOS),
                    ),
            ])
            ->recordActions([
                EditAction::make()->iconButton(),
                ActionGroup::make(AccionesDelFlujo::todas())->tooltip('Siguiente paso'),
            ]);
    }
}
