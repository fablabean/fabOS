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

    protected $fillable = [
        'user_id', 'texto', 'camino', 'porque', 'de_memoria',
        'acerto', 'camino_corregido', 'nota', 'revisada_por', 'revisada_el',
    ];

    protected function casts(): array
    {
        return [
            'de_memoria'  => 'boolean',
            // Nulo es «nadie la ha mirado», distinto de «estuvo mal».
            'acerto'      => 'boolean',
            'created_at'  => UtcDateTime::class,
            'revisada_el' => UtcDateTime::class,
        ];
    }

    /** Cuántas van en cada montón. @return array{bien:int,mal:int,sin_mirar:int} */
    public static function comoVaAcertando(): array
    {
        $cuenta = static::query()
            ->selectRaw('acerto, count(*) as cuantas')
            ->groupBy('acerto')
            ->pluck('cuantas', 'acerto');

        return [
            'bien'      => (int) ($cuenta[1] ?? $cuenta['1'] ?? $cuenta[true] ?? 0),
            'mal'       => (int) ($cuenta[0] ?? $cuenta['0'] ?? $cuenta[false] ?? 0),
            'sin_mirar' => (int) ($cuenta[''] ?? 0),
        ];
    }

    /**
     * Lo corregido a mano, para enseñárselo a la guía (§10).
     *
     * Es el cierre del círculo: alguien reconoce un caso mal clasificado, dice
     * con qué debió contestar, y eso vuelve al mensaje como ejemplo. Sin esto,
     * corregir la lista sería llevar la cuenta de los errores en vez de
     * dejar de cometerlos.
     *
     * Las últimas y no todas: van dentro de cada llamada a la API y se pagan
     * por letra. Veinte casos bien elegidos enseñan más que doscientos, y los
     * recientes son los que hablan de cómo se escribe ahora.
     *
     * @return \Illuminate\Support\Collection<int,static>
     */
    public static function loCorregido(int $cuantas = 20): \Illuminate\Support\Collection
    {
        return static::query()
            ->where('acerto', false)
            ->whereNotNull('camino_corregido')
            ->latest('id')
            ->limit($cuantas)
            ->get(['texto', 'camino_corregido', 'nota']);
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
