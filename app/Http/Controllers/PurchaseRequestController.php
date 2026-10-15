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

        return $this->requisicion(
            $purchaseRequest,
            route('compras.requisicion.pdf', $purchaseRequest),
            route('compras.cotizacion', $purchaseRequest),
        );
    }

    public function pdf(Request $request, PurchaseRequest $purchaseRequest)
    {
        abort_unless($request->user()->can('view', $purchaseRequest), 403);

        return $this->documento($purchaseRequest);
    }

    public function compartida(string $token)
    {
        $solicitud = $this->compartidaPor($token);

        return $this->requisicion(
            $solicitud,
            route('compras.compartida.pdf', $token),
            route('compras.compartida.cotizacion', $token),
        );
    }

    public function compartidaPdf(string $token)
    {
        return $this->documento($this->compartidaPor($token));
    }

    /** La cotización formal, para quien trabaja en el laboratorio. */
    public function cotizacion(Request $request, PurchaseRequest $purchaseRequest)
    {
        abort_unless($request->user()->can('view', $purchaseRequest), 403);

        return $this->archivoDeCotizacion($purchaseRequest);
    }

    /** Y por el enlace compartido: quien aprueba la compra necesita verla. */
    public function compartidaCotizacion(string $token)
    {
        return $this->archivoDeCotizacion($this->compartidaPor($token));
    }

    private function archivoDeCotizacion(PurchaseRequest $solicitud)
    {
        $disco = \Illuminate\Support\Facades\Storage::disk('local');

        abort_unless(filled($solicitud->quote_path) && $disco->exists($solicitud->quote_path), 404);

        // Siempre como descarga: es un archivo que subió alguien, y servirlo
        // en línea sería una página que se ejecuta en nuestro dominio.
        return $disco->download($solicitud->quote_path, $solicitud->nombreDeLaCotizacion(), [
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Solo abre lo que alguien decidió compartir y no ha revocado. */
    private function compartidaPor(string $token): PurchaseRequest
    {
        return PurchaseRequest::where('share_token', $token)->firstOrFail();
    }

    private function requisicion(PurchaseRequest $solicitud, string $enlacePdf, ?string $enlaceCotizacion = null)
    {
        return view(\App\Services\Purchasing\PurchasingService::vistaDe($solicitud), [
            'solicitud' => $this->cargada($solicitud),
            'enlacePdf' => $enlacePdf,
            'enlaceCotizacion' => filled($solicitud->quote_path) ? $enlaceCotizacion : null,
            'paraPdf'   => false,
        ]);
    }

    private function documento(PurchaseRequest $solicitud)
    {
        // El mismo PDF que se adjunta al enviarla a compras: una sola plantilla.
        return app(\App\Services\Purchasing\PurchasingService::class)
            ->pdfDeLaRequisicion($solicitud)
            ->download($solicitud->nombreDelPdf());
    }

    private function cargada(PurchaseRequest $solicitud): PurchaseRequest
    {
        return $solicitud->load(['items.supply', 'adjustments', 'budget', 'area', 'project', 'requestedBy', 'approvedBy']);
    }
}
