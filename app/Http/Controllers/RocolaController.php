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
            'ultimas'    => CancionPedida::with('user')->where('estado', 'en_cola')
                ->where('created_at', '>=', now()->subHours(3))->latest('id')->limit(10)->get(),
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

        return back()->with('status', '«' . $pedido->titulo . '» entró a la cola del Parlante. Se te descontó 1 FabCoin.');
    }
}
