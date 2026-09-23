<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Services\Ia\GuiaDeReservas;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una consulta a la guía de reservas: lo que escribieron y qué se les dijo (§10).
 *
 * Es el registro de lo más valioso que produce la guía. La gente escribe con
 * sus palabras qué quiere hacer —no lo que el catálogo le ofrece— y eso dice
 * qué cursos faltan, qué máquina nadie encuentra y qué se pide y no tenemos.
 * Y guardada junto al camino sugerido, dice además si la guía está acertando.
 */
class ConsultaDeGuia extends Model
{
    protected $table = 'consultas_de_guia';

    /** Solo se crea, nunca se edita: por eso no hay `updated_at`. */
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'texto', 'camino', 'porque', 'de_memoria'];

    protected function casts(): array
    {
        return [
            'de_memoria' => 'boolean',
            'created_at' => UtcDateTime::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** «Asesoría», o «Ninguno» cuando lo escrito no iba del laboratorio. */
    public function caminoLegible(): string
    {
        return GuiaDeReservas::CAMINOS[$this->camino]['titulo'] ?? 'Ninguno';
    }

    /**
     * Cuántas van por camino, de más preguntado a menos.
     *
     * @return \Illuminate\Support\Collection<string,int>
     */
    public static function porCamino(?int $dias = null): \Illuminate\Support\Collection
    {
        return static::query()
            ->when($dias, fn ($q) => $q->where('created_at', '>=', now()->subDays($dias)))
            ->selectRaw('camino, count(*) as cuantas')
            ->groupBy('camino')
            ->orderByDesc('cuantas')
            ->pluck('cuantas', 'camino');
    }
}
