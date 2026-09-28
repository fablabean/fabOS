{{-- La vista previa al compartir un enlace (WhatsApp, Facebook, LinkedIn…).
     Sin esto, esos sitios tomaban el único PNG que encontraban —el icono del
     sistema— y el enlace salía con la marca vieja. --}}
@php $imagenCompartir = \App\Support\Settings::imagenParaCompartir(); @endphp
<meta property="og:site_name" content="{{ config('fabos.lab.name') }}">
<meta property="og:type" content="website">
<meta property="og:url" content="{{ url()->current() }}">
<meta property="og:title" content="@yield('title', config('fabos.lab.name'))">
<meta property="og:description" content="@yield('description', config('fabos.lab.tagline') . ' de ' . config('fabos.lab.institution') . '.')">
@if ($imagenCompartir)
    <meta property="og:image" content="{{ $imagenCompartir }}">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="600">
    <meta property="og:image:height" content="600">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:image" content="{{ $imagenCompartir }}">
@endif
