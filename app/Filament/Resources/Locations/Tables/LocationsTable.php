<?php

namespace App\Filament\Resources\Locations\Tables;

use App\Models\Location;
use App\Services\Inventory\UbicacionesEnSerie;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LocationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label('Ubicación')->searchable()->weight('medium'),
                TextColumn::make('parent.name')->label('Dentro de')->placeholder('raíz')->searchable(),
                TextColumn::make('assets_count')->label('Equipos aquí')->counts('assets')->badge()->color('gray'),
                TextColumn::make('children_count')->label('Sub-ubicaciones')->counts('children')->badge()->color('gray'),

                // El efectivo, no el declarado: lo que importa es donde esta
                // esa gaveta, no si el dato lo puso ella o su estante.
                TextColumn::make('espacio')
                    ->label('Espacio')
                    ->state(fn (\App\Models\Location $record) => $record->espacio()?->name)
                    ->placeholder('sin asignar')
                    ->description(fn (\App\Models\Location $record) => $record->space_id ? null : 'heredado')
                    ->badge()
                    ->color(fn ($state) => $state ? 'success' : 'warning'),
            ])
            ->headerActions([self::crearEnSerie()])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    /**
     * Dieciseis gavetas de un rack, sin teclearlas una por una.
     *
     * Teclear nombres iguales salvo el numero es trabajo de copiadora, e invita
     * a errores que luego cuestan: una gaveta saltada, dos con el mismo nombre.
     */
    private static function crearEnSerie(): Action
    {
        return Action::make('enSerie')
            ->label('Crear varias')
            ->icon('heroicon-o-squares-plus')
            ->modalHeading('Crear varias ubicaciones de una vez')
            ->modalDescription('Para un rack con gavetas, una mesa con cajones, un mueble con casillas.')
            ->modalSubmitActionLabel('Crear')
            ->schema([
                Select::make('parent_id')
                    ->label('Dentro de')
                    ->options(fn () => Location::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->helperText('El mueble que las contiene. Heredan su espacio.'),

                TextInput::make('base')
                    ->label('Nombre')
                    ->placeholder('Gaveta')
                    ->required()
                    ->live(onBlur: true)
                    ->maxLength(80),

                TextInput::make('cantidad')
                    ->label('Cuántas')
                    ->numeric()
                    ->default(16)
                    ->minValue(1)
                    ->maxValue(UbicacionesEnSerie::MAXIMO)
                    ->required()
                    ->live(onBlur: true),

                TextInput::make('desde')
                    ->label('Empezando en')
                    ->numeric()
                    ->default(1)
                    ->minValue(0)
                    ->required()
                    ->live(onBlur: true),

                Toggle::make('ceros')
                    ->label('Rellenar con ceros')
                    ->default(true)
                    ->live()
                    ->helperText('Sin esto, ordenadas por nombre «Gaveta 10» va antes que «Gaveta 2».'),

                Placeholder::make('vista')
                    ->label('Van a quedar así')
                    ->content(fn ($get) => app(UbicacionesEnSerie::class)->vistaPrevia(
                        (string) $get('base'),
                        (int) $get('cantidad'),
                        (int) $get('desde'),
                        (bool) $get('ceros'),
                    ) ?: '—'),
            ])
            ->action(function (array $data) {
                $r = app(UbicacionesEnSerie::class)->crear(
                    Location::findOrFail($data['parent_id']),
                    (string) $data['base'],
                    (int) $data['cantidad'],
                    (int) $data['desde'],
                    (bool) ($data['ceros'] ?? true),
                );

                if ($r['creadas'] !== []) {
                    Notification::make()
                        ->title(count($r['creadas']) . ' ubicaciones creadas')
                        ->body(implode(' · ', array_slice($r['creadas'], 0, 6))
                            . (count($r['creadas']) > 6 ? ' …' : ''))
                        ->success()
                        ->send();
                }

                if ($r['omitidas'] !== []) {
                    Notification::make()
                        ->title('Algunas ya existían')
                        ->body('No se repitieron: ' . implode(' · ', $r['omitidas'])
                            . '. Dos con el mismo nombre no se podrían distinguir.')
                        ->warning()
                        ->persistent()
                        ->send();
                }
            });
    }
}
