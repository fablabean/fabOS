{{--
    Una página escrita en el panel (§3).

    La plantilla no decide nada del contenido: recorre los bloques y delega en
    la parcial de cada tipo. Un bloque nuevo es un fichero en `bloques/` y una
    entrada en `Pagina::BLOQUES`, sin tocar esto.

    Los estilos viven aquí y no en el layout porque solo los usa esta pantalla,
    y el layout lo carga el sitio entero.
--}}
@extends('layouts.publico')

@section('title', $pagina->titulo . ' · ' . config('fabos.lab.name'))
@section('description', $pagina->resumen ?: $pagina->titulo)

@section('styles')
    /* El aviso del borrador. Va fijo arriba y en un color que no se confunde
       con nada del sitio: mirar el borrador y mirar lo publicado son la misma
       pantalla, y sin esto es fácil creer que ya salió. */
    .borrador{
        background:#8A5A00;color:#FFF6E5;text-align:center;
        padding:.55rem 1.4rem;font-size:.86rem;
    }
    .borrador b{color:#fff}

    .cabecera{padding:3rem 0 1.6rem}
    .cabecera .portada{
        width:100%;aspect-ratio:21/9;object-fit:cover;display:block;
        border-radius:8px;border:1px solid var(--rule);background:var(--surface);
        margin-bottom:2rem;
    }
    .cabecera h1{margin-bottom:.8rem}

    /* Los bloques respiran entre ellos y no dentro: con `padding` propio, dos
       bloques seguidos sumaban el doble de aire del que se quería. */
    .bloque{margin:0 0 3rem}
    .bloque > h2{margin-bottom:.9rem}

    /* ---------- texto ---------- */
    .prosa{max-width:42rem;color:var(--ink-soft)}
    .prosa h2,.prosa h3{color:var(--ink);letter-spacing:-.02em;margin:1.8rem 0 .5rem}
    .prosa h3{font-size:1.1rem}
    .prosa p{margin:0 0 1rem}
    .prosa ul,.prosa ol{margin:0 0 1rem;padding-left:1.3rem}
    .prosa li{margin-bottom:.35rem}
    .prosa blockquote{
        margin:1.4rem 0;padding:.2rem 0 .2rem 1.1rem;
        border-left:3px solid var(--accent);color:var(--ink);font-style:italic;
    }

    /* ---------- imagen y video ---------- */
    .medio{margin:0}
    .medio img,.medio video{
        width:100%;display:block;border-radius:8px;
        border:1px solid var(--rule);background:var(--surface);
    }
    .medio figcaption{font-size:.85rem;color:var(--muted);margin-top:.55rem}
    /* «De lado a lado» se sale del ancho de lectura sin romper el margen del
       teléfono: 100vw menos la barra de desplazamiento la deja pegada al
       borde y con desplazamiento horizontal en Windows. */
    .medio.completo{
        width:min(100%,92rem);
        margin-left:calc((min(70rem,100%) - min(100%,92rem)) / 2);
    }
    @media (max-width:74rem){.medio.completo{width:100%;margin-left:0}}

    /* ---------- galería ---------- */
    .galeria{display:grid;grid-template-columns:repeat(auto-fill,minmax(15rem,1fr));gap:.8rem}
    .galeria figure{margin:0}
    .galeria img{
        width:100%;aspect-ratio:4/3;object-fit:cover;display:block;
        border-radius:6px;border:1px solid var(--rule);background:var(--surface);
    }
    .galeria figcaption{font-size:.8rem;color:var(--muted);margin-top:.4rem}

    /* ---------- cifras ---------- */
    .cifras{display:grid;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));gap:.8rem}
    .cifra{
        background:var(--surface);border:1px solid var(--rule);border-radius:6px;
        padding:1.2rem 1rem;text-align:center;
    }
    .cifra b{display:block;font-size:2rem;letter-spacing:-.04em;line-height:1;color:var(--accent)}
    .cifra span{display:block;font-size:.85rem;color:var(--muted);margin-top:.4rem}

    /* ---------- ficha de datos ---------- */
    .ficha{
        background:var(--surface);border:1px solid var(--rule);border-radius:6px;
        padding:.4rem 1.2rem;max-width:42rem;
    }
    .ficha div{
        display:flex;gap:1rem;padding:.7rem 0;border-bottom:1px solid var(--rule);
        font-size:.92rem;flex-wrap:wrap;
    }
    .ficha div:last-child{border-bottom:0}
    .ficha dt{color:var(--muted);min-width:9rem;margin:0}
    .ficha dd{margin:0;color:var(--ink);font-weight:500}

    /* ---------- hitos ---------- */
    /* La línea se dibuja con un borde en la lista y no con un pseudoelemento
       por hito: así termina en el último punto en vez de colgar por debajo. */
    .hitos{list-style:none;margin:0;padding:0 0 0 1.5rem;border-left:2px solid var(--rule)}
    .hitos li{position:relative;padding:0 0 1.6rem}
    .hitos li:last-child{padding-bottom:0}
    .hitos li::before{
        content:'';position:absolute;left:-2.05rem;top:.45rem;
        width:.6rem;height:.6rem;border-radius:50%;
        background:var(--accent);box-shadow:0 0 0 4px var(--ground);
    }
    .hitos .cuando{
        font-family:ui-monospace,Consolas,monospace;font-size:.68rem;letter-spacing:.14em;
        text-transform:uppercase;color:var(--muted);display:block;margin-bottom:.15rem;
    }
    .hitos b{display:block;font-size:1rem;margin-bottom:.2rem}
    .hitos p{margin:0;color:var(--ink-soft);font-size:.92rem;max-width:42rem}

    /* ---------- botones ---------- */
    .botones{display:flex;gap:.7rem;flex-wrap:wrap}
    .botones .btn.secundario{
        background:transparent;color:var(--accent);border:1px solid var(--rule);
    }
    .botones .btn.secundario:hover{border-color:var(--accent)}
@endsection

@section('content')

@if ($borrador)
    <div class="borrador">
        <b>Esto es un borrador.</b>
        Solo lo ves tú porque puedes editarlo: quien abra esta dirección sin permiso recibe «no existe».
    </div>
@endif

<main>
    <header class="cabecera">
        @if ($pagina->portadaUrl())
            <img class="portada" src="{{ $pagina->portadaUrl() }}" alt="">
        @endif

        @if ($pagina->rotulo)
            <p class="rotulo">{{ $pagina->rotulo }}</p>
        @endif

        <h1>{{ $pagina->titulo }}</h1>

        @if ($pagina->resumen)
            <p class="lead">{{ $pagina->resumen }}</p>
        @endif
    </header>

    <section style="padding-top:0">
        @foreach ($bloques as $bloque)
            @include('publico.bloques.' . $bloque['tipo'], ['datos' => $bloque['datos']])
        @endforeach
    </section>
</main>

@endsection
