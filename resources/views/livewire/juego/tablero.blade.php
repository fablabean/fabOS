@php
    $reloj = fn (?int $s) => $s === null ? '—' : sprintf('%d:%02d', intdiv($s, 60), $s % 60);
    $total = max(1, $equipos->max(fn ($e) => $e->totalDeEtapas()) ?? 1);
@endphp

<div wire:poll.3s>
    <style>
        .fila-tablero{margin:0;display:grid;grid-template-columns:3rem minmax(10rem,1.3fr) 3fr 7rem;gap:1rem;align-items:center}
        @media (max-width:700px){
            .fila-tablero{grid-template-columns:2rem 1fr auto;gap:.4rem .8rem}
            .fila-tablero > :nth-child(3){grid-column:1 / -1;grid-row:2}
        }
    </style>
    <div style="display:flex;flex-wrap:wrap;justify-content:space-between;align-items:baseline;gap:.5rem 2rem">
        <h1 style="font-size:2rem">{{ $partida->nombre }}</h1>
        <p class="muted" style="margin:0">
            {{ \App\Models\Recorrido\Partida::ESTADOS[$partida->estado] ?? $partida->estado }}
            · {{ $equipos->count() }} {{ $equipos->count() === 1 ? 'equipo' : 'equipos' }}
            · {{ $equipos->filter->terminado()->count() }} terminaron
        </p>
    </div>

    @if ($equipos->isEmpty())
        <div class="tarjeta">Todavía no hay equipos.</div>
    @endif

    <div style="display:flex;flex-direction:column;gap:.7rem;margin-top:1rem">
        @foreach ($equipos as $puesto => $e)
            @php $hechas = $e->terminado() ? $e->totalDeEtapas() : max(0, $e->etapa - 1); @endphp
            <div class="tarjeta fila-tablero" style="border-left:6px solid {{ $e->color }}">
                <div style="font-size:1.8rem;font-weight:800;color:var(--muted);text-align:center">{{ $puesto + 1 }}</div>

                <div>
                    <div style="font-size:1.25rem;font-weight:700">{{ $e->nombre }}</div>
                    <div class="muted">
                        {{ \App\Models\Recorrido\Equipo::ESTADOS[$e->estado] ?? $e->estado }}
                        @if ($lider = $e->avances->firstWhere('etapa', $e->etapa)?->lider)
                            · líder: {{ $lider->nombre }}
                        @endif
                    </div>
                </div>

                <div>
                    <div class="etapas" style="margin:0 0 .35rem">
                        @for ($i = 1; $i <= $total; $i++)
                            <i class="{{ $i <= $hechas ? 'hecha' : ($i === $e->etapa && ! $e->terminado() && $e->estado !== 'esperando' ? 'actual' : '') }}"></i>
                        @endfor
                    </div>
                    <div class="muted" style="display:flex;flex-wrap:wrap;gap:.2rem 1rem;font-size:.82rem;font-variant-numeric:tabular-nums">
                        @foreach ($e->avances as $a)
                            @if ($a->segundos() !== null)
                                <span>E{{ $a->etapa }} {{ $reloj($a->segundos()) }}</span>
                            @endif
                        @endforeach
                        @if ($e->fallos)
                            <span style="color:var(--danger)">{{ $e->fallos }} {{ $e->fallos === 1 ? 'fallo' : 'fallos' }} · +{{ $e->penalizacion }} s</span>
                        @endif
                    </div>
                </div>

                <div style="text-align:right;font-size:1.6rem;font-weight:800;font-variant-numeric:tabular-nums;{{ $e->terminado() ? 'color:var(--accent)' : '' }}">
                    {{ $reloj($e->segundos()) }}
                </div>
            </div>
        @endforeach
    </div>

    <p class="muted" style="margin-top:1.2rem">
        Gana el menor tiempo total. Cada respuesta o secuencia equivocada suma {{ $partida->penalizacion_segundos }} s.
    </p>
</div>
