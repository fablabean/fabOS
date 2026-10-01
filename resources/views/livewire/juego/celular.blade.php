@php
    use App\Models\Recorrido\Equipo;
    use App\Models\Recorrido\Estacion;

    $total = $equipo->totalDeEtapas();
@endphp

{{-- Se refresca solo mientras espera algo que pasa en otra parte: el inicio,
     o que el líder marque la secuencia en las gafas. --}}
<div @if (in_array($equipo->estado, ['esperando', 'buscando', 'secuencia'], true)) wire:poll.4s @endif>
    <h1 style="display:flex;align-items:center;gap:.5rem">
        <span style="width:.9rem;height:.9rem;border-radius:50%;background:{{ $equipo->color }};flex:none"></span>
        {{ $equipo->nombre }}
    </h1>

    @if ($total)
        <div class="etapas" aria-label="Etapa {{ $equipo->etapa }} de {{ $total }}">
            @for ($i = 1; $i <= $total; $i++)
                <i class="{{ $i < $equipo->etapa || $equipo->terminado() ? 'hecha' : ($i === $equipo->etapa ? 'actual' : '') }}"></i>
            @endfor
        </div>
        @unless ($equipo->terminado())
            <p class="muted" style="margin:0">Etapa {{ $equipo->etapa }} de {{ $total }}</p>
        @endunless
    @endif

    @if ($aviso)
        <div class="aviso {{ $avisoBueno ? 'bueno' : 'malo' }}">{{ $aviso }}</div>
    @endif

    @if ($equipo->partida->estado === 'terminada' && ! $equipo->terminado())
        <div class="tarjeta">
            <h2>La partida terminó</h2>
            <p class="muted">Llegaron hasta la etapa {{ $equipo->etapa }}. ¡Gracias por jugar!</p>
        </div>

    @elseif ($equipo->estado === 'esperando')
        <div class="tarjeta">
            <h2>Ya están dentro</h2>
            <p>Esperen la señal de inicio. Esta pantalla cambia sola.</p>
            <p class="muted">Integrantes: {{ $equipo->integrantes->pluck('nombre')->implode(', ') ?: '—' }}</p>
        </div>

    @elseif ($equipo->estado === 'buscando')
        <div class="tarjeta">
            <h2>Escuchen a su líder</h2>
            <p>Quien lleva las gafas tiene la pista. Busquen el lugar y escaneen su QR con la cámara de este celular.</p>
            @if ($equipo->integrantes->isNotEmpty())
                <label for="lider">¿Quién lleva las gafas en esta etapa?</label>
                <select id="lider" wire:model.live="lider">
                    <option value="">Elijan…</option>
                    @foreach ($equipo->integrantes as $i)
                        <option value="{{ $i->id }}">{{ $i->nombre }}</option>
                    @endforeach
                </select>
            @endif
        </div>

    @elseif ($equipo->estado === 'resolviendo' && $estacion)
        <form wire:submit="responder" class="tarjeta">
            <h2>La prueba</h2>
            <p style="font-size:1.1rem;white-space:pre-line">{{ $estacion->pregunta }}</p>

            @if ($estacion->pregunta_imagen && $estacion->tipo_respuesta !== 'ubicar')
                <img src="{{ Estacion::urlDeImagen($estacion->pregunta_imagen) }}" alt="" style="width:100%;border-radius:10px;margin:.4rem 0">
            @endif

            @switch ($estacion->tipo_respuesta)
                @case('texto')
                    <label for="texto">Su respuesta</label>
                    <input id="texto" type="text" wire:model="texto" autocomplete="off" required>
                    @break

                @case('opcion')
                    @foreach (array_values($estacion->datos_respuesta['opciones'] ?? []) as $n => $o)
                        <label style="display:flex;gap:.6rem;align-items:center;font-size:1rem;color:var(--ink);padding:.7rem;border:1px solid var(--rule);border-radius:10px;margin:.4rem 0;cursor:pointer">
                            <input type="radio" wire:model="opcion" value="{{ $n }}" style="width:1.2rem;height:1.2rem">
                            {{ $o['texto'] ?? '' }}
                        </label>
                    @endforeach
                    @break

                @case('ubicar')
                    <p class="muted">Toquen la imagen donde está.</p>
                    <div x-data="{ p: $wire.entangle('punto') }" style="position:relative">
                        <img src="{{ Estacion::urlDeImagen($estacion->datos_respuesta['imagen'] ?? null) }}" alt=""
                             style="width:100%;border-radius:10px;display:block;cursor:crosshair"
                             @click="const r = $el.getBoundingClientRect(); p = { x: +((($event.clientX - r.left) / r.width) * 100).toFixed(2), y: +((($event.clientY - r.top) / r.height) * 100).toFixed(2) }">
                        <template x-if="p">
                            <span :style="`position:absolute;left:${p.x}%;top:${p.y}%;width:1.6rem;height:1.6rem;margin:-.8rem 0 0 -.8rem;border-radius:50%;border:3px solid #fff;background:var(--accent);box-shadow:0 0 0 2px var(--accent);pointer-events:none`"></span>
                        </template>
                    </div>
                    @break

                @case('enlazar')
                    <p class="muted">A cada uno, su pareja.</p>
                    @foreach (array_values($estacion->datos_respuesta['pares'] ?? []) as $n => $par)
                        <label for="enlace-{{ $n }}" style="font-size:1rem;color:var(--ink);font-weight:600">{{ $par['izquierda'] ?? '' }}</label>
                        <select id="enlace-{{ $n }}" wire:model="enlaces.{{ $n }}" required>
                            <option value="">Elijan…</option>
                            @foreach ($derecha as $d)
                                <option value="{{ $d['i'] }}">{{ $d['texto'] }}</option>
                            @endforeach
                        </select>
                    @endforeach
                    @break
            @endswitch

            <button class="boton" type="submit" style="margin-top:1rem" wire:loading.attr="disabled">Responder</button>
        </form>

    @elseif ($equipo->estado === 'secuencia')
        <div class="tarjeta">
            <h2>Lleven esta secuencia a su líder</h2>
            <p>Que la marque en el tablero de las gafas, en este orden:</p>
            <div class="secuencia">
                @foreach ($equipo->secuencia ?? [] as $n => $b)
                    <div style="background:{{ Equipo::BOTONES[$b][1] }}">
                        <span>{{ $n + 1 }}<small>{{ Equipo::BOTONES[$b][0] }}</small></span>
                    </div>
                @endforeach
            </div>
            <p class="muted">Cuando la marque bien, aquí aparece la siguiente etapa.</p>
        </div>

    @elseif ($equipo->terminado())
        @php $s = $equipo->segundos(); @endphp
        <div class="tarjeta" style="text-align:center">
            <h2>¡Terminaron el recorrido!</h2>
            <p style="font-size:2.2rem;font-weight:800;margin:.4rem 0;font-variant-numeric:tabular-nums">
                {{ sprintf('%d:%02d', intdiv($s, 60), $s % 60) }}
            </p>
            <p class="muted">
                Tiempo total{{ $equipo->penalizacion ? ', con ' . $equipo->penalizacion . ' s de penalización por ' . $equipo->fallos . ' ' . ($equipo->fallos === 1 ? 'fallo' : 'fallos') : '' }}.
            </p>
        </div>
    @endif
</div>
