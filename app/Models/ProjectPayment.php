<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un pago pedido a quien encargó el proyecto (§11). */
class ProjectPayment extends Model
{
    protected $fillable = [
        'project_id', 'amount', 'concept', 'status',
        'requested_by', 'requested_at',
        'receipt_path', 'payer_name', 'payer_document', 'submitted_at',
        'validated_by', 'validated_at', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'amount'       => 'integer',
            'requested_at' => UtcDateTime::class,
            'submitted_at' => UtcDateTime::class,
            'validated_at' => UtcDateTime::class,
        ];
    }

    public const SOLICITADO = 'solicitado';
    public const ENVIADO    = 'enviado';
    public const VALIDADO   = 'validado';
    public const RECHAZADO  = 'rechazado';

    public const ESTADOS = [
        self::SOLICITADO => 'Pedido, sin comprobante',
        self::ENVIADO    => 'Comprobante enviado',
        self::VALIDADO   => 'Validado',
        self::RECHAZADO  => 'Comprobante rechazado',
    ];

    /** Los que siguen esperando algo: del cliente, o del laboratorio. */
    public const ABIERTOS = [self::SOLICITADO, self::ENVIADO, self::RECHAZADO];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function estaAbierto(): bool
    {
        return in_array($this->status, self::ABIERTOS, true);
    }

    /** Si al cliente le toca mandar (o volver a mandar) el comprobante. */
    public function esperaComprobante(): bool
    {
        return in_array($this->status, [self::SOLICITADO, self::RECHAZADO], true);
    }

    public function valorFormateado(): string
    {
        return config('fabos.money.symbol') . number_format((float) $this->amount, 0, ',', '.');
    }

    /** Lo que se cobra, en una linea: «Anticipo del 50 % · $1.250.000». */
    public function titulo(): string
    {
        return ($this->concept ? $this->concept . ' · ' : '') . $this->valorFormateado();
    }
}
