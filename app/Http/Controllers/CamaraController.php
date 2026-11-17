<?php

namespace App\Http\Controllers;

use App\Filament\Pages\Camaras as PantallaDeCamaras;
use App\Support\Camaras;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

    /** La mejor foto de una persona en un día. */
    public function visita(int $id)
    {
        return $this->recorte(DB::table('visitas_del_dia')->where('id', $id)->value('foto'));
    }

    /** La foto de donde salió una de las huellas de una persona. */
    public function huella(int $id)
    {
        return $this->recorte(DB::table('huellas_de_persona')->where('id', $id)->value('foto'));
    }

    private function recorte(?string $ruta)
    {
        abort_unless(PantallaDeCamaras::canAccess(), 403);
        abort_unless($ruta && Storage::disk('local')->exists($ruta), 404);

        return response(Storage::disk('local')->get($ruta), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=60',
        ]);
    }
}
