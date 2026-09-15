<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Una página del sitio público (§3, portal público).
 *
 * El banner deja cambiar la frase de la portada; esto es donde cabe lo que no
 * es una frase. Se escribe en el panel y se publica en `/p/<slug>`, que es
 * corto a propósito: esa dirección se dice en voz alta y se imprime en un QR.
 *
 * Una página es una **lista ordenada de bloques**: un párrafo, una galería,
 * unas cifras, unos hitos. No es un editor de texto largo porque lo que se
 * publica aquí casi nunca es un texto largo —es material heterogéneo que hay
 * que poder reordenar—, y no es una plantilla fija porque entonces cada página
 * nueva volvería a ser trabajo de quien despliega.
 *
 * El caso que la hizo existir: contar un proyecto. La página **nace sembrada**
 * con lo publicable de uno —nombre, resumen, área, compromisos, fotos del
 * banco— y a partir de ahí es contenido propio. Esa copia no es pereza: leer
 * el proyecto en vivo sería publicar sin mirar, y en la ficha de un proyecto
 * están el valor acordado, el documento del cliente y las notas internas.
 */
class Pagina extends Model
{
    protected $table = 'paginas';

    protected $fillable = [
        'slug', 'titulo', 'rotulo', 'resumen', 'portada_path',
        'bloques', 'project_id', 'is_active', 'starts_at', 'ends_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'bloques' => 'array',
            'is_active' => 'boolean',
            'starts_at' => UtcDateTime::class,
            'ends_at' => UtcDateTime::class,
        ];
    }

    /**
     * Las piezas con que se arma una página.
     *
     * La lista está aquí y no solo en el formulario porque la vista también
     * la necesita: cada clave es el nombre de una plantilla en
     * `publico/bloques/`. Un bloque cuyo tipo no esté aquí no se pinta —una
     * página guardada con un bloque que después se retira del código deja de
     * enseñarlo, en vez de reventar la página entera.
     */
    public const BLOQUES = [
        'texto' => 'Texto',
        'imagen' => 'Una imagen',
        'galeria' => 'Galería',
        'video' => 'Video',
        'cifras' => 'Cifras',
        'datos' => 'Ficha de datos',
        'hitos' => 'Hitos',
        'botones' => 'Botones',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Lo que se ve ahora mismo: encendida y en fecha.
     *
     * Mismo trato que el banner. Sin fechas vale siempre; con ellas aparece y
     * desaparece sola, que es la única forma de que la página de una
     * convocatoria deje de invitar a algo que ya pasó.
     */
    public function scopeVisible(Builder $q): Builder
    {
        $ahora = now();

        return $q->where('is_active', true)
            ->where(fn ($s) => $s->whereNull('starts_at')->orWhere('starts_at', '<=', $ahora))
            ->where(fn ($s) => $s->whereNull('ends_at')->orWhere('ends_at', '>=', $ahora));
    }

    public function estaVisible(): bool
    {
        return $this->is_active
            && (! $this->starts_at || $this->starts_at->isPast())
            && (! $this->ends_at || $this->ends_at->isFuture());
    }

    public function enlace(): string
    {
        return route('publico.pagina', $this->slug);
    }

    public function portadaUrl(): ?string
    {
        return filled($this->portada_path) ? asset('storage/'.$this->portada_path) : null;
    }

    /**
     * Los bloques que de verdad se pintan.
     *
     * Filtra dos cosas: los tipos que ya no existen en el código, y los
     * bloques vacíos —el editor deja añadir uno y no rellenarlo, y un hueco
     * en blanco a mitad de página parece un fallo del sitio—.
     *
     * @return list<array{tipo: string, datos: array}>
     */
    public function bloquesVisibles(): array
    {
        return collect($this->bloques ?? [])
            ->filter(fn ($b) => is_array($b) && isset(self::BLOQUES[$b['type'] ?? '']))
            ->map(fn (array $b) => ['tipo' => $b['type'], 'datos' => $b['data'] ?? []])
            ->filter(fn (array $b) => self::tieneAlgoQueEnsenar($b['tipo'], $b['datos']))
            ->values()
            ->all();
    }

    /**
     * Los bloques listos para pintar, con el banco de contenido consultado.
     *
     * Las fotos que vinieron del banco (§21) se copiaron al disco público, y
     * una copia no se entera de nada. Aquí se pregunta por las que se hayan
     * retirado desde entonces —sale alguien que no quiere aparecer, se subió
     * por error— y se quitan de la galería.
     *
     * Retirar un aporte del banco tiene que bastar: si además hubiera que
     * acordarse de venir a editar las páginas donde salga, no se haría, y la
     * foto que alguien pidió quitar seguiría publicada.
     *
     * @return list<array{tipo: string, datos: array}>
     */
    public function bloquesParaMostrar(): array
    {
        $bloques = $this->bloquesVisibles();

        $ids = collect($bloques)
            ->where('tipo', 'galeria')
            ->flatMap(fn (array $b) => collect($b['datos']['imagenes'] ?? [])->pluck('contenido_id'))
            ->filter()
            ->unique();

        if ($ids->isEmpty()) {
            return $bloques;
        }

        $retirados = Contenido::query()
            ->whereIn('id', $ids)
            ->whereNotNull('withdrawn_at')
            ->pluck('id')
            ->all();

        if ($retirados === []) {
            return $bloques;
        }

        return collect($bloques)
            ->map(function (array $b) use ($retirados) {
                if ($b['tipo'] !== 'galeria') {
                    return $b;
                }

                $b['datos']['imagenes'] = collect($b['datos']['imagenes'] ?? [])
                    ->reject(fn (array $i) => in_array($i['contenido_id'] ?? null, $retirados, true))
                    ->values()
                    ->all();

                return $b;
            })
            // Una galeria que se queda sin fotos desaparece entera: un titulo
            // con un hueco debajo parece un fallo del sitio.
            ->reject(fn (array $b) => $b['tipo'] === 'galeria' && $b['datos']['imagenes'] === [])
            ->values()
            ->all();
    }

    private static function tieneAlgoQueEnsenar(string $tipo, array $datos): bool
    {
        return match ($tipo) {
            'texto' => filled($datos['cuerpo'] ?? null) || filled($datos['titulo'] ?? null),
            'imagen' => filled($datos['imagen'] ?? null),
            'video' => filled($datos['video'] ?? null),
            'galeria' => filled($datos['imagenes'] ?? null),
            'cifras' => filled($datos['cifras'] ?? null),
            'datos' => filled($datos['filas'] ?? null),
            'hitos' => filled($datos['hitos'] ?? null),
            'botones' => filled($datos['botones'] ?? null),
            default => false,
        };
    }

    /**
     * Una dirección libre a partir de un título.
     *
     * Dos proyectos pueden llamarse igual —«Señalética», cada semestre— y el
     * slug es único en la tabla. Se numera en vez de fallar: quien está
     * creando la página no tiene por qué saber que existió otra hace un año, y
     * después puede cambiarla a mano.
     */
    public static function slugLibre(string $desde, ?int $exceptoId = null): string
    {
        $base = Str::slug($desde) ?: 'pagina';
        $slug = $base;
        $n = 2;

        while (static::query()
            ->where('slug', $slug)
            ->when($exceptoId, fn ($q) => $q->whereKeyNot($exceptoId))
            ->exists()
        ) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
