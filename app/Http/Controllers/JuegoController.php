<?php

namespace App\Http\Controllers;

use App\Models\Recorrido\Equipo;
use App\Models\Recorrido\Estacion;
use App\Models\Recorrido\Partida;
use App\Models\User;
use App\Services\Recorrido\Juego;
use App\Services\Recorrido\JuegoException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * El recorrido gamificado, del lado de quien juega.
 *
 * Sin cuentas: los visitantes llegan en grupo y nadie se va a registrar para
 * jugar una hora. El celular del equipo se identifica con el enlace de su
 * equipo, que deja una cookie; desde ahí, cada QR de estación que escanee con
 * la cámara del teléfono sabe de qué equipo viene.
 */
class JuegoController extends Controller
{
    public const COOKIE = 'juego_equipo';

    /** El celular del equipo. Abrirlo deja al teléfono identificado. */
    public function equipo(string $token)
    {
        $equipo = Equipo::with('partida')->where('token', $token)->firstOrFail();

        Cookie::queue(self::COOKIE, $equipo->token, 60 * 24);

        return view('juego.equipo', ['equipo' => $equipo]);
    }

    /** Lo que abre el QR pegado en una estación. */
    public function qr(Request $request, string $codigo, Juego $juego)
    {
        $estacion = Estacion::where('codigo', $codigo)->firstOrFail();
        $equipo = $this->equipoDelCelular($request);

        if (! $equipo) {
            return view('juego.unirse', [
                'aviso' => 'Encontraron un lugar del recorrido. Primero digan de qué equipo son.',
                'volverA' => $codigo,
            ]);
        }

        try {
            $mensaje = $juego->escanear($equipo, $estacion);
        } catch (JuegoException $e) {
            $mensaje = $e->getMessage();
        }

        return redirect()->route('juego.equipo', $equipo->token)->with('juego', $mensaje);
    }

    public function unirse()
    {
        return view('juego.unirse', ['aviso' => null, 'volverA' => null]);
    }

    /** Con el código del equipo, el que les dieron al armarlo. */
    public function entrar(Request $request)
    {
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:12'],
            'volver' => ['nullable', 'string', 'max:12'],
        ]);

        $equipo = Equipo::where('codigo', strtoupper(trim($datos['codigo'])))
            ->whereHas('partida', fn ($q) => $q->where('estado', '!=', 'terminada'))
            ->first();

        if (! $equipo) {
            return back()->withErrors(['codigo' => 'No hay un equipo en juego con ese código. Revísenlo con quien guía el recorrido.']);
        }

        Cookie::queue(self::COOKIE, $equipo->token, 60 * 24);

        // Venían de escanear un lugar: se escanea ahora, ya sabiendo quiénes son.
        if (filled($datos['volver'] ?? null)) {
            return redirect()->route('juego.qr', $datos['volver'])->withCookie(cookie(self::COOKIE, $equipo->token, 60 * 24));
        }

        return redirect()->route('juego.equipo', $equipo->token);
    }

    /**
     * Partidas por jornadas: el equipo que llega se inscribe solo, con el QR
     * del cartel de la entrada o el del tablero.
     */
    public function inscripcion(Request $request, string $codigo)
    {
        $partida = Partida::where('codigo', $codigo)->firstOrFail();
        abort_unless($partida->esPorJornadas(), 404);

        // Este celular ya es de un equipo de esta partida: no se inscribe dos veces.
        $suyo = $this->equipoDelCelular($request);

        return view('juego.inscribir', [
            'partida' => $partida,
            'suyo' => $suyo?->partida_id === $partida->id ? $suyo : null,
        ]);
    }

    public function inscribir(Request $request, string $codigo, Juego $juego)
    {
        $partida = Partida::where('codigo', $codigo)->firstOrFail();
        abort_unless($partida->esPorJornadas(), 404);

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:80'],
            'integrantes' => ['nullable', 'string', 'max:1200'],
        ], [], ['nombre' => 'nombre del equipo']);

        $integrantes = array_slice(preg_split('/\R/', (string) ($datos['integrantes'] ?? '')), 0, 12);

        try {
            $equipo = $juego->inscribir($partida, $datos['nombre'], $integrantes);
        } catch (JuegoException $e) {
            return back()->withInput()->withErrors(['nombre' => $e->getMessage()]);
        }

        return redirect()->route('juego.equipo', $equipo->token)
            ->withCookie(cookie(self::COOKIE, $equipo->token, 60 * 24));
    }

    /** Para proyectar: dónde va cada equipo. Sin datos de nadie más que el nombre. */
    public function tablero(string $codigo)
    {
        return view('juego.tablero', ['partida' => Partida::where('codigo', $codigo)->firstOrFail()]);
    }

    /**
     * Las gafas, en el navegador. Para probar el circuito sin el visor, y de
     * respaldo si uno se descarga en plena partida. Solo para el equipo del
     * laboratorio: con esto se marca la secuencia.
     */
    public function visor(Request $request, Equipo $equipo)
    {
        abort_unless($request->user()?->hasAnyRole(User::rolesDelEquipo()), 403);

        return view('juego.visor', ['equipo' => $equipo]);
    }

    private function equipoDelCelular(Request $request): ?Equipo
    {
        $token = $request->cookie(self::COOKIE);

        return $token ? Equipo::with('partida')->where('token', $token)->first() : null;
    }
}
