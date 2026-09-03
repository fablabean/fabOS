<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Reserva de un recurso durante un rango de tiempo (§10).
 *
 * La no superposición no se valida aquí: la garantiza PostgreSQL con una
 * restricción EXCLUDE. Si dos reservas chocan, el INSERT falla.
 */
class Reservation extends Model
{
    protected $fillable = [
        'reservable_type', 'reservable_id', 'user_id', 'project_id', 'project_task_id', 'supervisor_id',
        'advisory_asset_id', 'advisory_area_id', 'participants', 'parent_reservation_id',
        'status', 'mode', 'is_production', 'starts_at', 'ends_at', 'reinstated_at',
        'checked_in_at', 'checked_out_at',
        'estimated_cost_minor', 'actual_cost_minor', 'purpose', 'status_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_production'  => 'boolean',
            'starts_at'      => UtcDateTime::class,
            'ends_at'        => UtcDateTime::class,
            'checked_in_at'  => UtcDateTime::class,
            'checked_out_at' => UtcDateTime::class,
            'reinstated_at'  => UtcDateTime::class,
        ];
    }

    public const ESTADOS = [
        'solicitada'  => 'Solicitada',
        'confirmada'  => 'Confirmada',
        'en_curso'    => 'En curso',
        'completada'  => 'Completada',
        'rechazada'   => 'Rechazada',
        'cancelada'   => 'Cancelada',
        'no_show'     => 'No se presentó',
    ];

    public const MODOS = [
        'directa'        => 'Directa',
        'asesoria'       => 'Asesoría',
        'con_aprobacion' => 'Con aprobación',
        'solo_solicitud' => 'Solo por solicitud',
        // Un recorrido ocupa el laboratorio sin cerrarlo: la base no lo
        // cuenta como solape, y cuántas personas caben a la vez lo suma el
        // servicio de espacios.
        'recorrido'      => 'Recorrido',
        // El tiempo de alguien, apartado para una tarea de proyecto: en esas
        // horas no se le reparte nada.
        'proyecto'       => 'Tiempo de proyecto',
    ];

    /** Estados en los que la reserva ocupa el recurso de verdad. */
    public const BLOQUEANTES = ['confirmada', 'en_curso'];

    public const MODO_RECORRIDO = 'recorrido';

    /**
     * Producir no es reservar, aunque ocupe igual. Una produccion es el
     * laboratorio operando su propia maquina para un encargo: no se le cobra a
     * nadie, no pide certifab, y puede correr de madrugada. Pero bloquea el
     * equipo exactamente lo mismo, y por eso vive en esta tabla.
     */
    public function esProduccion(): bool
    {
        return (bool) $this->is_production;
    }

    /**
     * Lo que salio de aqui: el .stl, el .gcode, la foto de la pieza. En una
     * produccion son los archivos definitivos, y son lo unico que permite
     * repetir el trabajo dentro de seis meses sin volver a empezar.
     */
    public function evidence(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Evidencia::class, 'evidenciable')->orderBy('id');
    }

    /** La tarea para la que se apartó este tiempo, si es un bloque de proyecto (§11). */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ProjectTask::class, 'project_task_id');
    }

    public function esBloqueDeProyecto(): bool
    {
        return $this->mode === 'proyecto';
    }

    /** Un proyecto al que se carga esta reserva, si lo hay (§11). */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function reservable(): MorphTo
    {
        return $this->morphTo();
    }

    /** El bloque del colaborador que acompaña esta reserva, si lo hay. */
    public function acompanamiento(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(self::class, 'parent_reservation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    /**
     * Quiénes del equipo acompañan (§7).
     *
     * Distinto del supervisor, que es UNO y lo exige el certifab. Aquí van los
     * que se apuntan a acompañar una actividad: ninguno, uno, o todos. Un
     * recorrido de treinta lo llevan dos o tres.
     */
    public function companions(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'reservation_companions')->withTimestamps();
    }

    public function esRecorrido(): bool
    {
        return $this->mode === self::MODO_RECORRIDO;
    }

    /**
     * El laboratorio entero, tomado en exclusiva. Es la reserva que no deja
     * reservar ni una sala ni una máquina mientras dure.
     */
    public function esCierreTotal(): bool
    {
        return $this->reservable_type === Space::class
            && ! $this->esRecorrido()
            && (bool) $this->reservable?->es_todo;
    }

    /** El equipo sobre el que trata una asesoría; nulo en el resto (§10). */
    public function advisoryAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'advisory_asset_id');
    }

    /** El área, cuando la asesoría es general y no de una máquina (§10). */
    public function advisoryArea(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Area::class, 'advisory_area_id');
    }

    public function esAsesoria(): bool
    {
        return $this->mode === 'asesoria';
    }

    /**
     * Sobre qué trata la asesoría, para decirlo en una línea.
     *
     * Una general no tiene máquina: decir «asesoría» a secas obliga a abrir la
     * ficha para saber de qué iba.
     */
    public function sobreQue(): ?string
    {
        return $this->advisoryAsset?->name
            ?? ($this->advisoryArea ? 'General de ' . $this->advisoryArea->name : null);
    }
}
