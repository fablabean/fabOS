<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Filament\Componentes\ArchivoPrivado;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Alguien que se postuló a una práctica (§5).
 *
 * Todavía no es nada en el sistema: sin cuenta y sin rol. Se convierte en
 * persona **cuando se acepta**, y ni un minuto antes —darle usuario a quien
 * probablemente no quede llena el sistema de gente que nunca entró—.
 */
class InternshipApplication extends Model
{
    protected $fillable = [
        'call_id', 'name', 'email', 'phone', 'document_number',
        'institution', 'program', 'semester', 'required_hours', 'availability',
        'motivation', 'portfolio_url', 'cv_path', 'cv_url', 'source', 'consent_at',
        'status', 'score', 'evaluation_note', 'fablab_note', 'evaluated_at', 'evaluated_by',
        'user_id', 'notes', 'position',
    ];

    protected $attributes = ['status' => 'pendiente'];

    /** Los mismos que un candidato de proyecto (§11): el proceso es el mismo. */
    public const ESTADOS = [
        'pendiente'  => 'Sin evaluar',
        'espera'     => 'En lista de espera',
        'aceptado'   => 'Aceptado',
        'descartado' => 'Descartado',
    ];

    public const ORIGENES = [
        'web'    => 'Se postuló por el sitio',
        'equipo' => 'Lo cargó el equipo',
    ];

    /** La nota, con las puntas dichas: un 3 sin explicar no se puede comparar. */
    public const NOTAS = [
        1 => '1 · no encaja',
        2 => '2',
        3 => '3 · regular',
        4 => '4',
        5 => '5 · mucho',
    ];

    protected function casts(): array
    {
        return [
            'consent_at'   => UtcDateTime::class,
            'evaluated_at' => UtcDateTime::class,
        ];
    }

    /** Borrar la postulación se lleva su hoja de vida del disco. */
    protected static function booted(): void
    {
        static::deleting(function (self $postulacion) {
            if ($postulacion->cv_path && Storage::disk('local')->exists($postulacion->cv_path)) {
                Storage::disk('local')->delete($postulacion->cv_path);
            }
        });
    }

    public function call(): BelongsTo
    {
        return $this->belongsTo(InternshipCall::class, 'call_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function evaluatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluated_by');
    }

    // ------------------------------------------------------------- lecturas

    /**
     * De la casa o de fuera.
     *
     * **Se deriva del correo**, no se marca: un correo del dominio de la
     * Universidad prueba pertenencia; una casilla marcada a mano solo prueba
     * que alguien la marcó. Si no hay dominio configurado, nadie es interno —el
     * mismo guarda que usa el resto del sistema—.
     */
    public function esInterno(): bool
    {
        return User::correoInstitucional($this->email);
    }

    public function deDonde(): string
    {
        if ($this->esInterno()) {
            return config('fabos.lab.institution') ?: 'La Universidad';
        }

        return $this->institution ?: 'Otra institución';
    }

    public function estaEvaluado(): bool
    {
        return $this->status !== 'pendiente';
    }

    public function yaTieneCuenta(): bool
    {
        return $this->user_id !== null;
    }

    public function estadoLegible(): string
    {
        return self::ESTADOS[$this->status] ?? $this->status;
    }

    /** En qué va, dicho en una línea para la lista. */
    public function enQueVa(): string
    {
        if ($this->yaTieneCuenta()) {
            return 'Aceptado, ya tiene cuenta';
        }

        return match ($this->status) {
            'aceptado'   => 'Aceptado, falta crearle cuenta',
            'descartado' => 'Descartado',
            'espera'     => 'En lista de espera',
            default      => 'Sin evaluar',
        };
    }

    /** Qué estudia, en una línea: «Diseño industrial · 8º · Otra universidad». */
    public function queEstudia(): ?string
    {
        $partes = array_filter([
            $this->program,
            $this->semester ? $this->semester . 'º semestre' : null,
            $this->deDonde(),
        ]);

        return $partes ? implode(' · ', $partes) : null;
    }

    /** Hay hoja de vida: subida o enlazada. Sin ella no se puede evaluar. */
    public function tieneHojaDeVida(): bool
    {
        return filled($this->cv_url) || filled($this->cv_path);
    }

    /** Dónde está la hoja de vida. La subida se sirve solo desde el panel. */
    public function hojaDeVida(): ?string
    {
        if ($this->cv_url) {
            return $this->cv_url;
        }

        if (! $this->cv_path || ! Storage::disk('local')->exists($this->cv_path)) {
            return null;
        }

        $extension = pathinfo($this->cv_path, PATHINFO_EXTENSION);
        $nombre = 'Hoja de vida · ' . $this->name . ($extension ? '.' . $extension : '');

        return ArchivoPrivado::url($this->cv_path, $nombre, descargar: true);
    }

    public function scopeEnEstado(Builder $query, string $estado): Builder
    {
        return $query->where('status', $estado);
    }
}
