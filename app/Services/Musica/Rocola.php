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
