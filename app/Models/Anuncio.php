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
        'titulo', 'texto', 'voz', 'sonido_previo',
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

    /**
     * El tono que suena antes de la voz (§3).
     *
     * Una voz que arranca de golpe se pierde las primeras palabras: la gente
     * todavía no está escuchando. Los sonidos están en `public/sonidos/anuncios`
     * y son del laboratorio —se generaron, no se descargaron—, así que no hay
     * derechos que pagar.
     */
    public const SONIDOS = [
        'aeropuerto' => 'Aeropuerto',
        'noticia'    => 'Noticiero',
        'timbre'     => 'Timbre',
        'ninguno'    => 'Sin sonido',
    ];

    public static function urlDelSonido(?string $sonido): ?string
    {
        return $sonido && $sonido !== 'ninguno' && isset(self::SONIDOS[$sonido])
            ? asset('sonidos/anuncios/' . $sonido . '.mp3')
            : null;
    }

    public function sonidoPrevioUrl(): ?string
    {
        return self::urlDelSonido($this->sonido_previo);
    }

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

    public function programaciones(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(AnuncioProgramacion::class)->orderBy('hora');
    }

    /**
     * Lo que tiene que sonar entre dos momentos, en orden (§3).
     *
     * Para la pantalla «Parlante», que se deja abierta en el equipo de los
     * parlantes. Cada vez sale con su instante exacto en milisegundos —no con
     * «17:50»—: así el navegador no tiene que saber en qué zona horaria está,
     * y un equipo con el reloj en otra zona suena igual a la hora de Bogotá.
     *
     * Cuenta la vigencia —un anuncio de la feria deja de sonar cuando la feria
     * pasa— pero no si sale en la portada: lo que se dice por los parlantes no
     * tiene por qué publicarse en el sitio. Sin audio al día no hay nada que
     * reproducir, y no sale.
     *
     * @return array<int,array{clave:string,anuncio:int,titulo:string,url:string,t:int,hora:string,hoy:bool,repeticiones:int,pausa:int}>
     */
    public static function agenda(\Carbon\CarbonInterface $desde, \Carbon\CarbonInterface $hasta): array
    {
        $tz = config('fabos.lab.timezone');
        $eventos = [];

        $anuncios = static::query()->has('programaciones')->with('programaciones')->get()
            ->filter(fn (self $a) => $a->tieneAudioAlDia());

        for ($dia = $desde->copy()->timezone($tz)->startOfDay(); $dia->lte($hasta); $dia = $dia->addDay()) {
            foreach ($anuncios as $anuncio) {
                foreach ($anuncio->programaciones as $p) {
                    if (! in_array($dia->isoWeekday(), array_map('intval', $p->dias ?? []), true)) {
                        continue;
                    }

                    [$h, $m] = array_map('intval', explode(':', $p->horaCorta()));
                    $momento = $dia->copy()->setTime($h, $m);

                    if ($momento->lt($desde) || $momento->gt($hasta)) {
                        continue;
                    }

                    if (($anuncio->starts_at && $momento->lt($anuncio->starts_at))
                        || ($anuncio->ends_at && $momento->gt($anuncio->ends_at))) {
                        continue;
                    }

                    $eventos[] = [
                        // Lo que identifica ESTE sonar: el mismo horario otro
                        // día es otra clave. El navegador la recuerda para no
                        // repetirlo si se recarga la pantalla.
                        'clave' => $p->id . '@' . $momento->timestamp,
                        'anuncio' => $anuncio->id,
                        'titulo' => $anuncio->titulo,
                        'url' => $anuncio->audioUrl(),
                        'previo' => $anuncio->sonidoPrevioUrl(),
                        't' => $momento->getTimestampMs(),
                        'hora' => $momento->format('H:i'),
                        'hoy' => $momento->isSameDay(now($tz)),
                        'repeticiones' => max(1, $p->repeticiones),
                        'pausa' => max(0, $p->pausa),
                    ];
                }
            }
        }

        usort($eventos, fn ($a, $b) => $a['t'] <=> $b['t']);

        return $eventos;
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
