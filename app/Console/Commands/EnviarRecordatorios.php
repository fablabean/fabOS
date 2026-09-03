<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Reservation;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;

/**
 * Recordatorios de reservas próximas (§15).
 *
 * Corre cada hora y avisa de lo que empieza dentro de la ventana. El aviso se
 * manda **una sola vez por reserva**, y de eso se encarga la bitácora: sin ese
 * control, correr cada hora significaría un correo cada hora.
 *
 * No recuerda las reservas de dentro de un rato: si alguien reservó para dentro
 * de dos horas, ya lo tiene fresco y el recordatorio sería ruido.
 */
class EnviarRecordatorios extends Command
{
    protected $signature = 'fabos:recordatorios
                            {--horas=20 : Cuántas horas antes se recuerda}
                            {--simular : Muestra a quién se le avisaría sin enviar nada}';

    protected $description = 'Recuerda las reservas que empiezan pronto';

    public function handle(NotificationService $avisos): int
    {
        $horas = max(1, (int) $this->option('horas'));
        $desde = now()->addHours($horas - 1);
        $hasta = now()->addHours($horas);

        $reservas = Reservation::query()
            ->where('reservable_type', Asset::class)
            ->where('status', 'confirmada')
            ->whereNull('checked_in_at')
            // A una produccion no hay que recordarle que llegue: la corre el
            // propio laboratorio, sin llegada que registrar.
            ->where('is_production', false)
            ->whereBetween('starts_at', [$desde, $hasta])
            ->with('user')
            ->get();

        if ($reservas->isEmpty()) {
            $this->info('No hay reservas que recordar en esa ventana.');

            return self::SUCCESS;
        }

        $tz = config('fabos.lab.timezone');
        $enviados = 0;

        foreach ($reservas as $reserva) {
            if (! $reserva->user) {
                continue;
            }

            $equipo = Asset::find($reserva->reservable_id);

            if ($this->option('simular')) {
                $this->line(sprintf(
                    '  %-40s %s · %s',
                    $reserva->user->email,
                    $equipo?->name ?? '—',
                    $reserva->starts_at->timezone($tz)->format('d/m/Y H:i'),
                ));
                $enviados++;

                continue;
            }

            $registro = $avisos->enviarUnaVez('reserva.recordatorio', $reserva->user, $reserva, [
                'equipo' => $equipo?->name ?? 'el equipo',
                'fecha'  => $reserva->starts_at->timezone($tz)->format('d/m/Y'),
                'inicio' => $reserva->starts_at->timezone($tz)->format('H:i'),
                'fin'    => $reserva->ends_at->timezone($tz)->format('H:i'),
            ]);

            if ($registro?->status === 'enviado') {
                $enviados++;
            }
        }

        $this->info(sprintf(
            '%d de %d reservas %s.',
            $enviados,
            $reservas->count(),
            $this->option('simular') ? 'recibirían recordatorio' : 'recordadas',
        ));

        return self::SUCCESS;
    }
}
