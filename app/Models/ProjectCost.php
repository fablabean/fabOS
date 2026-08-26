<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un costo del proyecto que no pasó por el laboratorio (§11).
 *
 * El costeo ya reúne lo propio: máquina, material, compras internas y horas del
 * equipo. Esto es lo demás —la factura del tercero que hizo la pintura, un
 * flete, el alquiler de un equipo que no tenemos—. Sin un sitio donde anotarlo
 * el margen sale bonito y falso, que es peor que no calcularlo.
 */
class ProjectCost extends Model
{
    protected $fillable = [
        'project_id', 'kind', 'concept', 'supplier',
        'amount', 'incurred_on', 'document_ref', 'notes', 'registered_by',
    ];

    protected function casts(): array
    {
        return ['incurred_on' => 'date'];
    }

    public const TIPOS = [
        'servicio' => 'Servicio de un tercero',
        'material' => 'Material comprado por fuera',
        'flete'    => 'Flete o transporte',
        'alquiler' => 'Alquiler de equipo',
        'otro'     => 'Otro',
    ];

    /** La columna es NOT NULL: dejar el monto en blanco no puede reventar. */
    protected static function booted(): void
    {
        static::saving(function (self $costo) {
            $costo->amount ??= 0;
        });
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
