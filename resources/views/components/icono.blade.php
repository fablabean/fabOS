@props(['nombre'])
@php
    // Trazos sencillos, del mismo grosor, para los encabezados de Mi cuenta.
    $trazos = [
        'asesorias'   => '<path d="M8 2v3M16 2v3M3 9h18M5 5h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2z"/><path d="M9 14l2 2 4-4"/>',
        'atender'     => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 11a3 3 0 1 0 0-6"/><path d="M17 14a6 6 0 0 1 4.5 6"/>',
        'formacion'   => '<path d="M2 9l10-5 10 5-10 5z"/><path d="M6 11.5V16c0 1.5 3 3 6 3s6-1.5 6-3v-4.5"/><path d="M22 9v6"/>',
        'habilitado'  => '<path d="M12 2l2.4 4.9 5.4.8-3.9 3.8.9 5.4L12 14.4 7.2 16.9l.9-5.4L4.2 7.7l5.4-.8z"/><path d="M8 21l4-2 4 2v-5"/>',
        'proponen'    => '<path d="M4 12h12"/><path d="M12 8l4 4-4 4"/><path d="M20 4v16"/>',
        'acompanar'   => '<circle cx="8" cy="7" r="3"/><circle cx="17" cy="9" r="2.5"/><path d="M2 20a6 6 0 0 1 12 0"/><path d="M13.5 20a4.5 4.5 0 0 1 8.5 0"/>',
        'reservas'    => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 10h18"/><path d="M8 14h3M13 14h3M8 18h3"/>',
        'proyectos'   => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'tiempo'      => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    ];
@endphp
<span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">{!! $trazos[$nombre] ?? $trazos['proyectos'] !!}</svg></span>
