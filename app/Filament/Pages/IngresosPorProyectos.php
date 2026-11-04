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

    public function mount(): void
    {
        $this->ajuste = Calculo::ajusteGuardado();
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
        return app(Calculo::class)->calcular(null, $this->ajuste) + [
            'sinGuardar' => Calculo::normalizar($this->ajuste) !== Calculo::ajusteGuardado(),
        ];
    }
}
