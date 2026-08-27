@extends('layouts.app')
@section('title', 'Tienda · ' . config('fabos.lab.name'))

@php
    $unidades = (int) config('fabos.currency.minor_units');
    $tasa = (int) config('fabos.currency.peso_rate');
    $simbolo = config('fabos.money.symbol');

    $fbc = fn (int $menor) => rtrim(rtrim(number_format($menor / $unidades, 2, ',', '.'), '0'), ',');
    $pesos = fn (int $menor) => $simbolo . number_format(round($menor / $unidades * $tasa), 0, ',', '.');

    /*
     * Cual de las dos monedas va grande depende de quien mira.
     *
     * Quien entra de fuera piensa en pesos: un precio en una moneda que no
     * conoce no le dice si puede pagarlo. Quien tiene cuenta paga con
     * FabCoins, y el numero que le importa es el que le mueve el saldo que
     * tiene arriba. La otra moneda no se esconde, se pone al lado.
     */
    $conCuenta = auth()->check();

    $principal = fn (int $menor) => $conCuenta ? $fbc($menor) . ' FBC' : $pesos($menor);
    $secundario = fn (int $menor) => $conCuenta ? '≈ ' . $pesos($menor) : $fbc($menor) . ' FBC';
@endphp

@section('content')
    <h1>Tienda</h1>

    <p class="help">
        Material para fabricar, cosas ya hechas, y trabajos con precio cerrado. Se paga con
        FabCoins, o se pide como cotización si lo que necesitas es un encargo a medida.
    </p>

    @if ($saldo !== null)
        {{-- Arriba y siempre: saber cuánto se tiene mientras se mira es lo que
             deja decidir sin ir y volver a la cuenta. --}}
        <p class="saldo">
            Tu saldo: <strong>{{ $fbc($saldo) }} FBC</strong>
            <span class="quien">≈ {{ $pesos($saldo) }}</span>
        </p>
    @endif

    @if (session('status'))
        <p class="msg ok">{{ session('status') }}</p>
    @endif

    @if (session('cotizacion'))
        <div class="panel" style="border-left:4px solid var(--ok)">
            <h2 style="margin-top:0">Quedó pedida</h2>
            <p>
                Tu solicitud es la <strong>{{ session('cotizacion') }}</strong>. Te mandamos un
                correo con ese código.
            </p>
            <p class="help" style="margin-bottom:0">
                Alguien del laboratorio la mira y te manda una propuesta con precio y plazo.
                Puedes seguirla desde <a href="{{ route('home') }}">tu cuenta</a>.
            </p>
        </div>
    @endif

    @error('carrito') <p class="msg error">{{ $message }}</p> @enderror

    @if ($errors->any() && ! $errors->has('carrito'))
        <div class="msg error">
            <ul style="margin:0;padding-left:1.1rem">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    {{-- ------------------------------------------------------------ carrito --}}
    @if ($carrito->isNotEmpty())
        <div class="panel carrito">
            <h2 style="margin-top:0">Tu carrito</h2>

            <table>
                <tbody>
                @foreach ($carrito as $linea)
                    <tr>
                        <td>
                            {{ $linea['nombre'] }}
                            <div class="quien">{{ $principal($linea['precio']) }} por {{ $linea['unidad'] }}</div>
                        </td>
                        <td style="width:9rem">
                            <form method="POST" action="{{ route('tienda.carrito.actualizar') }}" class="cantidad">
                                @csrf
                                <input type="hidden" name="tipo" value="{{ $linea['tipo'] }}">
                                <input type="hidden" name="id" value="{{ $linea['id'] }}">
                                <input type="number" name="cantidad" value="{{ rtrim(rtrim(number_format($linea['cantidad'], 3, '.', ''), '0'), '.') }}"
                                       min="0" step="0.001" aria-label="Cantidad de {{ $linea['nombre'] }}">
                                <button type="submit" class="secundario">↻</button>
                            </form>
                        </td>
                        <td style="text-align:right;white-space:nowrap">
                            <strong>{{ $principal($linea['total']) }}</strong>
                            <div class="quien">{{ $secundario($linea['total']) }}</div>
                        </td>
                    </tr>
                @endforeach
                <tr>
                    <th colspan="2">Total</th>
                    <td style="text-align:right;white-space:nowrap">
                        <strong style="font-size:1.15rem">{{ $principal($total) }}</strong>
                        <div class="quien">{{ $secundario($total) }}</div>
                    </td>
                </tr>
                </tbody>
            </table>

            <div class="salidas">
                @auth
                    <form method="POST" action="{{ route('tienda.pagar') }}">
                        @csrf
                        <button type="submit">Llevármelo con FabCoins</button>
                    </form>
                @else
                    <a class="btn" href="{{ route('login') }}">Entrar para pagar con FabCoins</a>
                @endauth

                <form method="POST" action="{{ route('tienda.carrito.vaciar') }}">
                    @csrf
                    <button type="submit" class="secundario">Vaciar</button>
                </form>
            </div>

            <details class="cotizar">
                <summary>O pídelo como cotización</summary>

                <p class="help">
                    Si lo que necesitas es un encargo a medida —otra cantidad, otro material, algo
                    que no está en la lista—, esto se convierte en una solicitud de proyecto con
                    cada línea como entregable. Te mandamos una propuesta con precio y plazo.
                </p>

                <form method="POST" action="{{ route('tienda.cotizar') }}">
                    @csrf

                    <label>
                        ¿Cómo lo llamamos?
                        <input type="text" name="titulo" maxlength="180" value="{{ old('titulo') }}"
                               placeholder="Señalética para el laboratorio de suelos">
                    </label>

                    <label>
                        Algo más que debamos saber
                        <textarea name="detalle" rows="3"
                                  placeholder="Para cuándo lo necesitas, medidas, color, dónde va.">{{ old('detalle') }}</textarea>
                    </label>

                    @guest
                        <div class="dos">
                            <label>
                                Tu nombre
                                <input type="text" name="nombre" required maxlength="120" value="{{ old('nombre') }}">
                            </label>
                            <label>
                                Correo
                                <input type="email" name="correo" required maxlength="180" value="{{ old('correo') }}">
                                <span class="foot">Con este correo se crea tu cuenta y sigues el pedido desde ahí.</span>
                            </label>
                            <label>
                                Teléfono
                                <input type="text" name="telefono" maxlength="40" value="{{ old('telefono') }}">
                            </label>
                            <label>
                                Organización
                                <input type="text" name="organizacion" maxlength="160" value="{{ old('organizacion') }}">
                            </label>
                            <label>
                                ¿Cuál es tu rol?
                                <select name="cliente" required>
                                    @foreach (\App\Models\Project::CLIENTES as $clave => $nombre)
                                        <option value="{{ $clave }}" @selected(old('cliente') === $clave)>{{ $nombre }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                    @endguest

                    <button type="submit" class="secundario">Pedir cotización</button>
                </form>
            </details>
        </div>
    @endif

    {{-- ---------------------------------------------------------- el catálogo --}}
    @foreach ([
        ['titulo' => 'Productos', 'ayuda' => 'Cosas ya hechas, listas para llevar.', 'cosas' => $productos],
        ['titulo' => 'Insumos', 'ayuda' => 'Material para fabricar. Se cobra por la unidad en que se mide.', 'cosas' => $insumos],
        ['titulo' => 'Servicios', 'ayuda' => 'Trabajos con precio cerrado: no hace falta saber operar la máquina.', 'cosas' => $servicios],
    ] as $bloque)
        @continue($bloque['cosas']->isEmpty())

        <h2>{{ $bloque['titulo'] }}</h2>
        <p class="help" style="margin-top:-.4rem">{{ $bloque['ayuda'] }}</p>

        <div class="rejilla">
            @foreach ($bloque['cosas'] as $fila)
                @php $cosa = $fila['cosa']; @endphp
                <div class="ficha">
                    @if ($cosa->fotoUrl())
                        <img src="{{ $cosa->fotoUrl() }}" alt="{{ $cosa->name }}" loading="lazy">
                    @else
                        <div class="sin-foto">{{ $bloque['titulo'] === 'Servicios' ? '🛠' : '📦' }}</div>
                    @endif

                    <div class="cuerpo">
                        <strong>{{ $cosa->name }}</strong>

                        <div class="quien">
                            @if ($cosa->area) {{ $cosa->area->name }} · @endif
                            por {{ $cosa->unit }}
                            @if ($fila['tipo'] === 'servicio' && $cosa->cuandoEstaListo())
                                · {{ $cosa->cuandoEstaListo() }}
                            @endif
                        </div>

                        @if ($fila['tipo'] === 'insumo' ? $cosa->public_description : $cosa->description)
                            <p class="detalle">
                                {{ $fila['tipo'] === 'insumo' ? $cosa->public_description : $cosa->description }}
                            </p>
                        @endif

                        <div class="precio">
                            {{ $principal($fila['precio']) }}
                            <span class="quien">
                                {{ $secundario($fila['precio']) }}
                                @if ($fila['derivado'] ?? false)
                                    · estimado del costo
                                @endif
                            </span>
                        </div>

                        <form method="POST" action="{{ route('tienda.carrito.agregar') }}" class="anadir">
                            @csrf
                            <input type="hidden" name="tipo" value="{{ $fila['tipo'] }}">
                            <input type="hidden" name="id" value="{{ $cosa->id }}">
                            <input type="number" name="cantidad" value="1" min="0.001" step="0.001"
                                   aria-label="Cantidad">
                            <button type="submit">Añadir</button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    @endforeach

    @if ($productos->isEmpty() && $insumos->isEmpty() && $servicios->isEmpty())
        <div class="panel">
            <p style="margin:0">Todavía no hay nada publicado en la tienda.</p>
            <p class="help" style="margin:.6rem 0 0">
                Los insumos y productos se publican desde el backoffice, marcándolos como
                visibles; los servicios se crean en su propia sección.
            </p>
        </div>
    @endif

    {{-- Rejilla propia: las utilidades responsivas de Tailwind no están compiladas. --}}
    <style>
        .saldo { margin:.2rem 0 1rem; }
        .rejilla { display:grid; grid-template-columns:repeat(auto-fill,minmax(15rem,1fr));
                   gap:.9rem; margin-bottom:2rem; }
        .ficha { border:1px solid var(--rule); border-radius:7px; overflow:hidden;
                 background:var(--surface); display:flex; flex-direction:column; }
        .ficha img, .ficha .sin-foto { width:100%; height:9rem; object-fit:cover; display:block; }
        .ficha .sin-foto { display:flex; align-items:center; justify-content:center;
                           font-size:2rem; background:var(--ground); color:var(--muted); }
        .ficha .cuerpo { padding:.8rem .9rem; display:flex; flex-direction:column; gap:.25rem;
                         flex:1; }
        .ficha .detalle { font-size:.83rem; margin:.2rem 0; color:var(--ink-soft); }
        .ficha .precio { font-weight:700; font-size:1.3rem; line-height:1.2;
                         margin-top:auto; padding-top:.5rem; }
        .ficha .precio .quien { font-weight:400; font-size:.8rem; display:block; }
        .ficha .anadir { display:flex; gap:.4rem; margin-top:.5rem; }
        .ficha .anadir input { width:5rem; }
        .ficha .anadir button { margin:0; flex:1; }

        .carrito .cantidad { display:flex; gap:.3rem; }
        .carrito .cantidad input { width:5rem; }
        .carrito .cantidad button { margin:0; padding:.35rem .6rem; }
        .carrito .salidas { display:flex; gap:.7rem; align-items:center;
                            flex-wrap:wrap; margin-top:1rem; }
        .carrito .salidas form { margin:0; }
        .carrito .salidas button { margin:0; }
        .carrito .cotizar { margin-top:1.2rem; border-top:1px solid var(--rule); padding-top:.9rem; }
        .carrito .cotizar summary { cursor:pointer; font-weight:600; }
        .carrito .cotizar label { display:block; margin:.8rem 0; font-size:.9rem; font-weight:600; }
        .carrito .cotizar input, .carrito .cotizar select, .carrito .cotizar textarea {
            width:100%; margin-top:.3rem; font-weight:400; }
        .carrito .cotizar .foot { display:block; font-weight:400; margin-top:.25rem; }
        .carrito .cotizar .dos { display:grid;
                                 grid-template-columns:repeat(auto-fit,minmax(14rem,1fr)); gap:0 1rem; }
    </style>
@endsection
