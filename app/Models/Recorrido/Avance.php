<?php

namespace App\Models\Recorrido;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una etapa de un equipo, paso por paso: cuándo vio la pista, cuándo dio con
 * el QR, cuándo resolvió y cuándo el líder marcó la secuencia.
 */
class Avance extends Model
{
    protected $table = 'recorrido_avances';

    protected $fillable = [
        'equipo_id', 'etapa', 'estacion_id', 'lider_id',
        'pista_at', 'qr_at', 'resuelta_at', 'secuencia_at', 'fallos_prueba', 'fallos_secuencia',
    ];

    protected function casts(): array
    {
        return [
            'pista_at'     => 'datetime',
            'qr_at'        => 'datetime',
            'resuelta_at'  => 'datetime',
            'secuencia_at' => 'datetime',
        ];
    }

    public function equipo(): BelongsTo
    {
        return $this->belongsTo(Equipo::class);
    }

    public function estacion(): BelongsTo
    {
        return $this->belongsTo(Estacion::class);
    }

    public function lider(): BelongsTo
    {
        return $this->belongsTo(Integrante::class, 'lider_id');
    }

    /** Lo que tardó la etapa, si ya terminó. */
    public function segundos(): ?int
    {
        return $this->pista_at && $this->secuencia_at
            ? (int) $this->pista_at->diffInSeconds($this->secuencia_at)
            : null;
    }
}
