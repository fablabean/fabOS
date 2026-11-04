<?php

namespace App\Support;

use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Project;
use Livewire\Livewire;

/**
 * Pasar de un proyecto al siguiente sin volver a la lista.
 *
 * La lista deja anotado el orden de lo que se estaba mirando —con sus
 * filtros, su pestaña, su búsqueda y su orden— y la ficha lo lee para saber
 * cuál va antes y cuál después. Quien entra a una ficha sin pasar por la
 * lista (un enlace de un correo, el buscador) recorre lo de siempre: lo suyo
 * y lo que no tiene responsable, activo, lo más nuevo primero.
 */
class RecorridoDeProyectos
{
    public const SESION = 'proyectos.recorrido';

    /** Más de esto no se recorre a mano; y la sesión no es un almacén. */
    public const TOPE = 500;

    /** @param array<int, int> $ids en el orden en que salen en la lista */
    public static function guardar(array $ids): void
    {
        session([self::SESION => array_values(array_map('intval', array_slice($ids, 0, self::TOPE)))]);
    }

    /**
     * Dónde queda el proyecto dentro del recorrido, y quiénes lo rodean.
     *
     * @return array{anterior: ?Project, siguiente: ?Project, posicion: ?int, total: int, filtrado: bool}
     */
    public static function alrededor(Project $proyecto): array
    {
        $ids = session(self::SESION, []);
        $filtrado = in_array($proyecto->id, $ids, true);

        if (! $filtrado) {
            $ids = self::deSiempre();
        }

        $i = array_search($proyecto->id, $ids, true);

        $vecino = fn (?int $id) => $id
            ? ProjectResource::getEloquentQuery()->select('id', 'code', 'name', 'notes')->find($id)
            : null;

        return [
            'anterior'  => $vecino($i === false ? null : ($ids[$i - 1] ?? null)),
            'siguiente' => $vecino($i === false ? ($ids[0] ?? null) : ($ids[$i + 1] ?? null)),
            'posicion'  => $i === false ? null : $i + 1,
            'total'     => count($ids),
            'filtrado'  => $filtrado,
        ];
    }

    /** Lo que trae la lista de entrada: lo mío y lo sin responsable, activo. */
    private static function deSiempre(): array
    {
        return ProjectResource::getEloquentQuery()
            ->where('status', 'activo')
            ->where(fn ($q) => $q->where('lead_id', auth()->id())->orWhereNull('lead_id'))
            ->orderByDesc('id')
            ->limit(self::TOPE)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /** La barra, para la ficha que se está pintando. */
    public static function barra(): string
    {
        $pagina = Livewire::current();
        $proyecto = method_exists($pagina, 'getRecord') ? $pagina->getRecord() : null;

        if (! $proyecto instanceof Project) {
            return '';
        }

        $pagina = $pagina instanceof \App\Filament\Resources\Projects\Pages\ViewProject ? 'view' : 'edit';

        return view('filament.proyectos.recorrido', [
            'proyecto' => $proyecto,
            'url'      => fn (Project $p) => ProjectResource::getUrl($pagina, ['record' => $p]),
            ...self::alrededor($proyecto),
        ])->render();
    }
}
