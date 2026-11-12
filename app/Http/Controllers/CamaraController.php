<?php

namespace App\Http\Controllers;

use App\Filament\Pages\Camaras as PantallaDeCamaras;
use App\Support\Camaras;

/** El cuadro más reciente de una cámara, para quien puede abrir la pantalla Cámaras. */
class CamaraController extends Controller
{
    public function foto(int $numero)
    {
        abort_unless(PantallaDeCamaras::canAccess(), 403);
        abort_unless(Camaras::una($numero), 404);

        $foto = Camaras::foto($numero);

        abort_if($foto === null, 503, 'La cámara no responde.');

        return response($foto, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
