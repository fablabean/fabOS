<?php

namespace App\Services\Analitica;

use App\Models\User;
use App\Support\Buscadores;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * La analítica propia del sitio (§20).
 *
 * Tres reglas que no se negocian:
 *
 *  1. **Nada que identifique a nadie.** Ni cookies, ni IP, ni el correo de
 *     quien tiene sesión. El visitante es una huella con una sal diaria que se
 *     olvida: vale para contar y para seguir un recorrido dentro del día.
 *  2. **La analítica nunca rompe el sitio.** Si falla el registro, se anota en
 *     el log y la página sigue; nadie se queda sin inscribirse porque una
 *     tabla de estadísticas estaba bloqueada.
 *  3. **Las conversiones las anota el servidor.** Que alguien se inscribió lo
 *     sabe el servidor al guardar la inscripción, no el navegador al pulsar un
 *     botón: así no se pueden inflar, y no se pierden por un bloqueador.
 */
class Analitica
{
    /** Los eventos que el navegador puede anotar. Los demás, solo el servidor. */
    public const EVENTOS_DEL_CLIENTE = ['formulario'];

    public const CONVERSIONES = [
        'inscripcion'   => 'Inscripción a una actividad',
        'lista_espera'  => 'Lista de espera de una actividad',
        'preinscripcion' => 'Preinscripción (Fab Academy y programas)',
        'solicitud_proyecto' => 'Solicitud de proyecto',
        'postulacion_practica' => 'Postulación a práctica',
        'aporte_alianza' => 'Aporte a una alianza',
    ];

    /** Dominio → fuente, por canal. Se compara por el final del dominio. */
    public const FUENTES = [
        'ia' => [
            'chatgpt.com' => 'ChatGPT', 'chat.openai.com' => 'ChatGPT', 'openai.com' => 'ChatGPT',
            'perplexity.ai' => 'Perplexity', 'claude.ai' => 'Claude', 'gemini.google.com' => 'Gemini',
            'bard.google.com' => 'Gemini', 'copilot.microsoft.com' => 'Copilot', 'you.com' => 'You.com',
            'phind.com' => 'Phind', 'meta.ai' => 'Meta AI', 'deepseek.com' => 'DeepSeek', 'grok.com' => 'Grok',
            'mistral.ai' => 'Mistral',
        ],
        'buscador' => [
            'google.' => 'Google', 'bing.com' => 'Bing', 'duckduckgo.com' => 'DuckDuckGo', 'search.yahoo.com' => 'Yahoo',
            'ecosia.org' => 'Ecosia', 'yandex.' => 'Yandex', 'baidu.com' => 'Baidu', 'search.brave.com' => 'Brave',
        ],
        'redes' => [
            'instagram.com' => 'Instagram', 'facebook.com' => 'Facebook', 'fb.me' => 'Facebook', 'm.facebook.com' => 'Facebook',
            'lm.facebook.com' => 'Facebook', 'l.facebook.com' => 'Facebook', 'linkedin.com' => 'LinkedIn', 'lnkd.in' => 'LinkedIn',
            't.co' => 'X', 'x.com' => 'X', 'twitter.com' => 'X', 'whatsapp.com' => 'WhatsApp', 'wa.me' => 'WhatsApp',
            'tiktok.com' => 'TikTok', 'youtube.com' => 'YouTube', 'youtu.be' => 'YouTube', 'pinterest.' => 'Pinterest',
            'reddit.com' => 'Reddit', 'threads.net' => 'Threads', 'telegram.org' => 'Telegram', 't.me' => 'Telegram',
        ],
    ];

    /** User agent → rastreador, por familia. */
    public const RASTREADORES = [
        'ia' => [
            'GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-SearchBot', 'Claude-User', 'Claude-Web', 'anthropic-ai',
            'PerplexityBot', 'Perplexity-User', 'Google-Extended', 'Applebot-Extended', 'Meta-ExternalAgent', 'Meta-ExternalFetcher',
            'CCBot', 'Bytespider', 'Amazonbot', 'cohere-ai', 'DuckAssistBot', 'MistralAI-User', 'YouBot',
        ],
        'buscador' => ['Googlebot', 'bingbot', 'DuckDuckBot', 'YandexBot', 'Baiduspider', 'Applebot', 'Slurp', 'SeznamBot', 'Qwantbot'],
        'redes' => ['facebookexternalhit', 'WhatsApp', 'Twitterbot', 'LinkedInBot', 'TelegramBot', 'Slackbot', 'Discordbot', 'Pinterestbot'],
    ];

    // ============================================================ quién es

    /** La huella del visitante de hoy: 16 caracteres que no vuelven a la IP. */
    public function visitante(Request $request): string
    {
        return substr(hash('sha256', $this->sal() . '|' . $request->ip() . '|' . $request->userAgent() . '|' . $request->getHost()), 0, 16);
    }

    /**
     * La sal de hoy. Se crea al azar y vive dos días en la caché: pasado
     * mañana ya no existe, y sin ella la huella de hoy no se puede rehacer.
     */
    private function sal(): string
    {
        $dia = now(config('fabos.lab.timezone'))->format('Y-m-d');

        return Cache::remember('analitica.sal.' . $dia, now()->addDays(2), fn () => Str::random(40));
    }

    /** Si esta petición no se cuenta: un robot, o alguien del equipo. */
    public function seExcluye(Request $request): bool
    {
        if (! Buscadores::analiticaActiva() || $this->rastreadorDe($request->userAgent()) !== null || $this->pareceRobot($request->userAgent())) {
            return true;
        }

        $quien = $request->user();

        return $quien instanceof User
            && ! Buscadores::contarEquipo()
            && $quien->hasAnyRole([...User::rolesDelEquipo(), User::ROL_COMUNICACIONES]);
    }

    // ============================================================ registrar

    /**
     * Una página vista, con lo que mandó el navegador.
     *
     * @param  array{ruta:string, referente?:?string, busqueda?:?string, ancho?:?int, nombre?:?string}  $datos
     */
    public function visita(Request $request, array $datos): void
    {
        if ($this->seExcluye($request)) {
            return;
        }

        $this->seguro(function () use ($request, $datos) {
            $ruta = $this->ruta($datos['ruta']);

            if ($ruta === null) {
                return;
            }

            parse_str(ltrim((string) ($datos['busqueda'] ?? ''), '?'), $utm);
            $referente = $this->dominio($datos['referente'] ?? null);
            $propio = $referente !== null && $referente === $request->getHost();
            [$fuente, $canal] = $this->fuente($propio ? null : $referente, $utm);

            DB::table('analitica_visitas')->insert([
                'dia'          => now(config('fabos.lab.timezone'))->toDateString(),
                'visitante'    => $this->visitante($request),
                'ruta'         => $ruta,
                'ruta_nombre'  => Str::limit((string) ($datos['nombre'] ?? ''), 80, '') ?: null,
                'desde'        => $propio ? $this->ruta(parse_url((string) $datos['referente'], PHP_URL_PATH) ?: '/') : null,
                'referente'    => $propio ? null : $referente,
                'fuente'       => $propio ? 'interno' : $fuente,
                'canal'        => $propio ? 'interno' : $canal,
                'utm_source'   => $this->corto($utm['utm_source'] ?? null, 80),
                'utm_medium'   => $this->corto($utm['utm_medium'] ?? null, 80),
                'utm_campaign' => $this->corto($utm['utm_campaign'] ?? null, 120),
                'dispositivo'  => $this->dispositivo($request->userAgent(), $datos['ancho'] ?? null),
                'con_sesion'   => $request->user() !== null,
                'created_at'   => now(),
            ]);
        });
    }

    /**
     * Algo que pasó: una conversión que anota el servidor, o un evento que
     * manda el navegador. La huella es la del visitante que lo hizo, así que
     * se une a su recorrido del día.
     */
    public function evento(string $tipo, ?Model $referencia = null, array $detalle = [], string $origen = 'servidor', ?string $ruta = null, ?Request $request = null): void
    {
        $request ??= request();

        if ($this->seExcluye($request)) {
            return;
        }

        $this->seguro(function () use ($tipo, $referencia, $detalle, $origen, $ruta, $request) {
            DB::table('analitica_eventos')->insert([
                'dia'             => now(config('fabos.lab.timezone'))->toDateString(),
                'visitante'       => $this->visitante($request),
                'tipo'            => $tipo,
                'ruta'            => $this->ruta($ruta ?? $this->paginaDeOrigen($request)),
                'origen'          => $origen,
                'referencia_type' => $referencia?->getMorphClass(),
                'referencia_id'   => $referencia?->getKey(),
                'detalle'         => $detalle ? json_encode($detalle, JSON_UNESCAPED_UNICODE) : null,
                'created_at'      => now(),
            ]);
        });
    }

    /** Un rastreador que pidió una página. Lo llama el middleware al terminar. */
    public function rastreo(Request $request, int $estado): void
    {
        $bot = $this->rastreadorDe($request->userAgent());

        if ($bot === null) {
            return;
        }

        $this->seguro(fn () => DB::table('analitica_rastreos')->insert([
            'dia'        => now(config('fabos.lab.timezone'))->toDateString(),
            'bot'        => $bot['nombre'],
            'familia'    => $bot['familia'],
            'ruta'       => $this->ruta('/' . ltrim($request->path(), '/')) ?? '/',
            'estado'     => $estado,
            'created_at' => now(),
        ]));
    }

    // ============================================================ clasificar

    /**
     * De dónde viene: fuente legible y canal.
     *
     * Las utm mandan sobre el dominio: un enlace de una campaña de correo
     * llega sin referente y es campaña, no «directo». Y ChatGPT marca sus
     * enlaces con utm_source=chatgpt.com: esos son IA, no campaña.
     *
     * @param  array<string,mixed>  $utm
     * @return array{0:string, 1:string}
     */
    public function fuente(?string $dominio, array $utm = []): array
    {
        $origen = mb_strtolower(trim((string) ($utm['utm_source'] ?? '')));

        if ($origen !== '') {
            foreach (self::FUENTES as $canal => $dominios) {
                foreach ($dominios as $d => $nombre) {
                    if (str_contains($origen, rtrim($d, '.'))) {
                        return [$nombre, $canal];
                    }
                }
            }

            return [Str::limit($origen, 40, ''), 'campaña'];
        }

        if ($dominio === null) {
            return ['Directo', 'directo'];
        }

        foreach (self::FUENTES as $canal => $dominios) {
            foreach ($dominios as $d => $nombre) {
                $coincide = str_ends_with($d, '.')
                    ? str_contains($dominio . '.', $d) || str_starts_with($dominio, rtrim($d, '.'))
                    : ($dominio === $d || str_ends_with($dominio, '.' . $d));

                if ($coincide) {
                    return [$nombre, $canal];
                }
            }
        }

        return [Str::limit(preg_replace('/^www\./', '', $dominio), 40, ''), 'enlace'];
    }

    /** @return array{nombre:string, familia:string}|null */
    public function rastreadorDe(?string $agente): ?array
    {
        $agente = (string) $agente;

        if ($agente === '') {
            return null;
        }

        foreach (self::RASTREADORES as $familia => $nombres) {
            foreach ($nombres as $nombre) {
                if (stripos($agente, $nombre) !== false) {
                    return ['nombre' => $nombre, 'familia' => $familia];
                }
            }
        }

        return $this->pareceRobot($agente) ? ['nombre' => 'Otro', 'familia' => 'otro'] : null;
    }

    private function pareceRobot(?string $agente): bool
    {
        $agente = (string) $agente;

        return $agente === '' || preg_match('/bot|crawl|spider|slurp|fetch|scrape|headless|lighthouse|monitor|curl|wget|python-requests|httpclient|preview/i', $agente) === 1;
    }

    private function dispositivo(?string $agente, mixed $ancho): string
    {
        $agente = (string) $agente;

        if (preg_match('/iPad|Tablet|Android(?!.*Mobile)/i', $agente)) {
            return 'tableta';
        }

        if (preg_match('/Mobi|iPhone|Android/i', $agente) || (is_numeric($ancho) && (int) $ancho < 700)) {
            return 'movil';
        }

        return 'escritorio';
    }

    // ============================================================ por dentro

    /**
     * La ruta, sin consulta ni fragmento y recortada. Las del panel y las que
     * llevan token no se guardan: no son el sitio público.
     */
    private function ruta(?string $ruta): ?string
    {
        $ruta = '/' . ltrim((string) parse_url((string) $ruta, PHP_URL_PATH), '/');

        foreach (['/admin', '/livewire', '/panel/', '/a/'] as $fuera) {
            if (str_starts_with($ruta, $fuera)) {
                return null;
            }
        }

        return Str::limit($ruta, 300, '');
    }

    private function dominio(?string $url): ?string
    {
        $host = parse_url((string) $url, PHP_URL_HOST);

        return $host ? mb_strtolower($host) : null;
    }

    /** La página desde la que se envió un formulario: la que el visitante estaba viendo. */
    private function paginaDeOrigen(Request $request): string
    {
        $referente = (string) $request->headers->get('referer');

        return $referente !== '' && $this->dominio($referente) === $request->getHost()
            ? (parse_url($referente, PHP_URL_PATH) ?: '/')
            : '/' . ltrim($request->path(), '/');
    }

    private function corto(mixed $valor, int $largo): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : Str::limit($valor, $largo, '');
    }

    private function seguro(callable $trabajo): void
    {
        try {
            $trabajo();
        } catch (\Throwable $e) {
            Log::warning('fabOS: la analítica no pudo registrar: ' . $e->getMessage());
        }
    }
}
