<?php

namespace App\Filament\Resources\Zonas;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Zonas\Pages\CreateZona;
use App\Filament\Resources\Zonas\Pages\EditZona;
use App\Filament\Resources\Zonas\Pages\ListZonas;
use App\Filament\Resources\Zonas\RelationManagers\ModulosRelationManager;
use App\Models\Zona;
use App\Services\Media\OptimizadorDeImagen;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

/**
 * Las zonas de conocimiento: el material de cada tema, con su subdominio.
 *
 * Va en Formación porque es lo que se enseña, aunque no sea un curso: la
 * presentación con la que se recibe a los aspirantes, el material de apoyo de
 * Fab Academy, lo que se lee antes de usar la cortadora láser.
 */
class ZonaResource extends Resource
{
    use ControlaSuAcceso;

    protected static ?string $model = Zona::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleGroup;

    protected static ?string $modelLabel = 'zona';

    protected static ?string $pluralModelLabel = 'Zonas de conocimiento';

    protected static ?string $recordTitleAttribute = 'nombre';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Formación';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('La zona')
                ->columns(2)
                ->schema([
                    TextInput::make('nombre')
                        ->label('Nombre')
                        ->required()
                        ->maxLength(120)
                        ->live(onBlur: true)
                        ->afterStateUpdated(function ($state, $set, $get, ?Zona $record) {
                            if (! $record && blank($get('slug'))) {
                                $set('slug', Str::limit(Str::slug((string) $state), 40, ''));
                            }
                        }),

                    TextInput::make('slug')
                        ->label('Subdominio')
                        ->required()
                        ->maxLength(40)
                        ->unique(ignoreRecord: true)
                        ->regex('/^[a-z0-9][a-z0-9-]*$/')
                        ->notIn(Zona::RESERVADOS)
                        ->suffix('.' . config('fabos.zonas.dominio'))
                        ->validationMessages([
                            'regex'  => 'Solo minúsculas, números y guiones, sin tildes ni espacios: es una dirección.',
                            'not_in' => 'Ese nombre ya es otra cosa del sitio. Elige otro.',
                        ])
                        ->helperText(fn (?Zona $record) => 'Es su dirección. Mientras los subdominios no estén activos, abre en '
                            . url('/zonas/' . ($record?->slug ?? '…')) . '. Cambiarlo rompe los enlaces que ya circulen.'),

                    RichEditor::make('descripcion')
                        ->label('De qué se trata')
                        ->toolbarButtons([['bold', 'italic', 'link'], ['bulletList', 'orderedList'], ['undo', 'redo']])
                        ->columnSpanFull(),

                    FileUpload::make('foto_path')
                        ->label('Foto')
                        ->helperText('Arriba de la zona y en la lista de zonas. Mejor horizontal.')
                        ->disk('public')
                        ->visibility('public')
                        ->image()
                        ->directory('zonas')
                        ->maxSize(20480)
                        ->imageResizeMode('contain')
                        ->imageResizeTargetWidth(2000)
                        ->imageResizeTargetHeight(2000)
                        ->imageResizeUpscale(false)
                        ->saveUploadedFileUsing(fn ($file) => app(OptimizadorDeImagen::class)->guardar($file, 'zonas'))
                        ->columnSpanFull(),

                    Toggle::make('publicada')
                        ->label('Publicada')
                        ->helperText('Apagada, solo la ve el equipo: sirve para armarla antes de anunciarla.'),

                    TextInput::make('orden')->label('Orden')->numeric()->default(0),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('orden')
            ->columns([
                TextColumn::make('nombre')->label('Zona')->searchable()
                    ->description(fn (Zona $z) => $z->subdominio()),
                TextColumn::make('modulos_count')->label('Módulos')->counts('modulos'),
                IconColumn::make('publicada')->label('Publicada')->boolean(),
            ])
            ->recordActions([
                Action::make('ver')
                    ->iconButton()
                    ->tooltip('Ver la zona')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (Zona $z) => $z->url())
                    ->openUrlInNewTab(),
                EditAction::make()->iconButton()->tooltip('Editar'),
            ]);
    }

    public static function getRelations(): array
    {
        return [ModulosRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index'  => ListZonas::route('/'),
            'create' => CreateZona::route('/create'),
            'edit'   => EditZona::route('/{record}/edit'),
        ];
    }
}
