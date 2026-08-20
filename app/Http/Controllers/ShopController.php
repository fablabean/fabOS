<?php

namespace App\Http\Controllers;

use App\Models\ProductionJob;
use App\Services\Ledger\LedgerService;
use App\Services\Shop\ProductionService;
use App\Services\Shop\ShopException;
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
        private ProductionService $produccion,
    ) {}

    public function index(Request $request)
    {
        return view('tienda.index', [
            'catalogo' => $this->tienda->catalogo(),
            'saldo'    => $this->libro->saldoDe($request->user()),
            'encargos' => ProductionJob::where('user_id', $request->user()->id)
                ->latest('id')
                ->limit(10)
                ->get(),
        ]);
    }

    /** Pedir un trabajo hecho por el equipo (§14). */
    public function encargar(Request $request)
    {
        $datos = $request->validate([
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'file_url'    => ['nullable', 'url', 'max:500'],
            'quantity'    => ['nullable', 'numeric', 'min:0.001'],
        ]);

        $encargo = $this->produccion->pedir($request->user(), $datos);

        return back()->with(
            'status',
            'Recibimos tu encargo ' . $encargo->code . '. Te cotizamos y te avisamos por correo; '
            . 'no empezamos a producir hasta que lo aceptes.'
        );
    }

    /** Aceptar la cotización: es quien pide quien decide. */
    public function aceptarEncargo(Request $request, ProductionJob $job)
    {
        abort_unless($job->user_id === $request->user()->id, 403);

        try {
            $this->produccion->aceptar($job);
        } catch (ShopException $e) {
            return back()->withErrors(['encargo' => $e->getMessage()]);
        }

        return back()->with('status', 'Aceptaste la cotización. Tu encargo entró a la cola de producción.');
    }

    public function cancelarEncargo(Request $request, ProductionJob $job)
    {
        abort_unless($job->user_id === $request->user()->id, 403);

        try {
            $this->produccion->cancelar($job, 'Cancelado por quien lo pidió');
        } catch (ShopException $e) {
            return back()->withErrors(['encargo' => $e->getMessage()]);
        }

        return back()->with('status', 'Cancelamos tu encargo.');
    }
}
