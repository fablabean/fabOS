<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un proyecto, de la idea al acta de cierre (§11). */
class Project extends Model
{
    protected $fillable = [
        'code', 'name', 'stage', 'status', 'source',
        'contact_name', 'contact_email', 'contact_phone', 'organization',
        'requested_by', 'lead_id', 'area_id',
        'summary', 'notes', 'agreed_value', 'estimated_value',
        'starts_on', 'due_on', 'closed_at', 'closing_notes',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'due_on'    => 'date',
            'closed_at' => UtcDateTime::class,
        ];
    }

    /** El embudo, en orden. El orden es la regla: no se salta ninguna. */
    public const ETAPAS = [
        'idea'      => 'Idea',
        'propuesta' => 'Propuesta',
        'contrato'  => 'Contrato',
        'brief'     => 'Brief',
        'ejecucion' => 'En ejecución',
        'cierre'    => 'Cerrado',
    ];

    public const ESTADOS = [
        'activo'     => 'Activo',
        'ganado'     => 'Ganado',
        'perdido'    => 'Perdido',
        'descartado' => 'Descartado',
        'cerrado'    => 'Cerrado',
    ];

    public const ORIGENES = [
        'correo'     => 'Correo',
        'whatsapp'   => 'WhatsApp',
        'formulario' => 'Formulario del sitio',
        'gerencia'   => 'Gerencia',
        'interno'    => 'Iniciativa del laboratorio',
    ];

    public function lead(): BelongsTo
    {
        return $this->belongsTo(User::class, 'lead_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ProjectDocument::class);
    }

    public function timeLogs(): HasMany
    {
        return $this->hasMany(ProjectTimeLog::class)->orderBy('worked_on');
    }

    /** Reservas cargadas a este proyecto: el tiempo de máquina que consumió. */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(ProjectTask::class)->orderBy('position')->orderBy('id');
    }

    /**
     * A qué se comprometió el laboratorio, un renglón por compromiso.
     *
     * Era un párrafo, y un párrafo no se puede marcar como cumplido: al final
     * del proyecto nadie sabía si se entregó lo prometido, sabía que se había
     * trabajado mucho.
     */
    public function deliverables(): HasMany
    {
        return $this->hasMany(ProjectDeliverable::class)->orderBy('position')->orderBy('id');
    }

    /** Lo que se gastó por fuera del laboratorio. */
    public function costs(): HasMany
    {
        return $this->hasMany(ProjectCost::class)->orderBy('incurred_on');
    }

    /**
     * Contra qué se mide el margen: lo firmado si ya se firmó, y si no lo
     * cotizado. Un proyecto en propuesta todavia no tiene valor acordado, y
     * medirlo contra cero lo pintaria en perdida desde el primer dia.
     */
    public function valorDeReferencia(): int
    {
        return (int) ($this->agreed_value ?: $this->estimated_value);
    }

    public function tieneDocumento(string $tipo): bool
    {
        return $this->documents()->where('kind', $tipo)->exists();
    }

    /** Avance del proyecto: promedio de sus tareas, no una barra a dedo. */
    public function avance(): int
    {
        $tareas = $this->tasks;

        if ($tareas->isEmpty()) {
            return $this->stage === 'cierre' ? 100 : 0;
        }

        return (int) round($tareas->avg(fn (ProjectTask $t) => $t->status === 'hecha' ? 100 : $t->progress));
    }

    public function estaCerrado(): bool
    {
        return $this->stage === 'cierre' || in_array($this->status, ['perdido', 'descartado'], true);
    }

    /** Quién pide, tenga cuenta o no. */
    public function quienPide(): string
    {
        return $this->organization
            ?: $this->contact_name
            ?: $this->requestedBy?->name
            ?: 'sin identificar';
    }

    /**
     * El código lo pone el modelo, no quien crea el proyecto.
     *
     * Antes lo generaba el servicio, y el formulario del backoffice creaba el
     * proyecto directamente: se saltaba esa línea y la base rechazaba la fila
     * con «null value in column code». El error salía como «Error al cargar la
     * página», que no dice nada.
     *
     * Aquí no hay forma de saltárselo: cualquiera que cree un proyecto, por la
     * vía que sea, obtiene su código.
     */
    protected static function booted(): void
    {
        static::creating(function (self $proyecto) {
            $proyecto->code ??= static::siguienteCodigo();
        });

        // Un proyecto en la etapa de idea todavia no tiene valor acordado, y el
        // formulario manda ese campo vacio. La columna es NOT NULL con default
        // 0, pero un NULL explicito se salta el default y revienta el insert:
        // sin esto no se puede anotar una idea, que es justo lo primero que
        // hace cualquiera. Vacio significa "sin acordar", y eso son 0 pesos.
        static::saving(function (self $proyecto) {
            $proyecto->agreed_value ??= 0;
            $proyecto->estimated_value ??= 0;
        });
    }

    /** PRY-2026-0001, consecutivo por año. */
    public static function siguienteCodigo(): string
    {
        $ano = now(config('fabos.lab.timezone'))->year;

        // El máximo por texto funciona porque el número va con ceros delante y
        // ancho fijo: «0010» ordena después de «0009».
        $ultimo = static::where('code', 'like', "PRY-{$ano}-%")->max('code');

        return sprintf('PRY-%d-%04d', $ano, $ultimo ? ((int) substr($ultimo, -4)) + 1 : 1);
    }
}
