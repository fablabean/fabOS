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
    ];

    protected function casts(): array
    {
        return [
            'options'           => 'array',
            'participant_types' => 'array',
            'required'          => 'boolean',
        ];
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
