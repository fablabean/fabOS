<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserCategory extends Model
{
    protected $fillable = [
        'slug', 'name', 'position', 'rate_factor', 'allowance_minor',
        'max_hours_per_week', 'max_days_ahead', 'can_reserve', 'is_institutional',
    ];

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
}
