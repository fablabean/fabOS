<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Models\ProjectTimeLog;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Las horas dedicadas al proyecto.
 *
 * Es la parte del costo que más se olvida y casi siempre la más grande: el
 * tiempo de la gente. Sin ella, cualquier proyecto parece barato.
 */
class TimeLogsRelationManager extends RelationManager
{
    protected static string $relationship = 'timeLogs';

    protected static ?string $title = 'Horas';

    // Sin esto, el estado vacio de Filament dice «Cree un project member».
    protected static ?string $modelLabel = 'registro de horas';

    protected static ?string $pluralModelLabel = 'registros de horas';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('user_id')
                    ->label('Quién')
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                    ->searchable(),

                TextInput::make('external_name')
                    ->label('O alguien de fuera')
                    ->helperText('Un proveedor no tiene cuenta, pero su tiempo sí cuesta.'),

                DatePicker::make('worked_on')
                    ->label('Qué día')
                    ->default(now())
                    ->required(),

                TextInput::make('hours')
                    ->label('Horas')
                    ->numeric()
                    ->required()
                    ->minValue(0.25)
                    ->step(0.25),

                TextInput::make('activity')->label('En qué')->columnSpanFull(),

                TextInput::make('hourly_cost')
                    ->label('Costo por hora')
                    ->numeric()
                    ->prefix(config('fabos.money.symbol'))
                    ->placeholder(number_format((int) config('fabos.money.hourly_cost'), 0, ',', '.'))
                    ->helperText('En blanco toma la tarifa de referencia del laboratorio. No es el sueldo de nadie: queda congelada en la línea.'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('activity')
            ->defaultSort('worked_on', 'desc')
            ->columns([
                TextColumn::make('worked_on')->label('Día')->date('d/m/Y')->sortable(),

                TextColumn::make('quien')
                    ->label('Quién')
                    ->state(fn (ProjectTimeLog $r) => $r->quien()),

                TextColumn::make('activity')->label('En qué')->wrap()->placeholder('—'),

                TextColumn::make('hours')->label('Horas')->alignEnd(),

                TextColumn::make('costo')
                    ->label('Costo')
                    ->alignEnd()
                    ->weight('medium')
                    ->state(fn (ProjectTimeLog $r) => config('fabos.money.symbol')
                        . number_format($r->costo(), 0, ',', '.')),
            ])
            ->headerActions([CreateAction::make()->label('Registrar horas')])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([]);
    }
}
