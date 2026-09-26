<?php

namespace App\Filament\Resources\Projects\Widgets;

use App\Filament\Resources\Projects\ProjectResource;
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

    /**
     * Las alianzas, aparte y en sus dos cifras (§11).
     *
     * No son una etapa más del embudo: no hay cliente ni precio, así que su
     * valor no es venta y sumarlo con lo demás diría que vendimos algo que
     * nadie encargó. Se leen de otra manera —lo que valen fuera y lo que nos
     * cuestan— y por eso van en su propia fila.
     */
    public function getAlianzas(): array
    {
        return Project::resumenDeAlianzas();
    }

    /** El listado filtrado a alianzas, que es a donde llevan sus tarjetas. */
    public function enlaceDeAlianzas(): string
    {
        return $this->listado(['modality' => ['value' => 'alianza']]);
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
        // La de pausa no es una etapa: lo pausado esta repartido por todas, y
        // filtrar por una sola escondería el resto.
        if ($tarjeta['pausa'] ?? false) {
            return $this->listado(['status' => ['value' => 'pausado']]);
        }

        return $this->listado([
            'stage'  => ['value' => $tarjeta['etapa']],
            'status' => ['value' => $tarjeta['cerrada'] ? 'cerrado' : 'activo'],
        ]);
    }

    /**
     * La dirección del listado con esos filtros puestos.
     *
     * Filament lee los filtros de `?filters[...]`: el nombre de la propiedad
     * (`tableFilters`) no le dice nada en la dirección, y la tarjeta abría el
     * listado sin filtrar. Por eso se arma con su propio generador y no a mano.
     *
     * «Míos y sin responsable» se apaga siempre: el embudo cuenta los de todo
     * el laboratorio, y la tabla tiene que enseñar los mismos que la tarjeta
     * dice que hay.
     */
    private function listado(array $filtros): string
    {
        return ProjectResource::getUrl('index', [
            'filters' => $filtros + ['mios' => ['isActive' => false]],
        ]);
    }
}
