<?php

namespace App\Services\Musica;

use App\Models\CancionPedida;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\Ledger\LedgerException;
use App\Services\Ledger\LedgerService;
use App\Support\MusicaDelParlante;
use App\Support\SpotifyDelParlante;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * La rocola del Parlante: cualquiera con FabCoins pone una canción (§3).
 *
 * Se busca en Spotify con la cuenta del laboratorio —quien pide no necesita
 * Spotify—, se cobra un FabCoin y la canción entra a la cola del Parlante.
 * Tres reglas:
 *
 *  · **Nada explícito.** Es un espacio de la universidad, con visitantes y
 *    cursos con menores: lo que Spotify marca como explícito ni se muestra,
 *    y si alguien lo pide por su identificador, tampoco entra.
 *  · **Se cobra antes, se devuelve si no entra.** Si Spotify no la acepta
 *    —el parlante está apagado, se cayó la conexión—, el FabCoin vuelve.
 *  · **Solo con Spotify sonando.** La cola es del dispositivo Parlante; sin
 *    él no hay dónde ponerla.
 */
class Rocola
{
    /** Lo más que Spotify entrega en una página de búsqueda. */
    private const POR_PAGINA = 10;

    public function __construct(private LedgerService $libro) {}

    /** Lo que cuesta poner una canción, en unidades menores: 1 FabCoin. */
    public static function precioMenor(): int
    {
        return (int) config('fabos.currency.minor_units');
    }

    /** Si hay rocola: Spotify elegido y conectado. */
    public static function abierta(): bool
    {
        return MusicaDelParlante::fuente() === 'spotify' && SpotifyDelParlante::conectada();
    }

    /**
     * Canciones de Spotify que coinciden, sin las explícitas.
     *
     * @return array<int, array{id:string, titulo:string, artista:string, portada:?string, duracion_ms:int}>
     */
    public function buscar(string $texto): array
    {
        $texto = trim($texto);

        if (mb_strlen($texto) < 2) {
            return [];
        }

        // Spotify entrega como mucho 10 por página ('Invalid limit' con más).
        // Si al quitar las explícitas quedan pocas, se pide la siguiente.
        $limpias = collect();

        foreach ([0, 10] as $desde) {
            $r = $this->spotify('get', '/search', [
                'q' => mb_substr($texto, 0, 100), 'type' => 'track', 'limit' => self::POR_PAGINA,
                'offset' => $desde, 'market' => 'from_token',
            ]);

            $pagina = collect($r->json('tracks.items') ?? []);
            $limpias = $limpias->merge($pagina->reject(fn (array $t) => ! empty($t['explicit'])));

            if ($limpias->count() >= 6 || $pagina->count() < self::POR_PAGINA) {
                break;
            }
        }

        return $limpias
            ->map(fn (array $t) => self::cancion($t))
            ->unique('id')
            ->take(12)
            ->values()
            ->all();
    }

    /**
     * Cobra un FabCoin y pone la canción en la cola del Parlante.
     *
     * @throws RocolaException
     */
    public function pedir(User $quien, string $spotifyId): CancionPedida
    {
        if (! self::abierta()) {
            throw new RocolaException('La rocola está cerrada: el Parlante no está tocando Spotify.');
        }

        if (! preg_match('/^[A-Za-z0-9]{10,40}$/', $spotifyId)) {
            throw new RocolaException('Esa canción no es de Spotify.');
        }

        // Los datos de la canción se piden a Spotify, no se toman del
        // formulario: así no se cuela una explícita cambiando un campo.
        $pista = $this->spotify('get', '/tracks/' . $spotifyId, ['market' => 'from_token'])->json();

        if (empty($pista['id'])) {
            throw new RocolaException('Spotify no encontró esa canción.');
        }

        if (! empty($pista['explicit'])) {
            throw new RocolaException('Esa canción tiene contenido explícito: en el laboratorio no se pone.');
        }

        $datos = self::cancion($pista);
        $precio = self::precioMenor();

        // Primero el cobro, y en firme: si no alcanza, ni se intenta.
        $pedido = DB::transaction(function () use ($quien, $datos, $precio) {
            $pedido = CancionPedida::create([
                'user_id'       => $quien->id,
                'spotify_id'    => $datos['id'],
                'titulo'        => $datos['titulo'],
                'artista'       => $datos['artista'],
                'portada_url'   => $datos['portada'],
                'duracion_ms'   => $datos['duracion_ms'],
                'cobrado_minor' => $precio,
                'estado'        => 'en_cola',
            ]);

            $cuenta = $this->libro->cuentaDe($quien);

            if ($cuenta->saldoMenor() < $precio) {
                throw new RocolaException('No te alcanzan los FabCoins: cada canción cuesta 1 y tienes '
                    . number_format($cuenta->saldoMenor() / config('fabos.currency.minor_units'), 2, ',', '.') . '.');
            }

            try {
                $this->libro->transferir(
                    $cuenta,
                    $this->libro->cuentaDeSistema(LedgerAccount::INGRESO),
                    $precio,
                    'venta',
                    'Rocola: ' . $datos['titulo'],
                    'rocola:' . $pedido->id . ':cobro',
                    $pedido,
                );
            } catch (LedgerException $e) {
                throw new RocolaException($e->getMessage());
            }

            return $pedido;
        });

        // Después, a la cola. Si Spotify no la acepta, el FabCoin vuelve.
        try {
            $r = $this->spotify('post', '/me/player/queue?' . http_build_query(['uri' => 'spotify:track:' . $datos['id']]), lanzar: false);

            if ($r->status() !== 200 && $r->status() !== 204) {
                throw new RocolaException($r->status() === 404
                    ? 'El Parlante no está encendido: no hay dónde poner la canción.'
                    : 'Spotify no aceptó la canción (' . $r->status() . ').');
            }
        } catch (\Throwable $e) {
            $this->devolver($pedido, $e instanceof RocolaException ? $e->getMessage() : 'No se pudo hablar con Spotify.');

            throw $e instanceof RocolaException
                ? new RocolaException($e->getMessage() . ' Te devolvimos el FabCoin.')
                : new RocolaException('No se pudo hablar con Spotify. Te devolvimos el FabCoin.');
        }

        return $pedido;
    }

    /**
     * Lo que suena ahora y lo que viene, con la hora aproximada de cada una.
     *
     * Spotify dice qué suena, cuánto le falta y la cola —primero lo pedido,
     * después la lista—. La hora sale de sumar duraciones desde ahora: es una
     * estimación, porque alguien puede saltar una canción. Las pedidas en la
     * rocola se marcan con quién las pidió, en el orden en que entraron.
     *
     * Se guarda unos segundos: la página y el Parlante preguntan a menudo, y
     * la cola no cambia a ese ritmo.
     *
     * @return array{actual: ?array, siguientes: array<int, array>}|null  nulo si no hay rocola o Spotify no responde
     */
    public function estado(bool $fresco = false): ?array
    {
        if (! self::abierta()) {
            return null;
        }

        if ($fresco) {
            \Illuminate\Support\Facades\Cache::forget(self::ESTADO_EN_CACHE);
        }

        return \Illuminate\Support\Facades\Cache::remember(self::ESTADO_EN_CACHE, 10, function () {
            try {
                $reproductor = $this->spotify('get', '/me/player', lanzar: false);
                $cola = $this->spotify('get', '/me/player/queue', lanzar: false);
            } catch (\Throwable) {
                return null;
            }

            if (! $cola->successful()) {
                return null;
            }

            $ahora = now();
            $actual = $cola->json('currently_playing');
            $progreso = $reproductor->status() === 200 ? (int) $reproductor->json('progress_ms') : 0;
            $sonando = $reproductor->status() === 200 && (bool) $reproductor->json('is_playing');

            // Las pedidas que siguen en espera, de la más vieja a la más
            // nueva: la cola de Spotify las tiene en ese mismo orden.
            $pedidas = CancionPedida::with('user')->where('estado', 'en_cola')
                ->where('created_at', '>=', now()->subHours(6))->orderBy('id')->get();

            $marcar = function (array $pista) use (&$pedidas): array {
                $i = $pedidas->search(fn (CancionPedida $p) => $p->spotify_id === ($pista['id'] ?? null));

                if ($i === false) {
                    return [null, null];
                }

                $p = $pedidas->pull($i);

                return [$p->id, str($p->user?->name)->before(' ')->title()->value()];
            };

            $enCurso = null;
            $cuando = $ahora->copy();

            if (! empty($actual['id'])) {
                $restante = max(0, (int) ($actual['duration_ms'] ?? 0) - $progreso);
                [, $quien] = $marcar($actual);
                $enCurso = self::cancion($actual) + [
                    'progreso_ms' => $progreso,
                    'sonando'     => $sonando,
                    'pidio'       => $quien,
                    'termina'     => $ahora->copy()->addMilliseconds($restante),
                ];
                $cuando = $enCurso['termina']->copy();
            }

            $siguientes = [];

            foreach (array_slice($cola->json('queue') ?? [], 0, 12) as $pista) {
                if (empty($pista['id'])) {
                    continue;
                }

                [$pedidoId, $quien] = $marcar($pista);
                $siguientes[] = self::cancion($pista) + ['empieza' => $cuando->copy(), 'pidio' => $quien, 'pedido_id' => $pedidoId];
                $cuando->addMilliseconds((int) ($pista['duration_ms'] ?? 0));
            }

            return ['actual' => $enCurso, 'siguientes' => $siguientes];
        });
    }

    /** A qué hora, más o menos, suena una canción pedida. Nulo si ya no está en la cola. */
    public function cuandoSuena(CancionPedida $pedido): ?\Carbon\CarbonInterface
    {
        foreach ($this->estado(fresco: true)['siguientes'] ?? [] as $s) {
            if ($s['pedido_id'] === $pedido->id) {
                return $s['empieza'];
            }
        }

        return null;
    }

    private const ESTADO_EN_CACHE = 'rocola.estado';

    private function devolver(CancionPedida $pedido, string $motivo): void
    {
        $this->libro->transferir(
            $this->libro->cuentaDeSistema(LedgerAccount::INGRESO),
            $this->libro->cuentaDe($pedido->user),
            $pedido->cobrado_minor,
            'devolucion',
            'Rocola: no entró ' . $pedido->titulo,
            'rocola:' . $pedido->id . ':devolucion',
            $pedido,
        );

        $pedido->update(['estado' => 'devuelta', 'motivo' => mb_substr($motivo, 0, 300)]);
    }

    /** @return array{id:string, titulo:string, artista:string, portada:?string, duracion_ms:int} */
    private static function cancion(array $t): array
    {
        $imagenes = $t['album']['images'] ?? [];

        return [
            'id'          => (string) $t['id'],
            'titulo'      => (string) ($t['name'] ?? ''),
            'artista'     => collect($t['artists'] ?? [])->pluck('name')->implode(', '),
            // La más pequeña que no sea diminuta: 300 px va bien en la lista.
            'portada'     => collect($imagenes)->sortBy('width')->first(fn ($i) => ($i['width'] ?? 0) >= 64)['url'] ?? ($imagenes[0]['url'] ?? null),
            'duracion_ms' => (int) ($t['duration_ms'] ?? 0),
        ];
    }

    private function spotify(string $metodo, string $ruta, array $datos = [], bool $lanzar = true): Response
    {
        try {
            $acceso = SpotifyDelParlante::acceso();
        } catch (\Throwable $e) {
            throw new RocolaException('La rocola está cerrada: ' . $e->getMessage());
        }

        $r = Http::withToken($acceso)->timeout(15)->{$metodo}('https://api.spotify.com/v1' . $ruta, $datos);

        if ($lanzar && ! $r->successful()) {
            throw new RocolaException('Spotify no respondió (' . $r->status() . '). Intenta en un momento.');
        }

        return $r;
    }
}
