<?php

namespace App\Filament\Resources\NotificationLogs\Tables;

use App\Models\NotificationLog;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class NotificationLogsTable
{
    public static function configure(Table $table): Table
    {
        $tz = config('fabos.lab.timezone');

        return $table
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Cuándo')
                    ->formatStateUsing(fn ($state) => $state?->timezone($tz)->format('d/m/Y H:i'))
                    ->sortable(),

                TextColumn::make('key')->label('Aviso')->fontFamily('mono')->searchable(),

                TextColumn::make('to')
                    ->label('A')
                    ->searchable()
                    ->description(fn (NotificationLog $r) => $r->user?->name),

                TextColumn::make('subject')->label('Asunto')->wrap()->limit(60),

                TextColumn::make('status')
                    ->label('Resultado')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => NotificationLog::ESTADOS[$state] ?? $state)
                    ->color(fn (string $state) => match ($state) {
                        'enviado' => 'success',
                        'omitido' => 'gray',
                        default   => 'danger',
                    })
                    ->description(fn (NotificationLog $r) => $r->reason),
            ])
            ->filters([
                SelectFilter::make('status')->label('Resultado')->options(NotificationLog::ESTADOS),
                SelectFilter::make('key')
                    ->label('Aviso')
                    ->options(fn () => NotificationLog::query()
                        ->distinct()
                        ->orderBy('key')
                        ->pluck('key', 'key')),
            ])
            ->recordActions([
                ViewAction::make()
                    ->label('Ver el texto')
                    ->modalHeading(fn (NotificationLog $r) => $r->subject ?? $r->key)
                    ->modalContent(fn (NotificationLog $r) => view('filament.avisos.registro', ['registro' => $r]))
                    ->modalSubmitAction(false),
            ])
            ->toolbarActions([]);
    }
}
