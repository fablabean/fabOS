<?php

namespace App\Console\Commands;

use App\Models\Project;
use App\Services\Projects\ProjectService;
use Illuminate\Console\Command;

/**
 * Las pausas que esperan a quien pidió el proyecto (§11).
 *
 * Unos días antes del plazo se le recuerda; cumplido el plazo sin respuesta,
 * el proyecto se cierra y se archiva con un mensaje estándar. Si responde
 * antes, el proyecto vuelve a estar activo y deja de pasar por aquí.
 *
 * Solo las marcadas como «espera respuesta»: una pausa por algo del
 * laboratorio —una pieza que no llega, una firma— no tiene plazo.
 */
class PausasSinRespuesta extends Command
{
    protected $signature = 'fabos:pausas-sin-respuesta';

    protected $description = 'Recuerda y cierra los proyectos en pausa que esperan una respuesta que no llega';

    public function handle(ProjectService $proyectos): int
    {
        $avisoPrevio = max(0, (int) config('fabos.proyectos.dias_de_aviso_previo'));
        $recordados = 0;
        $cerrados = 0;

        Project::query()
            ->where('status', 'pausado')
            ->where('awaits_client', true)
            ->whereNotNull('paused_at')
            ->get()
            ->each(function (Project $proyecto) use ($proyectos, $avisoPrevio, &$recordados, &$cerrados) {
                $vence = $proyecto->venceLaPausaEl();

                if (! $vence) {
                    return;
                }

                if (now()->greaterThanOrEqualTo($vence)) {
                    $proyectos->cerrarPorFaltaDeRespuesta($proyecto);
                    $cerrados++;

                    return;
                }

                if ($avisoPrevio > 0 && $proyecto->pause_reminded_at === null
                    && now()->greaterThanOrEqualTo($vence->copy()->subDays($avisoPrevio))) {
                    $proyectos->recordarPausa($proyecto);
                    $recordados++;
                }
            });

        $this->info("Recordados: {$recordados}. Cerrados por falta de respuesta: {$cerrados}.");

        return self::SUCCESS;
    }
}
