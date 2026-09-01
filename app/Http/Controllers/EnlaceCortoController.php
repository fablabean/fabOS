<?php

namespace App\Http\Controllers;

use App\Models\ShortLink;
use App\Models\ShortLinkVisit;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Abrir un enlace corto y dejar su rastro (§7, §21).
 *
 * Es la única página del sistema que la va a abrir gente con el teléfono en la
 * mano y prisa, así que hace una cosa: mirar a dónde va y mandar allá.
 */
class EnlaceCortoController extends Controller
{
    public function __invoke(Request $request, string $codigo)
    {
        // Sin distinguir mayúsculas: un código copiado a mano de un cartel
        // llega como salga, y rechazarlo por eso sería quisquilloso.
        $enlace = ShortLink::whereRaw('upper(code) = ?', [mb_strtoupper($codigo)])->first();

        abort_unless($enlace, 404);

        if (! $enlace->vigente()) {
            // 410 y no 404: este código existió. La diferencia importa cuando
            // alguien pregunta por qué su cartel dejó de funcionar.
            abort(410, 'Este código ya no está activo.');
        }

        $this->anotar($request, $enlace);

        // 302 y no 301: un 301 se queda cacheado en el navegador para siempre,
        // y entonces cambiar el destino del enlace no serviría de nada para
        // quien ya lo escaneó una vez. Es justo lo que este sistema existe
        // para permitir.
        return redirect()->away($enlace->target, 302);
    }

    /**
     * Cuándo, de dónde y con qué. Nada más.
     *
     * No se guarda la IP ni se pone una cookie: para contar cuántas veces se
     * escaneó un cartel no hace falta saber quién lo escaneó, y lo que no se
     * guarda no se puede filtrar.
     */
    private function anotar(Request $request, ShortLink $enlace): void
    {
        $referente = $request->headers->get('referer');

        ShortLinkVisit::create([
            'short_link_id' => $enlace->id,
            'visited_at'    => now(),
            // Solo el dominio: sirve para distinguir «llegó por Instagram» de
            // «lo escaneó en el pasillo», que es la pregunta real.
            'source'        => $referente ? Str::limit(parse_url($referente, PHP_URL_HOST) ?? '', 118, '') : null,
            'device'        => $this->dispositivo((string) $request->userAgent()),
        ]);
    }

    /** Teléfono u ordenador. Basta para saber si el QR se escanea o se teclea. */
    private function dispositivo(string $agente): string
    {
        return Str::contains($agente, ['Mobile', 'Android', 'iPhone', 'iPad'], ignoreCase: true)
            ? 'telefono'
            : 'ordenador';
    }
}
