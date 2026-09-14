<?php

namespace App\Filament\Resources\Wishes\Widgets;

use App\Models\Wish;
use Filament\Widgets\Widget;

/**
 * Lo que costaría la lista, encima de la lista.
 *
 * La pregunta que se hace al abrir esta pantalla en septiembre no es «qué
 * queremos» sino «cuánto hay que pedir»; sacarla sumando cuarenta filas a mano,
 * área por área, es justo la cuenta que sale mal y que después hay que
 * defender.
 */
class ResumenDeDeseos extends Widget
{
    protected string $view = 'filament.deseos.resumen';

    /*
     * Sin pereza, como el resumen del presupuesto: es lo primero que se mira, y
     * un hueco que se rellena medio segundo despues hace leer la cifra dos
     * veces para creersela.
     */
    protected static bool $isLazy = false;

    protected int | string | array $columnSpan = 'full';

    public function getResumen(): array
    {
        return Wish::resumenDelAno($this->ano());
    }

    public function ano(): int
    {
        return Wish::anoPorDefecto();
    }
}
