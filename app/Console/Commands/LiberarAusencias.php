<?php

namespace App\Console\Commands;

use App\Services\Booking\AttendanceService;
use Illuminate\Console\Command;

/**
 * Red de seguridad: libera las reservas a las que nadie llegó.
 *
 * Lo normal es que la ausencia se marque sola cuando alguien intenta el
 * check-in tarde. Este barrido cubre el caso en que nadie vuelve a tocar el
 * equipo y la reserva quedaría bloqueándolo indefinidamente.
 */
class LiberarAusencias extends Command
{
    protected $signature = 'fabos:liberar-ausencias';

    protected $description = 'Marca como no presentadas las reservas sin llegada';

    public function handle(AttendanceService $asistencia): int
    {
        $n = $asistencia->liberarAusencias();

        $this->info($n === 0 ? 'Nada que liberar.' : "Liberadas {$n} reservas sin llegada.");

        return self::SUCCESS;
    }
}
