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

@endsection
