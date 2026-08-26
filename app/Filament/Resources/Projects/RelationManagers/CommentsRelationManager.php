<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Models\ProjectComment;
use App\Services\Projects\ProjectService;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * La conversación sobre la propuesta.
 *
 * Lo que dice quien pidió el proyecto llega aquí desde la página pública; lo
 * que responde el laboratorio se escribe aquí. Tenerlo junto al proyecto es
 * todo el punto: «casi, pero cambia la fecha» en un chat es una frase que nadie
 * vuelve a encontrar cuando hay que recordar qué se acordó.
 */
class CommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    protected static ?string $title = 'Conversación';

    protected static ?string $modelLabel = 'comentario';

    protected static ?string $pluralModelLabel = 'comentarios';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Textarea::make('body')
                ->label('Qué se responde')
                ->required()
                ->rows(4)
                ->columnSpanFull()
                ->helperText('Queda en el hilo del proyecto. Quien lo pidió lo ve en la página de la propuesta.'),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('body')
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('Cuándo')
                    ->dateTime('d/m/Y H:i')
                    ->timezone(config('fabos.lab.timezone'))
                    ->sortable(),

                TextColumn::make('quien')
                    ->label('Quién')
                    ->state(fn (ProjectComment $r) => $r->quien())
                    ->description(fn (ProjectComment $r) => ProjectComment::LADOS[$r->side] ?? $r->side),

                TextColumn::make('body')->label('Qué dijo')->wrap(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Responder')
                    ->using(fn (array $data) => app(ProjectService::class)->comentar(
                        $this->getOwnerRecord(),
                        $data['body'],
                        auth()->user(),
                    )),
            ])
            ->recordActions([DeleteAction::make()])
            ->toolbarActions([]);
    }
}
