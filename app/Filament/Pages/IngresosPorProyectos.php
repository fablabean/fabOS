<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Services\Reports\IngresosPorProyectos as Calculo;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Ingresos por proyectos ejecutados, y su proyección a doce meses (§13).
 *
 * Se calcula al abrirla, de los mismos proyectos de la lista: no hay cifras
 * que copiar a mano ni que se desactualicen. Lo que no tiene valor registrado
 * se dice, con enlace a cada ficha, para que la cifra se complete sola.
 */
class IngresosPorProyectos extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.ingresos-por-proyectos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static ?int $navigationSort = 6;

    public static function getNavigationGroup(): string | \UnitEnum | null
    {
        return 'Finanzas';
    }

    public static function getNavigationLabel(): string
    {
        return 'Ingresos por proyectos';
    }

    public function getTitle(): string
    {
        return 'Ingresos por proyectos';
    }

    /**
     * El ajuste de la proyección que se está probando. Cada cambio redibuja la
     * gráfica; solo queda para todos al guardarlo.
     *
     * @var array{realista:int, meses:array<int,array<string,int>>}
     */
    public array $ajuste = [];

    /** @var array<int,int> el valor aproximado de cada área, mientras se decide */
    public array $valoresPorArea = [];

    public function mount(): void
    {
        $this->ajuste = Calculo::ajusteGuardado();
        $this->valoresPorArea = collect(app(Calculo::class)->porArea())->map(fn ($f) => $f['valor'])->all();
    }

    public function aplicarAproximadosAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('aplicarAproximados')
            ->label('Aplicar a los proyectos sin valor')
            ->icon('heroicon-o-calculator')
            ->requiresConfirmation()
            ->modalHeading('Valorar los proyectos sin valor')
            ->modalDescription(fn () => 'Cada ejecutado sin valor toma el de su área como valor estimado y queda como interno. '
                . 'No se avisa a nadie ni cambia su etapa. Quedan marcados como «Aproximado por área» y se puede deshacer.')
            ->modalSubmitActionLabel('Aplicar')
            ->action(function () {
                $n = app(Calculo::class)->aplicarAproximados($this->valoresPorArea);

                \Filament\Notifications\Notification::make()->success()
                    ->title($n . ' ' . ($n === 1 ? 'proyecto valorado' : 'proyectos valorados'))
                    ->body('Ahora cuentan como internos. Las áreas con valor en cero se quedaron sin valor.')->send();
            });
    }

    public function deshacerAproximadosAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('deshacerAproximados')
            ->label('Deshacer los aproximados')
            ->color('gray')
            ->icon('heroicon-o-arrow-uturn-left')
            ->requiresConfirmation()
            ->modalDescription('Los proyectos valorados por área vuelven a quedar sin valor, y los que eran de un cliente dejan de ser internos. Los que alguien corrigió a mano se quedan como están.')
            ->visible(fn () => Calculo::aproximados() > 0)
            ->action(function () {
                $n = app(Calculo::class)->deshacerAproximados();
                $this->valoresPorArea = collect(app(Calculo::class)->porArea())->map(fn ($f) => $f['valor'])->all();

                \Filament\Notifications\Notification::make()->success()->title($n . ' vueltos a como estaban')->send();
            });
    }

    public function guardarAjuste(): void
    {
        Calculo::guardarAjuste($this->ajuste);
        $this->ajuste = Calculo::ajusteGuardado();

        \Filament\Notifications\Notification::make()->success()->title('Ajuste guardado')
            ->body('La proyección queda así para todos, y el Excel sale con este ajuste.')->send();
    }

    public function volverAlGuardado(): void
    {
        $this->ajuste = Calculo::ajusteGuardado();
    }

    public function ajusteDeFabrica(): void
    {
        $this->ajuste = Calculo::ajusteDeFabrica();
    }

    public function descargar(): BinaryFileResponse
    {
        return response()
            ->download(app(Calculo::class)->excel(null, $this->ajuste), 'ingresos-por-proyectos-' . now(config('fabos.lab.timezone'))->format('Y-m-d') . '.xlsx')
            ->deleteFileAfterSend();
    }

    /**
     * El detalle de una cifra: qué proyectos la forman, con su valor y de
     * dónde viene, y el enlace a cada ficha para corregirlo.
     */
    public function origenAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('origen')
            ->modalHeading(function (array $arguments) {
                $mes = $arguments['mes'] ?? null;

                return (Calculo::QUE_ES[$arguments['columna'] ?? 'n'] ?? 'Proyectos') . ' · '
                    . ($mes ? \Illuminate\Support\Carbon::parse($mes . '-01')->locale('es')->isoFormat('MMMM YYYY') : 'todo el periodo');
            })
            ->modalWidth('4xl')
            ->modalContent(fn (array $arguments) => view('filament.pages.ingresos-por-proyectos-origen', [
                'filas' => app(Calculo::class)->origen($arguments['mes'] ?? null, $arguments['columna'] ?? 'n'),
            ]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar');
    }

    public function getViewData(): array
    {
        // Una pantalla abierta desde antes de un despliegue vuelve sin ajuste:
        // se toma el guardado en vez de romper.
        $this->ajuste = Calculo::normalizar($this->ajuste);

        return app(Calculo::class)->calcular(null, $this->ajuste) + [
            'porArea'     => app(Calculo::class)->porArea(),
            'aproximados' => Calculo::aproximados(),
            'sinGuardar' => Calculo::normalizar($this->ajuste) !== Calculo::ajusteGuardado(),
        ];
    }
}
