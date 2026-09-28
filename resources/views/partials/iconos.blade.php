{{-- El icono del sitio, en los tamaños que piden el navegador y el móvil.

     Manda el logo subido en Comunicaciones → Marca. Antes esto apuntaba
     siempre a los archivos de `public/img`, así que quien cambiaba la marca la
     veía cambiada en la barra y seguía viendo la vieja en la pestaña, en la
     misma pantalla.

     Con logo subido se pone ese y solo ese: dejar los de antes al lado sería
     darle al navegador dos iconos entre los que elegir, y elige él. --}}
@php $marca = \App\Support\Settings::logoParaLaWeb(); @endphp

@if ($marca)
    <link rel="icon" type="{{ $marca['tipo'] }}" href="{{ $marca['url'] }}">
    {{-- El de iOS no entiende SVG: se usa el PNG que se genera de la marca al
         guardarla. Solo si no lo hay, el del sistema. --}}
    @if ($marca['svg'])
        <link rel="apple-touch-icon" sizes="180x180" href="{{ \App\Support\Settings::iconoTactil() ?? asset('img/apple-touch-icon.png') }}">
    @else
        <link rel="apple-touch-icon" sizes="180x180" href="{{ $marca['url'] }}">
    @endif
@else
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('img/favicon-32.png') }}">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('img/icon-192.png') }}">
    <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('img/apple-touch-icon.png') }}">
@endif

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
