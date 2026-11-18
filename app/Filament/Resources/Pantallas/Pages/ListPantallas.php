<?php

namespace App\Filament\Resources\Pantallas\Pages;

use App\Filament\Resources\Pantallas\PantallaResource;
use App\Services\Iot\ImportadorDeAgenda;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use RuntimeException;

class ListPantallas extends ListRecords
{
    protected static string $resource = PantallaResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('importar')
                ->label('Cargar agenda (CSV o Excel)')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->visible(fn () => PantallaResource::canCreate())
                ->modalHeading('Cargar la agenda de los salones')
                ->modalDescription(new HtmlString(
                    'Una fila por actividad con las columnas <strong>salon, fecha, hora_inicio, duracion_min, actividad, responsable</strong> '
                    . '(en vez de duracion_min sirve <em>hora_fin</em>). El salón tiene que tener su pantalla registrada. '
                    . 'Si una fila tiene un error no se carga nada y se dice cuál; si una actividad ya existe (mismo salón, hora y nombre), se actualiza.'
                ))
                ->modalSubmitActionLabel('Cargar')
                ->schema([
                    FileUpload::make('archivo')
                        ->label('Archivo')
                        ->disk('local')
                        ->directory('pantallas/importaciones')
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', '.csv', '.xlsx'])
                        ->maxSize(5120)
                        ->required(),
                ])
                ->action(function (array $data, Action $action): void {
                    $ruta = Storage::disk('local')->path($data['archivo']);

                    try {
                        $r = app(ImportadorDeAgenda::class)->importar($ruta, (string) $data['archivo']);
                    } catch (RuntimeException $e) {
                        Notification::make()->title('No se cargó')->body($e->getMessage())->danger()->persistent()->send();
                        $action->halt();
                    } finally {
                        Storage::disk('local')->delete($data['archivo']);
                    }

                    if ($r['errores'] !== []) {
                        Notification::make()
                            ->title('No se cargó nada: hay ' . count($r['errores']) . ' ' . (count($r['errores']) === 1 ? 'fila con error' : 'filas con error'))
                            ->body(new HtmlString(implode('<br>', array_map('e', array_slice($r['errores'], 0, 12))) . (count($r['errores']) > 12 ? '<br>…' : '') . '<br><br>Corrige el archivo y vuelve a cargarlo.'))
                            ->danger()
                            ->persistent()
                            ->send();
                        $action->halt();
                    }

                    Notification::make()
                        ->title('Agenda cargada')
                        ->body($r['creadas'] . ' actividades nuevas' . ($r['actualizadas'] ? ' y ' . $r['actualizadas'] . ' actualizadas' : '') . '. Las pantallas las muestran la próxima vez que se actualicen.')
                        ->success()
                        ->send();
                }),
            Action::make('plantilla')
                ->label('Plantilla')
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(fn () => response()->streamDownload(
                    fn () => print(ImportadorDeAgenda::plantillaCsv()),
                    'agenda-de-salones.csv',
                    ['Content-Type' => 'text/csv; charset=UTF-8'],
                )),
            CreateAction::make(),
        ];
    }
}
