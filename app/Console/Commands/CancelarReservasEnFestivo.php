<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Services\Booking\Festivos;
use Illuminate\Console\Command;

/**
 * Las reservas que se hicieron en un festivo antes de que el sistema los
 * conociera: se cancelan y a cada quien se le escribe para que reserve otro
 * día (App\Services\Booking\Festivos).
 *
 * No corre solo: cancelar le cambia el día a alguien, y se decide mirando la
 * lista. Con --ver solo la muestra.
 */
class CancelarReservasEnFestivo extends Command
{
    protected $signature = 'fabos:cancelar-reservas-en-festivo {--ver : Solo listar, sin cancelar ni escribir}';

    protected $description = 'Cancela las reservas que caen en festivo y avisa a quien las hizo';

    public function handle(Festivos $festivos): int
    {
        $tz = config('fabos.lab.timezone');
        $afectadas = $festivos->reservasAfectadas();

        if ($afectadas->isEmpty()) {
            $this->info('No hay reservas en pie en los festivos que vienen.');

            return self::SUCCESS;
        }

        $this->table(['Reserva', 'Cuándo', 'Qué', 'Quién', 'Correo', 'Estado'], $afectadas->map(fn (Reservation $r) => [
            $r->id,
            $r->starts_at->copy()->timezone($tz)->format('d/m H:i') . '–' . $r->ends_at->copy()->timezone($tz)->format('H:i'),
            Festivos::queEs($r),
            $r->user?->name ?? '—',
            $r->user?->email ?? '—',
            $r->status,
        ])->all());

        if ($this->option('ver')) {
            $this->info($afectadas->count() . ' por cancelar. Sin --ver, se cancelan y se avisa a cada persona.');

            return self::SUCCESS;
        }

        $fallas = 0;

        foreach ($afectadas as $r) {
            try {
                $festivos->cancelarYAvisar($r);
            } catch (\Throwable $e) {
                $fallas++;
                $this->error("Reserva {$r->id}: {$e->getMessage()}");
            }
        }

        $this->info(($afectadas->count() - $fallas) . ' canceladas y avisadas.' . ($fallas ? " {$fallas} con error." : ''));

        return $fallas ? self::FAILURE : self::SUCCESS;
    }
}
