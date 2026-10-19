<?php

namespace App\Http\Controllers;

use App\Models\CancionPedida;
use App\Services\Ledger\LedgerService;
use App\Services\Musica\Rocola;
use App\Services\Musica\RocolaException;
use Illuminate\Http\Request;

/** La rocola: buscar una canción y ponerla en el Parlante por 1 FabCoin (§3). */
class RocolaController extends Controller
{
    public function __construct(private Rocola $rocola, private LedgerService $libro) {}

    public function show(Request $request)
    {
        $texto = trim((string) $request->query('q', ''));
        $resultados = [];
        $aviso = null;

        if (Rocola::abierta() && $texto !== '') {
            try {
                $resultados = $this->rocola->buscar($texto);
            } catch (RocolaException $e) {
                $aviso = $e->getMessage();
            }
        }

        return view('rocola.show', [
            'abierta'    => Rocola::abierta(),
            'texto'      => $texto,
            'resultados' => $resultados,
            'aviso'      => $aviso,
            'saldo'      => $this->libro->saldoDe($request->user()),
            'precio'     => Rocola::precioMenor(),
            'estado'     => Rocola::abierta() ? $this->rocola->estado() : null,
        ]);
    }

    public function pedir(Request $request)
    {
        $datos = $request->validate(['cancion' => ['required', 'string', 'max:40']]);

        try {
            $pedido = $this->rocola->pedir($request->user(), $datos['cancion']);
        } catch (RocolaException $e) {
            return back()->withErrors(['cancion' => $e->getMessage()]);
        }

        // Cuándo suena, si Spotify ya la muestra en la cola.
        $cuando = $this->rocola->cuandoSuena($pedido);
        $minutos = $cuando ? max(0, (int) round(now()->diffInSeconds($cuando) / 60)) : null;

        return back()->with('status', '«' . $pedido->titulo . '» entró a la cola del Parlante'
            . ($cuando
                ? ': sonará hacia las ' . $cuando->copy()->timezone(config('fabos.lab.timezone'))->format('H:i')
                    . ($minutos > 0 ? ' (en unos ' . $minutos . ' min)' : ' (ya casi)')
                : '')
            . '. Se te descontó 1 FabCoin.');
    }

    /** Lo que suena y lo que viene, para refrescar la página sin recargarla. */
    public function estado()
    {
        return view('rocola._estado', ['estado' => $this->rocola->estado()]);
    }
}
