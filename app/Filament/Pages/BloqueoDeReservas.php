<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\Bloqueo as BloqueoProgramado;
use App\Support\BloqueoDeReservas as Bloqueo;
use App\Support\HorarioDeAutoservicio as Horario;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;

/**
 * El interruptor que cierra el laboratorio a las reservas (App\Support\BloqueoDeReservas).
 *
 * Se escribe el porqué, porque es lo que ve todo el que entra al sitio en el
 * aviso; y opcionalmente cuándo se reabre, para que se levante solo.
 *
 * Y su pariente suave: el horario en que la gente reserva máquinas por su
 * cuenta (App\Support\HorarioDeAutoservicio), para cuando lo que sobra no es
 * una emergencia sino demanda.
 */
class BloqueoDeReservas extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.bloqueo-de-reservas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    protected static ?int $navigationSort = 7;

    /** @var array<string,mixed> */
    public array $datos = [];

    /** @var array{desde:string,hasta:string,aplica:array<int,string>} */
    public array $horario = [];

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Operación';
    }

    public static function getNavigationLabel(): string
    {
        return 'Bloqueo y horario';
    }

    public static function getNavigationBadge(): ?string
    {
        return Bloqueo::activo() ? 'Activo' : (Bloqueo::vigentes()->isNotEmpty() ? 'Programado' : null);
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return Bloqueo::activo() ? 'danger' : 'warning';
    }

    public function getTitle(): string
    {
        return 'Bloqueo y horario de reservas';
    }

    /** Cuánto antes se empieza a avisar en el sitio, en días. */
    public const AVISOS = ['ya' => 'Desde ya', '1' => 'Un día antes', '3' => 'Tres días antes', '7' => 'Una semana antes'];

    public function mount(): void
    {
        $this->horario = Horario::estado();

        $this->form->fill(['cuando' => 'dias', 'avisar' => 'ya']);
    }

    public function form(Schema $schema): Schema
    {
        $es = fn (string $cual) => fn (Get $get) => $get('cuando') === $cual;

        return $schema
            ->statePath('datos')
            ->components([
                Textarea::make('motivo')
                    ->label('Mensaje para quien reserva')
                    ->helperText('Es lo que lee todo el que entra al sitio, en el aviso. Escríbelo para quien reserva: «El viernes el laboratorio recibe la feria de Halloween y no abre para reservas».')
                    ->rows(3)
                    ->maxLength(400)
                    ->required(),

                ToggleButtons::make('cuando')
                    ->label('Cuándo')
                    ->options(['dias' => 'Días completos', 'horas' => 'De una hora a otra', 'ahora' => 'Desde ya'])
                    ->icons(['dias' => 'heroicon-o-calendar-days', 'horas' => 'heroicon-o-clock', 'ahora' => 'heroicon-o-bolt'])
                    ->inline()
                    ->live()
                    ->required(),

                Grid::make(2)->schema([
                    DatePicker::make('dia_desde')
                        ->label('Desde el día')
                        ->minDate(now(config('fabos.lab.timezone'))->toDateString())
                        ->required($es('dias'))
                        ->live(),
                    DatePicker::make('dia_hasta')
                        ->label('Hasta el día (incluido)')
                        ->helperText('Vacío: solo ese día.')
                        ->minDate(fn (Get $get) => $get('dia_desde') ?: now(config('fabos.lab.timezone'))->toDateString())
                        ->afterOrEqual('dia_desde'),
                ])->visible($es('dias')),

                Grid::make(2)->schema([
                    DateTimePicker::make('desde')
                        ->label('Desde')
                        ->seconds(false)
                        ->minDate(now()->startOfDay())
                        ->required($es('horas')),
                    DateTimePicker::make('hasta')
                        ->label('Hasta')
                        ->seconds(false)
                        ->after('desde')
                        ->required($es('horas')),
                ])->visible($es('horas')),

                DateTimePicker::make('reabre')
                    ->label('Se reabre el')
                    ->helperText('Opcional. Con fecha, se puede reservar desde ya para después de esa hora, y el bloqueo se levanta solo; sin ella, no se reserva nada hasta que alguien lo levante aquí.')
                    ->seconds(false)
                    ->minDate(now())
                    ->visible($es('ahora')),

                Select::make('avisar')
                    ->label('Avisar en el sitio')
                    ->options(self::AVISOS)
                    ->helperText('Antes de empezar, una ventana que se puede cerrar dice qué días se cierra y que los demás se reserva normal. Durante el bloqueo sale el aviso rojo.')
                    ->selectablePlaceholder(false)
                    ->visible(fn (Get $get) => $get('cuando') !== 'ahora'),
            ]);
    }

    public function activar(): void
    {
        $e = $this->form->getState();
        $tz = config('fabos.lab.timezone');
        $por = auth()->user()?->name;

        if ($e['cuando'] === 'ahora') {
            Bloqueo::activar($e['motivo'], filled($e['reabre'] ?? null) ? Carbon::parse($e['reabre']) : null, $por);

            Notification::make()
                ->title('Reservas bloqueadas')
                ->body('Nadie puede reservar hasta que se levante. El aviso ya sale en el sitio.')
                ->danger()
                ->send();
        } else {
            // El selector de fecha la entrega a medianoche UTC: vale el día,
            // y el día empieza a medianoche del laboratorio.
            $dia = fn ($fecha) => Carbon::parse(Carbon::parse($fecha)->toDateString(), $tz);

            [$desde, $hasta] = $e['cuando'] === 'dias'
                ? [$dia($e['dia_desde']), $dia($e['dia_hasta'] ?: $e['dia_desde'])->addDay()]
                : [Carbon::parse($e['desde']), Carbon::parse($e['hasta'])];

            $avisar = ($e['avisar'] ?? 'ya') === 'ya' ? null : $desde->copy()->timezone($tz)->subDays((int) $e['avisar'])->startOfDay();

            $b = Bloqueo::programar($e['motivo'], $desde, $hasta, $avisar, $por, $e['cuando'] === 'dias');

            Notification::make()
                ->title($b->enCurso() ? 'Reservas bloqueadas' : 'Bloqueo programado')
                ->body('No se podrá reservar ' . $b->cuando() . '. '
                    . ($b->avisar_desde->lte(now()) ? 'El aviso ya sale en el sitio.' : 'El aviso sale en el sitio desde el ' . Bloqueo::legible($b->avisar_desde) . '.'))
                ->warning()
                ->send();
        }

        $this->form->fill(['cuando' => 'dias', 'avisar' => 'ya']);
    }

    /** Quita un bloqueo, en curso o programado. */
    public function quitar(int $id): void
    {
        BloqueoProgramado::query()->vigentes()->whereKey($id)->first()?->update(['levantado_at' => now()]);

        Notification::make()->title('Bloqueo quitado')->success()->send();
    }

    /**
     * Corregir el mensaje de un bloqueo ya puesto, sin quitarlo y volverlo a
     * programar. Las fechas no se tocan: cambiarlas es otro bloqueo.
     *
     * Al guardarlo cambia la versión del aviso (Bloqueo::version()), así que
     * quien ya lo había cerrado lo vuelve a ver con el texto nuevo.
     */
    public function editarMensajeAction(): Action
    {
        return Action::make('editarMensaje')
            ->modalHeading('Editar el mensaje del bloqueo')
            ->modalDescription('Las fechas siguen igual. Quien ya había cerrado el aviso lo vuelve a ver, con el texto nuevo.')
            ->modalSubmitActionLabel('Guardar el mensaje')
            ->fillForm(fn (array $arguments): array => [
                'motivo' => BloqueoProgramado::find($arguments['bloqueo'] ?? 0)?->motivo,
            ])
            ->schema([
                Textarea::make('motivo')
                    ->label('Mensaje para quien reserva')
                    ->rows(5)
                    ->maxLength(400)
                    ->required(),
            ])
            ->action(function (array $arguments, array $data): void {
                $bloqueo = BloqueoProgramado::query()->vigentes()->whereKey($arguments['bloqueo'] ?? 0)->first();

                if (! $bloqueo) {
                    Notification::make()->title('Ese bloqueo ya no está')->warning()->send();

                    return;
                }

                $bloqueo->update(['motivo' => trim($data['motivo'])]);

                Notification::make()->title('Mensaje actualizado')->body('El aviso del sitio ya dice lo nuevo.')->success()->send();
            });
    }

    public function guardarHorario(): void
    {
        $datos = validator($this->horario, [
            'desde' => ['required', 'date_format:H:i'],
            'hasta' => ['required', 'date_format:H:i', 'after:desde'],
        ], [
            'hasta.after' => 'La hora de cierre tiene que ser después de la de apertura.',
        ])->validate();

        Horario::guardar((array) ($this->horario['aplica'] ?? []), $datos['desde'], $datos['hasta']);

        $tipos = collect(Horario::estado()['aplica'])->map(fn ($t) => Horario::TIPOS[$t][0])->join(', ', ' y ');

        Notification::make()
            ->title(Horario::activo() ? $tipos . ': ' . Horario::legible() : 'Horario de autoservicio apagado')
            ->success()
            ->send();
    }

    public function levantar(): void
    {
        Bloqueo::levantar();

        Notification::make()->title('Reservas abiertas de nuevo')->success()->send();
    }

}
