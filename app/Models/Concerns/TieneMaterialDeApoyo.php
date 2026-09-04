<?php

namespace App\Models\Concerns;

/**
 * Lo que acompaña una pantalla de teoria o una pregunta: una foto, un video
 * subido, o un video de YouTube o Vimeo (§9).
 *
 * El modelo guarda dos cosas -`media_path`, el fichero en el disco publico, y
 * `video_url`, el enlace- y esto las convierte en lo que la vista necesita
 * saber: que hay, y de donde se carga. Las vistas no deberian adivinar por la
 * extension si un fichero es foto o video.
 */
trait TieneMaterialDeApoyo
{
    /**
     * El material, listo para la vista, o null si no hay.
     *
     * @return array{tipo:'imagen'|'video'|'embed'|'enlace',url:string}|null
     */
    public function material(): ?array
    {
        if (filled($this->media_path)) {
            return [
                'tipo' => $this->materialEsVideo() ? 'video' : 'imagen',
                'url'  => asset('storage/' . $this->media_path),
            ];
        }

        if (filled($this->video_url)) {
            $embed = self::embedDe($this->video_url);

            return $embed
                ? ['tipo' => 'embed', 'url' => $embed]
                : ['tipo' => 'enlace', 'url' => $this->video_url];
        }

        return null;
    }

    public function tieneMaterial(): bool
    {
        return $this->material() !== null;
    }

    public function materialEsVideo(): bool
    {
        return in_array(strtolower(pathinfo((string) $this->media_path, PATHINFO_EXTENSION)), ['mp4', 'webm', 'mov', 'm4v'], true);
    }

    /**
     * De un enlace de YouTube o Vimeo, la direccion que se puede incrustar.
     *
     * Se acepta lo que la gente pega: `watch?v=`, `youtu.be/`, `shorts/`,
     * `vimeo.com/123`. Cualquier otra cosa no se incrusta -no se sabe que
     * es- y se enseña como enlace.
     */
    public static function embedDe(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        if (preg_match('~(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([A-Za-z0-9_-]{6,})~', $url, $m)) {
            return 'https://www.youtube-nocookie.com/embed/' . $m[1];
        }

        if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) {
            return 'https://player.vimeo.com/video/' . $m[1];
        }

        return null;
    }
}
