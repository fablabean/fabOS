{{-- Anterior y siguiente, fijos arriba mientras se baja por la ficha: el
     proyecto que se está viendo siempre a la vista, y el paso al de al lado a
     un clic. Pega justo debajo de la barra superior del panel (4rem). --}}
<nav class="recorrido-proyectos" aria-label="Recorrer los proyectos">
    @if ($anterior)
        <a href="{{ $url($anterior) }}" class="recorrido-paso" title="{{ $anterior->code }} · {{ $anterior->name }}">
            <x-filament::icon icon="heroicon-m-chevron-left" class="recorrido-icono" />
            <span class="recorrido-vecino">
                <span class="recorrido-codigo">{{ $anterior->code }}</span>
                <span class="recorrido-nombre">{{ $anterior->notes ? \Illuminate\Support\Str::limit($anterior->notes, 40) : $anterior->name }}</span>
            </span>
        </a>
    @else
        <span class="recorrido-paso is-vacio"></span>
    @endif

    <div class="recorrido-actual">
        <div class="recorrido-titulo">
            <span class="recorrido-codigo">{{ $proyecto->code }}</span>
            <strong>{{ $proyecto->name }}</strong>
            <span class="recorrido-etapa">{{ \App\Models\Project::ETAPAS[$proyecto->stage] ?? $proyecto->stage }}</span>
        </div>
        <div class="recorrido-detalle">
            @if (filled($proyecto->notes))
                <span class="recorrido-nota">{{ \Illuminate\Support\Str::limit($proyecto->notes, 90) }}</span> ·
            @endif
            @if ($posicion)
                {{ $posicion }} de {{ $total }} {{ $filtrado ? 'del listado como lo dejaste' : 'tuyos y sin responsable' }}
            @else
                Fuera del listado
            @endif
        </div>
    </div>

    @if ($siguiente)
        <a href="{{ $url($siguiente) }}" class="recorrido-paso is-siguiente" title="{{ $siguiente->code }} · {{ $siguiente->name }}">
            <span class="recorrido-vecino">
                <span class="recorrido-codigo">{{ $siguiente->code }}</span>
                <span class="recorrido-nombre">{{ $siguiente->notes ? \Illuminate\Support\Str::limit($siguiente->notes, 40) : $siguiente->name }}</span>
            </span>
            <x-filament::icon icon="heroicon-m-chevron-right" class="recorrido-icono" />
        </a>
    @else
        <span class="recorrido-paso is-vacio"></span>
    @endif
</nav>
