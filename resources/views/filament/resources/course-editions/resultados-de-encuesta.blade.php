<x-filament-panels::page>
    {{-- Estilos propios: el CSS de Filament viene compilado con un conjunto
         fijo de clases y las de una pagina a medida no se aplicarian. --}}
    <style>
        .enc{display:flex;flex-direction:column;gap:1.25rem}
        .enc .rejilla{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr))}
        .enc .cifra b{display:block;font-size:1.8rem;letter-spacing:-.02em;line-height:1.1}
        .enc .cifra span{font-size:.78rem;color:rgb(107 114 128)}
        .enc .fila{display:grid;grid-template-columns:5.5rem 1fr 3rem;gap:.6rem;align-items:center;font-size:.85rem;margin:.25rem 0}
        .enc .barra{height:.6rem;border-radius:999px;background:rgba(128,128,128,.18);overflow:hidden}
        .enc .barra i{display:block;height:100%;background:rgb(13 110 99)}
        .enc .num{text-align:right;color:rgb(107 114 128)}
        .enc .gris{color:rgb(107 114 128);font-size:.85rem}
        .enc ul.textos{margin:.4rem 0 0;padding-left:1.1rem;font-size:.9rem}
        .enc ul.textos li{margin:.3rem 0}
    </style>

    <div class="enc">
        @if ($grupos > 1)
            <div>
                <label style="display:inline-flex;gap:.5rem;align-items:center;font-size:.9rem">
                    <input type="checkbox" wire:model.live="todos">
                    Ver los {{ $grupos }} grupos de {{ $edicion->course->name }} juntos
                </label>
            </div>
        @endif

        <x-filament::section>
            <div class="rejilla">
                <div class="cifra"><b>{{ $resultados['respuestas'] }}</b><span>respuestas</span></div>
                <div class="cifra"><b>{{ $resultados['asistentes'] }}</b><span>personas asistieron</span></div>
                <div class="cifra">
                    <b>{{ $resultados['asistentes'] ? round(100 * $resultados['respuestas'] / $resultados['asistentes']) . ' %' : '—' }}</b>
                    <span>de quienes vinieron respondió</span>
                </div>
                <div class="cifra">
                    <b>{{ $resultados['satisfaccion'] !== null ? number_format($resultados['satisfaccion'], 1, ',', '.') . ' / 5' : '—' }}</b>
                    <span>satisfacción promedio</span>
                </div>
            </div>
            @if (! $edicion->survey_sent_at && ! $todos)
                <p class="gris" style="margin-top:1rem">La encuesta todavía no se ha enviado en este grupo. Se envía desde la ficha de la edición, en el menú «⋮».</p>
            @endif
        </x-filament::section>

        @forelse ($resultados['preguntas'] as $p)
            <x-filament::section>
                <x-slot name="heading">{{ $p['pregunta'] }}</x-slot>
                <x-slot name="description">
                    {{ $p['respondieron'] }} {{ $p['respondieron'] === 1 ? 'respuesta' : 'respuestas' }}
                    @if (($p['promedio'] ?? null) !== null) · promedio {{ number_format($p['promedio'], 1, ',', '.') }} de 5 @endif
                </x-slot>

                @if (isset($p['reparto']))
                    @php $max = max(1, max($p['reparto'] ?: [0])); @endphp
                    @foreach ($p['reparto'] as $opcion => $n)
                        <div class="fila">
                            <span>{{ $p['tipo'] === 'escala' ? str_repeat('★', (int) $opcion) : $opcion }}</span>
                            <div class="barra"><i style="width:{{ round(100 * $n / $max) }}%"></i></div>
                            <span class="num">{{ $n }}</span>
                        </div>
                    @endforeach
                @elseif (! empty($p['textos']))
                    <ul class="textos">
                        @foreach ($p['textos'] as $t)<li>{{ $t }}</li>@endforeach
                    </ul>
                @else
                    <p class="gris">Sin respuestas todavía.</p>
                @endif
            </x-filament::section>
        @empty
            <x-filament::section>
                <p class="gris">La actividad no tiene preguntas de encuesta. Agrégalas en el curso, en la sección «Encuesta de satisfacción».</p>
            </x-filament::section>
        @endforelse
    </div>
</x-filament-panels::page>
