<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un proyecto, de la idea al acta de cierre (§11). */
class Project extends Model
{
    protected $fillable = [
        'code', 'name', 'stage', 'status', 'source', 'is_internal', 'client_kind',
        'contact_name', 'contact_email', 'contact_phone', 'organization',
        'requested_by', 'lead_id', 'area_id',
        'summary', 'reference_image_path', 'notes', 'agreed_value', 'estimated_value',
        'starts_on', 'due_on', 'closed_at', 'closing_notes', 'proposal_sent_at',
        'accepted_at', 'accepted_by', 'acceptance_note',
    ];

    protected function casts(): array
    {
        return [
            'is_internal' => 'boolean',
            'starts_on' => 'date',
            'due_on'    => 'date',
            'closed_at'        => UtcDateTime::class,
            'proposal_sent_at' => UtcDateTime::class,
            'accepted_at'      => UtcDateTime::class,
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

    /**
     * De quién es el encargo. Cambia el trámite, no el trabajo.
     *
     * Un área de la propia institución no paga: mueve presupuesto por la venta
     * interna, un circuito de cuatro manos que no se corre en tres días. Un
     * estudiante no pasa por nada de eso, y una empresa de fuera tampoco.
     */
    public const CLIENTES = [
        'interno'    => 'Área o facultad de la Universidad',
        'estudiante' => 'Estudiante',
        'externo'    => 'Empresa u organización de fuera',
    ];

    /** Solo el área institucional pasa por el traslado presupuestal. */
    public function esClienteInterno(): bool
    {
        return $this->client_kind === 'interno';
    }

    public function estaAceptado(): bool
    {
        return $this->accepted_at !== null;
    }

    /**
     * En qué va, dicho para quien lo pidió.
     *
     * **Sale de los hechos, no de la etapa.** El embudo —idea, propuesta,
     * contrato— es vocabulario interno y avanza con sus compuertas
     * documentales; decirle «Idea» a alguien que ya aceptó la propuesta es
     * mentirle con una palabra que además no significa nada para él.
     *
     * @return array{titulo:string,detalle:?string}
     */
    public function estadoParaElCliente(): array
    {
        if (in_array($this->status, ['descartado', 'perdido'], true)) {
            return ['titulo' => 'No siguió adelante', 'detalle' => $this->closing_notes];
        }

        if ($this->stage === 'cierre' || $this->status === 'cerrado') {
            return ['titulo' => 'Cerrado', 'detalle' => 'El trabajo se entregó.'];
        }

        if ($this->stage === 'ejecucion') {
            return ['titulo' => 'En ejecución', 'detalle' => 'Se está fabricando.'];
        }

        if ($this->estaAceptado()) {
            $cuando = $this->accepted_at
                ->timezone(config('fabos.lab.timezone'))
                ->format('d/m/Y');

            return [
                'titulo'  => 'Aceptada',
                'detalle' => $this->esClienteInterno()
                    ? "aceptada el {$cuando} · falta el traslado presupuestal"
                    : "aceptada el {$cuando} · preparando el arranque",
            ];
        }

        if ($this->proposal_sent_at) {
            $cuando = $this->proposal_sent_at
                ->timezone(config('fabos.lab.timezone'))
                ->format('d/m/Y');

            return [
                'titulo'  => 'Propuesta enviada',
                'detalle' => "el {$cuando} · esperando tu respuesta",
            ];
        }

        return ['titulo' => 'En revisión', 'detalle' => 'estamos mirando si cabe y cuánto tomaría'];
    }

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

    /**
     * Los equipos que usa el proyecto. Se declaran para saber con qué se
     * cuenta antes de programar nada; programar producción con uno lo añade
     * solo, porque eso es la prueba más clara de que el proyecto lo usa.
     */
    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(Asset::class, 'project_assets')
            ->withPivot('note')
            ->withTimestamps()
            ->orderBy('assets.name');
    }

    /**
     * Los bloques en que una máquina fabrica para este proyecto.
     *
     * Son reservas: ocupan el equipo igual que cualquier otra, y por eso salen
     * de la lista de horarios disponibles sin que haya que hacer nada más.
     */
    public function producciones(): HasMany
    {
        return $this->hasMany(Reservation::class)
            ->where('is_production', true)
            ->orderBy('starts_at');
    }

    /**
     * Los soportes que adjuntó quien lo pidió: fotos, planos, el PDF con el
     * brief que ya tenía escrito, un dibujo hecho a mano alzada.
     *
     * Una idea explicada solo con palabras se entiende de tantas formas como
     * personas la lean. Una foto de la pieza rota, o un garabato con medidas,
     * ahorra tres correos de ida y vuelta.
     */
    public function evidence(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(Evidencia::class, 'evidenciable')->orderBy('id');
    }

    /**
     * La imagen que resume de qué va, si la hay.
     *
     * Va por una ruta con permiso y no por /storage: es material de alguien de
     * fuera, y una URL adivinable la dejaría a la vista de cualquiera.
     */
    public function imagenDeReferencia(): ?string
    {
        return $this->reference_image_path
            ? route('proyectos.imagen', $this)
            : null;
    }

    /**
     * Lo que se grabó durante el proyecto: fotos y videos del banco.
     *
     * Se sube desde el teléfono, delante de la máquina, que es cuando existe.
     * Aquí queda para el informe de cierre y para enseñar lo que se hizo.
     */
    public function contenido(): HasMany
    {
        return $this->hasMany(Contenido::class)->latest('id');
    }

    /** Cada propuesta que se mandó, en orden. */
    public function proposals(): HasMany
    {
        return $this->hasMany(ProjectProposal::class)->orderBy('version');
    }

    /** La última que se mandó, que es la que el cliente tiene delante. */
    public function propuestaVigente(): ?ProjectProposal
    {
        return $this->proposals()->orderByDesc('version')->first();
    }

    /**
     * Lo que se dijo sobre la propuesta, de los dos lados.
     *
     * Sin esto, «casi, pero cambia la fecha» acaba en un chat donde nadie lo
     * vuelve a encontrar.
     */
    public function comments(): HasMany
    {
        return $this->hasMany(ProjectComment::class)->orderBy('created_at');
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

            // En un compromiso interno no hay contrato que acordar. Sin esto,
            // marcar como interno un proyecto que ya tenia valor acordado
            // dejaria las dos cifras contradiciendose: el formulario esconde
            // el campo y el costeo seguiria midiendo contra el.
            if ($proyecto->is_internal) {
                $proyecto->agreed_value = 0;
            }
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
