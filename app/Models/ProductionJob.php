<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un encargo de la tienda: alguien pide un trabajo hecho por el equipo (§14). */
class ProductionJob extends Model
{
    protected $fillable = [
        'code', 'user_id', 'assigned_to', 'asset_id', 'area_id', 'project_id',
        'title', 'description', 'file_url', 'quantity',
        'status', 'priority', 'quoted_minutes', 'quoted_total_minor', 'quote_notes',
        'quoted_at', 'accepted_at', 'due_on',
        'started_at', 'finished_at', 'delivered_at', 'sale_id',
        'notes', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'quantity'     => 'decimal:3',
            'due_on'       => 'date',
            'quoted_at'    => UtcDateTime::class,
            'accepted_at'  => UtcDateTime::class,
            'started_at'   => UtcDateTime::class,
            'finished_at'  => UtcDateTime::class,
            'delivered_at' => UtcDateTime::class,
        ];
    }

    public const ESTADOS = [
        'solicitado'    => 'Solicitado',
        'cotizado'      => 'Cotizado',
        'aceptado'      => 'Aceptado',
        'en_cola'       => 'En cola',
        'en_produccion' => 'En producción',
        'listo'         => 'Listo para recoger',
        'entregado'     => 'Entregado',
        'rechazado'     => 'Rechazado',
        'cancelado'     => 'Cancelado',
    ];

    public const PRIORIDADES = ['baja' => 'Baja', 'normal' => 'Normal', 'alta' => 'Alta'];

    /** Estados en los que el encargo sigue ocupando a alguien. */
    public const ABIERTOS = ['solicitado', 'cotizado', 'aceptado', 'en_cola', 'en_produccion', 'listo'];

    /** Estados en los que ya está en la cola de trabajo del laboratorio. */
    public const EN_COLA = ['aceptado', 'en_cola', 'en_produccion'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function total(): float
    {
        return $this->quoted_total_minor / config('fabos.currency.minor_units');
    }

    public function estaAbierto(): bool
    {
        return in_array($this->status, self::ABIERTOS, true);
    }

    /** Vencido: pasó la fecha prometida y todavía no se entregó. */
    public function estaVencido(): bool
    {
        return $this->due_on !== null
            && $this->estaAbierto()
            && $this->due_on->endOfDay()->isPast();
    }
}
