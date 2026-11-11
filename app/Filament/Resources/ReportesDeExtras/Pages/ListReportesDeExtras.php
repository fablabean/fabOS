<?php

namespace App\Filament\Resources\ReportesDeExtras\Pages;

use App\Filament\Resources\ReportesDeExtras\ReporteDeExtrasResource;
use App\Models\CorteDeNomina;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

class ListReportesDeExtras extends ListRecords
{
    protected static string $resource = ReporteDeExtrasResource::class;

    /** Cuántos meses se muestran para fijar el corte: el actual y los que vienen. */
    private const MESES = 6;

    protected function getHeaderWidgets(): array
    {
        return [\App\Filament\Resources\ShiftAssignments\Widgets\ContadorDeExtras::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            /*
             * La fecha de corte de cada mes. Nómina la mueve entre el 14 y el
             * 17; un mes sin fecha corta el día de la configuración. Cambiarla
             * mueve qué jornadas entran en el reporte del mes y el periodo del
             * tope de 48 horas.
             */
            Action::make('cortes')
                ->label('Fechas de corte')
                ->icon('heroicon-o-calendar-days')
                ->visible(fn () => auth()->user()?->puedeEnLaSeccion('editar', 'reporte-de-extras') ?? false)
                ->modalDescription('El día hasta el que entran las jornadas en el reporte de cada mes. Normalmente entre el 14 y el 17, según el cronograma de nómina. Lo que se haga después del corte queda para el mes siguiente.')
                ->modalSubmitActionLabel('Guardar')
                ->fillForm(fn () => collect(self::meses())->mapWithKeys(fn (Carbon $m) => [
                    'm' . $m->format('Y_m') => CorteDeNomina::fechaDe($m)->toDateString(),
                ])->all())
                ->schema(fn () => collect(self::meses())->map(fn (Carbon $m) => DatePicker::make('m' . $m->format('Y_m'))
                    ->label(ucfirst($m->locale('es')->isoFormat('MMMM [de] YYYY')))
                    ->native(false)
                    ->displayFormat('d/m/Y')
                    ->minDate($m->copy()->startOfMonth())
                    ->maxDate($m->copy()->endOfMonth())
                    ->required())->all())
                ->modalWidth('md')
                ->action(function (array $data) {
                    foreach (self::meses() as $m) {
                        $fecha = $data['m' . $m->format('Y_m')] ?? null;
                        if ($fecha) {
                            CorteDeNomina::updateOrCreate(['mes' => $m->toDateString()], ['fecha' => Carbon::parse($fecha)->toDateString()]);
                        }
                    }
                    CorteDeNomina::olvidar();
                    Notification::make()->title('Fechas de corte guardadas')->success()->send();
                }),
        ];
    }

    /** @return list<Carbon> el primero de cada mes, desde el actual */
    private static function meses(): array
    {
        $inicio = now(config('fabos.lab.timezone'))->startOfMonth();

        return array_map(fn (int $i) => $inicio->copy()->addMonthsNoOverflow($i), range(0, self::MESES - 1));
    }
}
