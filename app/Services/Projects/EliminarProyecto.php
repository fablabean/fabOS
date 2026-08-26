<?php

namespace App\Services\Projects;

use App\Models\Evidencia;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\ProjectProposal;
use App\Models\ProjectTask;
use App\Models\Reservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Borrar un proyecto descartado, de verdad (§11).
 *
 * Un proyecto descartado se guarda: el histórico enseña, y una idea que no
 * cuajó explica por qué no se aceptó la siguiente igual. Pero después de unas
 * cuantas pruebas y un par de encargos que nunca fueron, la lista se llena de
 * ruido que nadie va a volver a mirar, y una lista con ruido se deja de mirar
 * entera.
 *
 * Por eso esto existe, y por eso pide condiciones:
 *
 *  · **Solo lo descartado o perdido.** Un proyecto vivo no se borra: se
 *    descarta primero, que obliga a escribir por qué.
 *  · **Lo que ocurrió de verdad no se borra con él.** El tiempo de máquina que
 *    se ocupó, el material que salió del inventario y el dinero que se movió
 *    pasaron, y siguen pasando aunque el proyecto desaparezca: esas reservas y
 *    esas compras se **desligan**, no se destruyen. Borrarlas dejaría el
 *    inventario y el libro contable diciendo cosas que no cuadran.
 *  · **El material grabado tampoco.** Es de quien lo grabó, con su
 *    autorización: pierde el vínculo con el proyecto y se queda en el banco.
 *
 * Lo que sí se va: entregables, tareas, documentos, propuestas, comentarios,
 * costos anotados a mano, horas, el equipo, y **los archivos** de todo eso.
 */
class EliminarProyecto
{
    /**
     * @return array<string,int> qué se borró, para poder decirlo
     *
     * @throws ProjectException si el proyecto sigue vivo
     */
    public function __invoke(Project $proyecto): array
    {
        if (! in_array($proyecto->status, ['descartado', 'perdido'], true)) {
            throw new ProjectException(
                'Solo se borran los proyectos descartados o perdidos. Descártalo primero: '
                . 'así queda escrito por qué no siguió.',
            );
        }

        $resumen = [
            'entregables'  => $proyecto->deliverables()->count(),
            'tareas'       => $proyecto->tasks()->count(),
            'documentos'   => $proyecto->documents()->count(),
            'propuestas'   => $proyecto->proposals()->count(),
            'comentarios'  => $proyecto->comments()->count(),
            'costos'       => $proyecto->costs()->count(),
            'horas'        => $proyecto->timeLogs()->count(),
            'archivos'     => 0,
            'desligadas'   => 0,
            'material'     => $proyecto->contenido()->count(),
        ];

        return DB::transaction(function () use ($proyecto, $resumen) {
            $resumen['archivos'] = $this->borrarLosArchivos($proyecto);

            // Lo que ocurrió de verdad se desliga y se queda. La restricción de
            // la base ya lo haría sola, pero contarlo aquí permite decirlo.
            $resumen['desligadas'] = Reservation::where('project_id', $proyecto->id)->count();

            $codigo = $proyecto->code;
            $nombre = $proyecto->name;

            $proyecto->delete();

            // Queda en la bitácora: un borrado sin rastro es indistinguible de
            // un dato que nunca existió, y alguien va a preguntar.
            Log::info('fabOS: proyecto borrado', array_merge($resumen, [
                'codigo' => $codigo,
                'nombre' => $nombre,
                'quien'  => auth()->user()?->email ?? 'consola',
            ]));

            return $resumen;
        });
    }

    /**
     * Los archivos del disco, que ninguna restricción de la base se lleva.
     *
     * La evidencia es polimórfica: cuelga del proyecto, de sus tareas, de sus
     * entregables y de cada versión de la propuesta. Sin recorrerla a mano
     * quedarían filas huérfanas apuntando a archivos que nadie va a borrar
     * nunca, y el disco se llenaría de material de proyectos que ya no existen.
     */
    private function borrarLosArchivos(Project $proyecto): int
    {
        $disco = Storage::disk('local');
        $borrados = 0;

        $duenios = [
            [Project::class, [$proyecto->id]],
            [ProjectTask::class, $proyecto->tasks()->pluck('id')->all()],
            [ProjectDeliverable::class, $proyecto->deliverables()->pluck('id')->all()],
            [ProjectProposal::class, $proyecto->proposals()->pluck('id')->all()],
        ];

        foreach ($duenios as [$clase, $ids]) {
            if ($ids === []) {
                continue;
            }

            $evidencias = Evidencia::where('evidenciable_type', $clase)
                ->whereIn('evidenciable_id', $ids)
                ->get();

            foreach ($evidencias as $evidencia) {
                if (filled($evidencia->file_path) && $disco->exists($evidencia->file_path)) {
                    $disco->delete($evidencia->file_path);
                    $borrados++;
                }

                $evidencia->delete();
            }
        }

        // Los documentos del proyecto: contratos, briefs, informes.
        foreach ($proyecto->documents as $documento) {
            if (filled($documento->file_path) && $disco->exists($documento->file_path)) {
                $disco->delete($documento->file_path);
                $borrados++;
            }
        }

        if (filled($proyecto->reference_image_path) && $disco->exists($proyecto->reference_image_path)) {
            $disco->delete($proyecto->reference_image_path);
            $borrados++;
        }

        return $borrados;
    }
}
