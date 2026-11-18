<?php

namespace App\Filament\Pages\Personal\Concerns;

use App\Models\User;
use App\Models\WorkSchedule;
use App\Services\Booking\BookingException;
use App\Services\Staffing\Programacion;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Lo común de «Mi semana» y «Semana del equipo» (§5): moverse de semana y
 * cambiar cómo trabaja una persona un día concreto.
 *
 * La regla vive en `Programacion`; aquí solo se pregunta y se muestra lo que
 * contesta.
 */
trait EditaLaSemana
{
    /** El lunes de la semana que se mira, Y-m-d. */
    public string $lunes = '';

    protected function iniciarSemana(): void
    {
        $this->lunes = Carbon::today(config('fabos.lab.timezone'))->startOfWeek(CarbonInterface::MONDAY)->toDateString();
    }

    public function semanaAnterior(): void
    {
        $this->lunes = $this->lunesCarbon()->subWeek()->toDateString();
    }

    public function semanaSiguiente(): void
    {
        $this->lunes = $this->lunesCarbon()->addWeek()->toDateString();
    }

    public function estaSemana(): void
    {
        $this->iniciarSemana();
    }

    protected function lunesCarbon(): Carbon
    {
        return Carbon::parse($this->lunes ?: 'today', config('fabos.lab.timezone'))->startOfWeek(CarbonInterface::MONDAY);
    }

    /** @return list<Carbon> */
    protected function diasDeLaSemana(): array
    {
        $lunes = $this->lunesCarbon();

        return array_map(fn ($i) => $lunes->copy()->addDays($i), range(0, 6));
    }

    /** Si quien mira puede cambiar ese día de esa persona. */
    public function puedeEditar(User $persona, CarbonInterface $dia): bool
    {
        $yo = auth()->user();
        $programacion = app(Programacion::class);

        if ($programacion->puedeCoordinar($yo)) {
            return true;
        }

        return $yo?->id === $persona->id
            && $persona->modalidadDeTrabajo() !== User::TRABAJO_NORMAL
            && ! $dia->lt(Carbon::today(config('fabos.lab.timezone')));
    }

    public function editarDiaAction(): Action
    {
        return Action::make('editarDia')
            ->modalHeading(fn (array $arguments) => $this->tituloDelDia($arguments))
            ->modalDescription(fn (array $arguments) => $this->ayudaDelDia($arguments))
            ->modalSubmitActionLabel('Guardar este día')
            ->modalWidth('lg')
            ->fillForm(function (array $arguments): array {
                [$persona, $dia] = $this->personaYDia($arguments);

                return ['bloques' => app(Programacion::class)->bloquesDe($persona, $dia)
                    ->map(fn ($b) => ['inicio' => $b['inicio'], 'fin' => $b['fin'], 'modalidad' => $b['modalidad']])
                    ->all() ?: [['inicio' => '08:00', 'fin' => '17:30', 'modalidad' => WorkSchedule::PRESENCIAL]]];
            })
            ->schema(fn (array $arguments) => [
                Repeater::make('bloques')
                    ->label('Bloques de ese día')
                    ->schema([
                        TextInput::make('inicio')->label('Desde')->type('time')->required(),
                        TextInput::make('fin')->label('Hasta')->type('time')->required(),
                        Select::make('modalidad')
                            ->label('Cómo')
                            ->options(WorkSchedule::MODALIDADES)
                            ->default(WorkSchedule::PRESENCIAL)
                            ->required()
                            ->native(false),
                    ])
                    ->columns(3)
                    ->minItems(1)
                    ->addActionLabel('Agregar un bloque')
                    ->reorderable(false),
                Toggle::make('forzar')
                    ->label('Guardar aunque un área quede sin nadie presencial')
                    ->helperText('Solo la coordinación. Queda anotado.')
                    ->visible(fn () => app(Programacion::class)->puedeCoordinar(auth()->user())),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                [$persona, $dia] = $this->personaYDia($arguments);

                try {
                    app(Programacion::class)->guardarDia($persona, $dia, array_values($data['bloques'] ?? []), auth()->user(), (bool) ($data['forzar'] ?? false));
                } catch (BookingException $e) {
                    Notification::make()->title('No se guardó')->body($e->getMessage())->danger()->persistent()->send();
                    $action->halt();
                }

                Notification::make()->title('Listo: ' . $this->fechaLarga($dia) . ' quedó así.')->success()->send();
            });
    }

    public function volverAlHorarioAction(): Action
    {
        return Action::make('volverAlHorario')
            ->label('Volver al horario de siempre')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => '¿Volver al horario de siempre el ' . $this->fechaLarga($this->personaYDia($arguments)[1]) . '?')
            ->modalDescription('Se borran los cambios de ese día y vale el horario semanal.')
            ->action(function (array $arguments, Action $action): void {
                [$persona, $dia] = $this->personaYDia($arguments);

                try {
                    app(Programacion::class)->volverAlHorario($persona, $dia, auth()->user());
                } catch (BookingException $e) {
                    Notification::make()->title('No se cambió')->body($e->getMessage())->danger()->persistent()->send();
                    $action->halt();
                }

                Notification::make()->title('Ese día vuelve a tu horario de siempre.')->success()->send();
            });
    }

    /** @return array{0:User,1:Carbon} */
    protected function personaYDia(array $arguments): array
    {
        $persona = User::findOrFail((int) ($arguments['persona'] ?? auth()->id()));
        $dia = Carbon::parse((string) ($arguments['fecha'] ?? 'today'), config('fabos.lab.timezone'))->startOfDay();

        abort_unless($this->puedeEditar($persona, $dia), 403);

        return [$persona, $dia];
    }

    protected function fechaLarga(CarbonInterface $dia): string
    {
        return $dia->locale('es')->isoFormat('dddd D [de] MMMM');
    }

    private function tituloDelDia(array $arguments): string
    {
        [$persona, $dia] = $this->personaYDia($arguments);

        return ucfirst($this->fechaLarga($dia)) . ($persona->id === auth()->id() ? '' : ' · ' . $persona->name);
    }

    private function ayudaDelDia(array $arguments): string
    {
        [$persona] = $this->personaYDia($arguments);

        return match ($persona->modalidadDeTrabajo()) {
            User::TRABAJO_HIBRIDO => 'Modalidad híbrida: el horario es el de siempre; marca qué parte es presencial y qué parte remota. Puedes partir el día en varios bloques.',
            User::TRABAJO_FLEXIBLE => 'Modalidad flexible: puedes mover las horas de ese día y decir si cada parte es presencial o remota.',
            default => 'Modalidad normal: este cambio lo está haciendo la coordinación.',
        } . ' Siempre tiene que quedar al menos una persona presencial por área.';
    }
}
