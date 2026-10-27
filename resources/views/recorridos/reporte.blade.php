@extends('layouts.juego')

@section('title', 'Reporte · ' . $partida->nombre)
@section('ancho', 'ancho')

@php
    $reloj = fn (?int $s) => $s === null ? '—' : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    $jugaron = $equipos->reject(fn ($e) => $e->estado === 'esperando');
    $terminaron = $equipos->filter->terminado();
    $personas = $equipos->sum(fn ($e) => $e->integrantes->count());
    $mejor = $terminaron->map->segundos()->filter()->min();
    $quien = $partida->reservation?->user?->name;
@endphp

@section('content')
    {{-- Para quien trajo al grupo: lo lee en el correo, lo imprime o lo
         guarda en PDF. Lleva los nombres de todos y ningún correo. --}}
    <style>
        .reporte{max-width:60rem;margin:0 auto}
        .cifras{display:grid;grid-template-columns:repeat(auto-fit,minmax(9rem,1fr));gap:.8rem;margin:1rem 0}
        .cifras .tarjeta{margin:0}
        .cifras b{display:block;font-size:2rem;line-height:1.1;font-variant-numeric:tabular-nums}
        table{width:100%;border-collapse:collapse;font-size:.95rem}
        th,td{text-align:left;padding:.6rem .5rem;border-bottom:1px solid var(--rule);vertical-align:top}
        th{font-size:.78rem;letter-spacing:.06em;text-transform:uppercase;color:var(--muted);font-weight:600}
        td.num,th.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
        .punto{display:inline-block;width:.7rem;height:.7rem;border-radius:50%;margin-right:.4rem;vertical-align:middle}
        .tabla{overflow-x:auto}
        @media (max-width:640px){
            table,tbody,tr,td{display:block}
            thead{display:none}
            tr{border-bottom:1px solid var(--rule);padding:.5rem 0}
            td{border:0;padding:.15rem 0}
            td.num{text-align:left}
            td[data-titulo]::before{content:attr(data-titulo) ': ';color:var(--muted);font-size:.85rem}
        }
        @media print{
            :root{--ground:#fff;--surface:#fff;--ink:#111;--ink-soft:#333;--muted:#555;--rule:#ccc;--accent:#0D6E63}
            .no-imprimir{display:none}
            main.ancho{padding:0}
            tr{break-inside:avoid}
        }
    </style>

    <div class="reporte">
        <h1 style="font-size:2rem;margin-bottom:.2rem">{{ $partida->nombre }}</h1>
        <p class="muted" style="margin:0">
            Reporte del recorrido «{{ $partida->circuito->nombre }}» · {{ ucfirst($partida->fechaDeJuego()) }}
            @if ($quien) · solicitado por {{ $quien }} @endif
        </p>

        <div class="cifras">
            <div class="tarjeta"><b>{{ $jugaron->count() ?: $equipos->count() }}</b><span class="muted">equipos</span></div>
            <div class="tarjeta"><b>{{ $personas }}</b><span class="muted">participantes</span></div>
            <div class="tarjeta"><b>{{ $terminaron->count() }}</b><span class="muted">terminaron las {{ $estaciones }} estaciones</span></div>
            <div class="tarjeta"><b>{{ $reloj($mejor) }}</b><span class="muted">mejor tiempo</span></div>
        </div>

        <h2 style="margin-top:1.6rem">Clasificación</h2>
        @if ($equipos->isEmpty())
            <div class="tarjeta">Esta partida no tuvo equipos.</div>
        @else
            <div class="tabla">
                <table>
                    <thead>
                        <tr>
                            <th class="num">#</th>
                            <th>Equipo</th>
                            <th>Integrantes</th>
                            <th>Resultado</th>
                            <th class="num">Fallos</th>
                            <th class="num">Tiempo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($equipos as $puesto => $e)
                            <tr>
                                <td class="num" style="font-weight:800;font-size:1.1rem">{{ $e->estado === 'esperando' ? '—' : $puesto + 1 }}</td>
                                <td style="font-weight:700"><span class="punto" style="background:{{ $e->color }}"></span>{{ $e->nombre }}</td>
                                <td data-titulo="Integrantes">{{ $e->integrantes->pluck('nombre')->implode(', ') ?: '—' }}</td>
                                <td data-titulo="Resultado">
                                    @if ($e->terminado())
                                        Terminó
                                    @elseif ($e->estado === 'esperando')
                                        No empezó
                                    @else
                                        Llegó a la etapa {{ $e->etapa }} de {{ $e->totalDeEtapas() }}
                                    @endif
                                </td>
                                <td class="num" data-titulo="Fallos">{{ $e->fallos }}@if ($e->penalizacion) <span class="muted">(+{{ $e->penalizacion }} s)</span>@endif</td>
                                <td class="num" data-titulo="Tiempo" style="font-weight:700">{{ $reloj($e->segundos()) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <p class="muted" style="margin-top:1.2rem">
            Gana el menor tiempo total. Cada respuesta o secuencia equivocada sumó {{ $partida->penalizacion_segundos }} s.
            @if ($partida->esPorJornadas())
                Cada equipo tuvo su propio reloj, desde que empezó.
            @endif
        </p>

        <p class="no-imprimir" style="margin-top:1.5rem">
            <button class="boton" type="button" onclick="window.print()" style="max-width:20rem">Imprimir o guardar en PDF</button>
        </p>
    </div>
@endsection
