<?php

namespace App\Filament\Resources\CourseEditions\Pages;

use App\Filament\Resources\CourseEditions\CourseEditionResource;
use App\Models\CourseEdition;
use App\Models\CourseEditionChange;
use App\Services\Training\Actividades;
use App\Services\Training\TrainingException;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * La ficha de una edición, con su ciclo de vida arriba (§9).
 *
 * Publicar, cerrar, reprogramar y cancelar van por botones y no por el campo
 * de estado: cada uno deja su línea en el historial y, cuando toca, avisa a
 * los inscritos. Cambiar el estado a mano sigue siendo posible, pero en
 * silencio.
 */
class EditCourseEdition extends EditRecord
{
    protected static string $resource = CourseEditionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->vistaPrevia(),
            $this->publicar(),
            $this->cerrarInscripciones(),
            ActionGroup::make([
                $this->reprogramar(),
                $this->avisarNovedad(),
                $this->cancelar(),
            ])->label('Cambios y avisos')->icon('heroicon-o-megaphone')->button()->color('gray'),
            ActionGroup::make([
                $this->enviarEncuesta(),
                Action::make('resultados')
                    ->label('Resultados de la encuesta')
                    ->icon('heroicon-o-chart-bar')
                    ->url(fn (CourseEdition $record) => CourseEditionResource::getUrl('encuesta', ['record' => $record])),
                $this->duplicar(),
                DeleteAction::make(),
            ])->icon('heroicon-o-ellipsis-vertical'),
        ];
    }

    private function vistaPrevia(): Action
    {
        return Action::make('vista_previa')
            ->label(fn (CourseEdition $record) => $record->estaPublicada() ? 'Ver en el sitio' : 'Vista previa')
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->url(fn (CourseEdition $record) => $record->url())
            ->openUrlInNewTab();
    }

    private function publicar(): Action
    {
        return Action::make('publicar')
            ->label(fn (CourseEdition $record) => $record->status === 'planeada' ? 'Publicar' : 'Reabrir inscripciones')
            ->icon('heroicon-o-rocket-launch')
            ->color('success')
            ->visible(fn (CourseEdition $record) => in_array($record->status, ['planeada', 'inscripciones_cerradas'], true))
            ->requiresConfirmation()
            ->modalDescription(fn (CourseEdition $record) => $record->status === 'planeada'
                ? 'Aparece en el sitio y recibe inscripciones hasta llenar el cupo; después, lista de espera. Revisa antes la vista previa.'
                : 'Vuelve a recibir inscripciones desde el formulario.')
            ->action(fn (CourseEdition $record) => $this->correr(
                fn () => app(Actividades::class)->publicar($record, auth()->user()),
                $record->status === 'planeada' ? 'Publicada' : 'Inscripciones reabiertas',
            ));
    }

    private function cerrarInscripciones(): Action
    {
        return Action::make('cerrar_inscripciones')
            ->label('Cerrar inscripciones')
            ->icon('heroicon-o-lock-closed')
            ->color('warning')
            ->visible(fn (CourseEdition $record) => $record->status === 'abierta')
            ->modalDescription('La página sigue publicada, pero el formulario deja de recibir inscripciones y lista de espera.')
            ->schema([
                Textarea::make('motivo')->label('Motivo')->rows(2)->helperText('Opcional. Queda en el historial.'),
            ])
            ->action(fn (CourseEdition $record, array $data) => $this->correr(
                fn () => app(Actividades::class)->cerrarInscripciones($record, auth()->user(), $data['motivo'] ?? null),
                'Inscripciones cerradas',
            ));
    }

    private function reprogramar(): Action
    {
        return Action::make('reprogramar')
            ->label('Reprogramar o cambiar lugar')
            ->icon('heroicon-o-calendar')
            ->visible(fn (CourseEdition $record) => ! in_array($record->status, ['cancelada', 'cerrada'], true))
            ->modalHeading('Reprogramar la actividad')
            ->modalDescription('Queda en el historial lo que había y lo que queda, con el motivo. El aviso lleva las dos cosas.')
            ->fillForm(fn (CourseEdition $record) => [
                'starts_on'  => $record->starts_on?->format('Y-m-d'),
                'ends_on'    => $record->ends_on?->format('Y-m-d'),
                'start_time' => $record->start_time,
                'end_time'   => $record->end_time,
                'location'   => $record->location,
                'avisar'     => true,
            ])
            ->schema([
                DatePicker::make('starts_on')->label('Empieza')->required(),
                DatePicker::make('ends_on')->label('Termina'),
                TimePicker::make('start_time')->label('Hora de inicio')->seconds(false),
                TimePicker::make('end_time')->label('Hora de fin')->seconds(false),
                TextInput::make('location')->label('Lugar')->maxLength(200),
                Select::make('cause')->label('Causa')->options(CourseEditionChange::CAUSAS)->required(),
                Textarea::make('motivo')->label('Motivo')->rows(3)->required()
                    ->placeholder('Por el cierre de la sede el sábado, la sesión pasa al sábado siguiente.'),
                Toggle::make('avisar')->label('Avisar por correo a inscritos y lista de espera')->default(true),
            ])
            ->action(fn (CourseEdition $record, array $data) => $this->correr(
                function () use ($record, $data) {
                    $r = app(Actividades::class)->reprogramar(
                        $record,
                        collect($data)->only(['starts_on', 'ends_on', 'start_time', 'end_time', 'location'])->all(),
                        $data['cause'],
                        $data['motivo'],
                        (bool) $data['avisar'],
                        auth()->user(),
                    );

                    return $r['avisados'];
                },
                'Reprogramada',
                fn ($avisados) => $avisados ? 'Se avisó a ' . $avisados . ($avisados === 1 ? ' persona.' : ' personas.') : 'Sin aviso por correo.',
            ));
    }

    private function avisarNovedad(): Action
    {
        return Action::make('novedad')
            ->label('Avisar una novedad')
            ->icon('heroicon-o-envelope')
            ->visible(fn (CourseEdition $record) => $record->status !== 'cancelada')
            ->modalDescription('Un correo a los inscritos sin cambiar la fecha: traer abrigo, entrar por otra puerta, una falla técnica. Queda en el historial.')
            ->schema([
                Textarea::make('mensaje')->label('La novedad')->rows(4)->required(),
                Select::make('cause')->label('Causa')->options(CourseEditionChange::CAUSAS)->placeholder('Ninguna en particular'),
                Toggle::make('incluir_espera')->label('Avisar también a la lista de espera'),
            ])
            ->action(fn (CourseEdition $record, array $data) => $this->correr(
                fn () => app(Actividades::class)->avisarNovedad($record, $data['mensaje'], $data['cause'] ?? null, (bool) ($data['incluir_espera'] ?? false), auth()->user())['avisados'],
                'Novedad enviada',
                fn ($avisados) => 'Le llegó a ' . $avisados . ($avisados === 1 ? ' persona.' : ' personas.'),
            ));
    }

    private function cancelar(): Action
    {
        return Action::make('cancelar')
            ->label('Cancelar la actividad')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (CourseEdition $record) => ! in_array($record->status, ['cancelada', 'cerrada'], true))
            ->modalDescription('Sigue en el sitio diciendo que se canceló, y nadie se borra: saber cuántos se habían inscrito sirve para la próxima.')
            ->schema([
                Select::make('cause')->label('Causa')->options(CourseEditionChange::CAUSAS)->required(),
                Textarea::make('motivo')->label('Motivo')->rows(3)->required(),
                Toggle::make('avisar')->label('Avisar por correo a inscritos y lista de espera')->default(true),
            ])
            ->action(fn (CourseEdition $record, array $data) => $this->correr(
                fn () => app(Actividades::class)->cancelar($record, $data['cause'], $data['motivo'], (bool) $data['avisar'], auth()->user())['avisados'],
                'Actividad cancelada',
                fn ($avisados) => $avisados ? 'Se avisó a ' . $avisados . ($avisados === 1 ? ' persona.' : ' personas.') : 'Sin aviso por correo.',
            ));
    }

    private function enviarEncuesta(): Action
    {
        return Action::make('encuesta')
            ->label('Enviar la encuesta')
            ->icon('heroicon-o-clipboard-document-check')
            ->requiresConfirmation()
            ->modalDescription(fn (CourseEdition $record) => 'Va solo a quienes registraron asistencia y todavía no han respondido.'
                . ($record->survey_sent_at ? ' Ya se envió el ' . $record->survey_sent_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y') . '; esto la manda a quien falte.' : ''))
            ->action(fn (CourseEdition $record) => $this->correr(
                fn () => app(Actividades::class)->enviarEncuesta($record, auth()->user()),
                'Encuesta enviada',
                fn ($n) => 'Le llegó a ' . $n . ($n === 1 ? ' persona.' : ' personas.'),
            ));
    }

    private function duplicar(): Action
    {
        return Action::make('duplicar')
            ->label('Duplicar para otro grupo')
            ->icon('heroicon-o-document-duplicate')
            ->requiresConfirmation()
            ->modalDescription('Una edición nueva con el mismo curso, cupo, público, precio y lugar. Nace planeada, sin inscritos: cámbiale la fecha y publícala.')
            ->action(function (CourseEdition $record) {
                $copia = app(Actividades::class)->duplicarEdicion($record, auth()->user());

                Notification::make()->success()->title('Duplicada como ' . $copia->code)->send();

                $this->redirect(CourseEditionResource::getUrl('edit', ['record' => $copia]));
            });
    }

    /** Corre la acción, avisa como fue y refresca la ficha. */
    private function correr(callable $accion, string $titulo, ?callable $cuerpo = null): void
    {
        try {
            $resultado = $accion();
        } catch (TrainingException $e) {
            Notification::make()->danger()->title('No se pudo')->body($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($titulo)->body($cuerpo ? $cuerpo($resultado) : null)->send();

        $this->record->refresh();
        $this->refreshFormData(['status', 'starts_on', 'ends_on', 'start_time', 'end_time', 'location']);
    }
}
