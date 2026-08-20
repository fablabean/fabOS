<?php

namespace App\Filament\Resources\ShiftAssignments\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ShiftAssignmentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                DateTimePicker::make('starts_at')
                    ->required(),
                DateTimePicker::make('ends_at')
                    ->required(),
                TextInput::make('reason')
                    ->required(),
                Toggle::make('counts_as_overtime')
                    ->required(),
                TextInput::make('assigned_by')
                    ->numeric(),
                DateTimePicker::make('accepted_at'),
                Textarea::make('conflict_note')
                    ->columnSpanFull(),
            ]);
    }
}
