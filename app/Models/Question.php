<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** Una duda del laboratorio, y su historia (§20). */
class Question extends Model
{
    protected $fillable = ['user_id', 'title', 'slug', 'body', 'area_id', 'asset_id', 'status'];

    public const ESTADOS = [
        'abierta'    => 'Sin responder',
        'respondida' => 'Respondida',
        'cerrada'    => 'Cerrada',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $p) {
            $p->slug ??= static::slugLibre($p->title);
        });
    }

    /** Un slug que no choque: dos personas pueden preguntar lo mismo. */
    public static function slugLibre(string $titulo): string
    {
        $base = Str::slug(Str::limit($titulo, 60, ''));
        $slug = $base ?: 'pregunta';
        $n = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base . '-' . ++$n;
        }

        return $slug;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(Answer::class);
    }

    /** Solo lo aprobado: un borrador no es una respuesta. */
    public function respuestasPublicadas(): HasMany
    {
        return $this->answers()->where('publicada', true)->oldest('publicada_at');
    }

    /**
     * Busca por sentido, no por letras.
     *
     * PostgreSQL lematiza en español, así que quien busca «impresoras»
     * encuentra «impresora» y quien busca «lavado» encuentra «lavar». Buscar
     * con LIKE fallaría en los dos casos.
     */
    public function scopeBuscar(Builder $q, string $texto): Builder
    {
        $texto = trim($texto);

        if ($texto === '') {
            return $q;
        }

        return $q->whereRaw("busqueda @@ plainto_tsquery('spanish', ?)", [$texto])
            ->orderByRaw("ts_rank(busqueda, plainto_tsquery('spanish', ?)) DESC", [$texto]);
    }

    /**
     * Preguntas parecidas a un texto.
     *
     * Se usa mientras alguien escribe una pregunta nueva: casi siempre la duda
     * ya está resuelta, y enseñarla antes de publicar ahorra el trabajo de
     * responderla otra vez.
     */
    public static function parecidas(string $texto, int $limite = 5, ?int $excepto = null)
    {
        return static::query()
            ->buscar($texto)
            ->when($excepto, fn ($q) => $q->whereKeyNot($excepto))
            ->with('user')
            ->withCount('respuestasPublicadas')
            ->limit($limite)
            ->get();
    }
}
