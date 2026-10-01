<?php

namespace App\Http\Controllers;

use App\Models\Recorrido\Circuito;
use App\Models\Recorrido\Partida;
use App\Models\User;
use App\Services\Qr\QrRenderer;
use Illuminate\Http\Request;

/**
 * Lo que se imprime para un recorrido: los QR que se pegan en cada estación
 * y la hoja con el código de cada equipo, que se reparte en la puerta.
 */
class RecorridoImpresionController extends Controller
{
    public function qr(Request $request, Circuito $circuito, QrRenderer $qr)
    {
        $this->exigirEquipo($request);

        return view('recorridos.qr', [
            'circuito' => $circuito->load('estaciones'),
            'qr' => $qr,
        ]);
    }

    public function equipos(Request $request, Partida $partida, QrRenderer $qr)
    {
        $this->exigirEquipo($request);

        return view('recorridos.equipos', [
            'partida' => $partida->load('equipos.integrantes', 'circuito'),
            'qr' => $qr,
        ]);
    }

    private function exigirEquipo(Request $request): void
    {
        abort_unless($request->user()?->hasAnyRole(User::rolesDelEquipo()), 403);
    }
}
