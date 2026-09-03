<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Models\Asset;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Booking\ApprovalService;
use App\Services\Booking\BookingException;
use App\Services\Staffing\CoverageService;
use App\Services\Staffing\OvertimeService;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * La bandeja de solicitudes (§10).
 *
 * Aquí llega lo que la gente pide y el sistema no puede confirmar solo: un
 * sábado, un equipo que se pide en vez de reservarse, o una sesión más larga de
 * lo que su certifab permite.
 *
 * Cada solicitud muestra **quién podría atenderla y a qué costo en horas
 * extras**. Decidir sin ver eso es cómo un «sí» amable se convierte, tres
 * sábados después, en un problema con Talento Humano.
 */
class Bandeja extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.bandeja';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?int $navigationSort = 0;

    /** Quién atiende cada solicitud, por id de reserva. */
    public array $acompanante = [];

    /** El motivo del rechazo, por id de reserva. */
    public array $motivo = [];


    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Reservas';
    }

    public static function getNavigationLabel(): string
    {
        return 'Solicitudes';
    }

    public function getTitle(): string
    {
        return 'Solicitudes por decidir';
    }

    /** El número al lado del menú: lo que está esperando respuesta. */
    public static function getNavigationBadge(): ?string
    {
        $pendientes = Reservation::where('status', 'solicitada')->where('ends_at', '>', now())->count();

        return $pendientes ?: null;
    }

    public function aprobar(int $reservaId): void
    {
        $solicitud = Reservation::find($reservaId);

        if (! $solicitud) {
            return;
        }

        $quien = ! empty($this->acompanante[$reservaId])
            ? User::find($this->acompanante[$reservaId])
            : null;

        try {
            app(ApprovalService::class)->aprobar($solicitud, $quien, auth()->user());
        } catch (BookingException $e) {
            Notification::make()
                ->title('No se pudo aprobar')
                ->body($e->getMessage())
                ->danger()
                ->persistent()
                ->send();

            return;
        }

        Notification::make()
            ->title('Solicitud aprobada')
            ->body($quien
                ? $quien->name . ' queda con la jornada programada y su tiempo reservado.'
                : 'La reserva quedó confirmada.')
            ->success()
            ->send();
    }

    public function rechazar(int $reservaId): void
    {
        $solicitud = Reservation::find($reservaId);

        if (! $solicitud) {
            return;
        }

        $motivo = (string) ($this->motivo[$reservaId] ?? '');

        if (trim($motivo) === '') {
            Notification::make()
                ->title('Falta el motivo')
                ->body('Quien pidió algo y recibe un «no» sin explicación vuelve a pedir lo mismo la semana siguiente.')
                ->warning()
                ->send();

            return;
        }

        try {
            app(ApprovalService::class)->rechazar($solicitud, $motivo, auth()->user());
        } catch (BookingException $e) {
            Notification::make()->title('No se pudo rechazar')->body($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()->title('Solicitud rechazada')->success()->send();
    }

    /** @return array<string,mixed> */
    public function getViewData(): array
    {
        $solicitudes = app(ApprovalService::class)->bandeja();

        $equipos = Asset::with('area')
            ->whereIn('id', $solicitudes->where('reservable_type', Asset::class)->pluck('reservable_id'))
            ->get()
            ->keyBy('id');

        // Los espacios también se piden: fuera de la jornada del equipo la
        // sala no se confirma sola, porque abrirla cuesta horas extras.
        $espacios = \App\Models\Space::query()
            ->whereIn('id', $solicitudes->where('reservable_type', \App\Models\Space::class)->pluck('reservable_id'))
            ->get()
            ->keyBy('id');

        $cobertura = app(CoverageService::class);
        $extras = app(OvertimeService::class);

        // Para un espacio no hay certifab que pedir: la atiende cualquiera del
        // equipo, y la lista lo dice con sus extras del mes al lado.
        $personal = User::role(User::ROLES_BACKOFFICE)->where('status', 'activo')->orderBy('name')->get();

        return [
            'solicitudes' => $solicitudes->map(function (Reservation $s) use ($equipos, $espacios, $personal, $cobertura, $extras) {
                $equipo = $s->reservable_type === Asset::class ? ($equipos[$s->reservable_id] ?? null) : null;
                $espacio = $s->reservable_type === \App\Models\Space::class ? ($espacios[$s->reservable_id] ?? null) : null;

                // Quién puede atenderla. Se ofrece a TODO el que esté
                // certificado, no solo a quien esté en jornada: en un sábado
                // no hay nadie en jornada por definición, y aun así hay que
                // poder decidir a quién llamar. Al lado de cada nombre, sus
                // extras del mes, que es el costo real de decir que sí.
                $candidatos = match (true) {
                    $equipo !== null  => $cobertura->certificadosPara($equipo),
                    $espacio !== null => $personal,
                    default           => collect(),
                };

                $enJornada = $cobertura->enJornada($s->starts_at, $s->ends_at, incluirRemota: $espacio?->type === 'virtual')->pluck('id');

                return [
                    'reserva'    => $s,
                    'equipo'     => $equipo,
                    'espacio'    => $espacio,
                    'candidatos' => $candidatos->map(fn (User $u) => [
                        'id'         => $u->id,
                        'nombre'     => $u->name,
                        'en_jornada' => $enJornada->contains($u->id),
                        'extras_mes' => round($extras->minutosMes($u, $s->starts_at->copy()) / 60, 1),
                    ]),
                ];
            }),
            'topeMes' => round(config('fabos.overtime.max_mes_minutos') / 60),
        ];
    }
}
