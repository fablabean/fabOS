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
            // A dónde va el reporte firmado y quién firma como jefe, por defecto.
            Action::make('configuracion')
                ->label('Configuración')
                ->icon('heroicon-o-cog-6-tooth')
                ->color('gray')
                ->visible(fn () => auth()->user()?->puedeEnLaSeccion('editar', 'reporte-de-extras') ?? false)
                ->modalDescription('El buzón al que se envía el ADF-404-f4 firmado, y lo que sale por defecto al generarlo. Cada quien puede cambiar el área y el jefe en su reporte.')
                ->modalSubmitActionLabel('Guardar')
                ->fillForm(fn () => \App\Support\ConfiguracionDeNomina::todo())
                ->schema([
                    \Filament\Forms\Components\TextInput::make('correo')->label('Correo de novedades de nómina')->email()->required(),
                    \Filament\Forms\Components\TextInput::make('destinatario')->label('A nombre de')->required(),
                    \Filament\Forms\Components\TextInput::make('area')->label('Proceso o área, por defecto')->required(),
                    \Filament\Forms\Components\Select::make('coordinador_id')
                        ->label('Jefe inmediato, por defecto')
                        ->helperText('Quien firma después del colaborador. Le llega el enlace por correo.')
                        ->options(fn () => \App\Support\ConfiguracionDeNomina::opcionesDeCoordinador())
                        ->searchable(),
                ])
                ->action(function (array $data) {
                    \App\Support\ConfiguracionDeNomina::guardar($data);
                    Notification::make()->title('Configuración guardada')->success()->send();
                }),

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
