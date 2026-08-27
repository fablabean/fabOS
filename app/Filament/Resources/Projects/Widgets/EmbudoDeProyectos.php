<?php

namespace App\Filament\Resources\Projects\Widgets;

use App\Models\Project;
use Filament\Widgets\Widget;

/**
 * El embudo, encima del listado.
 *
 * La lista dice qué proyectos hay; no dice dónde están atascados. Con las
 * etapas repartidas en una columna de la tabla, saber que hay cuatro
 * propuestas sin respuesta y una sola cosa en ejecución obliga a filtrar seis
 * veces, y por eso nadie lo hace.
 */
class EmbudoDeProyectos extends Widget
{
    protected string $view = 'filament.proyectos.embudo';

    /*
     * Sin pereza: es lo primero que se mira al abrir la pantalla, y un hueco
     * que se rellena despues hace leer la cifra dos veces para creersela.
     */
    protected static bool $isLazy = false;

    protected int | string | array $columnSpan = 'full';

    public function getTarjetas(): array
    {
        return Project::resumenDelEmbudo($this->ano());
    }

    public function ano(): int
    {
        return (int) now(config('fabos.lab.timezone'))->year;
    }

    /**
     * A dónde lleva cada tarjeta: al listado ya filtrado por esa etapa.
     *
     * Un resumen que solo informa obliga a repetir a mano el filtro que uno
     * acaba de leer. La de cierre además quita el filtro de «activo» que trae
     * la tabla por defecto, o enseñaría cero proyectos justo debajo de una
     * tarjeta que dice que hay cinco.
     */
    public function enlaceDe(array $tarjeta): string
    {
        $filtros = ['tableFilters[stage][value]=' . $tarjeta['etapa']];

        $filtros[] = 'tableFilters[status][value]=' . ($tarjeta['cerrada'] ? 'cerrado' : 'activo');

        return '/admin/projects?' . implode('&', $filtros);
    }
}
