<?php

namespace App\Filament\Pages\Personal;

use App\Filament\Pages\Personal\Concerns\EditaLaSemana;
use App\Models\User;
use App\Services\Staffing\Programacion;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Mi semana (§5): cómo trabaja cada quien, día por día.
 *
 * Cada persona del equipo ve su semana —su horario de siempre y lo que
 * cambió— y, según su modalidad, la ajusta: la híbrida reparte cada día entre
 * presencial y remoto; la flexible además mueve las horas. Lo que guarda vale
 * de inmediato para la cobertura, las reservas y las asesorías.
 */
class MiSemana extends Page
{
    use EditaLaSemana;

    protected string $view = 'filament.pages.mi-semana';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Mi semana';

    protected static ?string $title = 'Mi semana';

    protected static ?string $slug = 'mi-semana';

    protected static ?int $navigationSort = -2;

    public string $enlace = '';

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Jornadas';
    }

    /** Todo el equipo: hasta quien tiene modalidad normal consulta su semana. */
    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u instanceof User && $u->hasAnyRole(User::rolesDelEquipo());
    }

    public function mount(): void
    {
        $this->iniciarSemana();
        $this->enlace = (string) auth()->user()->enlace_reunion;
    }

    public function guardarEnlace(): void
    {
        $this->validate(['enlace' => ['nullable', 'url', 'max:255']], [
            'enlace.url' => 'Pega el enlace completo, empezando por https://',
        ]);

        auth()->user()->update(['enlace_reunion' => $this->enlace ?: null]);

        Notification::make()->title('Enlace guardado')->body('Va en las asesorías virtuales que atiendas.')->success()->send();
    }

    protected function getViewData(): array
    {
        $yo = auth()->user();
        $programacion = app(Programacion::class);
        $dias = [];
        $horas = 0.0;

        foreach ($this->diasDeLaSemana() as $dia) {
            $bloques = $programacion->bloquesDe($yo, $dia);
            $ausencia = $programacion->ausencias($dia)[$yo->id] ?? null;

            foreach ($bloques as $b) {
                $horas += $this->horas($b);
            }

            $dias[] = [
                'fecha' => $dia,
                'bloques' => $bloques,
                'ausencia' => $ausencia,
                'cierre' => $programacion->hayCierre($dia),
                'ajustado' => $bloques->contains('ajustado', true),
                'editable' => $this->puedeEditar($yo, $dia) && ! $ausencia,
            ];
        }

        return [
            'dias' => $dias,
            'horas' => $horas,
            'modalidad' => $yo->modalidadDeTrabajo(),
        ];
    }

    /** Horas de un bloque, sin descanso si lo dice. */
    private function horas(array $b): float
    {
        [$hi, $mi] = array_map('intval', explode(':', $b['inicio']));
        [$hf, $mf] = array_map('intval', explode(':', $b['fin']));
        $minutos = ($hf * 60 + $mf) - ($hi * 60 + $mi);

        if ($b['descanso']) {
            [$di, $dmi] = array_map('intval', explode(':', $b['descanso'][0]));
            [$df, $dmf] = array_map('intval', explode(':', $b['descanso'][1]));
            $minutos -= ($df * 60 + $dmf) - ($di * 60 + $dmi);
        }

        return max(0, $minutos) / 60;
    }
}
