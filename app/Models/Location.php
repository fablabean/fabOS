<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Árbol físico: sede › piso › sala › estante › gaveta (§7). */
class Location extends Model
{
    protected $fillable = ['parent_id', 'name', 'path', 'qr_token', 'space_id',];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Location::class, 'parent_id');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * El espacio, declarado solo en la raíz del árbol (§7).
     *
     * Una gaveta no está «en un espacio»: está en un estante, que está en una
     * sala. Declarar el espacio en cada nivel sería repetir el mismo dato tres
     * veces, y bastaría con cambiar uno para que el árbol se contradiga a sí
     * mismo.
     */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * El espacio de verdad: el propio, o el que herede de arriba.
     *
     * Sube por el árbol hasta encontrar quien lo declare. Si nadie lo hace,
     * devuelve null — y eso significa que ese mueble no está asignado a ningún
     * sitio, que es una respuesta honesta y no un cero disfrazado.
     */
    public function espacio(): ?Space
    {
        $nodo = $this;
        $visitados = 0;

        // El tope no es paranoia: un ciclo en el árbol —A dentro de B dentro de
        // A— colgaría el proceso sin decir por qué.
        while ($nodo && $visitados++ < 20) {
            if ($nodo->space_id) {
                return $nodo->space;
            }

            $nodo = $nodo->parent;
        }

        return null;
    }

    /** Solo la raíz declara espacio; el resto lo hereda. */
    public function declaraEspacio(): bool
    {
        return $this->parent_id === null;
    }

    protected static function booted(): void
    {
        static::saving(function (self $ubicacion) {
            // Dos fuentes de verdad para el mismo dato acaban discrepando. Si
            // esta ubicación cuelga de otra, el espacio lo pone la de arriba.
            if ($ubicacion->parent_id !== null) {
                $ubicacion->space_id = null;
            }
        });
    }
}
