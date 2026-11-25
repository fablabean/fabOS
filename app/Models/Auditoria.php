<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una auditoría de calidad sobre el laboratorio: interna o externa (ISO 9001).
 *
 * Cada una con su fecha y hora, quién auditó, qué resultó y, cuando hay
 * hallazgos, la mejora que se compromete y en qué va. Los hallazgos van dentro
 * de la auditoría y no en una tabla aparte: se leen siempre juntos, y son
 * pocos —un puñado por visita—.
 */
class Auditoria extends Model
{
    protected $table = 'auditorias';

    protected $fillable = [
        'tipo', 'titulo', 'realizada_at', 'auditor', 'proceso', 'alcance',
        'resultado', 'resumen', 'hallazgos', 'informe_path', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'realizada_at' => UtcDateTime::class,
            'hallazgos'    => 'array',
        ];
    }

    public const TIPOS = [
        'interna' => 'Interna',
        'externa' => 'Externa',
    ];

    public const RESULTADOS = [
        'pendiente'         => 'Por realizar',
        'conforme'          => 'Conforme',
        'con_observaciones' => 'Conforme con observaciones',
        'no_conforme'       => 'Con no conformidades',
    ];

    /** Lo que puede salir de una auditoría, del más leve al más grave. */
    public const TIPOS_DE_HALLAZGO = [
        'fortaleza'     => 'Fortaleza',
        'oportunidad'   => 'Oportunidad de mejora',
        'observacion'   => 'Observación',
        'no_conformidad' => 'No conformidad',
    ];

    public const ESTADOS_DE_MEJORA = [
        'abierta'    => 'Abierta',
        'en_curso'   => 'En curso',
        'cerrada'    => 'Cerrada',
        'no_aplica'  => 'No aplica',
    ];

    /** Los procedimientos del proceso INV-200 que tocan al FabLab. */
    public const PROCEDIMIENTOS = [
        'INV-113' => 'INV-113 · Elaboración de escenarios de realidad virtual',
        'INV-114' => 'INV-114 · Servicios de impresión a terceros',
        'INV-115' => 'INV-115 · Préstamo de espacios físicos, equipos y/o herramientas',
        'INV-116' => 'INV-116 · Préstamo de servicios de prototipado rápido',
        'INV-118' => 'INV-118 · Mantenimiento de equipos de laboratorios',
        'INV-200' => 'INV-200 · Caracterización del proceso',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Las mejoras que siguen abiertas. */
    public function mejorasAbiertas(): int
    {
        return collect($this->hallazgos ?? [])
            ->filter(fn ($h) => filled($h['accion'] ?? null) && in_array($h['estado'] ?? 'abierta', ['abierta', 'en_curso'], true))
            ->count();
    }
}
