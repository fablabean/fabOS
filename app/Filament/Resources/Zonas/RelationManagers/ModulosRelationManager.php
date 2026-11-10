<?php

namespace App\Filament\Resources\Zonas\RelationManagers;

use App\Models\Course;
use App\Models\Modulo;
use App\Services\Media\OptimizadorDeImagen;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Unique;

/**
 * Los módulos de una zona: hoy, presentaciones de una sola página que se
 * suben hechas, como «Conoce el FabLab». El .html tiene que traer todo dentro
 * —imágenes, estilos, código— porque se sirve solo, sin carpeta al lado.
 */
class ModulosRelationManager extends RelationManager
{
    protected static string $relationship = 'modulos';

    protected static ?string $title = 'Módulos';

    protected static ?string $modelLabel = 'módulo';

    protected static ?string $pluralModelLabel = 'módulos';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('titulo')
                    ->label('Título')
                    ->required()
                    ->maxLength(160)
                    ->live(onBlur: true)
                    ->afterStateUpdated(function ($state, $set, $get, ?Modulo $record) {
                        if (! $record && blank($get('slug'))) {
                            $set('slug', Str::limit(Str::slug((string) $state), 80, ''));
                        }
                    }),

                TextInput::make('slug')
                    ->label('Dirección')
                    ->required()
                    ->maxLength(80)
                    ->regex('/^[a-z0-9][a-z0-9-]*$/')
                    ->notIn(Modulo::RESERVADOS)
                    ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('zona_id', $this->getOwnerRecord()->id))
                    ->prefix(fn () => $this->getOwnerRecord()->subdominio() . '/')
                    ->validationMessages([
                        'regex'  => 'Solo minúsculas, números y guiones, sin tildes ni espacios.',
                        'not_in' => 'Ese nombre ya es otra cosa del sitio. Elige otro.',
                        'unique' => 'Ya hay un módulo con esa dirección en esta zona.',
                    ]),

                Textarea::make('resumen')
                    ->label('Resumen')
                    ->rows(2)
                    ->maxLength(400)
                    ->helperText('Una o dos frases: sale en la tarjeta del módulo.')
                    ->columnSpanFull(),

                FileUpload::make('html_path')
                    ->label('La presentación (.html)')
                    ->helperText('Un solo archivo con todo dentro: imágenes, estilos y código. Para cambiarla, sube la versión nueva: la dirección sigue igual.')
                    ->disk('local')
                    ->directory('zonas')
                    ->acceptedFileTypes(['text/html'])
                    ->maxSize(51200)
                    ->required()
                    ->columnSpanFull(),

                FileUpload::make('portada_path')
                    ->label('Portada')
                    ->helperText('La imagen de la tarjeta, horizontal. Puede ser una captura de la primera lámina.')
                    ->disk('public')
                    ->visibility('public')
                    ->image()
                    ->directory('zonas/portadas')
                    ->maxSize(20480)
                    ->imageResizeMode('contain')
                    ->imageResizeTargetWidth(1600)
                    ->imageResizeTargetHeight(1600)
                    ->imageResizeUpscale(false)
                    ->saveUploadedFileUsing(fn ($file) => app(OptimizadorDeImagen::class)->guardar($file, 'zonas/portadas'))
                    ->columnSpanFull(),

                Select::make('acceso')
                    ->label('Quién lo ve')
                    ->options(Modulo::ACCESOS)
                    ->default('publico')
                    ->required()
                    ->live()
                    ->helperText('El equipo lo ve siempre, también sin publicar.'),

                Select::make('course_id')
                    ->label('Curso')
                    ->options(fn () => Course::orderBy('name')->pluck('name', 'id'))
                    ->searchable()
                    ->visible(fn ($get) => $get('acceso') === 'inscritos')
                    ->required(fn ($get) => $get('acceso') === 'inscritos')
                    ->helperText('Lo ven quienes estén inscritos o hayan aprobado alguna edición de este curso.'),

                Toggle::make('publicado')
                    ->label('Publicado')
                    ->helperText('Apagado, solo lo ve el equipo.'),

                Toggle::make('destacado')
                    ->label('Anclado arriba')
                    ->helperText('Va primero en la zona, antes que los demás.'),

                TextInput::make('orden')->label('Orden')->numeric()->default(0),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->reorderable('orden')
            ->defaultSort('orden')
            ->columns([
                TextColumn::make('titulo')->label('Módulo')
                    ->description(fn (Modulo $m) => $m->resumen ? Str::limit($m->resumen, 90) : null)
                    ->searchable(),
                TextColumn::make('acceso')->label('Quién lo ve')->badge()
                    ->formatStateUsing(fn ($state) => Modulo::ACCESOS[$state] ?? $state)
                    ->color(fn ($state) => $state === 'publico' ? 'success' : 'gray'),
                IconColumn::make('destacado')->label('Anclado')->boolean()
                    ->trueIcon('heroicon-s-bookmark')->falseIcon('heroicon-o-minus')->falseColor('gray'),
                IconColumn::make('publicado')->label('Publicado')->boolean(),
                TextColumn::make('updated_at')->label('Actualizado')
                    ->dateTime('d/m/Y H:i', config('fabos.lab.timezone'))->sortable(),
            ])
            ->headerActions([CreateAction::make()->label('Nuevo módulo')])
            ->recordActions([
                Action::make('ver')
                    ->iconButton()
                    ->tooltip('Abrir el módulo')
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->color('gray')
                    ->url(fn (Modulo $m) => $m->setRelation('zona', $this->getOwnerRecord())->url())
                    ->openUrlInNewTab(),
                EditAction::make()->iconButton()->tooltip('Editar'),
                DeleteAction::make()->iconButton()->tooltip('Borrar'),
            ]);
    }
}
