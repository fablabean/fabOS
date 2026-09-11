{{--
    El dibujo de cada módulo del mapa.

    En línea y con `currentColor`, como los de la página de reservas: heredan el
    color del tema y no cuestan una petición ni dependen de una fuente de
    iconos. El nombre lo dice `config/fabos.roadmap`, para que añadir un módulo
    sea tocar una lista y no buscar dónde se pintan.

    Todos en la misma rejilla de 48×48 y con el mismo grosor de trazo: mezclar
    tamaños hace que unos se vean gordos y otros esmirriados en la misma fila.
--}}
@props(['icono' => null])

<span class="icono" aria-hidden="true">
    <svg viewBox="0 0 48 48" fill="none" stroke="currentColor" stroke-width="2.5"
         stroke-linecap="round" stroke-linejoin="round">
        @switch($icono)
            @case('llave')
                {{-- Una llave: se entra sin contraseña. --}}
                <circle cx="15" cy="24" r="8"/>
                <path d="M23 24h20"/><path d="M37 24v7"/><path d="M30 24v5"/>
                @break

            @case('fichas')
                {{-- Cuatro fichas: el catálogo. --}}
                <rect x="7" y="8" width="15" height="15" rx="2.5"/>
                <rect x="26" y="8" width="15" height="15" rx="2.5"/>
                <rect x="7" y="25" width="15" height="15" rx="2.5"/>
                <rect x="26" y="25" width="15" height="15" rx="2.5"/>
                @break

            @case('calendario')
                {{-- Un calendario con su día tomado. --}}
                <rect x="7" y="11" width="34" height="30" rx="3"/>
                <path d="M7 20h34"/><path d="M17 6v9M31 6v9"/>
                <rect x="14" y="26" width="7" height="6" rx="1.5"/>
                @break

            @case('insignia')
                {{-- Una insignia con su cinta: el certifab. --}}
                <circle cx="24" cy="18" r="11"/>
                <path d="M17 27.5L14 43l10-5.5L34 43l-3-15.5"/>
                @break

            @case('birrete')
                {{-- Un birrete: la escalera de cursos. --}}
                <path d="M24 8L4 17l20 9 20-9z"/>
                <path d="M13 21.5V32c0 3.3 4.9 6 11 6s11-2.7 11-6V21.5"/>
                <path d="M42 18v11"/>
                @break

            @case('engranaje')
                {{-- Un engranaje: lo que se revisa para que no falle. --}}
                <circle cx="24" cy="24" r="7"/>
                <path d="M24 5v6M24 37v6M5 24h6M37 24h6"/>
                <path d="M10.6 10.6l4.2 4.2M33.2 33.2l4.2 4.2M37.4 10.6l-4.2 4.2M14.8 33.2l-4.2 4.2"/>
                @break

            @case('moneda')
                {{-- Una moneda: la del laboratorio. --}}
                <circle cx="24" cy="24" r="16"/>
                <path d="M24 13v22"/>
                <path d="M29 18.5a5 5 0 0 0-5-2.5c-3 0-5.2 1.9-5.2 4.2s2.2 3.7 5.2 4.3 5.2 2 5.2 4.3-2.2 4.2-5.2 4.2a5 5 0 0 1-5-2.5"/>
                @break

            @case('bolsa')
                {{-- Una bolsa: la tienda. --}}
                <path d="M9 15h30l-2.7 24.6a3.2 3.2 0 0 1-3.2 2.9H14.9a3.2 3.2 0 0 1-3.2-2.9z"/>
                <path d="M17 15v-3a7 7 0 0 1 14 0v3"/>
                @break

            @case('tablero')
                {{-- Un tablero de columnas: el proyecto avanzando. --}}
                <rect x="6" y="9" width="36" height="30" rx="3"/>
                <path d="M18 9v30M30 9v30"/>
                <path d="M10 16h4M22 16h4M34 16h4M10 23h4M22 23h4"/>
                @break

            @default
                {{-- Sin dibujo asignado: un punto, y nunca un hueco roto. --}}
                <circle cx="24" cy="24" r="8"/>
        @endswitch
    </svg>
</span>
