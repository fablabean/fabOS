<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un programa instalado en un equipo concreto (§19).
 *
 * «¿En qué máquinas está Fusion?» y «¿qué tiene instalado esta workstation?»
 * son la misma pregunta desde los dos lados, y sin una tabla en medio se
 * responden preguntando a quien se acuerde.
 *
 * Cuelga del activo que ya existe: un equipo dado de baja se lleva su lista de
 * programas, que es lo correcto —esa instalación dejó de existir con él—.
 */
class SoftwareInstalacion extends Model
{
    protected $table = 'software_instalaciones';

    protected $fillable = [
        'software_id', 'asset_id', 'version', 'instalado_el', 'notas',
    ];

    protected function casts(): array
    {
        return ['instalado_el' => 'date'];
    }

    public function software(): BelongsTo
    {
        return $this->belongsTo(Software::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
