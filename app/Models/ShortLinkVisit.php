<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una visita a un enlace corto (§21).
 *
 * Se guarda lo justo para responder «¿esto sirvio?»: cuando, de donde venia y
 * si fue telefono u ordenador. No hay direccion IP ni cookie: para contar
 * cuantas veces se escaneo un cartel no hace falta saber quien lo escaneo, y lo
 * que no se guarda no se puede filtrar.
 */
class ShortLinkVisit extends Model
{
    public $timestamps = false;

    protected $fillable = ['short_link_id', 'visited_at', 'source', 'device'];

    protected function casts(): array
    {
        return ['visited_at' => 'datetime'];
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(ShortLink::class, 'short_link_id');
    }
}
