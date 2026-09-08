<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Widgets\EmbudoDeProyectos;
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

    /**
     * Pestañas por tipo de cliente: estudiantes, la Universidad, de fuera.
     *
     * Son tres tramites distintos —un estudiante no firma contrato, un area
     * de la Universidad pasa por traslado presupuestal— y quien administra
     * mira uno a la vez. Un filtro escondido en el desplegable obligaba a
     * tres clics cada vez; una pestaña es uno, y dice cuantos hay.
     */
    public function getTabs(): array
    {
        $cuenta = fn (?string $tipo) => Project::query()
            ->where('status', 'activo')
            ->when($tipo, fn ($q) => $q->where('client_kind', $tipo))
            ->count();

        $pestanas = ['todos' => Tab::make('Todos')->badge($cuenta(null))];

        foreach ([
            'estudiante' => 'Estudiantes',
            'interno'    => 'Universidad',
            'externo'    => 'De fuera',
        ] as $tipo => $nombre) {
            $pestanas[$tipo] = Tab::make($nombre)
                ->badge($cuenta($tipo))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('client_kind', $tipo));
        }

        return $pestanas;
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
            EmbudoDeProyectos::class,
        ];
    }
}
