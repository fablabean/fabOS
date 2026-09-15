<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Una convocatoria de práctica: el semestre al que alguien se postula (§5).
 *
 * Existe para que se evalúe **con la tanda entera delante**, que es como se
 * compara de verdad. Sin ella, las hojas de vida llegan sueltas durante tres
 * meses y la tercera se juzga con otro criterio que la primera.
 */
class InternshipCall extends Model
{
    protected $fillable = [
        'name', 'slug', 'period', 'description',
        'opens_on', 'closes_on', 'slots', 'status', 'is_public', 'created_by',
    ];

    protected $attributes = ['status' => 'abierta'];

    public const ESTADOS = [
        'abierta'  => 'Abierta',
        'evaluada' => 'Evaluada',
        'cerrada'  => 'Cerrada',
    ];

    protected function casts(): array
    {
        return [
            'opens_on'  => 'date',
            'closes_on' => 'date',
            'is_public' => 'boolean',
        ];
    }

    /**
     * La dirección pública sale del nombre, una sola vez.
     *
     * Con una parte aleatoria, como las categorías de insumos: dos
     * convocatorias que se llamen «Prácticas 2026-1» no pueden pelearse por la
     * misma dirección, y la que ya se anunció no cambia de sitio si alguien
     * corrige el nombre después.
     */
    protected static function booted(): void
    {
        static::creating(function (self $convocatoria) {
            if (blank($convocatoria->slug)) {
                $convocatoria->slug = Str::slug($convocatoria->name) . '-' . Str::lower(Str::random(4));
            }
        });
    }

    public function applications(): HasMany
    {
        return $this->hasMany(InternshipApplication::class, 'call_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ------------------------------------------------------------- lecturas

    public function estadoLegible(): string
    {
        return self::ESTADOS[$this->status] ?? $this->status;
    }

    /**
     * Si alguien puede postularse ahora mismo.
     *
     * Tres condiciones, y las tres tienen que decirse por separado en la
     * pantalla pública: que esté anunciada, que siga abierta y que estemos
     * dentro de las fechas. «No se puede» a secas hace que la persona escriba
     * un correo preguntando por qué.
     */
    public function admitePostulaciones(): bool
    {
        if (! $this->is_public || $this->status !== 'abierta') {
            return false;
        }

        $hoy = now(config('fabos.lab.timezone'))->startOfDay();

        if ($this->opens_on && $hoy->lt($this->opens_on)) {
            return false;
        }

        return ! ($this->closes_on && $hoy->gt($this->closes_on));
    }

    /** Por qué no se puede, dicho a quien lo intenta. */
    public function porQueNoAdmite(): ?string
    {
        if ($this->admitePostulaciones()) {
            return null;
        }

        $hoy = now(config('fabos.lab.timezone'))->startOfDay();

        if ($this->opens_on && $hoy->lt($this->opens_on)) {
            return 'Todavía no abre: empieza el ' . $this->opens_on->format('d/m/Y') . '.';
        }

        if ($this->closes_on && $hoy->gt($this->closes_on)) {
            return 'Ya cerró: se recibieron postulaciones hasta el ' . $this->closes_on->format('d/m/Y') . '.';
        }

        return 'No está recibiendo postulaciones.';
    }

    // ------------------------------------------------------------ contadores

    public function pendientes(): int
    {
        return $this->applications()->where('status', 'pendiente')->count();
    }

    public function aceptados(): int
    {
        return $this->applications()->where('status', 'aceptado')->count();
    }

    /** Aceptados a los que todavía nadie les creó cuenta. */
    public function sinCuenta(): int
    {
        return $this->applications()
            ->where('status', 'aceptado')
            ->whereNull('user_id')
            ->count();
    }

    /**
     * Cuántos cupos quedan. Nulo si nadie dijo cuántos hay.
     *
     * Nunca negativo por debajo de cero en la pantalla, pero sí se puede pasar:
     * aceptar de más es una decisión que a veces se toma, y esconderla haría
     * que nadie se enterara.
     */
    public function cuposLibres(): ?int
    {
        return $this->slots === null ? null : $this->slots - $this->aceptados();
    }

    public function scopeAbiertas(Builder $query): Builder
    {
        return $query->where('status', 'abierta')->where('is_public', true);
    }
}
