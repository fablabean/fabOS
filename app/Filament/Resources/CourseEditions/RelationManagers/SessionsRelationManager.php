<?php

namespace App\Filament\Resources\CourseEditions\RelationManagers;

use App\Models\CourseEdition;
use App\Models\CourseSession;
use App\Models\Enrollment;
use App\Services\Qr\QrRenderer;
use App\Services\Training\AsistenciaDeActividad;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;

/**
 * Las sesiones de una edición y su asistencia (§9).
 *
 * Cada sesión tiene su QR, que se imprime o se proyecta en la puerta. Lo que
 * el QR no alcanza se anota a mano, pasando lista.
 */
class SessionsRelationManager extends RelationManager
{
    protected static string $relationship = 'sessions';

    protected static ?string $title = 'Sesiones y asistencia';

    public function form(Schema $schema): Schema
    {
        $tz = config('fabos.lab.timezone');

        return $schema->components([
            TextInput::make('title')->label('Nombre')->maxLength(120)->placeholder('Sesión 1'),
            DateTimePicker::make('starts_at')->label('Empieza')->seconds(false)->timezone($tz)->required(),
            DateTimePicker::make('ends_at')->label('Termina')->seconds(false)->timezone($tz)->after('starts_at'),
        ]);
    }

    public function table(Table $table): Table
    {
        $tz = config('fabos.lab.timezone');

        return $table
            ->defaultSort('starts_at')
            ->emptyStateHeading('Sin sesiones')
            ->emptyStateDescription('Crea una por cada día de la actividad; cada una tiene su QR de asistencia. Con «Crear la sesión de la fecha» se arma sola con la fecha y la hora de la edición.')
            ->columns([
                TextColumn::make('starts_at')
                    ->label('Sesión')
                    ->state(fn (CourseSession $r) => $r->nombre())
                    ->weight('medium'),

                TextColumn::make('asistencia')
                    ->label('Asistencia')
                    ->state(function (CourseSession $r) {
                        $conCupo = $r->edition->inscritos();
                        $vinieron = $r->attendances()->where('status', 'asistio')->count();

                        return $vinieron . ' de ' . $conCupo;
                    })
                    ->description(function (CourseSession $r) {
                        $qr = $r->attendances()->where('status', 'asistio')->where('method', 'qr')->count();
                        $ausentes = $r->attendances()->where('status', 'no_asistio')->count();

                        return $qr . ' con QR' . ($ausentes ? ' · ' . $ausentes . ' no vinieron' : '');
                    }),

                TextColumn::make('qr')
                    ->label('QR')
                    ->state(fn (CourseSession $r) => $r->qrAbierto() ? 'Registrando ahora' : ($r->terminaA()->isPast() ? 'Ya pasó' : 'Todavía no'))
                    ->badge()
                    ->color(fn (CourseSession $r) => $r->qrAbierto() ? 'success' : 'gray'),
            ])
            ->headerActions([
                $this->crearDeLaFecha(),
                CreateAction::make()->label('Añadir sesión'),
            ])
            ->recordActions([
                $this->verQr(),
                $this->pasarLista(),
                EditAction::make(),
                DeleteAction::make()
                    ->modalDescription('Se borra también la asistencia anotada en esta sesión.'),
            ]);
    }

    /** Una sesión con la fecha y la hora de la edición: el caso de un taller de un día. */
    private function crearDeLaFecha(): Action
    {
        return Action::make('de_la_fecha')
            ->label('Crear la sesión de la fecha')
            ->icon('heroicon-o-bolt')
            ->color('gray')
            ->visible(fn () => $this->getOwnerRecord()->sessions()->doesntExist() && $this->getOwnerRecord()->starts_on)
            ->action(function () {
                /** @var CourseEdition $e */
                $e = $this->getOwnerRecord();
                $tz = config('fabos.lab.timezone');
                $dia = $e->starts_on->format('Y-m-d');

                $e->sessions()->create([
                    'starts_at' => Carbon::parse($dia . ' ' . ($e->start_time ? substr((string) $e->start_time, 0, 5) : '08:00'), $tz),
                    'ends_at'   => $e->end_time ? Carbon::parse($dia . ' ' . substr((string) $e->end_time, 0, 5), $tz) : null,
                ]);

                Notification::make()->success()->title('Sesión creada')->send();
            });
    }

    private function verQr(): Action
    {
        return Action::make('qr')
            ->label('QR')
            ->icon('heroicon-o-qr-code')
            ->color('info')
            ->modalHeading(fn (CourseSession $r) => 'QR de asistencia · ' . $r->nombre())
            ->modalContent(fn (CourseSession $r) => new HtmlString(
                '<div style="text-align:center">'
                . app(QrRenderer::class)->svg($r->url(), 260)
                . '<p style="font-size:.85rem;margin-top:.8rem">Registra desde una hora antes de la sesión hasta una hora después de que termina. '
                . 'Quien lo escanea escribe el correo con que se inscribió; escanear dos veces no cuenta dos veces.</p>'
                . '<p style="font-size:.78rem;word-break:break-all;opacity:.7">' . e($r->url()) . '</p>'
                . '</div>'
            ))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar')
            ->extraModalFooterActions(fn (CourseSession $r) => [
                Action::make('imprimir')
                    ->label('Abrir para imprimir o proyectar')
                    ->url(route('asistencia.qr', $r))
                    ->openUrlInNewTab(),
            ]);
    }

    /**
     * Pasar lista: los marcados vinieron; los demás, no. Quien ya escaneó
     * sale marcado, y una casilla vacía no le borra el QR.
     */
    private function pasarLista(): Action
    {
        return Action::make('lista')
            ->label('Pasar lista')
            ->icon('heroicon-o-clipboard-document-list')
            ->modalHeading(fn (CourseSession $r) => 'Asistencia · ' . $r->nombre())
            ->modalDescription('Marca quién vino. Sirve para anotar a quien no pudo escanear y para corregir. Quien ya registró con el QR no pierde su asistencia por quedar sin marcar.')
            ->fillForm(fn (CourseSession $r) => [
                'presentes' => $r->attendances()->where('status', 'asistio')->pluck('enrollment_id')->map(fn ($id) => (string) $id)->all(),
            ])
            ->schema(fn (CourseSession $r) => [
                CheckboxList::make('presentes')
                    ->label('Vinieron')
                    ->options(fn () => $r->edition->enrollments()
                        ->whereNotIn('status', Enrollment::SIN_CUPO)
                        ->with('user')
                        ->get()
                        ->sortBy(fn (Enrollment $i) => mb_strtolower((string) $i->user?->name))
                        ->mapWithKeys(fn (Enrollment $i) => [(string) $i->id => ($i->user?->name ?? 'Sin nombre') . ' · ' . ($i->user?->email ?? '')])
                        ->all())
                    ->bulkToggleable()
                    ->searchable()
                    ->columns(1),
            ])
            ->action(function (CourseSession $record, array $data) {
                $r = app(AsistenciaDeActividad::class)->pasarLista($record, (array) ($data['presentes'] ?? []), auth()->user());

                Notification::make()->success()
                    ->title('Asistencia guardada')
                    ->body($r['asistieron'] . ' vinieron · ' . $r['ausentes'] . ' no vinieron')
                    ->send();
            });
    }
}
