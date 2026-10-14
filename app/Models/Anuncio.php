<?php

namespace App\Models;

use App\Casts\UtcDateTime;
use App\Jobs\GenerarAudioDelAnuncio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Un anuncio con voz (§3).
 *
 * Se escribe en el panel y se oye en la portada. La voz la pone el servidor de
 * texto a voz del FabLab, y el audio se guarda: generar tarda la mitad de lo
 * que dura el audio y el servidor atiende de uno en uno, así que pedirlo en
 * cada visita sería hacer esperar a todos por algo que no ha cambiado.
 *
 * Tres cosas que están en los campos y conviene no perder:
 *
 *  · **La huella.** `audio_de` dice con qué texto y qué voz se generó el audio
 *    guardado. Corregir una tilde cambia la huella y pide audio nuevo; tocar
 *    solo las fechas o el orden, no.
 *  · **Se genera en la cola.** Un audio largo tarda minutos, más que lo que
 *    Cloudflare espera una respuesta. Guardar el anuncio es inmediato; el audio
 *    llega después, y el panel dice en qué va.
 *  · **Sin audio al día, no sale.** Un anuncio cuyo audio todavía dice el
 *    texto de antes no se enseña con ese audio: se oiría una cosa y se leería
 *    otra.
 */
class Anuncio extends Model
{
    protected $table = 'anuncios';

    protected $fillable = [
        'position', 'is_active', 'favorito',
        'titulo', 'texto', 'voz',
        'starts_at', 'ends_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'favorito' => 'boolean',
            'position' => 'integer',
            'starts_at' => UtcDateTime::class,
            'ends_at' => UtcDateTime::class,
            'audio_generado_el' => UtcDateTime::class,
        ];
    }

    /** Las que tiene el servidor de voz. */
    public const VOCES = [
        'mujer' => 'Voz de mujer',
        'hombre' => 'Voz de hombre',
    ];

    /** Lo más largo que el servidor convierte en un solo audio. */
    public const MAX_CARACTERES = 5000;

    public const ESTADOS = [
        'pendiente' => 'En cola',
        'generando' => 'Generándose',
        'listo' => 'Listo',
        'fallo' => 'No se pudo generar',
    ];

    /**
     * Si cambió lo que se oye, se pide audio nuevo.
     *
     * En `saving` se marca y en `saved` se encola: el trabajo tiene que
     * encontrar el anuncio ya guardado, y `afterCommit` evita que lo busque
     * antes de que la transacción del panel termine.
     */
    protected static function booted(): void
    {
        static::saving(function (self $anuncio) {
            if ($anuncio->isDirty(['texto', 'voz']) && $anuncio->necesitaAudio()) {
                $anuncio->audio_estado = 'pendiente';
                $anuncio->audio_error = null;
            }
        });

        static::saved(function (self $anuncio) {
            if ($anuncio->wasChanged(['texto', 'voz']) || $anuncio->wasRecentlyCreated) {
                $anuncio->pedirAudio();
            }
        });

        // El audio no le sirve a nadie más: se va con el anuncio.
        static::deleted(function (self $anuncio) {
            if (filled($anuncio->audio_path)) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($anuncio->audio_path);
            }
        });
    }

    /**
     * Encola la generación, si hace falta.
     *
     * Es también lo que hace «Volver a generar» tras un fallo: el servidor de
     * voz puede estar apagado un rato, y el anuncio no tiene que reescribirse
     * para intentarlo otra vez.
     */
    public function pedirAudio(): void
    {
        if (! $this->necesitaAudio()) {
            return;
        }

        if ($this->audio_estado !== 'pendiente') {
            $this->forceFill(['audio_estado' => 'pendiente', 'audio_error' => null])->saveQuietly();
        }

        GenerarAudioDelAnuncio::dispatch($this)->afterCommit();
    }

    /**
     * Lo que identifica al audio: el texto y la voz, nada más.
     *
     * El texto se compara tal cual —sin recortar ni normalizar— porque eso es
     * lo que se le manda al servidor: si dos textos dan la misma huella, tienen
     * que dar el mismo audio.
     */
    public function huella(): string
    {
        return hash('sha256', $this->voz . "\n" . $this->texto);
    }

    public function necesitaAudio(): bool
    {
        return $this->audio_de !== $this->huella();
    }

    public function tieneAudioAlDia(): bool
    {
        return filled($this->audio_path) && ! $this->necesitaAudio();
    }

    public function audioUrl(): ?string
    {
        return $this->tieneAudioAlDia() ? asset('storage/' . $this->audio_path) : null;
    }

    /** Encendido y en fecha, como las láminas del banner. */
    public function scopeVigente(Builder $q): Builder
    {
        $ahora = now();

        return $q->where('is_active', true)
            ->where(fn ($s) => $s->whereNull('starts_at')->orWhere('starts_at', '<=', $ahora))
            ->where(fn ($s) => $s->whereNull('ends_at')->orWhere('ends_at', '>=', $ahora));
    }

    /**
     * Los que se oyen en la portada: vigentes y con su audio al día.
     *
     * @return Collection<int,static>
     */
    public static function paraLaPortada(): Collection
    {
        return static::query()->vigente()->orderBy('position')->orderBy('id')->get()
            ->filter(fn (self $a) => $a->tieneAudioAlDia())
            ->values();
    }

    /**
     * Los de la barra de arriba del panel.
     *
     * No miran la vigencia ni si salen en la portada: «cerramos en diez
     * minutos» no se publica en el sitio, se lanza por los parlantes. Sí hace
     * falta el audio: un botón que no suena no sirve de acceso directo.
     *
     * @return Collection<int,static>
     */
    public static function favoritos(): Collection
    {
        return static::query()->where('favorito', true)->orderBy('position')->orderBy('id')->get()
            ->filter(fn (self $a) => $a->tieneAudioAlDia())
            ->values();
    }
}
