<?php

namespace App\Filament\Resources\NotificationTemplates\Tables;

use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class NotificationTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('key')
            ->columns([
                TextColumn::make('name')
                    ->label('Aviso')
                    ->searchable()
                    ->weight('medium')
                    ->description(fn (NotificationTemplate $r) => $r->description),

                TextColumn::make('key')->label('Clave')->fontFamily('mono')->searchable(),

                TextColumn::make('channel')
                    ->label('Canal')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => NotificationTemplate::CANALES[$state] ?? $state),

                IconColumn::make('is_essential')
                    ->label('Esencial')
                    ->boolean()
                    ->tooltip(fn (NotificationTemplate $r) => $r->is_essential
                        ? 'No se puede silenciar'
                        : 'Cada persona puede dejar de recibirlo'),

                IconColumn::make('is_active')->label('Activa')->boolean(),

                TextColumn::make('enviados')
                    ->label('Enviados')
                    ->alignEnd()
                    ->state(fn (NotificationTemplate $r) => NotificationLog::where('key', $r->key)
                        ->where('status', 'enviado')
                        ->count()),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label('Activa')->default(true),
                TernaryFilter::make('is_essential')->label('Esencial'),
            ])
            ->recordActions([EditAction::make()]);
    }
}
