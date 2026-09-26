<?php

namespace App\Filament\Widgets;

use App\Models\User;
use App\Services\Booking\OcupacionSemanal;
use Filament\Widgets\Widget;

/**
 * La semana del laboratorio, sobre las listas de proyectos y de reservas
 * (§10, §11).
 *
 * Las dos tablas dicen qué hay; ninguna dice cómo queda la semana. Para saber
 * si el martes a las diez está libre la sala de corte había que ordenar por
 * fecha y leer fila por fila.
 *
 * Es un componente Livewire y no enlaces: cambiar de semana no recarga la
 * página, y así no se pierden los filtros que se traían puestos en la tabla.
 *
 * Solo el equipo del laboratorio: a la lista de proyectos también entra quien
 * solo tiene los suyos, y la semana es la de todo el mundo.
 */
class SemanaDelLaboratorio extends Widget
{
    protected string $view = 'filament.widgets.semana-del-laboratorio';

    protected int | string | array $columnSpan = 'full';

    protected static bool $isLazy = false;

    /** Cualquier día de la semana que se mira; nulo es esta. */
    public ?string $semana = null;

    /** Texto y no entero: el desplegable manda "" cuando es «todos». */
    public ?string $espacio = null;

    public bool $solo = false;

    public string $vista = 'horas';

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyRole(User::rolesDelEquipo()) ?? false;
    }

    public function irA(string $dia): void
    {
        $this->semana = $dia;
    }

    protected function getViewData(): array
    {
        return [
            's' => app(OcupacionSemanal::class)->paraVer(
                $this->semana,
                (int) $this->espacio ?: null,
                $this->solo,
                $this->vista,
                auth()->user(),
            ),
        ];
    }
}
