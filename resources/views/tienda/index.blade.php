@extends('layouts.app')
@section('title', 'Tienda · fabOS')

@php
    $moneda = config('fabos.currency.code');
    $unidades = config('fabos.currency.minor_units');
    $cobrosActivos = \App\Support\Settings::cobrosActivos();
    $enFbc = fn ($menor) => number_format($menor / $unidades, 2, ',', '.');
    $cantidad = fn ($v) => rtrim(rtrim(number_format((float) $v, 3, ',', '.'), '0'), ',');
@endphp

@section('content')
    <h1>Tienda</h1>
    <p class="help">
        Insumos del laboratorio y servicios especiales, en {{ config('fabos.currency.name') }}s.
        Se paga en el mostrador, donde te entregan lo que llevas.
    </p>

    <div class="panel">
        <p style="margin:0">
            Tu saldo:
            <strong style="font-size:1.4rem">{{ $enFbc($saldo) }} {{ $moneda }}</strong>
        </p>
        @unless ($cobrosActivos)
            <p class="help" style="margin:.5rem 0 0">
                Los cobros todavía están apagados: los precios que ves son de referencia
                mientras la coordinación define las tarifas definitivas.
            </p>
        @endunless
    </div>

    @if ($catalogo->isEmpty())
        <div class="panel">
            <p style="margin:0">Por ahora no hay nada con existencia disponible.</p>
            <p class="help" style="margin:.6rem 0 0">
                Cuando llegue la próxima compra aparecerá aquí.
            </p>
        </div>
    @else
        <div class="panel">
            <table>
                <thead>
                    <tr>
                        <th>Insumo</th>
                        <th>Disponible</th>
                        <th style="text-align:right">Precio</th>
                    </tr>
                </thead>
                <tbody>
                @foreach ($catalogo as $fila)
                    @php $insumo = $fila['insumo']; @endphp
                    <tr>
                        <td>
                            <strong>{{ $insumo->name }}</strong>
                            @if ($insumo->description)
                                <div class="quien">{{ $insumo->description }}</div>
                            @elseif ($insumo->area)
                                <div class="quien">{{ $insumo->area->name }}</div>
                            @endif
                        </td>
                        <td>
                            {{ $cantidad($insumo->stock) }} {{ $insumo->unit }}
                            @if ($insumo->bajoMinimos())
                                <span class="pill warn" style="margin-left:.3rem">queda poco</span>
                            @endif
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            <strong>{{ $enFbc($fila['precio']) }}</strong>
                            <div class="quien">
                                por {{ $insumo->unit }}
                                @if ($fila['derivado']) · estimado @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>

            <p class="foot" style="margin-top:.9rem">
                Los precios marcados como <em>estimados</em> se calculan a partir del costo de
                compra y todavía no los ha fijado la coordinación.
            </p>
        </div>
    @endif

    {{-- ------------------------------------------------------- encargos --}}
    <h2>Pedir un trabajo</h2>

    <div class="panel">
        <p style="margin:0">
            No hace falta saber operar la máquina. Cuéntanos qué necesitas, lo cotizamos y
            —si te sirve— lo producimos: impresión, corte, acabados o un trabajo a medida.
        </p>
        <p class="help" style="margin:.6rem 0 0">
            Cotizamos antes de empezar. Nada se produce ni se cobra hasta que aceptes.
        </p>

        <form method="POST" action="{{ route('tienda.encargar') }}">
            @csrf

            <label for="title">Qué necesitas</label>
            <input id="title" name="title" type="text" required maxlength="255"
                   placeholder="40 piezas cortadas en acrílico de 3 mm"
                   value="{{ old('title') }}">

            <label for="description">Detalles</label>
            <textarea id="description" name="description" rows="3" maxlength="2000"
                      placeholder="Medidas, material, acabados, para cuándo lo necesitas">{{ old('description') }}</textarea>

            <label for="file_url">Enlace al archivo <span style="text-transform:none;letter-spacing:0">(opcional)</span></label>
            <input id="file_url" name="file_url" type="url"
                   placeholder="https://drive.google.com/..." value="{{ old('file_url') }}">

            <label for="quantity">Cantidad</label>
            <input id="quantity" name="quantity" type="number" step="0.001" min="0.001"
                   value="{{ old('quantity', 1) }}">

            <button type="submit">Pedir cotización</button>
        </form>
    </div>

    @if ($encargos->isNotEmpty())
        <h2>Mis encargos</h2>
        <div class="panel">
            <table>
                <thead><tr><th>Encargo</th><th>Estado</th><th style="text-align:right">Valor</th><th></th></tr></thead>
                <tbody>
                @foreach ($encargos as $encargo)
                    <tr>
                        <td>
                            <strong>{{ $encargo->title }}</strong>
                            <div class="quien">
                                {{ $encargo->code }}
                                @if ($encargo->due_on) · entrega {{ $encargo->due_on->format('d/m/Y') }} @endif
                            </div>
                            @if ($encargo->quote_notes)
                                <div class="quien">{{ $encargo->quote_notes }}</div>
                            @endif
                            @if ($encargo->rejection_reason)
                                <div class="quien">{{ $encargo->rejection_reason }}</div>
                            @endif
                        </td>
                        <td>
                            <span class="pill {{ match ($encargo->status) {
                                'listo', 'entregado' => 'ok',
                                'rechazado', 'cancelado' => 'bad',
                                default => 'warn',
                            } }}">
                                {{ \App\Models\ProductionJob::ESTADOS[$encargo->status] ?? $encargo->status }}
                            </span>
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            {{ $encargo->quoted_total_minor
                                ? number_format($encargo->total(), 2, ',', '.') . ' ' . $moneda
                                : '—' }}
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            @if ($encargo->status === 'cotizado')
                                <form method="POST" action="{{ route('tienda.encargo.aceptar', $encargo) }}"
                                      style="display:inline">
                                    @csrf
                                    <button type="submit" style="margin:0;padding:.3rem .7rem;font-size:.78rem">
                                        Aceptar
                                    </button>
                                </form>
                            @endif

                            @if ($encargo->estaAbierto() && $encargo->status !== 'en_produccion')
                                <form method="POST" action="{{ route('tienda.encargo.cancelar', $encargo) }}"
                                      style="display:inline">
                                    @csrf
                                    <button type="submit"
                                            style="margin:0;padding:.3rem .7rem;font-size:.78rem;
                                                   background:transparent;color:var(--muted);
                                                   border:1px solid var(--rule)">
                                        Cancelar
                                    </button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <p><a class="volver" href="{{ route('home') }}">← Volver a mi cuenta</a></p>
@endsection
