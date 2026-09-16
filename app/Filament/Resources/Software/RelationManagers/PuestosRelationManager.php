<?php

namespace App\Filament\Resources\Software\RelationManagers;

use App\Models\SoftwarePuesto;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Los puestos de licencia y quién los usa (§19).
 *
 * Contesta dos preguntas que llegan por separado: «¿nos alcanzan los puestos?»
 * al empezar el semestre, y «¿a quién hay que quitarle el acceso?» cuando
 * alguien se va.
 *
 * Por eso un puesto se **libera y no se borra**: borrar la fila responde la
 * primera y deja la segunda sin historia.
 */
class PuestosRelationManager extends RelationManager
{
    protected static string $relationship = 'puestosAsignados';

    protected static ?string $title = 'Quién lo usa';

    protected static ?string $modelLabel = 'puesto';

    /** Cuantos van de cuantos, en la propia pestaña. */
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        return $ownerRecord->puestos === null
            ? (string) $ownerRecord->puestosOcupados()
            : $ownerRecord->puestosOcupados().'/'.$ownerRecord->puestos;
    }

    public static function getBadgeColor(Model $ownerRecord, string $pageClass): ?string
    {
        return $ownerRecord->seFueDePuestos() ? 'danger' : null;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('user_id')
                ->label('Quién')
                ->relationship('user', 'name')
                ->searchable()
                ->preload()
                ->placeholder('Alguien de fuera del sistema')
                ->helperText('Déjalo vacío si el puesto no es de una persona con cuenta.')
                ->columnSpanFull(),

            TextInput::make('etiqueta')
                ->label('O de qué es')
                ->maxLength(120)
                ->placeholder('Equipo de la sala de corte')
                ->helperText('Solo si no elegiste persona arriba.')
                ->columnSpanFull(),

            DatePicker::make('asignado_el')->label('Desde')->default(now()),

            // Ponerla a mano tambien vale: a veces se apunta despues de que
            // la persona ya se fue.
            DatePicker::make('liberado_el')
                ->label('Liberado el')
                ->helperText('Vacío mientras lo siga usando.'),

            Textarea::make('notas')->label('Notas')->rows(2)->maxLength(500)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('etiqueta')
            ->defaultSort(fn (Builder $query) => $query
                ->orderByRaw('liberado_el is not null')
                ->orderBy('asignado_el'))
            ->columns([
                TextColumn::make('quien')
                    ->label('Quién')
                    ->weight('medium')
                    ->state(fn (SoftwarePuesto $r) => $r->deQuienEs())
                    ->description(fn (SoftwarePuesto $r) => $r->user?->email),

                TextColumn::make('asignado_el')->label('Desde')->date('d/m/Y')->placeholder('—'),

                TextColumn::make('liberado_el')
                    ->label('Estado')
                    ->badge()
                    ->state(fn (SoftwarePuesto $r) => $r->estaOcupado() ? 'En uso' : 'Liberado')
                    ->color(fn (SoftwarePuesto $r) => $r->estaOcupado() ? 'success' : 'gray')
                    ->description(fn (SoftwarePuesto $r) => $r->liberado_el?->format('d/m/Y')),

                TextColumn::make('notas')->label('Notas')->limit(40)->toggleable(),
            ])
            ->filters([
                Filter::make('ocupados')
                    ->label('Solo los que están en uso')
                    ->query(fn (Builder $query) => $query->ocupados())
                    ->default(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Asignar un puesto')
                    /*
                     * No se impide pasarse de puestos, se avisa.
                     *
                     * El sistema no puede saber si se compraron tres mas ayer,
                     * y bloquear una asignacion real por una cifra
                     * desactualizada haria que se dejara de usar la pantalla.
                     */
                    ->after(function () {
                        $software = $this->getOwnerRecord()->fresh();

                        if ($software->seFueDePuestos()) {
                            Notification::make()
                                ->warning()
                                ->title('Van más puestos que licencias')
                                ->body('Hay '.$software->puestosOcupados().' en uso y '.$software->puestos.' pagados. Se apuntó igual: corrige la cifra o libera un puesto.')
                                ->persistent()
                                ->send();
                        }
                    }),
            ])
            ->recordActions([
                // Liberar es el gesto de «esta persona se fue»: un boton, no
                // buscar el campo de fecha dentro del formulario.
                Action::make('liberar')
                    ->label('Liberar')
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->visible(fn (SoftwarePuesto $r) => $r->estaOcupado())
                    ->requiresConfirmation()
                    ->modalHeading('Liberar el puesto')
                    ->modalDescription('Queda registrado que lo usó y hasta cuándo. Acuérdate de quitarle el acceso también en el propio programa: esto es el inventario, no el proveedor.')
                    ->action(fn (SoftwarePuesto $r) => $r->update(['liberado_el' => now()])),

                EditAction::make(),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->emptyStateHeading('Nadie tiene puesto asignado todavía');
    }
}
