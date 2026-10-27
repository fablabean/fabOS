<?php

namespace App\Filament\Resources\Partidas\Pages;

use App\Filament\Resources\Partidas\PartidaResource;
use App\Models\Recorrido\Partida;
use App\Services\Recorrido\InformeDeParticipantes;
use App\Services\Recorrido\Juego;
use App\Services\Recorrido\JuegoException;
use App\Services\Recorrido\ReporteDelRecorrido;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/** @property Partida $record */
class EditPartida extends EditRecord
{
    protected static string $resource = PartidaResource::class;

    /**
     * Un equipo que se arma con la partida ya en curso entra a jugar al
     * guardar, con el reloj de todos: el que llegó tarde, llegó tarde.
     *
     * Por jornadas no: cada equipo arranca desde su celular, cuando está listo.
     */
    protected function afterSave(): void
    {
        if (! $this->record->enCurso() || $this->record->esPorJornadas()) {
            return;
        }

        foreach ($this->record->equipos()->where('estado', 'esperando')->get() as $equipo) {
            app(Juego::class)->arrancarEquipo($this->record, $equipo);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('iniciar')
                ->label('Iniciar la partida')
                ->icon('heroicon-o-play')
                ->color('success')
                ->visible(fn () => $this->record->estado === 'preparada' && ! $this->record->esPorJornadas())
                ->requiresConfirmation()
                ->modalHeading('¿Iniciar la partida?')
                ->modalDescription('Arranca el reloj para todos los equipos a la vez y cada líder recibe su primera pista.')
                ->action(function (): void {
                    try {
                        app(Juego::class)->iniciar($this->record);
                    } catch (JuegoException $e) {
                        Notification::make()->danger()->title('No se pudo iniciar')->body($e->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title('¡A jugar!')->send();
                    $this->refreshFormData(['estado']);
                }),

            Action::make('terminar')
                ->label('Terminar')
                ->icon('heroicon-o-flag')
                ->color('danger')
                ->visible(fn () => $this->record->enCurso() || ($this->record->esPorJornadas() && $this->record->estado === 'preparada'))
                ->requiresConfirmation()
                ->modalDescription(fn () => $this->record->esPorJornadas()
                    ? 'Cierra las inscripciones aunque queden jornadas y detiene el reloj de los equipos que estén jugando. Sus tiempos quedan como están.'
                    : 'Detiene el reloj de los equipos que no han terminado. Sus tiempos quedan como están.')
                ->action(function (): void {
                    app(Juego::class)->terminar($this->record);
                    Notification::make()->success()->title('Partida terminada')->send();
                }),

            Action::make('inscripcion')
                ->label('Enlace de inscripción')
                ->icon('heroicon-o-user-plus')
                ->color('gray')
                ->visible(fn () => $this->record->esPorJornadas())
                ->url(fn () => $this->record->urlDeInscripcion())
                ->openUrlInNewTab(),

            Action::make('equipos')
                ->label(fn () => $this->record->esPorJornadas() ? 'QR de inscripción' :'Códigos de los equipos')
                ->icon('heroicon-o-qr-code')
                ->color('gray')
                ->url(fn () => route('recorridos.equipos', $this->record))
                ->openUrlInNewTab(),

            // Lo que se le entrega a quien trajo al grupo, y la lista para el laboratorio.
            ActionGroup::make([
                Action::make('enviarReporte')
                    ->label('Enviar reporte')
                    ->icon('heroicon-o-paper-airplane')
                    ->color('gray')
                    ->modalHeading('Enviar el reporte del recorrido')
                    ->modalDescription(fn () => 'Le llega un enlace con el resumen: equipos, quiénes jugaron, puestos y tiempos. Sin el correo de nadie.'
                        . ($this->record->reporte_enviado_at
                            ? ' Ya se envió a ' . $this->record->reporte_enviado_a . ' el ' . $this->record->reporte_enviado_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y H:i') . '.'
                            : ''))
                    ->schema([
                        TextInput::make('nombre')
                            ->label('A quién')
                            ->helperText('Quien solicitó el recorrido, normalmente el profesor.')
                            ->default(fn () => ReporteDelRecorrido::solicitante($this->record)['nombre'])
                            ->required()->maxLength(160),
                        TextInput::make('correo')
                            ->email()
                            ->default(fn () => ReporteDelRecorrido::solicitante($this->record)['correo'])
                            ->required()->maxLength(160),
                    ])
                    ->modalSubmitActionLabel('Enviar')
                    ->action(function (array $data): void {
                        $aviso = app(ReporteDelRecorrido::class)->enviar($this->record, $data['correo'], $data['nombre']);

                        $aviso->status === 'enviado'
                            ? Notification::make()->success()->title('Reporte enviado')->body('A ' . $data['correo'] . '.')->send()
                            : Notification::make()->danger()->title('No se pudo enviar')->body($aviso->reason)->send();
                    }),

                Action::make('reporte')
                    ->label('Ver reporte')
                    ->icon('heroicon-o-document-chart-bar')
                    ->color('gray')
                    ->url(fn () => $this->record->urlDelReporte())
                    ->openUrlInNewTab(),

                Action::make('participantes')
                    ->label('Participantes (CSV)')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(fn () => response()->streamDownload(
                        fn () => print(app(InformeDeParticipantes::class)->csv(collect([$this->record]))),
                        'participantes-' . \Illuminate\Support\Str::slug($this->record->nombre) . '.csv',
                        ['Content-Type' => 'text/csv; charset=UTF-8'],
                    )),
            ])
                ->label('Reporte')
                ->icon('heroicon-o-document-chart-bar')
                ->color('gray')
                ->button(),

            Action::make('tablero')
                ->label('Tablero')
                ->icon('heroicon-o-presentation-chart-bar')
                ->color('gray')
                ->url(fn () => $this->record->urlDelTablero())
                ->openUrlInNewTab(),

            DeleteAction::make()->visible(fn () => $this->record->estado !== 'en_curso'),
        ];
    }
}
