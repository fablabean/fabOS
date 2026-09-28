<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una pregunta del formulario de inscripción, además de las fijas (§9).
 *
 * Las fijas —nombre, correo, programa, tipo de participante, condiciones— no
 * viven aquí: son las mismas para todas las actividades y no se pueden quitar.
 * Estas son las que cada curso, taller o evento añade: un diseño previo, la
 * talla de la camiseta, si ya usó una cortadora láser.
 */
class RegistrationQuestion extends Model
{
    protected $fillable = [
        'course_id', 'position', 'type', 'label', 'help', 'options', 'required', 'participant_types',
        'capacity_per_option',
    ];

    protected function casts(): array
    {
        return [
            'options'             => 'array',
            'participant_types'   => 'array',
            'required'            => 'boolean',
            'capacity_per_option' => 'boolean',
        ];
    }

    /** Si reparte la gente por opción, con un cupo para cada una. */
    public function reparteCupo(): bool
    {
        return $this->type === 'seleccion' && $this->capacity_per_option;
    }

    /** @return array<string,int> el cupo de cada opción; las que no tienen número no se limitan */
    public function cupos(): array
    {
        if (! $this->reparteCupo()) {
            return [];
        }

        return collect((array) $this->options)
            ->filter(fn ($o) => is_array($o) && filled($o['texto'] ?? null) && is_numeric($o['cupo'] ?? null))
            ->mapWithKeys(fn ($o) => [trim((string) $o['texto']) => max(0, (int) $o['cupo'])])
            ->all();
    }

    /** Cuántos ocupan silla en esa opción, en esa edición. */
    public function ocupados(CourseEdition $edicion, string $opcion): int
    {
        return $edicion->enrollments()
            ->whereNotIn('status', Enrollment::SIN_CUPO)
            ->whereRaw("answers -> ? ->> 'valor' = ?", [(string) $this->id, $opcion])
            ->count();
    }

    /** @return array<string, array{cupo:int, ocupados:int, libres:int}> */
    public function disponibilidad(CourseEdition $edicion): array
    {
        return collect($this->cupos())
            ->map(function (int $cupo, string $opcion) use ($edicion) {
                $ocupados = $this->ocupados($edicion, $opcion);

                return ['cupo' => $cupo, 'ocupados' => $ocupados, 'libres' => max(0, $cupo - $ocupados)];
            })
            ->all();
    }

    public const TIPOS = [
        'texto'      => 'Texto corto',
        'parrafo'    => 'Párrafo',
        'seleccion'  => 'Selección (una opción)',
        'multiple'   => 'Opción múltiple (varias)',
        'aceptacion' => 'Aceptación (casilla)',
        'archivo'    => 'Archivo o diseño',
        'numero'     => 'Número',
        'fecha'      => 'Fecha',
        'enlace'     => 'Enlace',
    ];

    /** Los tipos que eligen entre opciones. */
    public const CON_OPCIONES = ['seleccion', 'multiple'];

    /** Lo que se acepta en una pregunta de archivo: diseños, imágenes y documentos. */
    public const EXTENSIONES = [
        'pdf', 'jpg', 'jpeg', 'png', 'webp', 'svg', 'dxf', 'dwg', 'ai', 'eps',
        'stl', 'obj', '3mf', 'step', 'stp', 'f3d', 'zip', 'docx', 'pptx', 'xlsx',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** Si a este tipo de participante se le hace la pregunta. */
    public function aplicaA(?string $tipo): bool
    {
        $tipos = array_filter((array) $this->participant_types);

        return $tipos === [] || ($tipo !== null && in_array($tipo, $tipos, true));
    }

    /** @return list<string> */
    public function opciones(): array
    {
        return collect((array) $this->options)
            ->map(fn ($o) => is_array($o) ? trim((string) ($o['texto'] ?? '')) : trim((string) $o))
            ->filter()
            ->values()
            ->all();
    }
}
