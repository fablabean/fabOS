<?php

namespace App\Http\Controllers;

use App\Filament\Pages\Parlante;
use App\Support\SpotifyDelParlante;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Conectar la cuenta de Spotify del laboratorio, y darle al Parlante su
 * acceso (§3). Solo quien puede usar el Parlante.
 */
class SpotifyDelParlanteController extends Controller
{
    public function conectar(Request $request)
    {
        abort_unless(Parlante::canAccess(), 403);

        if (! SpotifyDelParlante::configurada()) {
            return redirect(Parlante::getUrl())->with('spotify_aviso', 'Antes de conectar, pega el identificador y el secreto de la app de Spotify en «Música».');
        }

        // Para comprobar a la vuelta que la respuesta es de este pedido y no
        // de un enlace que alguien armó.
        $estado = Str::random(40);
        $request->session()->put('spotify_estado', $estado);

        return redirect()->away(SpotifyDelParlante::urlDeAutorizacion($estado));
    }

    public function vuelta(Request $request)
    {
        abort_unless(Parlante::canAccess(), 403);

        $esperado = $request->session()->pull('spotify_estado');

        if (! $esperado || ! hash_equals($esperado, (string) $request->query('state'))) {
            return redirect(Parlante::getUrl())->with('spotify_aviso', 'La respuesta de Spotify no corresponde a este pedido. Vuelve a pulsar «Conectar Spotify».');
        }

        if ($request->query('error')) {
            return redirect(Parlante::getUrl())->with('spotify_aviso', 'No se conectó: ' . ($request->query('error') === 'access_denied' ? 'se canceló en Spotify.' : $request->query('error') . '.'));
        }

        try {
            SpotifyDelParlante::canjear((string) $request->query('code'));
        } catch (\Throwable $e) {
            return redirect(Parlante::getUrl())->with('spotify_aviso', $e->getMessage());
        }

        return redirect(Parlante::getUrl())->with('spotify_aviso', 'Spotify conectado: ' . SpotifyDelParlante::cuenta() . '. Recarga y enciende el parlante.');
    }

    /** El acceso del momento, para el reproductor web de Spotify. */
    public function acceso()
    {
        abort_unless(Parlante::canAccess(), 403);

        try {
            return response()->json(['token' => SpotifyDelParlante::acceso()])->header('Cache-Control', 'no-store');
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 409)->header('Cache-Control', 'no-store');
        }
    }
}
