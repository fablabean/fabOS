<?php

namespace App\Services\Training;

use App\Models\CourseEdition;
use App\Models\Enrollment;

/**
 * La lista de una edición en planilla: quién, qué respondió y si vino (§9).
 *
 * Punto y coma y UTF-8 con BOM, como la abre Excel en Colombia. Con la lista
 * de espera incluida: quien se quedó sin cupo es la demanda para otro grupo.
 */
class ListaDeInscritos
{
    public function __construct(private AsistenciaDeActividad $asistencia) {}

    public function csv(CourseEdition $edicion): string
    {
        $edicion->loadMissing('course.registrationQuestions');

        $inscripciones = $edicion->enrollments()->with(['user', 'attendances'])->orderBy('id')->get();
        $preguntas = $edicion->course->registrationQuestions;
        $sesiones = $edicion->sessions()->count();
        $tz = config('fabos.lab.timezone');

        $salida = fopen('php://temp', 'r+');
        fwrite($salida, "\xEF\xBB\xBF");

        fputcsv($salida, array_merge(
            ['Nombre', 'Correo', 'Documento', 'Tipo de participante', 'Programa o área', 'Estado', 'Se inscribió', 'Asistencia'],
            $preguntas->pluck('label')->all(),
        ), ';', '"', '\\');

        /** @var Enrollment $i */
        foreach ($inscripciones as $i) {
            $respuestas = (array) $i->answers;

            fputcsv($salida, array_merge([
                $i->user?->name,
                $i->user?->email,
                $i->user?->document_number,
                $i->tipoDeParticipante(),
                $i->program,
                Enrollment::ESTADOS[$i->status] ?? $i->status,
                $i->enrolled_at?->timezone($tz)->format('d/m/Y H:i'),
                $this->asistencia->resumen($i, $sesiones)['estado'],
            ], $preguntas->map(function ($p) use ($respuestas) {
                $valor = $respuestas[(string) $p->id]['valor'] ?? null;

                return is_array($valor) ? implode(', ', $valor) : $valor;
            })->all()), ';', '"', '\\');
        }

        rewind($salida);
        $csv = stream_get_contents($salida);
        fclose($salida);

        return $csv;
    }
}
