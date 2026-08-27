<?php

namespace App\Http\Controllers;

use App\Models\Area;
use App\Models\ServiceOffering;
use App\Models\Supply;
use App\Services\Ledger\LedgerService;
use App\Services\Money\PricingService;
use App\Services\Projects\ProjectService;
use App\Services\Shop\Carrito;
use App\Services\Shop\ShopException;
use App\Services\Shop\ShopService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La tienda que se mira sin entrar (§14).
 *
 * Tres cosas distintas en un mismo sitio: insumos, productos terminados y
 * servicios con precio cerrado. Y dos salidas, porque el laboratorio atiende
 * dos necesidades que no se parecen:
 *
 *  · **Llevárselo con FabCoins.** Lo que está en el catálogo tiene precio y
 *    existencia: se cobra y se entrega.
 *  · **Pedir una cotización.** Lo que se junta en el carrito no siempre es una
 *    compra: a veces es la forma más clara de decir «necesito esto». Se
 *    convierte en un proyecto, con cada línea como entregable, y sigue el
 *    camino que ya existe.
 */
class TiendaPublicaController extends Controller
{
    public function __construct(
        private Carrito $carrito,
        private PricingService $precios,
        private ShopService $tienda,
        private LedgerService $libro,
        private ProjectService $proyectos,
    ) {}

    public function index(Request $request)
    {
        $insumos = Supply::enLaTienda()
            // Lo agotado no se enseña: la tienda promete lo que se puede
            // llevar hoy, y un catalogo lleno de cosas que no hay erosiona esa
            // promesa. Para lo que no hay esta pedir cotizacion.
            ->where('stock', '>', 0)
            ->with(['area', 'priceBreaks'])
            ->orderBy('name')
            ->get()
            ->map(fn (Supply $s) => [
                'cosa'   => $s,
                'tipo'   => 'insumo',
                'precio' => $this->precios->precioDe($s),
                // Un precio derivado del costo de compra no lo decidio nadie:
                // decirlo evita que se lea como una tarifa acordada.
                'derivado' => $this->precios->esDerivado($s),
                // Los escalones se enseñan antes de comprar. Un descuento que
                // solo aparece al llegar a la cantidad no cambia la decision de
                // nadie: no llega a saberse que existia.
                'escalones' => $this->precios->escalonesDe($s),
            ])
            ->filter(fn (array $f) => $f['precio'] > 0);

        $servicios = ServiceOffering::enLaTienda()
            ->with(['area', 'priceBreaks'])
            ->orderBy('name')
            ->get()
            ->map(fn (ServiceOffering $s) => [
                'cosa'      => $s,
                'tipo'      => 'servicio',
                'precio'    => (int) $s->price_minor,
                'derivado'  => false,
                'escalones' => $this->precios->escalonesDe($s),
            ])
            ->filter(fn (array $f) => $f['precio'] > 0);

        return view('tienda.publica', [
            'productos' => $insumos->filter(fn (array $f) => $f['cosa']->esProducto())->values(),
            'insumos'   => $insumos->filter(fn (array $f) => ! $f['cosa']->esProducto())->values(),
            'servicios' => $servicios->values(),
            'areas'     => Area::orderBy('name')->get(),
            'carrito'   => $this->carrito->lineas(),
            'total'     => $this->carrito->totalMenor(),
            'saldo'     => $request->user() ? $this->libro->saldoDe($request->user()) : null,
        ]);
    }

    public function agregar(Request $request)
    {
        $datos = $request->validate([
            'tipo'     => ['required', Rule::in(['insumo', 'servicio'])],
            'id'       => ['required', 'integer'],
            'cantidad' => ['nullable', 'numeric', 'min:0.001', 'max:9999'],
        ]);

        $this->carrito->agregar($datos['tipo'], (int) $datos['id'], (float) ($datos['cantidad'] ?? 1));

        return back()->with('status', 'Añadido al carrito.');
    }

    public function actualizar(Request $request)
    {
        $datos = $request->validate([
            'tipo'     => ['required', Rule::in(['insumo', 'servicio'])],
            'id'       => ['required', 'integer'],
            'cantidad' => ['required', 'numeric', 'min:0', 'max:9999'],
        ]);

        $this->carrito->fijar($datos['tipo'], (int) $datos['id'], (float) $datos['cantidad']);

        return back();
    }

    public function vaciar()
    {
        $this->carrito->vaciar();

        return back()->with('status', 'Carrito vacío.');
    }

    /**
     * Llevárselo con FabCoins.
     *
     * La venta se abre, se llena y se cobra con el mismo servicio del
     * mostrador: es la misma operación, la haga quien atiende o la persona
     * desde su teléfono. Duplicarla daría dos formas de cobrar que acabarían
     * descontando distinto.
     */
    public function pagar(Request $request)
    {
        if ($this->carrito->estaVacio()) {
            return back()->withErrors(['carrito' => 'El carrito está vacío.']);
        }

        $faltantes = $this->carrito->sinExistencia();

        if ($faltantes->isNotEmpty()) {
            return back()->withErrors([
                'carrito' => 'No hay suficiente de: '
                    . $faltantes->pluck('nombre')->implode(', ')
                    . '. Ajusta la cantidad o pídelo como cotización.',
            ]);
        }

        $venta = $this->tienda->abrirVenta($request->user());

        try {
            foreach ($this->carrito->lineas() as $linea) {
                $linea['tipo'] === 'insumo'
                    ? $this->tienda->agregarInsumo($venta, $linea['cosa'], $linea['cantidad'])
                    : $this->tienda->agregarServicio(
                        $venta,
                        $linea['nombre'],
                        $linea['cantidad'],
                        $linea['precio'],
                    );
            }

            $this->tienda->cobrar($venta->refresh());
        } catch (ShopException $e) {
            // La venta a medias no se queda por ahí: o se cobra entera o no
            // existe, o el mostrador acumularia ventas abiertas de nadie.
            $venta->items()->delete();
            $venta->delete();

            return back()->withErrors(['carrito' => $e->getMessage()]);
        }

        $this->carrito->vaciar();

        return redirect()
            ->route('tienda.publica')
            ->with('status', 'Listo. Tu compra es la ' . $venta->code . '. Recógela en el laboratorio.');
    }

    /**
     * Pedirlo como cotización.
     *
     * Lo que se junta en el carrito no siempre es una compra: a veces es la
     * forma más clara de decir «necesito esto». Se convierte en un proyecto con
     * cada línea como entregable, y de ahí sigue el camino que ya existe:
     * evaluación, propuesta, aceptación.
     */
    public function cotizar(Request $request)
    {
        if ($this->carrito->estaVacio()) {
            return back()->withErrors(['carrito' => 'El carrito está vacío.']);
        }

        $identificado = $request->user();

        $datos = $request->validate([
            'titulo'       => ['nullable', 'string', 'max:180'],
            'detalle'      => ['nullable', 'string', 'max:2000'],
            'nombre'       => [Rule::requiredIf(! $identificado), 'nullable', 'string', 'max:120'],
            'correo'       => [Rule::requiredIf(! $identificado), 'nullable', 'email', 'max:180'],
            'telefono'     => ['nullable', 'string', 'max:40'],
            'organizacion' => ['nullable', 'string', 'max:160'],
            'cliente'      => [Rule::requiredIf(! $identificado?->category), Rule::in(array_keys(\App\Models\Project::CLIENTES))],
        ]);

        $lineas = $this->carrito->lineas();

        // Cada cosa del carrito es un entregable: es exactamente lo que la
        // persona está pidiendo, dicho con sus palabras y sus cantidades.
        $entregables = $lineas
            ->map(fn (array $l) => trim(sprintf(
                '%s · %s %s',
                $l['nombre'],
                rtrim(rtrim(number_format($l['cantidad'], 3, ',', '.'), '0'), ','),
                $l['unidad'],
            )))
            ->implode("\n");

        $proyecto = $this->proyectos->solicitarDesdeLaWeb([
            'titulo'       => ($datos['titulo'] ?? null) ?: 'Pedido de la tienda (' . $lineas->count() . ' cosas)',
            'resumen'      => trim(($datos['detalle'] ?? '') . "\n\n"
                . 'Pedido armado desde la tienda:' . "\n" . $entregables),
            'entregables'  => $entregables,
            'nombre'       => $identificado?->name ?? $datos['nombre'],
            'correo'       => $identificado?->email ?? $datos['correo'],
            'telefono'     => $datos['telefono'] ?? $identificado?->phone,
            'organizacion' => $datos['organizacion'] ?? null,
            'para_cuando'  => null,
            'cliente'      => $identificado?->category?->tramiteDeCliente()
                ?? ($datos['cliente'] ?? 'externo'),
        ]);

        // El valor de lista queda anotado como estimación: es de dónde parte
        // quien va a cotizarlo de verdad, y no tenerlo obliga a rehacer la suma.
        $proyecto->update([
            'estimated_value' => $this->enPesos($this->carrito->totalMenor()),
        ]);

        $this->carrito->vaciar();

        return redirect()
            ->route('tienda.publica')
            ->with('cotizacion', $proyecto->code);
    }

    private function enPesos(int $menor): int
    {
        $unidades = (int) config('fabos.currency.minor_units');
        $tasa = (int) config('fabos.currency.peso_rate');

        return (int) round($menor / $unidades * $tasa);
    }
}
