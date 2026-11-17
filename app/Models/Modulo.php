<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un módulo de una zona: hoy, una presentación de una sola página que se sube
 * hecha (un .html con todo dentro, como «Conoce el FabLab»).
 *
 * Quién lo ve se elige en cada uno: cualquiera con el enlace, quien tenga
 * cuenta, o quien esté inscrito en un curso. El equipo lo ve siempre, también
 * sin publicar, para revisarlo antes de anunciarlo.
 */
class Modulo extends Model
{
    protected $table = 'modulos';

    protected $fillable = [
        'zona_id', 'slug', 'titulo', 'resumen', 'tipo', 'html_path', 'portada_path',
        'acceso', 'course_id', 'publicado', 'destacado', 'orden',
    ];

    public const ACCESOS = [
        'publico'   => 'Cualquiera con el enlace',
        'cuenta'    => 'Quien tenga cuenta',
        'inscritos' => 'Inscritos en un curso',
    ];

    /** Lo que no puede ser el nombre de un módulo: son direcciones del sitio. */
    public const RESERVADOS = ['admin', 'livewire', 'storage', 'build', 'up', 'ingresar', 'salir', 'zonas'];

    protected function casts(): array
    {
        return ['publicado' => 'boolean', 'destacado' => 'boolean', 'orden' => 'integer'];
    }

    public function zona(): BelongsTo
    {
        return $this->belongsTo(Zona::class);
    }

    public function curso(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function esPublico(): bool
    {
        return $this->acceso === 'publico';
    }

    /**
     * La dirección para compartir. Por subdominio solo los públicos: la sesión
     * vive en el dominio principal, y lo que pide cuenta tiene que abrirse
     * donde se sabe quién es.
     */
    public function url(): string
    {
        return Zona::conSubdominio() && $this->esPublico()
            ? route('zonas.sub.modulo', ['zona' => $this->zona->slug, 'modulo' => $this->slug])
            : route('zonas.modulo', [$this->zona, $this->slug]);
    }

    public function portadaUrl(): ?string
    {
        return $this->portada_path ? asset('storage/' . $this->portada_path) : null;
    }

    public static function delEquipo(?User $quien): bool
    {
        return $quien !== null && $quien->hasAnyRole(User::rolesDelEquipo());
    }

    /**
     * ¿Lo puede abrir? Nulo si sí; si no, lo que le falta: «oculto» (no está
     * publicado), «cuenta» (tiene que ingresar) o «inscritos» (no está en el curso).
     */
    public function queLeFalta(?User $quien): ?string
    {
        if (static::delEquipo($quien)) {
            return null;
        }

        if (! $this->publicado || ! $this->zona?->publicada) {
            return 'oculto';
        }

        return match ($this->acceso) {
            'publico' => null,
            'cuenta'  => $quien ? null : 'cuenta',
            default   => ! $quien ? 'cuenta' : ($this->estaInscrito($quien) ? null : 'inscritos'),
        };
    }

    private function estaInscrito(User $quien): bool
    {
        if (! $this->course_id) {
            return false;
        }

        return Enrollment::where('user_id', $quien->id)
            ->whereIn('status', ['inscrito', 'aprobado'])
            ->whereHas('edition', fn ($q) => $q->where('course_id', $this->course_id))
            ->exists();
    }
}
