<?php

namespace App\Filament\Resources\WorkSchedules\Schemas;

use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Schemas\Schema;

class WorkScheduleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->relationship('user', 'name')
                    ->required(),
                TextInput::make('weekday')
                    ->required()
                    ->numeric(),
                TimePicker::make('starts_at')
                    ->required(),
                TimePicker::make('ends_at')
                    ->required(),
                TextInput::make('break_minutes')
                    ->required()
                    ->numeric()
                    ->default(60),
                DatePicker::make('effective_from')
                    ->required(),
                DatePicker::make('effective_until'),
            ]);
    }
}
