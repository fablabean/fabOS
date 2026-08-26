<?php

namespace App\Filament\Componentes;

use App\Models\Evidencia;
use App\Services\Media\OptimizadorDeImagen;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

/**
 * El bloque de evidencia, uno solo para los tres sitios donde hace falta.
 *
 * Cuelga de una tarea, de un entregable o de una producción. Tres copias del
 * mismo formulario se habrían separado a la primera diferencia —una guardando
 * en el disco público, otra sin optimizar la foto— y esas diferencias son
 * justo las que no se ven hasta que duelen.
 *
 * Los archivos van al **disco privado** siempre: son el trabajo de alguien.
 */
class CampoDeEvidencia
{
    public static function repetidor(
        string $etiqueta = 'Evidencia',
        ?string $ayuda = null,
        string $directorio = 'proyectos/evidencia',
    ): Repeater {
        return Repeater::make('evidence')
            ->relationship()
            ->label($etiqueta)
            ->columnSpanFull()
            ->addActionLabel('Añadir evidencia')
            ->defaultItems(0)
            ->collapsible()
            ->itemLabel(fn (array $state) => $state['caption'] ?? $state['original_name'] ?? null)
            ->helperText($ayuda ?? '«Se hizo» es una afirmación; una foto o el archivo definitivo son una comprobación. Dentro de dos años es todo lo que queda.')
            ->columns(2)
            ->schema([
                Select::make('kind')
                    ->label('Qué es')
                    ->options(Evidencia::TIPOS)
                    ->default('foto')
                    ->live()
                    ->required(),

                TextInput::make('caption')->label('Qué es o qué se ve'),

                FileUpload::make('file_path')
                    ->label(fn (Get $get) => $get('kind') === 'archivo' ? 'Archivo' : 'Foto')
                    ->visible(fn (Get $get) => in_array($get('kind'), Evidencia::SE_SUBEN, true))
                    ->columnSpanFull()
                    // Disco privado A PROPOSITO. En el publico quedaria en una
                    // URL adivinable que cualquiera puede pedir sin sesion.
                    ->directory($directorio)
                    // Generoso: lo pesado se encoge o se acepta, no se rechaza.
                    ->maxSize(51200)
                    // El nombre con que llego dice mas que el aleatorio con que
                    // se guarda: «carcasa-v3-final.stl».
                    ->storeFileNamesIn('original_name')
                    ->helperText(fn (Get $get) => $get('kind') === 'archivo'
                        ? 'El definitivo: .stl, .gcode, .svg, el PDF que se entregó. Es lo que permite repetir el trabajo sin volver a empezar.'
                        : 'Súbela tal cual: el sistema la endereza y la comprime.')
                    // Solo las fotos se optimizan; un .gcode pasado por GD seria
                    // un .gcode roto.
                    ->saveUploadedFileUsing(function ($file, Get $get) use ($directorio) {
                        if ($get('kind') === 'foto') {
                            return app(OptimizadorDeImagen::class)->guardar($file, $directorio, 'local');
                        }

                        return $file->store($directorio, 'local');
                    }),

                TextInput::make('url')
                    ->label('Enlace')
                    ->visible(fn (Get $get) => ! in_array($get('kind'), Evidencia::SE_SUBEN, true))
                    ->url()
                    ->columnSpanFull()
                    ->helperText('A YouTube, Drive o donde ya viva. Un video de dos minutos no tiene por qué pasar por aquí.'),
            ]);
    }
}
