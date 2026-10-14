<?php

namespace App\Jobs;

use App\Models\Anuncio;
use App\Services\Ia\TextoAVoz;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pide al servidor de voz el audio de un anuncio y lo guarda (§3).
 *
 * En la cola porque tarda: la mitad de lo que dura el audio, más lo que tarde
 * en terminar quien esté generando antes —el servidor atiende de uno en uno y
 * lo comparte el laboratorio entero—.
 *
 * Antes de pedir, mira si todavía hace falta. Entre que se encoló y que le toca
 * pueden pasar minutos, y en ese rato el anuncio puede haberse corregido otra
 * vez —con su propio trabajo detrás— o haberse borrado.
 */
class GenerarAudioDelAnuncio implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Cinco minutos de espera al servidor, más margen para guardar.
     *
     * `retry_after` de la cola tiene que ser mayor que esto: si no, la cola da
     * el trabajo por perdido mientras sigue esperando y lo lanza otra vez, y el
     * servidor de voz recibe dos veces lo mismo.
     */
    public int $timeout = 330;

    public int $tries = 3;

    /** Si el servidor estaba ocupado o apagado, se le da un rato. */
    public array $backoff = [60, 300];

    /** Si el anuncio se borró mientras esperaba, no hay nada que hacer. */
    public bool $deleteWhenMissingModels = true;

    public function __construct(public Anuncio $anuncio) {}

    /**
     * Uno a la vez por anuncio: dos correcciones seguidas no deben pedir dos
     * audios en paralelo a un servidor que de todas formas los hace en fila.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping((string) $this->anuncio->id))->releaseAfter(30)->expireAfter($this->timeout)];
    }

    public function handle(TextoAVoz $voz): void
    {
        $anuncio = $this->anuncio->fresh();

        if ($anuncio === null) {
            return;
        }

        if (! $anuncio->necesitaAudio()) {
            if ($anuncio->audio_estado !== 'listo' && filled($anuncio->audio_path)) {
                $anuncio->forceFill(['audio_estado' => 'listo', 'audio_error' => null])->saveQuietly();
            }

            return;
        }

        // La huella de lo que se va a pedir, tomada ANTES de pedirlo: si el
        // texto cambia mientras el servidor trabaja, este audio queda marcado
        // como de la versión vieja y no se enseña.
        $huella = $anuncio->huella();

        $anuncio->forceFill(['audio_estado' => 'generando'])->saveQuietly();

        $mp3 = $voz->mp3($anuncio->texto, $anuncio->voz);

        $ruta = 'anuncios/' . $anuncio->id . '-' . Str::random(8) . '.mp3';
        Storage::disk('public')->put($ruta, $mp3);

        $anterior = $anuncio->audio_path;

        // Se vuelve a leer: alguien pudo editarlo mientras se generaba, y lo
        // que se guarda aquí son solo las columnas del audio.
        $anuncio->refresh()->forceFill([
            'audio_path' => $ruta,
            'audio_de' => $huella,
            'audio_estado' => $anuncio->huella() === $huella ? 'listo' : 'pendiente',
            'audio_error' => null,
            'audio_generado_el' => now(),
        ])->saveQuietly();

        // El de antes ya no dice lo que dice el anuncio. Se borra al final,
        // cuando el nuevo ya está guardado: borrarlo antes deja la portada sin
        // audio si guardar el nuevo falla.
        if (filled($anterior) && $anterior !== $ruta) {
            Storage::disk('public')->delete($anterior);
        }
    }

    /**
     * Tras el último intento, el motivo queda en el anuncio.
     *
     * Quien escribió el anuncio no va a mirar el registro del sistema: lo que
     * no se diga en el panel, para esa persona no pasó.
     */
    public function failed(?Throwable $e): void
    {
        Log::warning('No se pudo generar el audio de un anuncio', [
            'anuncio' => $this->anuncio->id,
            'error' => $e?->getMessage(),
        ]);

        $this->anuncio->fresh()?->forceFill([
            'audio_estado' => 'fallo',
            'audio_error' => Str::limit((string) $e?->getMessage(), 250),
        ])->saveQuietly();
    }
}
