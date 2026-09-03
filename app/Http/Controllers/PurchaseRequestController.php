<?php

namespace App\Http\Controllers;

use App\Models\PurchaseRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/**
 * La requisición (§13).
 *
 * Es el entregable del módulo: el documento que va al área de compras de la
 * Universidad. Llega por dos puertas:
 *
 * - Con sesión, para quien trabaja en el laboratorio.
 * - Por un enlace compartido, sin sesión, para compras. Lleva proveedores,
 *   precios y quién aprobó, así que el enlace no existe hasta que alguien
 *   decide compartir, es largo y aleatorio, y se puede revocar.
 *
 * Por las dos se puede bajar en PDF. El mismo documento: lo que se ve en
 * pantalla es lo que se baja, sin una plantilla aparte que se desfase.
 */
class PurchaseRequestController extends Controller
{
    public function show(Request $request, PurchaseRequest $purchaseRequest)
    {
        abort_unless($request->user()->can('view', $purchaseRequest), 403);

        return $this->requisicion($purchaseRequest, route('compras.requisicion.pdf', $purchaseRequest));
    }

    public function pdf(Request $request, PurchaseRequest $purchaseRequest)
    {
        abort_unless($request->user()->can('view', $purchaseRequest), 403);

        return $this->documento($purchaseRequest);
    }

    public function compartida(string $token)
    {
        $solicitud = $this->compartidaPor($token);

        return $this->requisicion($solicitud, route('compras.compartida.pdf', $token));
    }

    public function compartidaPdf(string $token)
    {
        return $this->documento($this->compartidaPor($token));
    }

    /** Solo abre lo que alguien decidió compartir y no ha revocado. */
    private function compartidaPor(string $token): PurchaseRequest
    {
        return PurchaseRequest::where('share_token', $token)->firstOrFail();
    }

    private function requisicion(PurchaseRequest $solicitud, string $enlacePdf)
    {
        return view('compras.requisicion', [
            'solicitud' => $this->cargada($solicitud),
            'enlacePdf' => $enlacePdf,
            'paraPdf'   => false,
        ]);
    }

    private function documento(PurchaseRequest $solicitud)
    {
        $pdf = Pdf::loadView('compras.requisicion', [
            'solicitud' => $this->cargada($solicitud),
            'enlacePdf' => null,
            'paraPdf'   => true,
        ])->setPaper('letter');

        return $pdf->download($solicitud->code . '.pdf');
    }

    private function cargada(PurchaseRequest $solicitud): PurchaseRequest
    {
        return $solicitud->load(['items.supply', 'budget', 'area', 'project', 'requestedBy', 'approvedBy']);
    }
}
