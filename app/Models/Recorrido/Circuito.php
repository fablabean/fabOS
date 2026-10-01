<?php

namespace App\Models\Recorrido;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** La plantilla de un recorrido gamificado: sus estaciones, en orden. */
class Circuito extends Model
{
    protected $table = 'recorrido_circuitos';

    protected $fillable = ['nombre', 'descripcion', 'activo'];

    protected function casts(): array
    {
        return ['activo' => 'boolean'];
    }

    public function estaciones(): HasMany
    {
        return $this->hasMany(Estacion::class)->orderBy('orden')->orderBy('id');
    }

    public function partidas(): HasMany
    {
        return $this->hasMany(Partida::class);
    }
}
