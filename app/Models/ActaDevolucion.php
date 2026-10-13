<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una devolución: lo que volvió de un acta, y cómo volvió (§8).
 *
 * Un acta puede tener varias: tres equipos que salieron juntos no siempre
 * regresan juntos. Cada una es un documento, firmado por quien recibe los
 * equipos en el laboratorio, que es quien puede dar fe de su estado.
 */
class ActaDevolucion extends Model
{
    protected $table = 'acta_devoluciones';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'fecha'               => 'date',
            'firmo_at'            => UtcDateTime::class,
            'enviada_a_planta_at' => UtcDateTime::class,
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $d) {
            $d->code ??= self::siguienteCodigo();
        });
    }

    public static function siguienteCodigo(): string
    {
        $anio = now(config('fabos.lab.timezone'))->year;
        $ultimo = static::where('code', 'like', "DEV-{$anio}-%")->max('code');

        return sprintf('DEV-%d-%04d', $anio, $ultimo ? (int) substr($ultimo, -4) + 1 : 1);
    }

    public function acta(): BelongsTo
    {
        return $this->belongsTo(Acta::class);
    }

    /** Los activos que volvieron en esta devolución. */
    public function items(): HasMany
    {
        return $this->hasMany(ActaItem::class, 'devolucion_id')->orderBy('position')->orderBy('id');
    }

    public function recibe(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recibe_user_id');
    }
}
