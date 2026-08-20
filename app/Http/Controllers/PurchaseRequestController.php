<?php

namespace App\Http\Controllers;

use App\Models\PurchaseRequest;
use Illuminate\Http\Request;

/**
 * La requisición imprimible (§13).
 *
 * Es el entregable del módulo: el documento que va al área de compras de la
 * Universidad. Se sirve con sesión y no como enlace público porque lleva
 * proveedores, precios y quién aprobó.
 */
class PurchaseRequestController extends Controller
{
    public function show(Request $request, PurchaseRequest $purchaseRequest)
    {
        abort_unless($request->user()->can('view', $purchaseRequest), 403);

        return view('compras.requisicion', [
            'solicitud' => $purchaseRequest->load(['items.supply', 'budget', 'area', 'requestedBy', 'approvedBy']),
        ]);
    }
}
