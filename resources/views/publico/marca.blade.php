@extends('layouts.publico')

@section('title', 'La marca · ' . config('fabos.lab.name'))
@section('description', 'Descarga el logo de ' . config('fabos.lab.name') . ' en sus distintas versiones.')

@section('styles')
    /* Cada pieza sobre el fondo que le toca. No es decoración: una versión
       clara sobre blanco no se ve, y es la forma más rápida de que alguien se
       lleve la equivocada creyendo que el archivo está roto. */
    .piezas{display:grid;grid-template-columns:repeat(auto-fill,minmax(17rem,1fr));gap:1rem;margin:0 0 1rem}
    .pieza{border:1px solid var(--rule);border-radius:8px;overflow:hidden;background:var(--surface)}
    .pieza .lienzo{
        display:flex;align-items:center;justify-content:center;
        min-height:9rem;padding:1.6rem;background:#fff;
    }
    .pieza.sobre-oscuro .lienzo{background:#171A15}
    .pieza .lienzo img{max-width:100%;max-height:5rem;width:auto;height:auto;display:block}
    .pieza .pie{padding:.8rem .95rem;border-top:1px solid var(--rule)}
    .pieza .pie b{display:block;font-size:.95rem}
    .pieza .pie span{display:block;font-size:.82rem;color:var(--ink-soft);line-height:1.45;margin:.2rem 0 .6rem}
    .pieza .pie .baja{
        display:inline-flex;align-items:center;gap:.35rem;font-size:.84rem;font-weight:600;
        color:var(--accent);text-decoration:none;
    }
    .pieza .pie .baja::after{content:'↓'}
    .pieza .pie small{
        display:block;margin-top:.35rem;font-family:ui-monospace,Consolas,monospace;
        font-size:.7rem;color:var(--muted);word-break:break-all;
    }

    /* Las variaciones, más pequeñas: son el archivador, no lo principal. */
    .sueltas{display:grid;grid-template-columns:repeat(auto-fill,minmax(11rem,1fr));gap:.8rem}
    .suelta{
        display:block;border:1px solid var(--rule);border-radius:8px;overflow:hidden;
        background:var(--surface);text-decoration:none;color:inherit;
    }
    .suelta:hover{border-color:var(--accent)}
    /* A cuadros: una variante puede ser blanca o negra y no hay forma de
       saberlo de antemano. Sobre el damero se ve cualquiera de las dos, y
       además se ve dónde acaba el dibujo y empieza la transparencia. */
    .suelta .lienzo{
        display:flex;align-items:center;justify-content:center;min-height:7rem;padding:1.1rem;
        background:
            linear-gradient(45deg,#d8d8d2 25%,transparent 25%,transparent 75%,#d8d8d2 75%),
            linear-gradient(45deg,#d8d8d2 25%,transparent 25%,transparent 75%,#d8d8d2 75%),
            #f2f2ee;
        background-size:16px 16px;background-position:0 0,8px 8px;
    }
    .suelta .lienzo img{max-width:100%;max-height:4rem;width:auto;height:auto;display:block}
    .suelta .pie{padding:.5rem .7rem;border-top:1px solid var(--rule);font-size:.75rem}
    .suelta .pie b{display:block;font-family:ui-monospace,Consolas,monospace;font-weight:500;word-break:break-all}
    .suelta .pie span{color:var(--muted)}

    .todo{display:flex;flex-wrap:wrap;gap:.7rem;align-items:center;margin:1.4rem 0 0}
    .todo .nota{font-size:.85rem;color:var(--muted)}
@endsection

@section('content')
<main>
    <section style="padding-bottom:1.4rem">
        <p class="rotulo">Marca</p>
        <h1>El logo de <x-nombre-lab/></h1>
        <p class="lead">
            Si vas a poner nuestro logo en un afiche, una nota o tu sitio, bájalo de aquí.
            Esta página sale de la misma marca que usa el laboratorio, así que siempre está
            al día: no hace falta pedirnos el archivo.
        </p>

        @if ($versiones || $variaciones)
            <div class="todo">
                <a class="btn" href="{{ route('marca.publica.zip') }}">Descargar todo</a>
                <span class="nota">
                    Un solo archivo con {{ count($versiones) + count($variaciones) }}
                    {{ count($versiones) + count($variaciones) === 1 ? 'pieza' : 'piezas' }}.
                </span>
            </div>
        @endif
    </section>

    @if ($versiones)
        <section style="padding-top:0">
            <h2>Las versiones</h2>
            <p class="lead" style="margin-bottom:1.2rem">
                Cada una tiene su sitio. Usa la horizontal salvo que el espacio sea cuadrado,
                y la de fondo oscuro cuando la vayas a poner sobre algo oscuro: no es la misma
                imagen aclarada, está dibujada aparte.
            </p>

            <div class="piezas">
                @foreach ($versiones as $pieza)
                    <div class="pieza {{ $pieza['oscuro'] ? 'sobre-oscuro' : '' }}">
                        <div class="lienzo">
                            <img src="{{ $pieza['url'] }}" alt="{{ $pieza['nombre'] }}" loading="lazy">
                        </div>
                        <div class="pie">
                            <b>{{ $pieza['nombre'] }}</b>
                            <span>{{ $pieza['nota'] }}</span>
                            {{-- Con nombre: el archivo se guardó con un
                                 identificador al azar, que está bien en el
                                 disco y no dice nada en la carpeta de
                                 descargas de otra persona. --}}
                            <a class="baja" href="{{ $pieza['url'] }}" download="{{ $pieza['archivo'] }}">Descargar</a>
                            <small>{{ $pieza['archivo'] }} · {{ $pieza['peso'] }}</small>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if ($variaciones)
        <section style="padding-top:0">
            <h2>Otras variaciones</h2>
            <p class="lead" style="margin-bottom:1.2rem">
                Las que no usamos en el sitio pero existen: verticales, de una tinta, en blanco.
                Si ninguna de arriba te sirve, mira aquí antes de recortar una captura.
            </p>

            <div class="sueltas">
                @foreach ($variaciones as $suelta)
                    <a class="suelta" href="{{ $suelta['url'] }}" download>
                        <div class="lienzo">
                            <img src="{{ $suelta['url'] }}" alt="{{ $suelta['archivo'] }}" loading="lazy">
                        </div>
                        <div class="pie">
                            <b>{{ $suelta['archivo'] }}</b>
                            <span>{{ $suelta['peso'] }} · descargar</span>
                        </div>
                    </a>
                @endforeach
            </div>
        </section>
    @endif

    @if (! $versiones && ! $variaciones)
        <section style="padding-top:0">
            <p class="lead">
                Todavía no hay archivos publicados. Escríbenos y te los pasamos.
            </p>
        </section>
    @endif

    <section style="padding-top:0">
        <h2>Cómo usarlo</h2>
        <p class="lead">
            Deja aire alrededor del logo y no lo estires: si necesitas otro tamaño, cambia el
            alto y deja que el ancho siga la proporción. No le cambies los colores ni lo
            metas dentro de una caja de otro color: para eso está la versión de fondo
            oscuro. Y si vas a anunciar algo a nombre del laboratorio, cuéntanoslo antes.
        </p>
    </section>
</main>
@endsection
