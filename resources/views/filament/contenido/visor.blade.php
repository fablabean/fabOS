{{--
    Un aporte del banco, visto sin salir de la lista (§21).

    Antes esto abría una pestaña nueva con el archivo desnudo: para repasar
    quince fotos había que abrir quince pestañas y volver cada vez, y el sitio
    donde se decide si un aporte se reconoce es justo la lista, con el resto
    delante. Aquí se mira y se cierra.

    El archivo se pide por `contenido.archivo`, que comprueba quién lo pide: no
    hay URL adivinable, ni dentro del panel ni fuera. Es material de personas.
--}}
<div class="space-y-3 p-2">

    <div class="overflow-hidden rounded-lg bg-black/90 flex items-center justify-center"
         style="max-height:70vh">
        @if ($contenido->esVideo())
            {{-- `preload="metadata"`: la lista puede tener videos de varios
                 megas y quien abre el modal todavia no ha dicho que quiera
                 verlo entero. --}}
            <video src="{{ $contenido->enlace() }}"
                   controls
                   preload="metadata"
                   style="max-height:70vh;max-width:100%"
                   class="block"></video>
        @else
            <img src="{{ $contenido->enlace() }}"
                 alt="{{ $contenido->comoSeLlama() }}"
                 style="max-height:70vh;max-width:100%;object-fit:contain"
                 class="block">
        @endif
    </div>

    @if ($contenido->description)
        <p class="text-sm text-gray-600 dark:text-gray-300">{{ $contenido->description }}</p>
    @endif

    {{-- Lo que hace falta para decidir si se reconoce, que es a lo que se viene:
         quién lo grabó y cuándo. El peso y el nombre original van detrás porque
         importan cuando hay que buscarlo fuera, no al mirarlo. --}}
    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-4">
        @foreach ([
            'Quién lo grabó' => $contenido->user?->name,
            'Cuándo'         => $contenido->created_at?->timezone(config('fabos.lab.timezone'))->format('d/m/Y H:i'),
            'Proyecto'       => $contenido->project?->name,
            'Área'           => $contenido->area?->name,
            'Peso'           => $contenido->peso(),
            'Archivo'        => $contenido->original_name,
        ] as $clave => $valor)
            @if (filled($valor))
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">{{ $clave }}</dt>
                    <dd class="font-medium text-gray-900 dark:text-white">{{ $valor }}</dd>
                </div>
            @endif
        @endforeach
    </dl>

    @if (! $contenido->estaDisponible())
        {{-- Retirado se sigue pudiendo mirar desde el panel —el archivo no se
             borra, es de quien lo grabó— pero tiene que decirlo bien claro:
             es material que alguien pidió no usar. --}}
        <p class="rounded-md bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
            <strong>Retirado{{ $contenido->withdrawn_reason ? ':' : '.' }}</strong>
            {{ $contenido->withdrawn_reason }}
            No se puede usar para divulgación.
        </p>
    @endif

    {{-- Para descargarlo o verlo a tamaño completo. Se queda, porque el modal
         lo encoge a la pantalla y a veces hay que mirar un detalle. --}}
    <a href="{{ $contenido->enlace() }}"
       target="_blank"
       rel="noopener"
       class="inline-flex items-center gap-1 text-sm text-primary-600 hover:underline dark:text-primary-400">
        Abrir el archivo aparte
    </a>
</div>
