<?php

namespace App\Filament\Resources\Courses\Schemas;

use App\Models\Course;
use App\Services\Media\OptimizadorDeImagen;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CourseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('El curso')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nombre')->required(),

                        TextInput::make('slug')
                            ->label('Identificador')
                            ->required()
                            ->unique(ignoreRecord: true),

                        Select::make('level')
                            ->label('Nivel')
                            ->options(Course::NIVELES)
                            ->default('bit')
                            ->required()
                            ->helperText('Marca cuánta autonomía llega a dar. tera es Fab Academy.'),

                        Select::make('area_id')->label('Área')->relationship('area', 'name'),

                        TextInput::make('hours')->label('Duración en horas')->numeric(),

                        TextInput::make('price_minor')
                            ->label('Costo')
                            ->numeric()
                            ->default(0)
                            ->prefix(config('fabos.currency.code'))
                            ->helperText('Cero si no tiene costo para la comunidad.')
                            ->formatStateUsing(fn (?int $state) => $state === null ? null : $state / config('fabos.currency.minor_units'))
                            ->dehydrateStateUsing(fn (?string $state) => (int) round(((float) $state) * config('fabos.currency.minor_units'))),
                    ]),

                Section::make('Qué habilita')
                    ->description('Aprobarlo otorga certifab sobre estas familias de riesgo. Sin esto, el curso es solo una charla: no abre ninguna máquina.')
                    ->schema([
                        Select::make('riskFamilies')
                            ->label('Familias de riesgo')
                            ->relationship('riskFamilies', 'name')
                            ->multiple()
                            ->preload()
                            ->searchable(),
                    ]),

                Section::make('Para el catálogo público')
                    ->columns(2)
                    ->schema([
                        Textarea::make('summary')
                            ->label('Resumen')
                            ->rows(2)
                            ->columnSpanFull()
                            ->helperText('Una o dos frases. Es lo que se lee en la lista.'),

                        Textarea::make('description')->label('Descripción')->rows(5)->columnSpanFull(),

                        Textarea::make('requirements')
                            ->label('Qué hace falta para entrar')
                            ->rows(2)
                            ->columnSpanFull(),

                        FileUpload::make('photo_path')
                            ->label('Foto')
                            // Disco publico EXPLICITO. El disco por defecto es
                            // `local`, cuya raiz en Laravel 11+ es
                            // storage/app/private: el archivo se guardaba ahi,
                            // la base apuntaba a el, y la pagina lo buscaba en
                            // storage/app/public. Resultado: la foto se subia
                            // «bien» y salia rota, sin ningun error.
                            //
                            // Aqui va explicito porque esta foto SE PUBLICA. Lo
                            // que no se publica —contratos de proyecto,
                            // evidencia de mantenimiento— se queda en el disco
                            // privado a proposito.
                            ->disk('public')
                            ->visibility('public')
                            ->image()
                            ->directory('cursos')
                            // Generoso a proposito: lo pesado se encoge, no se
                            // rechaza. El tope solo evita subidas absurdas.
                            ->maxSize(20480)
                            ->saveUploadedFileUsing(
                                fn ($file) => app(OptimizadorDeImagen::class)
                                    ->guardar($file, 'cursos')
                            )
                            ->columnSpanFull(),

                        Toggle::make('is_active')->label('Activo')->default(true),

                        Toggle::make('is_public')
                            ->label('Visible en el sitio')
                            ->default(true)
                            ->helperText('Un curso puede existir sin salir en la vitrina.'),
                    ]),
            ]);
    }
}
