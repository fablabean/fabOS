<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Widgets\EmbudoDeProyectos;
use App\Filament\Widgets\SemanaDelLaboratorio;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // El cronograma de todos a la vez. Es la vista que decide si se
            // acepta el siguiente encargo: por separado todos parecen holgados.
            Action::make('cronograma')
                ->label('Cronograma general')
                ->icon('heroicon-o-calendar-days')
                ->color('gray')
                ->url(fn () => route('proyectos.cronograma'))
                ->openUrlInNewTab(),

            CreateAction::make(),
        ];
    }

    protected bool $recorridoGuardado = false;

    /**
     * Cada vez que se pinta la lista, queda anotado su orden completo —con
     * filtros, pestaña y búsqueda— para que la ficha sepa cuál va antes y
     * cuál después (App\Support\RecorridoDeProyectos). Una vez por pintada:
     * la tabla pide sus registros más de una vez.
     */
    public function getTableRecords(): \Illuminate\Support\Collection | \Illuminate\Contracts\Pagination\Paginator | \Illuminate\Contracts\Pagination\CursorPaginator
    {
        $registros = parent::getTableRecords();

        if ($this->recorridoGuardado) {
            return $registros;
        }

        $this->recorridoGuardado = true;

        \App\Support\RecorridoDeProyectos::guardar(
            $this->getFilteredSortedTableQuery()
                ->limit(\App\Support\RecorridoDeProyectos::TOPE)
                ->pluck('projects.id')
                ->all()
        );

        return $registros;
    }

    /**
     * Pestañas por tipo de cliente: estudiantes, la Universidad, de fuera.
     *
     * Son tres tramites distintos —un estudiante no firma contrato, un area
     * de la Universidad pasa por traslado presupuestal— y quien administra
     * mira uno a la vez. Un filtro escondido en el desplegable obligaba a
     * tres clics cada vez; una pestaña es uno, y dice cuantos hay.
     *
     * Cuántos con lo que está filtrado y buscado, no del laboratorio entero:
     * el número de la pestaña tiene que ser lo que se ve al pulsarla.
     */
    public function getTabs(): array
    {
        $cuenta = fn (?string $tipo) => $tipo === null
            ? array_sum($this->cuentasPorCliente())
            : ($this->cuentasPorCliente()[$tipo] ?? 0);

        $pestanas = ['todos' => Tab::make('Todos')->badge(fn () => $cuenta(null))];

        foreach ([
            'estudiante' => 'Estudiantes',
            'interno'    => 'Universidad',
            'externo'    => 'De fuera',
        ] as $tipo => $nombre) {
            $pestanas[$tipo] = Tab::make($nombre)
                ->badge(fn () => $cuenta($tipo))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('client_kind', $tipo));
        }

        return $pestanas;
    }

    /** @var array<string, int>|null */
    protected ?array $cuentasPorCliente = null;

    /**
     * Una sola consulta para las cuatro pestañas, con los filtros y la
     * búsqueda de la tabla pero sin la pestaña puesta.
     *
     * @return array<string, int>
     */
    protected function cuentasPorCliente(): array
    {
        if ($this->cuentasPorCliente !== null) {
            return $this->cuentasPorCliente;
        }

        $consulta = ProjectResource::getEloquentQuery();
        $this->applyFiltersToTableQuery($consulta);
        $this->applySearchToTableQuery($consulta);

        return $this->cuentasPorCliente = $consulta
            ->reorder()
            ->selectRaw('client_kind, count(*) as total')
            ->groupBy('client_kind')
            ->pluck('total', 'client_kind')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * El embudo va arriba.
     *
     * La lista dice que proyectos hay; no dice donde estan atascados. Con las
     * etapas repartidas en una columna, ver que hay cuatro propuestas sin
     * respuesta y una sola cosa en ejecucion obliga a filtrar seis veces, y
     * por eso nadie lo hace.
     */
    protected function getHeaderWidgets(): array
    {
        return [
            // Lo que viene de los proyectos: dice si cabe lo que se está por
            // aceptar. Va primero porque no toca la tabla.
            SemanaDelLaboratorio::make(['soloFijo' => true]),
            // El embudo, justo encima de la tabla: sus tarjetas la filtran, y
            // lo que actúa sobre algo se pone al lado de ese algo.
            EmbudoDeProyectos::class,
        ];
    }
}
