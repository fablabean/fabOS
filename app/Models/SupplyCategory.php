<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Cómo se ordena el almacén (§13).
 *
 * «Madera → MDF → 3 mm». Se anidan a cualquier profundidad porque la realidad
 * de cada laboratorio es distinta: uno separará por material, otro por área,
 * otro por proveedor. Fijar dos niveles obligaría a inventar categorías falsas
 * al llegar al tercero.
 */
class SupplyCategory extends Model
{
    protected $fillable = ['name', 'slug', 'parent_id', 'position'];

    protected static function booted(): void
    {
        static::saving(function (self $categoria) {
            $categoria->slug ??= Str::slug($categoria->name) . '-' . Str::lower(Str::random(4));
            $categoria->position ??= 0;
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('name');
    }

    public function supplies(): HasMany
    {
        return $this->hasMany(Supply::class, 'category_id');
    }

    /**
     * «Madera › MDF», para poder elegir sin abrir un árbol.
     *
     * Sube con tope: una categoría que se tuviera a sí misma de madre colgaría
     * la pantalla, y eso no puede depender de que nadie se equivoque.
     */
    public function ruta(string $separador = ' › '): string
    {
        $partes = [$this->name];
        $actual = $this;

        for ($saltos = 0; $saltos < 10; $saltos++) {
            $actual = $actual->parent;

            if (! $actual) {
                break;
            }

            array_unshift($partes, $actual->name);
        }

        return implode($separador, $partes);
    }

    /**
     * Todas, ya ordenadas por su ruta: es como se leen en un desplegable.
     *
     * @return \Illuminate\Support\Collection<int,string>  id => ruta
     */
    public static function paraElegir(): \Illuminate\Support\Collection
    {
        return static::with('parent')
            ->get()
            ->mapWithKeys(fn (self $c) => [$c->id => $c->ruta()])
            ->sort();
    }
}
