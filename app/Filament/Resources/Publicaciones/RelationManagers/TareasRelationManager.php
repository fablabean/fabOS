<?php

namespace App\Filament\Resources\Publicaciones\RelationManagers;

use App\Models\TareaDePublicacion;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Lo que hay que hacer para que la publicación salga, y quién lo hace (§21).
 */
class TareasRelationManager extends RelationManager
{
    protected static string $relationship = 'tareas';

    protected static ?string $title = 'Tareas';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('que')
                ->label('Qué hay que hacer')
                ->required()
                ->maxLength(160)
                ->placeholder('Grabar en el taller'),

            Select::make('responsable_id')
                ->label('Quién')
                ->options(fn () => \App\Filament\Componentes\SelectorDePersona::paraComunicaciones())
                ->searchable()
                ->default(fn () => $this->getOwnerRecord()->responsable_id),

            DatePicker::make('vence_el')
                ->label('Para cuándo'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('que')
            ->defaultSort('orden')
            ->reorderable('orden')
            ->paginated(false)
            ->emptyStateHeading('Sin tareas todavía')
            ->emptyStateDescription('«Poner las típicas» añade las que suele llevar este formato, con fecha contada hacia atrás desde el día en que sale.')
            ->columns([
                IconColumn::make('hecha')
                    ->label('')
                    ->state(fn (TareaDePublicacion $r) => $r->estaHecha())
                    ->boolean()
                    ->width('1px'),

                TextColumn::make('que')
                    ->label('Qué')
                    ->weight('medium')
                    ->color(fn (TareaDePublicacion $r) => $r->estaHecha() ? 'gray' : null)
                    ->description(fn (TareaDePublicacion $r) => $r->estaHecha()
                        ? 'hecha el ' . $r->hecha_at->timezone(config('fabos.lab.timezone'))->format('d/m')
                        : null),

                TextColumn::make('responsable.name')
                    ->label('Quién')
                    ->placeholder('sin asignar'),

                TextColumn::make('vence_el')
                    ->label('Para cuándo')
                    ->date('D d/m')
                    ->placeholder('—')
                    ->color(fn (TareaDePublicacion $r) => $r->vaTarde() ? 'danger' : null)
                    ->description(fn (TareaDePublicacion $r) => $r->vaTarde() ? 'va tarde' : null),
            ])
            ->headerActions([
                Action::make('tipicas')
                    ->label('Poner las típicas')
                    ->icon('heroicon-o-sparkles')
                    ->color('gray')
                    ->visible(fn () => $this->getOwnerRecord()->tareas()->doesntExist())
                    ->action(function () {
                        $n = $this->getOwnerRecord()->ponerTareasTipicas();

                        Notification::make()->title($n . ' tareas añadidas')->success()->send();
                    }),

                CreateAction::make()->label('Añadir tarea'),
            ])
            ->recordActions([
                Action::make('marcar')
                    ->label(fn (TareaDePublicacion $r) => $r->estaHecha() ? 'Reabrir' : 'Hecha')
                    ->icon(fn (TareaDePublicacion $r) => $r->estaHecha() ? 'heroicon-o-arrow-uturn-left' : 'heroicon-o-check')
                    ->color(fn (TareaDePublicacion $r) => $r->estaHecha() ? 'gray' : 'success')
                    ->action(fn (TareaDePublicacion $r) => $r->update(['hecha_at' => $r->estaHecha() ? null : now()])),

                EditAction::make()->iconButton(),
                DeleteAction::make()->iconButton(),
            ]);
    }
}
