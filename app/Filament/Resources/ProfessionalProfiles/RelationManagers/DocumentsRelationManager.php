<?php

namespace App\Filament\Resources\ProfessionalProfiles\RelationManagers;

use App\Filament\Componentes\ArchivoPrivado;
use App\Models\ProfileDocument;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Los papeles del perfil.
 *
 * Es lo que la Universidad pide para inscribir a alguien, y lo que se tarda
 * semanas en reunir si se pide por correo cada vez. Van al **disco privado**:
 * aquí hay cédulas y certificaciones bancarias, y de eso no puede haber una URL
 * adivinable.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    protected static ?string $title = 'Documentos';

    protected static ?string $modelLabel = 'documento';

    protected static ?string $pluralModelLabel = 'documentos';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('kind')
                    ->label('Tipo')
                    ->options(ProfileDocument::TIPOS)
                    ->required()
                    ->helperText('Hoja de vida, identidad, RUT, certificación bancaria, seguridad social y autorización de datos son los que se exigen para presentarlo.'),

                TextInput::make('title')->label('Título')->required(),

                ArchivoPrivado::previsualizar(FileUpload::make('file_path'))
                    ->label('Archivo')
                    ->directory('perfiles')
                    ->maxSize(20480)
                    ->helperText('PDF o imagen, hasta 20 MB. Se guarda en el disco privado: solo se abre desde el panel.')
                    ->columnSpanFull(),

                TextInput::make('url')
                    ->label('O un enlace')
                    ->url()
                    ->columnSpanFull()
                    ->helperText('Drive, Notion, lo que ya usen. Obligar a subir el archivo haría que documenten por fuera del sistema.'),

                DatePicker::make('issued_on')->label('Expedido el'),

                DatePicker::make('expires_on')
                    ->label('Vence el')
                    ->helperText('Los antecedentes y la planilla caducan; un título no. Déjalo vacío si no aplica.'),

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
                    ->formatStateUsing(fn (string $state) => ProfileDocument::TIPOS[$state] ?? $state),

                TextColumn::make('title')
                    ->label('Documento')
                    ->searchable()
                    ->url(fn (ProfileDocument $r) => $r->enlace())
                    ->openUrlInNewTab(),

                TextColumn::make('expires_on')
                    ->label('Vence')
                    ->date('d/m/Y')
                    ->placeholder('—')
                    // Un antecedente vencido no sirve para contratar, y de eso
                    // uno se entera cuando compras lo devuelve.
                    ->color(fn (ProfileDocument $r) => $r->estaVencido() ? 'danger' : null)
                    ->description(fn (ProfileDocument $r) => $r->estaVencido() ? 'vencido' : null),

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
