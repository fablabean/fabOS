<?php

namespace App\Filament\Resources\MaintenancePlans\Schemas;

use App\Models\MaintenancePlan;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Un plan preventivo (§8): qué equipos, cada cuánto, y qué se revisa.
 *
 * Era el formulario que genera Filament solo, con la lista de chequeo como un
 * campo de texto crudo. Los de la pauta se arman mejor desde Mantenimiento →
 * Pauta preventiva; aquí se ajusta uno.
 */
class MaintenancePlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('El plan')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->required(),

                        Toggle::make('is_active')
                            ->label('Activo')
                            ->default(true)
                            ->inline(false),

                        Select::make('every_days')
                            ->label('Cada cuánto')
                            ->options(collect(MaintenancePlan::PAUTA)->mapWithKeys(fn ($f) => [$f[0] => $f[1]])->all()
                                + [7 => 'Cada semana', 15 => 'Cada 15 días', 365 => 'Cada año'])
                            ->placeholder('Por uso, no por calendario')
                            ->helperText('O deja esto vacío y usa los minutos de uso.'),

                        TextInput::make('every_usage_minutes')
                            ->label('O cada tantos minutos de uso')
                            ->numeric()
                            ->helperText('Una láser se mide en horas de corte, no en días.'),

                        DatePicker::make('starts_on')
                            ->label('Primera revisión')
                            ->helperText('Vacío: la primera orden se abre ya.'),
                    ]),

                Section::make('Qué equipos')
                    ->description('Elegidos a mano; o uno solo; o una familia de riesgo entera.')
                    ->columns(2)
                    ->schema([
                        Select::make('assets')
                            ->label('Equipos')
                            ->relationship('assets', 'name')
                            ->multiple()
                            ->searchable()
                            ->preload()
                            ->columnSpanFull(),

                        Select::make('asset_id')
                            ->label('Un solo equipo')
                            ->relationship('asset', 'name')
                            ->searchable(),

                        Select::make('risk_family_id')
                            ->label('Toda una familia de riesgo')
                            ->relationship('riskFamily', 'name'),
                    ]),

                Section::make('Qué se revisa')
                    ->schema([
                        Textarea::make('checklist')
                            ->label('Lista de chequeo')
                            ->rows(5)
                            ->helperText('Un punto por línea. Se marca al cerrar cada orden.')
                            ->formatStateUsing(fn ($state) => implode("\n", MaintenancePlan::puntosDe($state)))
                            ->dehydrateStateUsing(fn ($state) => MaintenancePlan::puntosDe(preg_split('/\R/', (string) $state))),

                        Textarea::make('instructions')
                            ->label('Instrucciones')
                            ->rows(3),
                    ]),
            ]);
    }
}
