<?php

namespace App\Filament\Resources\Areas\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class AreaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, $set, $get) => filled($get('slug'))
                        ? null
                        : $set('slug', Str::slug((string) $state))),

                TextInput::make('slug')
                    ->label('Identificador')
                    ->helperText('Interno, sin espacios ni tildes.')
                    ->required()
                    ->maxLength(255),

                Textarea::make('description')
                    ->label('Descripción')
                    ->rows(3)
                    ->columnSpanFull(),

                /*
                 * La foto con la que se presenta el area en Reservas.
                 *
                 * Sin ella se usa la de una de sus maquinas, que es mejor que
                 * un hueco gris pero la elige el orden alfabetico: «Impresion
                 * 3D» acababa representada por un secador de filamento.
                 */
                FileUpload::make('photo_path')
                    ->label('Foto del área')
                    ->helperText('Es la que se ve al elegir área en Reservas. Si no pones ninguna, se usa la de una de sus máquinas.')
                    // Disco publico EXPLICITO: esta foto se enseña a quien
                    // entra sin haber iniciado sesion. El disco por defecto
                    // guarda en privado y la imagen saldria rota sin un error.
                    ->disk('public')
                    ->visibility('public')
                    ->image()
                    ->imageEditor()
                    ->directory('areas')
                    ->maxSize(20480)
                    // Encogida en el propio navegador antes de subirla: una
                    // foto de telefono son tres o cuatro megas, y por el tunel
                    // esa lentitud acaba en un 502 sin explicar por que.
                    ->imageResizeMode('contain')
                    ->imageResizeTargetWidth(2000)
                    ->imageResizeTargetHeight(2000)
                    ->imageResizeUpscale(false)
                    ->saveUploadedFileUsing(
                        fn ($file) => app(\App\Services\Media\OptimizadorDeImagen::class)
                            ->guardar($file, 'areas')
                    )
                    ->columnSpanFull(),

                TextInput::make('position')
                    ->label('Orden en el menú')
                    ->numeric()
                    ->default(0),
            ]);
    }
}
