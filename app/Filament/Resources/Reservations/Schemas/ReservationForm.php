<?php

namespace App\Filament\Resources\Reservations\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class ReservationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('reservable_type')
                    ->required(),
                TextInput::make('reservable_id')
                    ->required()
                    ->numeric(),
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                Select::make('supervisor_id')
                    ->relationship('supervisor', 'name'),
                TextInput::make('status')
                    ->required()
                    ->default('solicitada'),
                TextInput::make('mode')
                    ->required()
                    ->default('directa'),
                DateTimePicker::make('starts_at')
                    ->required(),
                DateTimePicker::make('ends_at')
                    ->required(),
                DateTimePicker::make('checked_in_at'),
                DateTimePicker::make('checked_out_at'),
                TextInput::make('estimated_cost_minor')
                    ->required()
                    ->numeric()
                    ->default(0),
                TextInput::make('actual_cost_minor')
                    ->numeric(),
                Textarea::make('purpose')
                    ->columnSpanFull(),
                Textarea::make('status_reason')
                    ->columnSpanFull(),
                TextInput::make('period'),
            ]);
    }
}
