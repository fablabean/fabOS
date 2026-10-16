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

{{-- Limpia: el layout deja fuera el menú y el pie. El título, el resumen y la portada se quedan. --}}
@if ($pagina->esLimpia())
    @section('sin-marco', '1')
@endif
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

    /* Sin el marco del sitio, el primer módulo no arranca pegado al borde. */
    main.limpia{padding-top:2rem}

    /* En el celular la portada va de borde a borde: sin el margen de la
       página a los lados, sin aire arriba y sin esquinas. En una pantalla
       angosta ese marco se come la imagen. */
    @media (max-width:640px){
        main.con-portada{padding-top:0}
        main.con-portada .cabecera{padding-top:0}
        main .cabecera img.portada{
            width:calc(100% + 2.8rem);max-width:none;margin:0 -1.4rem 1.6rem;
            border-radius:0;border:0;
        }
    }
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

    /* ---------- tarjetas ---------- */
    .tarjetas{display:grid;gap:1.2rem;grid-template-columns:repeat(var(--columnas,3),minmax(0,1fr))}
    @media (max-width:900px){.tarjetas{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media (max-width:560px){.tarjetas{grid-template-columns:minmax(0,1fr)}}
    .tarjeta{min-width:0;display:flex;flex-direction:column;background:var(--surface);border:1px solid var(--rule);border-radius:10px;overflow:hidden}
    .tarjeta-imagen{display:block;aspect-ratio:4/3;overflow:hidden;background:var(--rule)}
    .tarjeta-imagen img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .25s}
    .tarjeta:hover .tarjeta-imagen img{transform:scale(1.03)}
    .tarjeta-cuerpo{display:flex;flex-direction:column;gap:.5rem;padding:1rem 1.1rem 1.15rem;flex:1}
    .tarjeta-cuerpo h3{margin:0;font-size:1.1rem}
    .tarjeta-cuerpo p{margin:0;color:var(--ink-soft);font-size:.93rem}
    /* El botón al pie de cada tarjeta, a la misma altura en toda la fila. */
    .tarjeta-cuerpo .btn{margin-top:auto;align-self:flex-start}

    /* Dos por fila en el celular: tarjetas angostas, todo un poco más chico y
       el botón a lo ancho, que es más fácil de pulsar con el pulgar. */
    @media (max-width:560px){
        .tarjetas.dos-en-celular{grid-template-columns:repeat(2,minmax(0,1fr));gap:.7rem}
        .dos-en-celular .tarjeta-cuerpo{padding:.7rem .75rem .8rem;gap:.35rem}
        .dos-en-celular .tarjeta-cuerpo h3{font-size:.95rem;line-height:1.25}
        .dos-en-celular .tarjeta-cuerpo p{font-size:.82rem;line-height:1.4}
        .dos-en-celular .tarjeta-cuerpo .btn{align-self:stretch;text-align:center;padding:.5rem .4rem;font-size:.85rem}
    }
@endsection

@section('content')

@if ($borrador)
    <div class="borrador">
        <b>Esto es un borrador.</b>
        Solo lo ves tú porque puedes editarlo: quien abra esta dirección sin permiso recibe «no existe».
    </div>
@endif

<main @class(['limpia' => $pagina->esLimpia(), 'con-portada' => (bool) $pagina->portadaUrl()])>
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
        {{-- Cada módulo se marca para la analítica: un clic en un botón o un
             enlace de dentro se cuenta con su texto y su destino. --}}
        @foreach ($bloques as $bloque)
            <div data-bloque="{{ $bloque['tipo'] }}-{{ $loop->iteration }}">
                @include('publico.bloques.' . $bloque['tipo'], ['datos' => $bloque['datos']])
            </div>
        @endforeach
    </section>
</main>

@endsection
