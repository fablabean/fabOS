<?php

namespace App\Models\Iot;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Alguien que marcó su llegada a una actividad desde el QR del salón. */
class LlegadaDePantalla extends Model
{
    protected $table = 'pantalla_llegadas';

    public const DIRIGE = 'dirige';
    public const ASISTE = 'asiste';

    public const ROLES = [self::DIRIGE => 'Dirige', self::ASISTE => 'Asiste'];

    protected $fillable = ['actividad_id', 'user_id', 'rol'];

    public function actividad(): BelongsTo
    {
        return $this->belongsTo(ActividadDePantalla::class, 'actividad_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
