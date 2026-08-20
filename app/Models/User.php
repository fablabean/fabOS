<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name', 'email', 'password', 'user_category_id', 'document_number',
    'phone', 'status', 'external_id', 'identity_verified_at',
    'identity_verified_via', 'category_confirmed', 'locale', 'email_verified_at',
    'carnet_subject', 'carnet_linked_at',
])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, SoftDeletes;

    /** Roles internos (§5). El rol es distinto de la categoria de la persona. */
    public const ROL_CONSULTOR     = 'consultor';
    public const ROL_ADMINISTRADOR = 'administrador';
    public const ROL_SUPERADMIN    = 'superadmin';

    public const ROLES_BACKOFFICE = [
        self::ROL_CONSULTOR,
        self::ROL_ADMINISTRADOR,
        self::ROL_SUPERADMIN,
    ];

    /**
     * Quien entra al backoffice. Sin esto, Filament en entorno local deja pasar
     * a CUALQUIER usuario autenticado: un estudiante que pide su codigo por
     * correo llegaria al panel de administracion.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->status === 'activo'
            && $this->hasAnyRole(self::ROLES_BACKOFFICE);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at'    => 'datetime',
            'identity_verified_at' => 'datetime',
            'carnet_linked_at'     => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'category_confirmed'   => 'boolean',
            'password'             => 'hashed',
        ];
    }

    /** Áreas de las que responde, y por tanto en las que puede certificar. */
    public function responsibleAreas(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(Area::class, 'area_responsibles')
            ->withPivot('is_backup')
            ->withTimestamps();
    }

    public function esResponsableDe(?int $areaId): bool
    {
        return $areaId !== null
            && $this->responsibleAreas()->whereKey($areaId)->exists();
    }

    /**
     * Quién debe usar segundo factor (§16): quien puede cambiar el catálogo,
     * los permisos o el dinero. Un consultor solo mira.
     */
    public function segundoFactorObligatorio(): bool
    {
        return $this->hasAnyRole([self::ROL_ADMINISTRADOR, self::ROL_SUPERADMIN]);
    }

    public function tieneSegundoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null;
    }

    public function certifabs(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Certifab::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(UserCategory::class, 'user_category_id');
    }

    /** Pertenece a la Universidad segun su correo (§5). */
    public function isInstitutional(): bool
    {
        return str_ends_with($this->email, '@' . config('fabos.identity.institutional_domain'));
    }
}
