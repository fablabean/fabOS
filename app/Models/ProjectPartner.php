<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una parte de una alianza (§11): quién es, qué pone y en qué va.
 *
 * El laboratorio también es una fila: pone máquinas y horas como cualquier
 * otro, y verlo en la misma tabla que los demás es lo que hace honesta la
 * cuenta de quién puso qué.
 */
class ProjectPartner extends Model
{
    protected $fillable = [
        'project_id', 'user_id', 'role', 'name', 'organization', 'email', 'phone', 'document',
        'contribution_kind', 'contribution_value', 'contribution_note', 'share_percent',
        'status', 'source', 'consent_at', 'joined_on', 'confirmed_by', 'confirmed_at', 'notes',
    ];

    protected $attributes = ['status' => 'propuesto', 'role' => 'aliado', 'contribution_kind' => 'otro'];

    public const ROLES = [
        'laboratorio' => 'El laboratorio',
        'iniciador'   => 'Quien trajo la idea',
        'aliado'      => 'Aliado',
        'inversor'    => 'Inversor',
    ];

    public const APORTES = [
        'dinero'       => 'Dinero',
        'horas'        => 'Horas de trabajo',
        'equipos'      => 'Equipos o máquinas',
        'material'     => 'Material',
        'conocimiento' => 'Conocimiento o red',
        'otro'         => 'Otro',
    ];

    public const ESTADOS = [
        'propuesto'  => 'Propuesto',
        'confirmado' => 'Confirmado',
        'retirado'   => 'Retirado',
    ];

    protected function casts(): array
    {
        return [
            'consent_at'   => UtcDateTime::class,
            'confirmed_at' => UtcDateTime::class,
            'joined_on'    => 'date',
            'share_percent' => 'decimal:2',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function confirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'confirmed_by');
    }

    // ------------------------------------------------------------- lecturas

    public function esElLaboratorio(): bool
    {
        return $this->role === 'laboratorio';
    }

    public function estaConfirmado(): bool
    {
        return $this->status === 'confirmado';
    }

    public function papel(): string
    {
        return self::ROLES[$this->role] ?? $this->role;
    }

    public function estadoLegible(): string
    {
        return self::ESTADOS[$this->status] ?? $this->status;
    }

    /** «Acme S.A.S. (Ana Ruiz)» o solo el nombre. */
    public function quien(): string
    {
        return $this->organization && $this->organization !== $this->name
            ? $this->organization . ' (' . $this->name . ')'
            : $this->name;
    }

    /** «Horas de trabajo · $12.000.000 · 200 horas de diseño». */
    public function aporteLegible(): string
    {
        $partes = [self::APORTES[$this->contribution_kind] ?? $this->contribution_kind];

        if ($this->contribution_value > 0) {
            $partes[] = config('fabos.money.symbol') . number_format($this->contribution_value, 0, ',', '.');
        }

        if (filled($this->contribution_note)) {
            $partes[] = $this->contribution_note;
        }

        return implode(' · ', $partes);
    }

    public function scopeConfirmados(Builder $query): Builder
    {
        return $query->where('status', 'confirmado');
    }

    public function scopeVivos(Builder $query): Builder
    {
        return $query->whereIn('status', ['propuesto', 'confirmado']);
    }
}
