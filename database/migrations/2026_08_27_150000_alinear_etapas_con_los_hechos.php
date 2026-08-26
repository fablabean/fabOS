<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pone al día el embudo de lo que ya existía (§11).
 *
 * A partir de ahora los hechos mueven la etapa solos, pero los proyectos
 * anteriores se quedaron donde los dejó la regla vieja: aceptados y diciendo
 * «idea». Un listado que miente sobre lo que ya pasó es peor que uno
 * incompleto, porque nadie duda de él.
 *
 * Solo avanza, y nunca por encima de lo que dicen los hechos: si algo ya estaba
 * más adelante, se queda donde está.
 */
return new class extends Migration
{
    public function up(): void
    {
        $orden = ['idea', 'propuesta', 'contrato', 'brief', 'ejecucion', 'cierre'];

        $proyectos = DB::table('projects')
            ->whereNotIn('status', ['descartado', 'perdido'])
            ->get();

        foreach ($proyectos as $p) {
            // El documento más avanzado que tenga manda sobre lo demás.
            $tipos = DB::table('project_documents')->where('project_id', $p->id)->pluck('kind')->all();

            $destino = 'idea';

            if ($p->proposal_sent_at) {
                $destino = 'propuesta';
            }

            if ($p->accepted_at) {
                $destino = 'contrato';
            }

            if (in_array('contrato', $tipos, true)) {
                $destino = 'brief';
            }

            if (in_array('brief', $tipos, true)) {
                $destino = 'ejecucion';
            }

            if (in_array('informe', $tipos, true)) {
                $destino = 'cierre';
            }

            if (array_search($destino, $orden, true) > array_search($p->stage, $orden, true)) {
                DB::table('projects')->where('id', $p->id)->update(['stage' => $destino]);
            }
        }
    }

    public function down(): void
    {
        // No se deshace: devolver un proyecto a una etapa anterior seria
        // inventarse un pasado que no ocurrio.
    }
};
