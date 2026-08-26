<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserCategory extends Model
{
    protected $fillable = [
        'slug', 'name', 'position', 'rate_factor', 'allowance_minor',
        'max_hours_per_week', 'max_days_ahead', 'can_reserve', 'is_institutional',
     'client_kind',];

    protected function casts(): array
    {
        return [
            'rate_factor'      => 'decimal:3',
            'can_reserve'      => 'boolean',
            'is_institutional' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Qué trámite le toca a quien pertenece a esta categoría.
     *
     * Vive aquí y no en código porque cada laboratorio arma sus categorías: el
     * día que aparezca «egresado» o «aliado», quien coordina decide su trámite
     * sin que nadie despliegue nada.
     */
    public function tramiteDeCliente(): string
    {
        return in_array($this->client_kind, ['interno', 'estudiante', 'externo'], true)
            ? $this->client_kind
            : 'externo';
    }
}
