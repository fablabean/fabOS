{{-- La vista previa al compartir un enlace (WhatsApp, Facebook, LinkedIn…).
     Sin esto, esos sitios tomaban el único PNG que encontraban —el icono del
     sistema— y el enlace salía con la marca vieja.

     Una página con imagen propia —el banner de un taller, la portada de Fab
     Academy— la pone con @section('imagen', url) y sale en grande; sin ella,
     va la marca en cuadrado. --}}
@php
    $imagenDeLaPagina = trim((string) $__env->yieldContent('imagen'));
    $imagenCompartir = $imagenDeLaPagina ?: \App\Support\Settings::imagenParaCompartir();
@endphp
<meta property="og:site_name" content="{{ config('fabos.lab.name') }}">
<meta property="og:type" content="website">
<meta property="og:locale" content="es_CO">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:title" content="@yield('title', config('fabos.lab.name'))">
<meta property="og:description" content="@yield('description', \App\Support\Buscadores::descripcion())">
@if ($imagenDeLaPagina)
    <meta property="og:image" content="{{ $imagenDeLaPagina }}">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:image" content="{{ $imagenDeLaPagina }}">
@elseif ($imagenCompartir)
    <meta property="og:image" content="{{ $imagenCompartir }}">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="600">
    <meta property="og:image:height" content="600">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:image" content="{{ $imagenCompartir }}">
@endif
