<?php

namespace App\Models;

use App\Support\Buscadores;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Una publicación de la parrilla de redes (§21).
 *
 * Va de idea a publicada pasando por una aprobación: lo que sale con el nombre
 * del laboratorio lo mira alguien más que quien lo escribió. Si después de
 * aprobada se le cambia el texto o la pieza, vuelve a pasar por aprobación:
 * lo aprobado era otra cosa.
 */
class Publicacion extends Model
{
    protected $table = 'publicaciones';

    protected $fillable = [
        'titulo', 'estado', 'redes', 'formato', 'pilar', 'programada_para',
        'texto', 'hashtags', 'notas', 'piezas', 'responsable_id', 'creada_por',
        'aprobada_por', 'aprobada_at', 'devuelta_con', 'enlace', 'publicada_at',
        'origen_type', 'origen_id', 'sugerencia',
    ];

    protected function casts(): array
    {
        return [
            'redes'           => 'array',
            'piezas'          => 'array',
            'programada_para' => 'datetime',
            'aprobada_at'     => 'datetime',
            'publicada_at'    => 'datetime',
        ];
    }

    /** El camino, en orden. */
    public const ESTADOS = [
        'idea'        => 'Idea',
        'en_diseno'   => 'En diseño',
        'por_aprobar' => 'Por aprobar',
        'aprobada'    => 'Aprobada',
        'programada'  => 'Programada',
        'publicada'   => 'Publicada',
        'descartada'  => 'Descartada',
    ];

    /** El color de cada estado, en la parrilla y en la lista. */
    public const COLORES = [
        'idea'        => 'gray',
        'en_diseno'   => 'info',
        'por_aprobar' => 'warning',
        'aprobada'    => 'primary',
        'programada'  => 'primary',
        'publicada'   => 'success',
        'descartada'  => 'danger',
    ];

    /** Lo que ya no se mueve: se cuenta, pero no ocupa un hueco por llenar. */
    public const CERRADOS = ['publicada', 'descartada'];

    public const FORMATOS = [
        'post'     => 'Post',
        'carrusel' => 'Carrusel',
        'reel'     => 'Reel / video corto',
        'historia' => 'Historia',
        'video'    => 'Video largo',
        'articulo' => 'Artículo',
    ];

    /**
     * De qué habla. Una parrilla que solo anuncia cursos se vuelve un
     * catálogo; los pilares dejan ver de un vistazo si el mes está cojo.
     */
    public const PILARES = [
        'formacion'   => 'Formación',
        'proyectos'   => 'Proyectos',
        'comunidad'   => 'Comunidad',
        'equipos'     => 'Máquinas y procesos',
        'alianzas'    => 'Alianzas',
        'institucional' => 'Institucional',
    ];

    /**
     * Las redes en las que se puede publicar: las conocidas, por su clave.
     *
     * @return array<string,string>
     */
    public static function redesPosibles(): array
    {
        $redes = [];

        foreach (Buscadores::REDES_CONOCIDAS as [$clave, $nombre]) {
            $redes[$clave] = $nombre;
        }

        return $redes;
    }

    /**
     * Las del laboratorio, primero: las que están declaradas en Buscadores.
     *
     * @return array<string,string>
     */
    public static function redesDelLaboratorio(): array
    {
        $propias = collect(Buscadores::redesConNombre())
            ->where('red', '!=', 'sitio')
            ->mapWithKeys(fn ($r) => [$r['red'] => $r['nombre']])
            ->all();

        return $propias ?: self::redesPosibles();
    }

    protected static function booted(): void
    {
        /*
         * Lo aprobado era ese texto y esa pieza. Si cambian, vuelve a
         * aprobación: sin esto, aprobar una versión y publicar otra está a un
         * clic de distancia y nadie se entera.
         */
        static::saving(function (self $p) {
            $aprobada = in_array($p->getOriginal('estado'), ['aprobada', 'programada'], true);

            if ($p->exists && $aprobada && $p->estado === $p->getOriginal('estado')
                && ($p->isDirty('texto') || $p->isDirty('piezas') || $p->isDirty('hashtags'))) {
                $p->estado = 'por_aprobar';
                $p->aprobada_por = null;
                $p->aprobada_at = null;
            }
        });
    }

    /**
     * Quién la hace, si nadie dice otra cosa.
     *
     * Quien la crea, si es de Comunicaciones. Si no —la dirección anotando una
     * idea—, la persona de Comunicaciones cuando hay una sola; con varias no
     * se adivina: se elige.
     */
    public static function responsablePorDefecto(?User $quien): ?int
    {
        if ($quien?->hasRole(User::ROL_COMUNICACIONES)) {
            return $quien->id;
        }

        // whereHas y no el scope `role()`: ese revienta si el rol no existe.
        $deComunicaciones = User::whereHas('roles', fn ($q) => $q->where('name', User::ROL_COMUNICACIONES))
            ->where('status', 'activo')->limit(2)->pluck('id');

        return $deComunicaciones->count() === 1 ? $deComunicaciones->first() : null;
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function autora(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creada_por');
    }

    public function aprobador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobada_por');
    }

    public function origen(): MorphTo
    {
        return $this->morphTo();
    }

    public function contenidos(): BelongsToMany
    {
        return $this->belongsToMany(Contenido::class, 'contenido_publicacion');
    }

    public function tareas(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(TareaDePublicacion::class)->orderBy('orden')->orderBy('id');
    }

    /**
     * Le pone las tareas que suele llevar su formato, con fecha contada hacia
     * atrás desde el día en que sale. Si ya tiene, no toca nada: no se
     * duplican ni se pisan las que alguien ajustó.
     */
    public function ponerTareasTipicas(): int
    {
        if ($this->tareas()->exists()) {
            return 0;
        }

        $tipicas = TareaDePublicacion::TIPICAS[$this->formato] ?? TareaDePublicacion::TIPICAS['post'];
        $sale = $this->programada_para?->timezone(config('fabos.lab.timezone'))->startOfDay();

        foreach ($tipicas as $i => [$que, $diasAntes]) {
            $this->tareas()->create([
                'que'            => $que,
                'responsable_id' => $this->responsable_id,
                'vence_el'       => $sale?->copy()->subDays($diasAntes)->toDateString(),
                'orden'          => $i,
            ]);
        }

        return count($tipicas);
    }

    /** «2/4», o nada si no tiene tareas. */
    public function avanceDeTareas(): ?string
    {
        $total = $this->tareas->count();

        return $total ? $this->tareas->whereNotNull('hecha_at')->count() . '/' . $total : null;
    }

    public function scopeAbiertas(Builder $q): Builder
    {
        return $q->whereNotIn('estado', self::CERRADOS);
    }

    /** Lo que ocupa la parrilla: todo lo que no se descartó. */
    public function scopeEnLaParrilla(Builder $q): Builder
    {
        return $q->where('estado', '!=', 'descartada');
    }

    public function nombreDelEstado(): string
    {
        return self::ESTADOS[$this->estado] ?? $this->estado;
    }

    /** «Instagram · LinkedIn». */
    public function nombresDeLasRedes(): string
    {
        $todas = self::redesPosibles();

        return collect($this->redes ?? [])
            ->map(fn ($r) => $todas[$r] ?? $r)
            ->join(' · ');
    }

    /** Ya pasó su fecha y no ha salido. */
    public function vaTarde(): bool
    {
        return $this->programada_para !== null
            && $this->programada_para->isPast()
            && ! in_array($this->estado, self::CERRADOS, true);
    }
}
