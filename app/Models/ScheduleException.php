<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Ausencia de una persona, o cierre de todo el laboratorio (§5). */
class ScheduleException extends Model
{
    protected $fillable = ['user_id', 'kind', 'starts_on', 'ends_on', 'note'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date'];
    }

    public const TIPOS = [
        'vacaciones'   => 'Vacaciones',
        'incapacidad'  => 'Incapacidad',
        'permiso'      => 'Permiso',
        'comision'     => 'Comisión',
        'festivo'      => 'Festivo',
        'cierre'       => 'Cierre del laboratorio',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Sin persona, aplica a todo el laboratorio. */
    public function esGeneral(): bool
    {
        return $this->user_id === null;
    }
}
