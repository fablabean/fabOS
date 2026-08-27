<?php

namespace App\Services\Projects;

use App\Models\Candidate;
use App\Models\CandidateBatch;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Meter una lista entera, evaluarla, y convertir lo aceptado (§11).
 *
 * Sin esto, la lista vive en un Excel que alguien reenvía, se evalúa en una
 * reunión, y lo acordado se pierde entre la reunión y el momento de arrancar.
 */
class LoteDeCandidatos
{
    /**
     * Mete la lista tal como llega: pegada desde una hoja de cálculo.
     *
     * Se acepta tabulador, punto y coma o barra vertical porque eso es lo que
     * sale al copiar de Excel, de Google Sheets o de un correo, y pedirle a
     * quien pega que primero convierta el formato es pedirle que no lo haga.
     *
     * Las columnas, en orden: **nombre, organización, contacto, correo,
     * descripción**. Solo la primera es obligatoria; una lista de nombres a
     * secas ya sirve para empezar a evaluar.
     *
     * @return int cuántos entraron
     */
    public function pegar(CandidateBatch $lote, string $texto): int
    {
        $posicion = (int) $lote->candidates()->max('position');
        $cuantos = 0;

        foreach (preg_split('/\r\n|\r|\n/', $texto) as $linea) {
            $linea = trim($linea);

            if ($linea === '') {
                continue;
            }

            $campos = array_map('trim', preg_split('/\t|;|\|/', $linea));

            if (($campos[0] ?? '') === '') {
                continue;
            }

            // Una cabecera pegada por descuido no es un candidato.
            if ($cuantos === 0 && $posicion === 0 && $this->pareceCabecera($campos[0])) {
                continue;
            }

            $lote->candidates()->create([
                'name'          => mb_substr($campos[0], 0, 255),
                'organization'  => $this->campo($campos, 1),
                'contact_name'  => $this->campo($campos, 2),
                'contact_email' => filter_var($this->campo($campos, 3) ?? '', FILTER_VALIDATE_EMAIL)
                    ? $campos[3]
                    : null,
                'description'   => $this->campo($campos, 4),
                'position'      => ++$posicion,
            ]);

            $cuantos++;
        }

        return $cuantos;
    }

    /**
     * Evalúa un candidato.
     *
     * Queda quién lo hizo y cuándo. Una decisión sin autor se discute otra vez
     * dentro de un mes, y nadie recuerda por qué se dijo que no.
     */
    public function evaluar(
        Candidate $candidato,
        string $decision,
        ?int $nota = null,
        ?string $comentario = null,
        ?User $quien = null,
    ): Candidate {
        if (! array_key_exists($decision, Candidate::ESTADOS)) {
            throw new ProjectException('Esa decisión no existe.');
        }

        $candidato->update([
            'status'          => $decision,
            'score'           => $nota,
            'evaluation_note' => $comentario,
            'evaluated_at'    => $decision === 'pendiente' ? null : now(),
            'evaluated_by'    => $decision === 'pendiente' ? null : $quien?->id,
        ]);

        return $candidato->refresh();
    }

    /**
     * Convierte un candidato aceptado en proyecto.
     *
     * Solo lo aceptado, y solo una vez: convertir dos veces daría dos proyectos
     * para el mismo encargo, y el segundo aparecería sin explicación en el
     * listado de quien coordina.
     *
     * @throws ProjectException
     */
    public function convertir(Candidate $candidato, ?User $quien = null): Project
    {
        if ($candidato->status !== 'aceptado') {
            throw new ProjectException('Solo se convierten los candidatos aceptados. Evalúalo primero.');
        }

        if ($candidato->yaEsProyecto()) {
            throw new ProjectException('Este candidato ya es ' . $candidato->project->code . '.');
        }

        return DB::transaction(function () use ($candidato, $quien) {
            $proyecto = app(ProjectService::class)->registrarIdea([
                'name'          => $candidato->name,
                'source'        => 'interno',
                'organization'  => $candidato->organization,
                'contact_name'  => $candidato->contact_name,
                'contact_email' => $candidato->contact_email,
                'contact_phone' => $candidato->contact_phone,
                // Lo que se escribió al evaluarlo es lo primero que hay que
                // recordar al arrancar: por qué se aceptó.
                'summary'       => trim(implode("\n\n", array_filter([
                    $candidato->description,
                    $candidato->evaluation_note
                        ? 'Al evaluarlo: ' . $candidato->evaluation_note
                        : null,
                ]))) ?: null,
                'notes'         => 'Viene del lote «' . $candidato->batch->name . '».',
            ], $quien);

            $candidato->update(['project_id' => $proyecto->id]);

            return $proyecto;
        });
    }

    /**
     * Convierte todo lo aceptado que quede pendiente.
     *
     * @return int cuántos proyectos se crearon
     */
    public function convertirLoAceptado(CandidateBatch $lote, ?User $quien = null): int
    {
        $pendientes = $lote->candidates()
            ->where('status', 'aceptado')
            ->whereNull('project_id')
            ->get();

        foreach ($pendientes as $candidato) {
            $this->convertir($candidato, $quien);
        }

        return $pendientes->count();
    }

    private function campo(array $campos, int $indice): ?string
    {
        $valor = trim($campos[$indice] ?? '');

        return $valor === '' ? null : mb_substr($valor, 0, 255);
    }

    /** «Nombre», «Proyecto», «Empresa»: lo que encabeza una hoja de cálculo. */
    private function pareceCabecera(string $primero): bool
    {
        $limpio = mb_strtolower(trim($primero));

        return in_array($limpio, [
            'nombre', 'proyecto', 'nombre del proyecto', 'empresa',
            'organización', 'organizacion', 'spinoff', 'spin-off', 'candidato',
        ], true);
    }
}
