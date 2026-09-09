<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Filament\Componentes\ArchivoPrivado;
use App\Filament\Resources\Projects\Actions\AvisarNovedades;
use App\Models\Evidencia;
use App\Models\ProjectComment;
use App\Services\Projects\ProjectService;
use App\Services\Projects\SoportesDeSolicitud;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\FileUpload;
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

            /*
             * Con fotos, si hacen falta: el avance de la pieza, una captura
             * del diseño, un plano. Van pegadas a la respuesta —se ven debajo
             * de ella en la propuesta— y ademas quedan como soportes del
             * proyecto. Las imagenes viajan dentro del correo al avisar.
             */
            ArchivoPrivado::previsualizar(FileUpload::make('adjuntos'))
                ->label('Imágenes o archivos')
                ->multiple()
                ->maxFiles(SoportesDeSolicitud::MAXIMO)
                ->maxSize(SoportesDeSolicitud::TAMANO_MAXIMO)
                ->disk('local')
                ->directory('proyectos/soportes')
                ->visibility('private')
                ->storeFileNamesIn('nombres')
                ->columnSpanFull()
                ->helperText('Hasta ' . SoportesDeSolicitud::MAXIMO . ' archivos de '
                    . intdiv(SoportesDeSolicitud::TAMANO_MAXIMO, 1024) . ' MB. Se ven junto a la respuesta en la propuesta, '
                    . 'y las imágenes van dentro del correo cuando avises que hay novedades.'),
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

                TextColumn::make('adjuntos')
                    ->label('Adjuntos')
                    ->state(fn (ProjectComment $r) => $r->adjuntos->isEmpty() ? null : $r->adjuntos
                        ->map(fn (Evidencia $e) => '<a href="' . e(ArchivoPrivado::url($e->file_path, $e->comoSeLlama(), descargar: ! $e->esImagen()))
                            . '" target="_blank" rel="noopener" class="underline">' . e($e->comoSeLlama()) . '</a>')
                        ->implode('<br>'))
                    ->html()
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Responder')
                    ->using(function (array $data) {
                        $proyecto = $this->getOwnerRecord();
                        $comentario = app(ProjectService::class)->comentar($proyecto, $data['body'], auth()->user());

                        app(SoportesDeSolicitud::class)->anotarSubidos(
                            $proyecto, $comentario,
                            (array) ($data['adjuntos'] ?? []), (array) ($data['nombres'] ?? []),
                            auth()->user(),
                        );

                        return $comentario;
                    }),

                // Responder aqui no avisa a nadie: quien pidio el proyecto solo
                // lo veia si se le ocurria entrar. Este boton se lo cuenta.
                AvisarNovedades::make()
                    ->record(fn () => $this->getOwnerRecord()),
            ])
            ->recordActions([DeleteAction::make()])
            ->toolbarActions([]);
    }
}
