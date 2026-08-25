<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cómo se relaciona un equipo con otro (§7).
 *
 * Existe como modelo propio —y no solo como tabla intermedia— porque la
 * relación lleva datos: no basta con saber que dos equipos están relacionados,
 * hay que saber si el otro tiene que estar operativo, si se reserva junto o si
 * se ofrece al reservar.
 */
class AssetDependency extends Model
{
    protected $table = 'asset_dependencies';

    protected $fillable = ['asset_id', 'depends_on_asset_id', 'modo', 'note'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function dependeDe(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'depends_on_asset_id');
    }
}
