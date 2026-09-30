{{-- Lo que ven los buscadores y los asistentes de IA de cada página (§20).

     Solo las páginas de la lista de Buscadores::INDEXABLES van a los
     buscadores; todas las demás —confirmaciones, cuentas, lo que va con
     token— llevan «noindex». Una página nueva nace fuera hasta que alguien
     decide que es vitrina. --}}
@php
    $indexable = \App\Support\Buscadores::indexable();
    $google = \App\Support\Buscadores::verificacionGoogle();
    $bing = \App\Support\Buscadores::verificacionBing();
@endphp

@if ($indexable)
    <meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1">
    <link rel="canonical" href="{{ url()->current() }}">
    {!! \App\Services\Buscadores\DatosEstructurados::etiqueta(
        app(\App\Services\Buscadores\DatosEstructurados::class)->organizacion(),
        request()->routeIs('publico.home') ? app(\App\Services\Buscadores\DatosEstructurados::class)->sitioWeb() : [],
    ) !!}
    @stack('datos-estructurados')
@else
    <meta name="robots" content="noindex, nofollow">
@endif

@if ($google)
    <meta name="google-site-verification" content="{{ $google }}">
@endif
@if ($bing)
    <meta name="msvalidate.01" content="{{ $bing }}">
@endif

@include('partials.analitica')
