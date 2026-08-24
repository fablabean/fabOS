<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Activo fijo o herramienta: unidad individualizada con historial (§7). */
class Asset extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'area_id', 'risk_family_id', 'location_id', 'name', 'kind',
        'brand', 'model', 'serial', 'asset_tag', 'qr_token', 'status',
        'is_reservable', 'booking_mode', 'allows_off_hours_requests',
        'unattended_use', 'pool_key',
        'min_minutes', 'autonomous_minutes', 'max_minutes',
        'purchase_cost', 'purchased_at', 'warranty_until',
        'photo_path', 'video_url', 'public_description', 'is_public',
    ];

    /**
     * El modo por defecto también en memoria, no solo en la base de datos.
     *
     * Sin esto, un activo recién creado tiene `booking_mode` nulo hasta que se
     * relee, y el motor de reservas lo interpretaría como «no es directa».
     */
    protected $attributes = [
        'booking_mode' => 'directa',
        'allows_off_hours_requests' => false,
    ];

    protected function casts(): array
    {
        return [
            'is_reservable'  => 'boolean',
            'is_public'      => 'boolean',
            'unattended_use' => 'boolean',
            'allows_off_hours_requests' => 'boolean',
            'purchased_at'   => 'date',
            'warranty_until' => 'date',
            'purchase_cost'  => 'decimal:2',
        ];
    }

    public const TIPOS = ['fijo' => 'Activo fijo', 'herramienta' => 'Herramienta'];

    /** Un estado distinto de operativo bloquea la agenda (§8). */
    /** Cómo se toma este recurso (§10). El modo puede exigir más que la
     *  autonomía de la persona, nunca menos. */
    public const MODOS_RESERVA = [
        'directa'        => 'Directa: quien esté habilitado reserva',
        'con_aprobacion' => 'Con aprobación: siempre pasa por la coordinación',
        'solo_solicitud' => 'Solo solicitud: no se reserva, se pide',
    ];

    /**
     * Se puede pedir fuera de la franja atendida.
     *
     * Lo que no se reserva sino que se pide admite pedidos fuera de horario por
     * definición: es de lo que se trata. En el resto es una decisión explícita.
     */
    public function admitePedidosFueraDeJornada(): bool
    {
        return $this->allows_off_hours_requests || $this->booking_mode === 'solo_solicitud';
    }

    public const ESTADOS = [
        'operativo'         => 'Operativo',
        'mantenimiento'     => 'En mantenimiento',
        'fuera_de_servicio' => 'Fuera de servicio',
        'baja'              => 'Dado de baja',
    ];

    /** URL de la foto, o null si no tiene. */
    public function photoUrl(): ?string
    {
        return $this->photo_path ? asset('storage/' . $this->photo_path) : null;
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function riskFamily(): BelongsTo
    {
        return $this->belongsTo(RiskFamily::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** Sin compresor no hay CNC; sin aspiradora no hay láser (§7). */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'asset_dependencies', 'asset_id', 'depends_on_asset_id')
            ->withPivot('note')
            ->withTimestamps();
    }

    public function reservations(): MorphMany
    {
        return $this->morphMany(Reservation::class, 'reservable');
    }

    /** Reservable de verdad: además de estar marcado, debe estar operativo. */
    public function isBookable(): bool
    {
        return $this->is_reservable
            && $this->status === 'operativo'
            && ! $this->dependencies()->where('status', '!=', 'operativo')->exists();
    }

    /** Personas declaradas para asesorar sobre este equipo (§10). */
    public function advisors(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'asset_advisors')
            ->withPivot('es_responsable')
            ->withTimestamps();
    }
}
