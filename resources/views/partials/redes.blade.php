{{-- Las redes del laboratorio, en el pie (§20).

     Salen de Comunicaciones → Buscadores y analítica, la misma lista que se
     declara a Google: así no hay dos sitios que mantener al día. Iconos de
     trazo, en el color del texto, para que no compitan con la marca. --}}
@php $redes = \App\Support\Buscadores::redesConNombre(); @endphp

@if ($redes)
    <span class="redes">
        @foreach ($redes as $r)
            <a href="{{ $r['url'] }}" target="_blank" rel="noopener me" title="{{ $r['nombre'] }}" aria-label="{{ $r['nombre'] }}">
                <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    @switch($r['red'])
                        @case('instagram')
                            <rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><path d="M17.5 6.5h.01"/>
                            @break
                        @case('linkedin')
                            <path d="M16 8a6 6 0 0 1 6 6v7h-4v-7a2 2 0 0 0-4 0v7h-4v-7a6 6 0 0 1 6-6z"/><rect x="2" y="9" width="4" height="12"/><circle cx="4" cy="4" r="2"/>
                            @break
                        @case('facebook')
                            <path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/>
                            @break
                        @case('youtube')
                            <path d="M2.5 17a24 24 0 0 1 0-10 2 2 0 0 1 1.4-1.4 50 50 0 0 1 16.2 0A2 2 0 0 1 21.5 7a24 24 0 0 1 0 10 2 2 0 0 1-1.4 1.4 50 50 0 0 1-16.2 0A2 2 0 0 1 2.5 17"/><path d="m10 15 5-3-5-3z"/>
                            @break
                        @case('tiktok')
                            <path d="M9 12a4 4 0 1 0 4 4V2a5 5 0 0 0 5 5"/>
                            @break
                        @case('x')
                            <path d="M4 4l11.7 16H20L8.3 4z"/><path d="M4 20l6.8-6.8M13.2 10.8 20 4"/>
                            @break
                        @case('whatsapp')
                            <path d="M7.9 20A9 9 0 1 0 4 16.1L2 22z"/>
                            @break
                        @default
                            <circle cx="12" cy="12" r="10"/><path d="M12 2a14.5 14.5 0 0 0 0 20 14.5 14.5 0 0 0 0-20M2 12h20"/>
                    @endswitch
                </svg>
            </a>
        @endforeach
    </span>
@endif
