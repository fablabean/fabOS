<?php

namespace App\Filament\Pages\Personal;

use App\Filament\Pages\Personal\Concerns\EditaLaSemana;
use App\Models\Area;
use App\Models\JornadaDelDia;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Staffing\Programacion;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * Semana del equipo (§5): quién está en el laboratorio cada día.
 *
 * La ve todo el equipo, para coordinarse sin preguntar por el chat. Una fila
 * por persona y una columna por día, con lo presencial y lo remoto; arriba,
 * cuántos estarán en el laboratorio; abajo, la cobertura de cada área (en rojo
 * el día que un área se queda sin nadie presencial) y los últimos cambios.
 *
 * La coordinación además cambia el día de cualquiera desde aquí.
 */
class SemanaDelEquipo extends Page
{
    use EditaLaSemana;

    protected string $view = 'filament.pages.semana-del-equipo';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $navigationLabel = 'Semana del equipo';

    protected static ?string $title = 'Semana del equipo';

    protected static ?string $slug = 'semana-del-equipo';

    protected static ?int $navigationSort = -1;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Jornadas';
    }

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u instanceof User && $u->hasAnyRole(User::rolesDelEquipo());
    }

    public function mount(): void
    {
        $this->iniciarSemana();
    }

    protected function getViewData(): array
    {
        $programacion = app(Programacion::class);
        $lunes = $this->lunesCarbon();
        $dias = $this->diasDeLaSemana();
        $equipo = $programacion->equipo($lunes);
        $areasDe = $programacion->areasDe($equipo->pluck('id')->all());

        $porDia = [];
        $enElLab = [];
        $coberturaPorArea = [];

        foreach ($dias as $i => $dia) {
            $bloques = $programacion->bloquesDeVarios($equipo, $dia);
            $ausencias = $programacion->ausencias($dia);
            $cierre = $programacion->hayCierre($dia);
            $porDia[$i] = ['bloques' => $bloques, 'ausencias' => $ausencias, 'cierre' => $cierre];
            $enElLab[$i] = collect($bloques)->filter(fn ($b) => $b->contains('modalidad', WorkSchedule::PRESENCIAL))->count();
            $coberturaPorArea[$i] = $programacion->presencialesPorArea($dia, $equipo);
        }

        $areas = Area::whereIn('id', collect($areasDe)->flatten()->unique()->all())->orderBy('name')->get(['id', 'name']);

        // Los fines de semana solo si alguien trabaja.
        $visibles = collect(range(0, 6))->filter(fn ($i) => $i < 5
            || collect($porDia[$i]['bloques'])->contains(fn ($b) => $b->isNotEmpty()))->values()->all();

        $cambios = JornadaDelDia::query()
            ->with(['user:id,name', 'creadoPor:id,name'])
            ->whereDate('fecha', '>=', Carbon::today(config('fabos.lab.timezone'))->subDays(7))
            ->latest('updated_at')
            ->get()
            ->unique(fn (JornadaDelDia $j) => $j->user_id . '|' . $j->fecha->toDateString())
            ->take(12)
            ->values();

        return compact('dias', 'equipo', 'areasDe', 'porDia', 'enElLab', 'coberturaPorArea', 'areas', 'visibles', 'cambios')
            + ['coordina' => $programacion->puedeCoordinar(auth()->user())];
    }
}
