<?php

namespace App\Filament\Resources\Dispositivos\RelationManagers;

use App\Models\Iot\Turno;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** La fila y el historial: quién jugó, cuánto, y cómo llegó. Solo se mira. */
class TurnosRelationManager extends RelationManager
{
    protected static string $relationship = 'turnos';

    protected static ?string $title = 'Turnos';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        $tz = config('fabos.lab.timezone');

        return $table
            ->poll('10s')
            ->defaultSort('empieza_at', 'desc')
            ->columns([
                TextColumn::make('empieza_at')->label('Empieza')->dateTime('d/m H:i', $tz),
                TextColumn::make('user.name')->label('Quién')->placeholder(fn (Turno $t) => $t->nombre)
                    ->description(fn (Turno $t) => $t->user?->email)->searchable(),
                TextColumn::make('minutos')->label('Min'),
                TextColumn::make('origen')->label('Cómo')
                    ->formatStateUsing(fn (string $state) => Turno::ORIGENES[$state] ?? $state)
                    ->description(fn (Turno $t) => $t->invitadoPor ? 'Lo invitó ' . $t->invitadoPor->name : ($t->fabcoins ? $t->fabcoins . ' ' . config('fabos.currency.code') : null)),
                TextColumn::make('estado')->badge()
                    ->state(fn (Turno $t) => $t->estado())
                    ->color(fn (string $state) => match ($state) {
                        'Jugando' => 'success', 'En fila' => 'warning', 'Cancelado' => 'danger', default => 'gray',
                    }),
            ]);
    }
}
