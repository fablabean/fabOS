<?php

namespace App\Filament\Componentes;

use App\Support\Telefono;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;

/**
 * El telefono, en dos partes: el indicativo del pais y el numero.
 *
 * Se guarda en la columna de siempre, como «+57 3001234567», asi que sirve
 * en cualquier formulario que tuviera un campo de telefono: se cambia el
 * TextInput por esto y el resto no se entera. Lo que ya estaba escrito sin
 * indicativo se abre como de Colombia.
 */
class CampoDeTelefono
{
    public static function make(string $campo = 'phone', string $etiqueta = 'Teléfono'): Group
    {
        $indicativo = $campo . '_indicativo';
        $numero = $campo . '_numero';

        return Group::make([
            Select::make($indicativo)
                ->label('País')
                ->options(Telefono::INDICATIVOS)
                ->default(Telefono::POR_DEFECTO)
                ->selectablePlaceholder(false)
                ->dehydrated(false)
                ->afterStateHydrated(function ($component, Get $get) use ($campo) {
                    $component->state(Telefono::partir($get($campo))['indicativo']);
                }),

            TextInput::make($numero)
                ->label($etiqueta)
                ->tel()
                ->maxLength(30)
                ->placeholder('3001234567')
                ->dehydrated(false)
                ->afterStateHydrated(function ($component, Get $get) use ($campo) {
                    $component->state(Telefono::partir($get($campo))['numero']);
                })
                ->columnSpan(2),

            // La columna de verdad: se arma con las dos partes al guardar.
            Hidden::make($campo)
                ->dehydrateStateUsing(fn (Get $get) => Telefono::componer($get($indicativo), $get($numero))),
        ])->columns(3);
    }
}
