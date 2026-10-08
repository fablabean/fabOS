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

    /**
     * Todo lo que vive en un espacio: lo que lo declara y lo que cuelga de ello.
     *
     * No se puede preguntar por una columna: `space_id` solo existe en la raíz
     * del árbol —es deliberado, dos fuentes del mismo dato acaban
     * discrepando— así que se baja desde las raíces de ese espacio recogiendo
     * descendencia.
     *
     * Sin límite de profundidad y a prueba de ciclos: una gaveta dentro de un
     * estante dentro de sí mismo colgaría el proceso sin decir por qué, que es
     * la misma precaución que toma `espacio()` al subir.
     */
    public function scopeEnElEspacio(\Illuminate\Database\Eloquent\Builder $query, int $espacioId): \Illuminate\Database\Eloquent\Builder
    {
        $ids = static::conSuDescendencia(
            static::query()->where('space_id', $espacioId)->pluck('id')->all(),
        );

        return $query->whereIn('id', $ids ?: [0]);
    }

    /**
     * Lo que no está en ninguna sala.
     *
     * Es el complemento: todo menos lo que cuelga de una raíz con espacio. Sale
     * en su propio grupo y no escondido, porque un mueble sin sala no se
     * encuentra yendo a buscarlo y hay que poder verlo para arreglarlo.
     */
    public function scopeSinEspacio(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        $ubicadas = static::conSuDescendencia(
            static::query()->whereNotNull('space_id')->pluck('id')->all(),
        );

        return $query->whereNotIn('id', $ubicadas ?: [0]);
    }

    /**
     * Esas ubicaciones y todo lo que cuelgue de ellas, hasta el fondo.
     *
     * A prueba de ciclos: si algo ya estaba en la lista no se vuelve a
     * recorrer, así que un estante dentro de sí mismo termina el recorrido en
     * vez de colgar el proceso.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private static function conSuDescendencia(array $ids): array
    {
        $pendientes = $ids;

        while ($pendientes !== []) {
            $hijas = static::query()->whereIn('parent_id', $pendientes)->pluck('id')->all();
            $nuevas = array_values(array_diff($hijas, $ids));

            if ($nuevas === []) {
                break;
            }

            $ids = array_merge($ids, $nuevas);
            $pendientes = $nuevas;
        }

        return $ids;
    }

    /**
     * A qué profundidad cuelga: 0 la raíz, 1 sus hijas, y así.
     *
     * Con el mismo tope que `espacio()`, y por la misma razón: un ciclo en el
     * árbol —un estante dentro de sí mismo— colgaría el proceso sin decir por
     * qué. Llegado al tope se devuelve lo contado, que es una respuesta rara
     * pero acotada.
     */
    /**
     * El espacio efectivo de cada fila, en SQL: el suyo o el del antepasado
     * más cercano que lo declare, a cualquier profundidad. Es lo mismo que
     * `espacio()`, para poder ORDENAR por ello.
     *
     * Antes se miraba solo el propio y el de la madre: una repisa dentro de un
     * rack dentro de un armario quedaba «sin espacio» para el orden, y salía
     * al final de la lista, lejos de su mueble.
     */
    public const SQL_ESPACIO = <<<'SQL'
        (with recursive sube as (
            select l.parent_id, l.space_id, 0 as n from locations l where l.id = locations.id
            union all
            select p.parent_id, p.space_id, sube.n + 1 from locations p join sube on p.id = sube.parent_id where sube.n < 20
        ) select space_id from sube where space_id is not null order by n limit 1)
        SQL;

    /**
     * La ruta de cada fila desde la raíz de su árbol, para ordenar: cada
     * mueble seguido de todo lo que tiene dentro, y eso de lo suyo, hasta el
     * fondo. Hermanos por nombre; el id desempata a los que se llaman igual
     * —tres «Repisa 1»— para que cada una lleve debajo lo suyo.
     *
     * Con `chr(1)` de separador y comparación binaria: así «Armario 1» y todo
     * lo que contiene va antes que «Armario 10».
     */
    public const SQL_RUTA = <<<'SQL'
        (with recursive sube as (
            select l.parent_id, (lower(l.name) || ':' || lpad(l.id::text, 9, '0'))::text as ruta, 0 as n
              from locations l where l.id = locations.id
            union all
            select p.parent_id, lower(p.name) || ':' || lpad(p.id::text, 9, '0') || chr(1) || sube.ruta, sube.n + 1
              from locations p join sube on p.id = sube.parent_id where sube.n < 20
        ) select ruta from sube where parent_id is null limit 1) collate "C"
        SQL;

    public function nivel(): int
    {
        $nivel = 0;
        $nodo = $this;

        while ($nodo->parent_id && $nivel < 20) {
            $nodo = $nodo->parent;

            if (! $nodo) {
                break;
            }

            $nivel++;
        }

        return $nivel;
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
