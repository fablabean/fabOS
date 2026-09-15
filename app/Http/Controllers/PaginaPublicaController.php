<?php

namespace App\Http\Controllers;

use App\Models\Pagina;

/**
 * Una página del sitio, para quien llega de fuera (§3, portal público).
 *
 * No exige sesión: estas páginas existen para ser compartidas —un enlace en un
 * correo, un QR en un cartel, una publicación de la Universidad—.
 */
class PaginaPublicaController extends Controller
{
    public function __invoke(string $slug)
    {
        $pagina = Pagina::query()->where('slug', $slug)->firstOrFail();

        /*
         * Una pagina apagada no existe para nadie, salvo para quien la edita.
         *
         * Y se enseña la pagina de verdad, no una vista previa aparte: una
         * plantilla distinta puede mentir, y el punto de revisar es ver lo que
         * va a ver la gente. Quien la puede editar la ve en borrador; quien
         * llega de fuera con el enlace adivinado recibe un 404, no un 403 —que
         * confirmaria que la direccion existe y esta por publicar—.
         */
        abort_unless(
            $pagina->estaVisible() || (auth()->user()?->can('update', $pagina) ?? false),
            404,
        );

        return view('publico.pagina', [
            'pagina' => $pagina,
            'bloques' => $pagina->bloquesParaMostrar(),
            // Se avisa arriba de todo: sin eso, mirar el borrador y mirar lo
            // publicado son la misma pantalla y es facil creer que ya salio.
            'borrador' => ! $pagina->estaVisible(),
        ]);
    }
}
