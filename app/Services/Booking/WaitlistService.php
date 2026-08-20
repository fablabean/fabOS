<?php

namespace App\Services\Booking;

use App\Models\Asset;
use App\Models\Reservation;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Notifications\NotificationService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Lista de espera (§10).
 *
 * Un equipo lleno no debería ser el final de la conversación. Quien no
 * consiguió hora se apunta con **la ventana en la que le sirve**, y cuando algo
 * se libera dentro de esa ventana se le avisa.
 *
 * Guardar la ventana y no solo el equipo es lo que hace útil el aviso: decirle
 * a alguien que se liberó el martes cuando solo puede venir el jueves es ruido,
 * y el ruido enseña a ignorar los correos.
 *
 * Se avisa **a todos** los que esperan, no solo al primero. El laboratorio no
 * asigna el hueco: lo abre. Reservarlo automáticamente para quien lleva más
 * tiempo esperando suena justo hasta que esa persona no aparece y el equipo se
 * queda quieto igual.
 */
class WaitlistService
{
    public function __construct(private NotificationService $avisos) {}

    /**
     * @throws BookingException si ya está esperando ese mismo equipo
     */
    public function apuntar(
        User $persona,
        Asset $equipo,
        CarbonInterface $desde,
        CarbonInterface $hasta,
        ?string $nota = null,
    ): WaitlistEntry {
        if ($hasta->lessThanOrEqualTo($desde)) {
            throw new BookingException('La ventana debe terminar después de empezar.');
        }

        $yaEspera = WaitlistEntry::where('asset_id', $equipo->id)
            ->where('user_id', $persona->id)
            ->where('status', 'esperando')
            ->exists();

        if ($yaEspera) {
            throw new BookingException('Ya estás en la lista de espera de ' . $equipo->name . '.');
        }

        return WaitlistEntry::create([
            'asset_id'    => $equipo->id,
            'user_id'     => $persona->id,
            'wants_from'  => $desde,
            'wants_until' => $hasta,
            'status'      => 'esperando',
            'note'        => $nota,
        ]);
    }

    public function cancelar(WaitlistEntry $entrada): WaitlistEntry
    {
        $entrada->update(['status' => 'cancelado']);

        return $entrada->refresh();
    }

    /**
     * Avisa a quien esperaba que se liberó una franja.
     *
     * @return int cuántas personas recibieron el aviso
     */
    public function avisarHueco(Asset $equipo, CarbonInterface $desde, CarbonInterface $hasta): int
    {
        $tz = config('fabos.lab.timezone');
        $avisados = 0;

        foreach ($this->esperando($equipo) as $entrada) {
            if (! $entrada->leSirve($desde, $hasta)) {
                continue;
            }

            // Al que ya se le avisó de este mismo equipo hace poco no se le
            // vuelve a escribir por cada movimiento de agenda.
            if ($entrada->notified_at && $entrada->notified_at->greaterThan(now()->subHours(12))) {
                continue;
            }

            $registro = $this->avisos->enviar('reserva.se_libero', $entrada->user, [
                'equipo' => $equipo->name,
                'fecha'  => $desde->copy()->timezone($tz)->format('d/m/Y'),
                'inicio' => $desde->copy()->timezone($tz)->format('H:i'),
                'fin'    => $hasta->copy()->timezone($tz)->format('H:i'),
                'enlace' => route('reservas.show', $equipo),
            ], $entrada);

            if ($registro?->status === 'enviado') {
                $entrada->update(['status' => 'avisado', 'notified_at' => now()]);
                $avisados++;
            }
        }

        return $avisados;
    }

    /** Se llama al soltar una reserva: cancelada, no presentada o reprogramada. */
    public function alLiberarse(Reservation $reserva): int
    {
        if ($reserva->reservable_type !== Asset::class) {
            return 0;
        }

        $equipo = Asset::find($reserva->reservable_id);

        if (! $equipo || $reserva->ends_at->isPast()) {
            return 0;   // un hueco que ya pasó no le sirve a nadie
        }

        return $this->avisarHueco($equipo, $reserva->starts_at, $reserva->ends_at);
    }

    /** Marca atendido a quien ya consiguió su reserva. */
    public function marcarAtendido(User $persona, Asset $equipo): void
    {
        WaitlistEntry::where('asset_id', $equipo->id)
            ->where('user_id', $persona->id)
            ->whereIn('status', ['esperando', 'avisado'])
            ->update(['status' => 'atendido']);
    }

    /** @return Collection<int,WaitlistEntry> */
    public function esperando(Asset $equipo): Collection
    {
        return WaitlistEntry::query()
            ->where('asset_id', $equipo->id)
            ->whereIn('status', ['esperando', 'avisado'])
            ->where('wants_until', '>', now())
            ->with('user')
            ->orderBy('created_at')
            ->get();
    }

    /** Cierra las esperas cuya ventana ya pasó: nadie quiere ese aviso. */
    public function vencerAntiguas(): int
    {
        return WaitlistEntry::whereIn('status', ['esperando', 'avisado'])
            ->where('wants_until', '<', now())
            ->update(['status' => 'vencido']);
    }
}
