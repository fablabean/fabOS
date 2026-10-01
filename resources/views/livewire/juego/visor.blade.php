{{-- Lo mismo que ven las gafas: la pista y los cuatro botones. --}}
<div wire:poll.4s x-data="{ marcados: [] }">
    <h1 style="display:flex;align-items:center;gap:.5rem">
        <span style="width:.9rem;height:.9rem;border-radius:50%;background:{{ $estado['equipo']['color'] }};flex:none"></span>
        Visor · {{ $estado['equipo']['nombre'] }}
    </h1>
    <p class="muted" style="margin:0">
        Etapa {{ $estado['etapa'] }} de {{ $estado['total_etapas'] }} · {{ $estado['estado_texto'] }}
        @if ($estado['lider']) · Líder: {{ $estado['lider']['nombre'] }} @endif
    </p>

    @if ($aviso)
        <div class="aviso {{ $avisoBueno ? 'bueno' : 'malo' }}">{{ $aviso }}</div>
    @endif

    @if ($estado['pista'])
        <div class="tarjeta">
            <h2>La pista</h2>
            <p style="font-size:1.2rem;white-space:pre-line">{{ $estado['pista']['texto'] }}</p>
            @if ($estado['pista']['imagen'])
                <img src="{{ $estado['pista']['imagen'] }}" alt="" style="width:100%;border-radius:10px">
            @endif
        </div>
    @endif

    @if ($estado['estado'] === 'secuencia')
        <div class="tarjeta">
            <h2>Marca la secuencia</h2>
            <div style="display:flex;gap:.4rem;min-height:2.2rem;margin-bottom:.6rem">
                <template x-for="(b, i) in marcados" :key="i">
                    <span :style="`width:2.2rem;height:2.2rem;border-radius:8px;background:${ {{ json_encode(collect($estado['botones'])->pluck('color', 'valor')) }}[b] }`"></span>
                </template>
            </div>
            <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:.6rem">
                @foreach ($estado['botones'] as $b)
                    <button type="button" class="boton" style="background:{{ $b['color'] }};padding:1.4rem"
                            @click="if (marcados.length < 4) { marcados.push({{ $b['valor'] }}); if (marcados.length === 4) { $wire.marcar(marcados).then(() => marcados = []) } }">
                        {{ $b['nombre'] }}
                    </button>
                @endforeach
            </div>
            <button type="button" class="boton" style="background:var(--muted);margin-top:.6rem" @click="marcados = []">Borrar</button>
        </div>
    @elseif ($estado['estado'] === 'terminado')
        <div class="tarjeta"><h2>¡Terminaron!</h2></div>
    @endif

    <p class="muted">Visor web para el equipo del laboratorio: hace lo mismo que la app de las gafas.</p>
</div>
