<?php

namespace App\Http\Controllers;

use App\Services\Ledger\LedgerService;
use App\Services\Shop\ShopService;
use Illuminate\Http\Request;

/**
 * La tienda vista por quien compra (§14).
 *
 * Por ahora es un catálogo con precios y saldo, no un carrito: el pago ocurre
 * en el mostrador, donde alguien entrega la mercancía. Vender en línea sin
 * resolver la entrega solo produciría pedidos que nadie recoge.
 */
class ShopController extends Controller
{
    public function __construct(
        private ShopService $tienda,
        private LedgerService $libro,
    ) {}

    public function index(Request $request)
    {
        return view('tienda.index', [
            'catalogo' => $this->tienda->catalogo(),
            'saldo'    => $this->libro->saldoDe($request->user()),
        ]);
    }
}
