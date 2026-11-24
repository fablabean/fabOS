<?php

namespace App\Filament\Resources\ArchivosMultimedia;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\ArchivosMultimedia\Pages\ListArchivosMultimedia;
use App\Models\ArchivoMultimedia;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Number;

/**
 * La carpeta multimedia de las páginas.
 *
 * Se sube un archivo —un STL, una foto, un video— y se copia su dirección para
 * usarla dentro de una animación HTML o en cualquier otra parte. Reemplazar el
 * archivo deja la misma dirección: el HTML que lo usa no se toca.
 */
class ArchivoMultimediaResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = ArchivoMultimedia::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $modelLabel = 'archivo';

    protected static ?string $pluralModelLabel = 'Multimedia de páginas';

    protected static ?string $navigationLabel = 'Multimedia de páginas';

    protected static ?string $slug = 'multimedia';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'nombre';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Comunicaciones';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            FileUpload::make('archivo')
                ->label('Archivo')
                ->disk('local')
                ->directory(ArchivoMultimedia::CARPETA)
                ->visibility('private')
                ->storeFileNamesIn('nombre_original')
                ->maxSize(ArchivoMultimedia::PESO_MAXIMO_KB)
                ->required()
                ->rules([fn () => function (string $attribute, $value, \Closure $fail) {
                    $nombre = $value instanceof \Illuminate\Http\UploadedFile ? $value->getClientOriginalName() : (string) $value;
                    if (! ArchivoMultimedia::extensionPermitida($nombre)) {
                        $fail('Ese tipo de archivo no se sube aquí. Las páginas HTML van en el bloque «Animación (HTML)» de la página.');
                    }
                }])
                ->helperText('STL, OBJ, GLB, imágenes, video, audio, PDF, JSON… hasta 90 MB. Si lo reemplazas después, la dirección no cambia.'),

            TextInput::make('nombre')
                ->label('Nombre')
                ->maxLength(120)
                ->placeholder('Si lo dejas vacío, toma el del archivo')
                ->helperText('Para encontrarlo en la lista; también va al final de la dirección.'),

            Textarea::make('nota')
                ->label('Para qué es')
                ->rows(2)
                ->placeholder('Modelo del rodillo para la animación del muro'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('vista')
                    ->label('')
                    ->state(fn (ArchivoMultimedia $r) => $r->esImagen() ? $r->url() : null)
                    ->width(56)->height(40)
                    ->extraImgAttributes(['style' => 'object-fit:cover;border-radius:4px'])
                    ->placeholder('—'),

                TextColumn::make('nombre')
                    ->label('Nombre')
                    ->weight('medium')
                    ->description(fn (ArchivoMultimedia $r) => $r->nota ? \Illuminate\Support\Str::limit($r->nota, 70) : null)
                    ->searchable(['nombre', 'nota', 'nombre_original']),

                TextColumn::make('tipo')
                    ->label('Tipo')
                    ->state(fn (ArchivoMultimedia $r) => strtoupper($r->extension()) ?: '—')
                    ->badge()
                    ->color('gray'),

                TextColumn::make('tamano')
                    ->label('Peso')
                    ->formatStateUsing(fn ($state) => Number::fileSize((int) $state, precision: 1))
                    ->sortable(),

                TextColumn::make('direccion')
                    ->label('Dirección')
                    ->state(fn (ArchivoMultimedia $r) => $r->url())
                    ->limit(48)
                    ->fontFamily('mono')
                    ->size('sm')
                    ->color('gray')
                    ->copyable()
                    ->copyMessage('Dirección copiada')
                    ->icon('heroicon-o-clipboard-document')
                    ->iconPosition('after')
                    ->tooltip('Clic para copiar'),

                TextColumn::make('created_at')
                    ->label('Subido')
                    ->since()
                    ->description(fn (ArchivoMultimedia $r) => $r->user?->name)
                    ->sortable(),
            ])
            ->recordActions([
                \Filament\Actions\Action::make('abrir')
                    ->iconButton()->tooltip('Abrir')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (ArchivoMultimedia $r) => $r->url(), shouldOpenInNewTab: true),
                EditAction::make()->iconButton()->tooltip('Editar o reemplazar'),
                DeleteAction::make()->iconButton()->tooltip('Borrar')
                    ->modalDescription('Lo que use esta dirección dejará de encontrarlo.'),
            ])
            ->emptyStateHeading('La carpeta está vacía')
            ->emptyStateDescription('Sube un STL, una foto o un video y copia su dirección para usarla dentro de una animación HTML de las páginas.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListArchivosMultimedia::route('/'),
        ];
    }
}
