<?php

namespace App\Filament\Resources\Logos\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LogoForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('El logo')
                ->description('Sale en la franja de la portada, debajo del banner, con los demás. Un PNG o SVG con fondo transparente se ve mejor.')
                ->schema([
                    TextInput::make('nombre')
                        ->label('De quién es')
                        ->placeholder('Universidad EAN')
                        ->required()
                        ->maxLength(120)
                        ->helperText('Sale al pasar el ratón y lo leen los lectores de pantalla.'),

                    FileUpload::make('imagen_path')
                        ->label('La imagen')
                        ->disk('public')
                        ->visibility('public')
                        ->directory('logos')
                        ->image()
                        ->acceptedFileTypes(['image/png', 'image/svg+xml', 'image/webp', 'image/jpeg'])
                        ->imagePreviewHeight('80')
                        ->maxSize(4096)
                        ->required(),

                    TextInput::make('url')
                        ->label('A dónde lleva')
                        ->url()
                        ->placeholder('https://universidadean.edu.co')
                        ->maxLength(255)
                        ->helperText('Opcional. Si va vacío, el logo no es un enlace.'),

                    Toggle::make('is_active')
                        ->label('Se muestra')
                        ->default(true)
                        ->helperText('Apagado no sale en la portada, pero se conserva.'),
                ])
                ->columns(1),
        ]);
    }
}
