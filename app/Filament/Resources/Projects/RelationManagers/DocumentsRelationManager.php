<?php

namespace App\Filament\Resources\Projects\RelationManagers;

use App\Models\ProjectDocument;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Los documentos que sostienen cada etapa.
 *
 * Aquí es donde se abren las compuertas: sin la propuesta cargada, el proyecto
 * no pasa a contrato.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documentos';

    // Sin esto, el estado vacio de Filament dice «Cree un project member».
    protected static ?string $modelLabel = 'documento';

    protected static ?string $pluralModelLabel = 'documentos';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('kind')
                    ->label('Tipo')
                    ->options(ProjectDocument::TIPOS)
                    ->required()
                    ->helperText('Propuesta, contrato, brief e informe son los que abren etapas.'),

                TextInput::make('title')->label('Título')->required(),

                FileUpload::make('file_path')
                    ->label('Archivo')
                    ->directory('proyectos')
                    ->maxSize(10240)
                    ->columnSpanFull(),

                TextInput::make('url')
                    ->label('O un enlace')
                    ->url()
                    ->columnSpanFull()
                    ->helperText('Drive, Notion, lo que ya usen. Obligar a subir el archivo haría que documenten por fuera del sistema.'),

                DatePicker::make('signed_on')->label('Firmado el'),

                Textarea::make('notes')->label('Notas')->columnSpanFull(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('kind')
                    ->label('Tipo')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ProjectDocument::TIPOS[$state] ?? $state),

                TextColumn::make('title')
                    ->label('Documento')
                    ->searchable()
                    ->url(fn (ProjectDocument $r) => $r->enlace())
                    ->openUrlInNewTab(),

                TextColumn::make('signed_on')->label('Firmado')->date('d/m/Y')->placeholder('—'),

                TextColumn::make('uploadedBy.name')->label('Cargado por')->placeholder('—'),

                TextColumn::make('created_at')->label('Cuándo')->date('d/m/Y'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Añadir documento')
                    ->mutateDataUsing(function (array $data) {
                        $data['uploaded_by'] = auth()->id();

                        return $data;
                    }),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([]);
    }
}
