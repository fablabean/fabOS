<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Un logo de la franja de la portada: quién respalda al laboratorio (§3).
 *
 * La Universidad, la acreditación de calidad, la red a la que pertenece.
 * Se ordenan a mano y se apagan sin borrarlos, igual que las láminas del
 * banner.
 */
class Logo extends Model
{
    protected $table = 'logos';

    protected $fillable = ['position', 'is_active', 'nombre', 'imagen_path', 'url'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position'  => 'integer',
        ];
    }

    public function scopeActivo(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** @return Collection<int,static> */
    public static function paraLaPortada(): Collection
    {
        return static::query()->activo()->orderBy('position')->orderBy('id')->get();
    }

    public function imagenUrl(): ?string
    {
        return $this->imagen_path ? asset('storage/' . $this->imagen_path) : null;
    }
}
