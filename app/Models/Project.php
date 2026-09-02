<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Builder;
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

    /**
     * El embudo de un vistazo: cuantos proyectos hay en cada etapa.
     *
     * Las cinco primeras cuentan lo **activo**, que es trabajo por delante. La
     * de cierre cuenta lo **cerrado este año**: el total historico crece para
     * siempre y a los dos años deja de decir nada, mientras que «cuantos
     * entregamos este año» se lee de un golpe.
     *
     * Cada etapa lleva ademas la plata que mueve —lo acordado, o lo estimado
     * mientras no haya acuerdo—. Un embudo con solo el conteo dice que hay
     * cuatro cosas en propuesta; con el valor dice si vale la pena empujarlas.
     *
     * @return array<int, array{etapa: string, nombre: string, cuantos: int, valor: int, cerrada: bool}>
     */
    public static function resumenDelEmbudo(?int $ano = null): array
    {
        $ano ??= (int) now(config('fabos.lab.timezone'))->year;

        // Lo acordado manda; si todavia no hay acuerdo, lo estimado. Un
        // compromiso interno vale cero acordado a proposito, y ahi el estimado
        // es justo lo que se quiere ver: lo que costaria si se cobrara.
        $valor = 'coalesce(nullif(agreed_value, 0), estimated_value, 0)';

        $activos = static::query()
            ->where('status', 'activo')
            ->selectRaw("stage, count(*) as cuantos, sum($valor) as valor")
            ->groupBy('stage')
            ->get()
            ->keyBy('stage');

        $cerrados = static::query()
            ->where('status', 'cerrado')
            ->whereYear('closed_at', $ano)
            ->selectRaw("count(*) as cuantos, sum($valor) as valor")
            ->first();

        $enPausa = static::query()
            ->where('status', 'pausado')
            ->selectRaw("count(*) as cuantos, sum($valor) as valor")
            ->first();

        $tarjetas = [];

        foreach (self::ETAPAS as $etapa => $nombre) {
            $esCierre = $etapa === 'cierre';
            $fila = $esCierre ? $cerrados : $activos->get($etapa);

            $tarjetas[] = [
                'etapa'   => $etapa,
                'nombre'  => $nombre,
                'cuantos' => (int) ($fila->cuantos ?? 0),
                'valor'   => (int) ($fila->valor ?? 0),
                'cerrada' => $esCierre,
                'pausa'   => false,
            ];
        }

        /*
         * Lo pausado va en su propia tarjeta, no repartido por etapas.
         *
         * El embudo mide lo que se mueve; un proyecto parado en propuesta
         * sumado a los que estan vivos diria que hay mas cosas avanzando de
         * las que hay. Aparte se ve, y se ve que no avanza.
         */
        $tarjetas[] = [
            'etapa'   => 'pausado',
            'nombre'  => 'En pausa',
            'cuantos' => (int) ($enPausa->cuantos ?? 0),
            'valor'   => (int) ($enPausa->valor ?? 0),
            'cerrada' => false,
            'pausa'   => true,
        ];

        return $tarjetas;
    }

    public const ESTADOS = [
        'activo'     => 'Activo',
        // Parado, no muerto. Un proyecto que espera una firma, una pieza que
        // no llega o el semestre siguiente sigue vivo: marcarlo descartado
        // para que deje de aparecer como atrasado es perder el rastro de que
        // hay que volver a el.
        'pausado'    => 'En pausa',
        'ganado'     => 'Ganado',
        'perdido'    => 'Perdido',
        'descartado' => 'Descartado',
        'cerrado'    => 'Cerrado',
    ];

    public function estaEnPausa(): bool
    {
        return $this->status === 'pausado';
    }

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

    /**
     * Si esta persona lidera el proyecto (§11).
     *
     * El responsable responde por el: darle control sobre lo suyo no es un
     * permiso extra, es lo que ya se espera de el. Lo contrario —tener que
     * pedirle a un administrador que mueva de etapa un proyecto propio— es lo
     * que hace que las etapas dejen de estar al dia.
     */
    public function loLidera(?User $quien): bool
    {
        return $quien !== null && $this->lead_id === $quien->id;
    }

    /**
     * A quién va dirigida la propuesta: al cliente.
     *
     * Parece obvio y no lo era. `requested_by` no siempre es el cliente: un
     * proyecto que anota el propio laboratorio —el que llega por teléfono o por
     * correo— queda a nombre de **quien lo anotó**, que es alguien del equipo.
     * Como la propuesta se mandaba a `requested_by` si existía, esos proyectos
     * le mandaban la propuesta al colaborador y el cliente no recibía nada. Lo
     * contaba, además, con la frase que más cuesta responder: «no pude aceptar
     * la propuesta que me mandaron».
     *
     * El correo de contacto manda porque es, literalmente, el contacto del
     * proyecto. En una solicitud de la web coincide con la cuenta de quien la
     * escribió, así que ahí no cambia nada.
     */
    public function correoDeLaPropuesta(): ?string
    {
        return $this->contact_email ?: $this->requestedBy?->email;
    }

    /**
     * Y su cuenta, si ese correo tiene una.
     *
     * Con cuenta el aviso respeta sus preferencias y queda en su bitácora; sin
     * ella se manda igual, que para eso el laboratorio anota proyectos de quien
     * no entra al sistema.
     */
    public function destinatarioDeLaPropuesta(): ?User
    {
        $correo = $this->correoDeLaPropuesta();

        if (blank($correo)) {
            return null;
        }

        if ($this->requestedBy && mb_strtolower($this->requestedBy->email) === mb_strtolower($correo)) {
            return $this->requestedBy;
        }

        return User::whereRaw('lower(email) = ?', [mb_strtolower($correo)])->first();
    }

    /**
     * Quién puede aceptar la propuesta.
     *
     * La acepta el cliente y nadie más. Tres puertas, y las tres hacen falta:
     *
     *  · El **enlace firmado** del correo, que funciona sin haber entrado.
     *  · La **cuenta de quien la pidió**, para cuando el correo se pierde.
     *  · La **cuenta del correo de contacto**: en los proyectos que anota el
     *    laboratorio, quien pidió figura como el colaborador que los anotó, y
     *    el cliente se quedaba mirando una propuesta sin forma de aceptarla.
     *
     * Y la excepción que sostiene todo lo demás: quien es del laboratorio no
     * acepta en nombre del cliente, aunque el proyecto figure a su nombre por
     * haberlo anotado él.
     */
    public function loPuedeAceptar(?User $quien, bool $conEnlaceFirmado = false): bool
    {
        if ($conEnlaceFirmado) {
            return true;
        }

        if ($quien === null) {
            return false;
        }

        if (filled($this->contact_email)
            && mb_strtolower($quien->email) === mb_strtolower($this->contact_email)) {
            return true;
        }

        return $quien->id === $this->requested_by
            && ! $quien->hasAnyRole(User::ROLES_BACKOFFICE);
    }

    /**
     * Si esta persona es del equipo: lo lidera o esta apuntada en el.
     *
     * Solo cuenta quien tiene cuenta. Un proveedor o el cliente son parte del
     * equipo en el acta, pero no entran al sistema.
     */
    public function estaEnElEquipo(?User $quien): bool
    {
        if ($quien === null) {
            return false;
        }

        return $this->loLidera($quien)
            || $this->members()->where('user_id', $quien->id)->exists();
    }

    /** Los proyectos de alguien: los que lidera y en los que esta apuntado. */
    public function scopeDeAlguien(Builder $query, ?User $quien): Builder
    {
        if ($quien === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($quien) {
            $q->where('lead_id', $quien->id)
                ->orWhereHas('members', fn (Builder $m) => $m->where('user_id', $quien->id));
        });
    }

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
