<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Reservations\ReservationResource;
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
 *
 * **Vive dentro de Reservas**, no aparte. Al final una solicitud es una
 * reserva que todavía no se confirmó, y tenerla en su propio grupo del menú
 * hacía creer que eran dos temas distintos. Se entra desde la lista de
 * Reservas; el menú tiene una sola entrada.
 *
 * Sigue siendo una **pantalla propia** y no una pestaña de la tabla porque lo
 * que hace falta para decidir —quién puede atenderla, cuántas horas extras
 * lleva ya ese mes, si está libre a esa hora— no cabe en una fila.
 *
 * Y conserva el nombre `Bandeja` a propósito: de él sale la clave del permiso
 * (`ver.bandeja`), que vive en la base de datos y NO es la de Reservas.
 * Renombrar la clase dejaría el permiso huérfano y la sección cerrada; darle
 * el permiso de Reservas se lo abriría a consultores y practicantes, que hoy
 * ven reservas pero no deciden horas extras de nadie.
 */
class Bandeja extends Page
{
    use ControlaSuAcceso;

    protected string $view = 'filament.pages.bandeja';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?int $navigationSort = 0;

    /** Bajo Reservas también en la URL: es donde se piensa que está. */
    protected static ?string $slug = 'reservations/solicitudes';

    /**
     * Normalmente no se anuncia en el menú: se llega desde Reservas, que es de
     * donde cuelga, y dos entradas para el mismo tema es lo que se vino a
     * quitar.
     *
     * **Salvo que quien decide no vea Reservas.** Los permisos se editan en
     * *Roles y accesos* sin desplegar, así que esa combinación se puede
     * configurar cualquier martes; escondida y sin lista desde donde entrar,
     * la bandeja quedaría inalcanzable y las solicitudes sin responder sin que
     * nadie entendiera por qué. Ahí sí se anuncia sola: la regla es una sola
     * puerta, no ninguna.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess() && ! ReservationResource::canAccess();
    }

    /** Quién atiende cada solicitud, por id de reserva. */
    public array $acompanante = [];

    /** El motivo del rechazo, por id de reserva. */
    public array $motivo = [];


    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Operación';
    }

    public static function getNavigationLabel(): string
    {
        return 'Solicitudes';
    }

    public function getTitle(): string
    {
        return 'Solicitudes por decidir';
    }

    /**
     * El rastro de vuelta: esta pantalla cuelga de Reservas.
     *
     * Se entra desde ahi y no esta en el menu, asi que sin esto la unica
     * salida era el boton de atras del navegador o volver a buscar Reservas
     * en el menu. Una pantalla en la que se entra y no se sale es una
     * pantalla en la que la gente no entra dos veces.
     */
    public function getBreadcrumbs(): array
    {
        return [
            ReservationResource::getUrl() => 'Reservas',
            '#' => 'Solicitudes por decidir',
        ];
    }

    /** Y el boton, para quien no lee migas de pan. */
    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('volver')
                ->label('Volver a Reservas')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn () => ReservationResource::getUrl()),
        ];
    }

    /**
     * Lo que está esperando respuesta.
     *
     * El número lo enseña Reservas en el menú —esta pantalla ya no está en
     * él— y también el botón que lleva hasta aquí.
     */
    public static function pendientes(): int
    {
        return Reservation::where('status', 'solicitada')->where('ends_at', '>', now())->count();
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
        $reservas = app(\App\Services\Booking\BookingService::class);
        $extras = app(OvertimeService::class);

        // Para un espacio no hay certifab que pedir: la atiende cualquiera del
        // equipo, y la lista lo dice con sus extras del mes al lado.
        $personal = User::role(User::ROLES_BACKOFFICE)->where('status', 'activo')->orderBy('name')->get();

        return [
            'solicitudes' => $solicitudes->map(function (Reservation $s) use ($equipos, $espacios, $personal, $cobertura, $extras, $reservas) {
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
                    // La bandeja lista hasta que la franja TERMINA, y aprobar
                    // solo vale hasta que EMPIEZA. En medio hay que decirlo,
                    // o el boton verde miente.
                    'ya_empezo'  => $s->franjaYaEmpezo(),
                    'candidatos' => $candidatos->map(fn (User $u) => [
                        'id'         => $u->id,
                        'nombre'     => $u->name,
                        'en_jornada' => $enJornada->contains($u->id),
                        // Si esta libre a esa hora, y si no, por que: se
                        // dice antes de elegir, no despues como un error.
                        'ocupado'    => $reservas->porQueNoEstaLibre($u, $s->starts_at, $s->ends_at),
                        'extras_mes' => round($extras->minutosMes($u, $s->starts_at->copy()) / 60, 1),
                    ]),
                ];
            }),
            'topeMes' => round(config('fabos.overtime.max_mes_minutos') / 60),
        ];
    }
}
