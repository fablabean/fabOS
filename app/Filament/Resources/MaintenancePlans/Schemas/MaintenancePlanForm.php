<?php

namespace App\Filament\Resources\MaintenancePlans\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class MaintenancePlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->required(),
                Select::make('asset_id')
                    ->relationship('asset', 'name'),
                Select::make('risk_family_id')
                    ->relationship('riskFamily', 'name'),
                TextInput::make('every_days')
                    ->numeric(),
                TextInput::make('every_usage_minutes')
                    ->numeric(),
                TextInput::make('checklist'),
                Toggle::make('is_active')
                    ->required(),
                Textarea::make('instructions')
                    ->columnSpanFull(),
            ]);
    }
}
