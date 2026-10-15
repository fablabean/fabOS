<?php

namespace App\Filament\Resources\Publicaciones\Pages;

use App\Filament\Resources\Publicaciones\PublicacionResource;
use App\Models\Publicacion;
use App\Models\TareaDePublicacion;
use App\Services\Redes\FechasEspeciales;
use App\Services\Redes\Redactor;
use App\Services\Redes\Sugerencias;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

/**
 * El mes de un vistazo, con lo que fabOS sugiere al lado (§21).
 */
class Parrilla extends Page
{
    protected static string $resource = PublicacionResource::class;

    protected string $view = 'filament.redes.parrilla';

    /** El mes que se mira, como «2026-10». */
    public string $mes = '';

    public function mount(): void
    {
        $this->mes = request()->query('mes') && preg_match('/^\d{4}-\d{2}$/', (string) request()->query('mes'))
            ? (string) request()->query('mes')
            : $this->hoy()->format('Y-m');
    }

    public function getTitle(): string
    {
        return 'Parrilla de redes';
    }

    public function getBreadcrumb(): string
    {
        return 'Parrilla';
    }

    public function mover(int $meses): void
    {
        $this->mes = $this->inicio()->addMonthsNoOverflow($meses)->format('Y-m');
    }

    public function irAHoy(): void
    {
        $this->mes = $this->hoy()->format('Y-m');
    }

    public function aceptar(string $clave): void
    {
        abort_unless(PublicacionResource::canCreate(), 403);

        $p = app(Sugerencias::class)->aceptar($clave, auth()->user());

        if (! $p) {
            Notification::make()->title('Esa sugerencia ya no está')->warning()->send();

            return;
        }

        Notification::make()
            ->title('Quedó en la parrilla como idea')
            ->body('«' . $p->titulo . '», con su borrador. Ábrela para ajustarla.')
            ->success()
            ->send();
    }

    public function descartar(string $clave): void
    {
        abort_unless(PublicacionResource::canCreate(), 403);

        app(Sugerencias::class)->descartar($clave);
    }

    /** Marca hecha una tarea propia desde «Mis tareas». */
    public function marcarHecha(int $id): void
    {
        $tarea = TareaDePublicacion::with('publicacion')->findOrFail($id);

        abort_unless(auth()->user()?->can('update', $tarea->publicacion), 403);

        $tarea->update(['hecha_at' => now()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('fechas')
                ->label('Fechas especiales')
                ->icon('heroicon-o-star')
                ->color('gray')
                ->visible(fn () => PublicacionResource::canCreate())
                ->modalHeading('Fechas especiales')
                ->modalDescription('Los días del año que le dan pie al laboratorio para contar algo. Salen en la parrilla y como sugerencia un mes antes. Quita las que no sirvan y añade las propias: el aniversario del laboratorio, la semana de la facultad.')
                ->modalWidth('4xl')
                ->fillForm(fn () => ['fechas' => FechasEspeciales::todas()])
                ->schema([
                    Repeater::make('fechas')
                        ->hiddenLabel()
                        ->columns(6)
                        ->addActionLabel('Añadir una fecha')
                        ->reorderable(false)
                        ->collapsible()
                        ->itemLabel(fn (array $state) => trim(($state['dia'] ?? '') . ' · ' . ($state['nombre'] ?? '')))
                        ->schema([
                            TextInput::make('dia')
                                ->label('Día (mes-día)')
                                ->placeholder('03-08')
                                ->required()
                                ->regex('/^(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/')
                                ->validationMessages(['regex' => 'Como 03-08: mes y día, con dos cifras cada uno.']),
                            TextInput::make('nombre')->label('Qué se celebra')->required()->columnSpan(3),
                            Select::make('pilar')->label('Tema')->options(Publicacion::PILARES)->default('comunidad')->columnSpan(2),
                            Textarea::make('idea')->label('Idea para contarlo')->rows(2)->columnSpanFull(),
                        ]),
                ])
                ->action(function (array $data) {
                    FechasEspeciales::guardar($data['fechas'] ?? []);
                    Notification::make()->title('Fechas guardadas')->success()->send();
                }),

            Action::make('resumen')
                ->label('Resumen del mes')
                ->icon('heroicon-o-chart-bar')
                ->color('gray')
                ->url(PublicacionResource::getUrl('resumen')),

            Action::make('lista')
                ->label('Ver como lista')
                ->icon('heroicon-o-list-bullet')
                ->color('gray')
                ->url(PublicacionResource::getUrl('lista')),

            Action::make('nueva')
                ->label('Nueva publicación')
                ->icon('heroicon-o-plus')
                ->visible(fn () => PublicacionResource::canCreate())
                ->url(PublicacionResource::getUrl('create')),
        ];
    }

    protected function getViewData(): array
    {
        $tz = config('fabos.lab.timezone');
        $inicio = $this->inicio();
        $desde = $inicio->startOfWeek(CarbonImmutable::MONDAY);
        $hasta = $inicio->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);

        $publicaciones = Publicacion::query()
            ->with('tareas')
            ->whereBetween('programada_para', [$desde->utc(), $hasta->utc()])
            ->orderBy('programada_para')
            ->get()
            ->groupBy(fn (Publicacion $p) => $p->programada_para->timezone($tz)->toDateString());

        $especiales = FechasEspeciales::entre($desde, $hasta);

        $semanas = [];
        for ($dia = $desde; $dia->lessThanOrEqualTo($hasta); $dia = $dia->addDay()) {
            $semanas[$dia->format('o-W')][] = [
                'fecha'      => $dia,
                'delMes'     => $dia->month === $inicio->month,
                'hoy'        => $dia->isSameDay($this->hoy()),
                'piezas'     => $publicaciones->get($dia->toDateString(), collect()),
                'especiales' => $especiales->get($dia->toDateString(), collect()),
            ];
        }

        $delMes = Publicacion::query()
            ->enLaParrilla()
            ->whereBetween('programada_para', [$inicio->utc(), $inicio->endOfMonth()->utc()])
            ->get();

        return [
            'inicio'      => $inicio,
            'semanas'     => $semanas,
            'sinFecha'    => Publicacion::whereNull('programada_para')->abiertas()->latest('id')->limit(20)->get(),
            'porAprobar'  => Publicacion::where('estado', 'por_aprobar')->count(),
            'tarde'       => Publicacion::abiertas()->where('programada_para', '<', now())->count(),
            'porPilar'    => $delMes->countBy('pilar'),
            'totalDelMes' => $delMes->count(),
            'sugerencias' => app(Sugerencias::class)->paraHoy($this->hoy()),
            'redactor'    => app(Redactor::class)->nombre(),
            'puedeCrear'  => PublicacionResource::canCreate(),

            // Lo que le toca a quien mira, lo más urgente primero.
            'misTareas'   => TareaDePublicacion::query()
                ->with('publicacion')
                ->pendientes()
                ->where('responsable_id', auth()->id())
                ->whereHas('publicacion', fn ($q) => $q->abiertas())
                ->orderByRaw('vence_el is null, vence_el')
                ->limit(12)
                ->get(),
        ];
    }

    private function inicio(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $this->mes . '-01', config('fabos.lab.timezone'))->startOfDay();
    }

    private function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now(config('fabos.lab.timezone'));
    }
}
