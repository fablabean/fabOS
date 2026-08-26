<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Models\Asset;
use App\Models\Reservation;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Los equipos que usa el proyecto.
 *
 * Declararlos no bloquea nada —una máquina apartada durante los tres meses que
 * dura un proyecto dejaría al laboratorio sin laboratorio—. Lo que bloquea es
 * la producción, que es tiempo concreto. Esta lista responde antes: con qué se
 * cuenta, y sobre qué se puede programar.
 */
class AssetsRelationManager extends RelationManager
{
    protected static string $relationship = 'assets';

    protected static ?string $title = 'Activos';

    protected static ?string $modelLabel = 'equipo';

    protected static ?string $pluralModelLabel = 'equipos';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('note')
                ->label('Para qué')
                ->helperText('En qué se usa dentro del proyecto. Opcional, pero es lo que explica la elección dentro de seis meses.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Equipo')
                    ->weight('medium')
                    ->description(fn (Asset $r) => $r->area?->name),

                TextColumn::make('kind')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => Asset::TIPOS[$state] ?? $state),

                TextColumn::make('status')->label('Estado')->badge(),

                TextColumn::make('note')->label('Para qué')->placeholder('—')->wrap(),

                // Cuánto lleva fabricando para este proyecto: es la respuesta a
                // «¿de verdad lo estamos usando?».
                TextColumn::make('produccion')
                    ->label('Producción')
                    ->state(function (Asset $r) {
                        $horas = Reservation::query()
                            ->where('project_id', $this->getOwnerRecord()->id)
                            ->where('is_production', true)
                            ->where('reservable_type', Asset::class)
                            ->where('reservable_id', $r->id)
                            ->whereIn('status', ['confirmada', 'en_curso', 'completada'])
                            ->get()
                            ->sum(fn (Reservation $p) => $p->starts_at->diffInMinutes($p->ends_at)) / 60;

                        return $horas > 0 ? number_format($horas, 1, ',', '.') . ' h' : '—';
                    }),
            ])
            ->headerActions([
                AttachAction::make()
                    ->label('Declarar un equipo')
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn ($query) => $query->where('status', '!=', 'baja'))
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect()->label('Equipo'),
                        TextInput::make('note')->label('Para qué'),
                    ]),
            ])
            ->recordActions([DetachAction::make()->label('Quitar')])
            ->toolbarActions([]);
    }
}
