@extends('layouts.publico')
@php
    use App\Support\Galeria3d;
    $tituloPagina = $esFicha ? $actual['nombre'] . ' · Galería 3D' : 'Galería 3D';
    $medidas = fn ($m) => $m['medidas']
        ? collect([$m['medidas']['ancho'], $m['medidas']['fondo'], $m['medidas']['alto']])->map(fn ($v) => number_format($v * 100, 1, ',', '.'))->implode(' × ') . ' cm'
        : '—';
    // A dónde lleva el QR (el dominio, o el enlace del túnel del celular): la
    // dirección de la imagen cambia con él, para que no quede uno viejo en caché.
    $baseQr = Galeria3d::baseQr();
    $versionQr = substr(md5($baseQr), 0, 8);
    $qrAlcanzable = Galeria3d::qrAlcanzable();
    // Lo que necesita el visor de cada modelo, nada más.
    $datos = $modelos->map(fn ($m) => [
        'slug'       => $m['slug'],
        'nombre'     => $m['nombre'],
        'categoria'  => $m['categoria_nombre'],
        'tecnica'    => $m['tecnica'],
        'maquina'    => $m['maquina'],
        'material'   => $m['material'],
        'resumen'    => $m['resumen'],
        'descripcion'=> $m['descripcion'],
        'urls'       => $m['urls'],
        'bytes'      => $m['bytes'],
        'medidas'    => $medidas($m),
        'triangulos' => $m['triangulos'],
        'texturas'   => $m['texturas'],
        'fuente'     => $m['fuente'],
        'animado'    => $m['animado'],
        'escalaFija' => $m['escala_fija'],
        'alejar'     => $m['alejar'] ?? 1,
        'acabados'   => $m['acabados'],
        'pagina'     => route('galeria.show', $m['slug']),
        // Para compartir: la dirección que abre en cualquier parte (el dominio o el túnel).
        'compartir'  => $baseQr . '/galeria-3d/' . rawurlencode($m['slug']),
        'contar'     => route('galeria.contar', $m['slug']),
        'cifras'     => $contadores[$m['slug']] ?? ['vistas' => 0, 'me_gusta' => 0, 'descargas' => 0],
        'meGusta'    => in_array($m['slug'], $meGusta, true),
        'etiquetas'  => array_keys(Galeria3d::etiquetas($m)),
        'materialImpresion' => Galeria3d::materialDeImpresion($m),
        // ancho, fondo y alto en milímetros (las medidas vienen en metros)
        'mm'         => $m['medidas'] ? [round($m['medidas']['ancho'] * 1000, 1), round($m['medidas']['fondo'] * 1000, 1), round($m['medidas']['alto'] * 1000, 1)] : null,
        'qr'         => route('galeria.qr', ['modelo' => $m['slug'], 'b' => $versionQr]),
        'ra'         => route('galeria.ra', $m['slug']),
    ])->values();
    $vendor = asset('3d/vendor/three-r186');
    // Armado aquí y no dentro de @json: Blade parte sus argumentos por las comas.
    $impresion = config('galeria3d.impresion');
    $datosPagina = ['modelos' => $datos, 'actual' => $actual['slug'], 'base' => route('galeria.index'), 'qrAlcanzable' => $qrAlcanzable,
        'token' => csrf_token(), 'impresion' => $impresion, 'baseCompartir' => $baseQr];
    $cifrasActual = $contadores[$actual['slug']] ?? ['vistas' => 0, 'me_gusta' => 0, 'descargas' => 0];
    $num = fn ($n) => number_format((int) $n, 0, ',', '.');
    // El botón «Encender el QR para el celular», solo en el computador de desarrollo.
    if (app()->environment('local')) {
        $datosPagina['tunel'] = ['estado' => route('galeria.tunel'), 'encender' => route('galeria.tunel.encender'), 'token' => csrf_token()];
    }
    // «Sube tu modelo 3D»: cualquiera lo abre en su navegador (no se guarda);
    // quien administra la galería puede además publicarlo, en el panel.
    $datosPagina['subir'] = [
        'publicar' => auth()->check() && \App\Filament\Resources\Galeria3d\Galeria3dResource::canAccess()
            ? \App\Filament\Resources\Galeria3d\Galeria3dResource::getUrl('create')
            : null,
    ];
    $formatosSubir = '.stl,.step,.stp,.iges,.igs,.obj,.mtl,.fbx,.3mf,.glb,.gltf,.bin,.png,.jpg,.jpeg,.webp,.tga,.bmp';
@endphp
@section('title', $tituloPagina . ' · ' . config('fabos.lab.name'))
@section('description', $esFicha
    ? $actual['resumen']
    : 'Modelos 3D de lo que se fabrica en ' . config('fabos.lab.name') . ': gíralos en el navegador, explora sus materiales y míralos en tu espacio con realidad aumentada.')

@section('styles')
    /* ---------- Galería 3D ---------- */
    .g3d{padding-bottom:1rem}
    .g3d .cabecera{padding:2.6rem 0 1.4rem}
    .g3d .cabecera p.lead{max-width:52rem;margin:0}
    .g3d .pistas{display:flex;flex-wrap:wrap;gap:.4rem .9rem;margin:.9rem 0 0;padding:0;list-style:none;font-size:.84rem;color:var(--muted)}
    .g3d .pistas li{display:inline-flex;align-items:center;gap:.35rem}
    .g3d .pistas svg{width:1rem;height:1rem;color:var(--accent)}

    /* Sube tu modelo 3D */
    .g3d .subir{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem 1rem;margin-top:1.2rem}
    .g3d .subir-boton{display:inline-flex;align-items:center;gap:.5rem;padding:.7rem 1.2rem;border-radius:999px;background:var(--accent);color:var(--surface);font-weight:600;font-size:.95rem;cursor:pointer;box-shadow:0 4px 14px color-mix(in srgb,var(--accent) 30%,transparent);transition:filter .15s ease,transform .15s ease;flex:none}
    .g3d .subir-boton:hover{filter:brightness(1.08);transform:translateY(-1px)}
    .g3d .subir-boton svg{width:1.2rem;height:1.2rem}
    .g3d .subir-boton[aria-disabled="true"]{opacity:.65;cursor:progress;transform:none}
    .g3d .subir-entrada{position:absolute;width:1px;height:1px;opacity:0;pointer-events:none}
    .g3d .subir:has(.subir-entrada:focus-visible) .subir-boton{outline:2px solid var(--accent);outline-offset:3px}
    .g3d .subir-texto{margin:0;font-size:.82rem;color:var(--muted);max-width:34rem;line-height:1.45}
    .g3d .subir-estado{flex-basis:100%;margin:0;font-size:.86rem;color:var(--ink)}
    .g3d .subir-estado:empty{display:none}
    .g3d .subir-estado.error{color:#b42318}
    @media (hover:none){ .g3d .subir-soltar{display:none} }
    .g3d .visor.encima{outline:3px dashed var(--accent);outline-offset:-10px}
    .g3d .propio{margin-top:1.1rem;padding:.9rem 1rem;border-radius:8px;background:color-mix(in srgb,var(--accent) 8%,var(--surface));border:1px solid color-mix(in srgb,var(--accent) 30%,var(--rule))}
    .g3d .propio[hidden]{display:none}
    .g3d .propio p{margin:0;font-size:.84rem;color:var(--ink-soft)}
    .g3d .propio strong{display:block;color:var(--ink);font-size:.9rem;margin-bottom:.2rem}
    .g3d .propio .propio-nota{margin-top:.4rem;font-size:.78rem;color:var(--muted)}
    .g3d .tarjeta.propia .cat{color:#9a5b00}

    /* El estudio: el visor y su ficha, lado a lado; uno sobre otro en el teléfono. */
    .g3d .estudio{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(19rem,1fr);gap:1.2rem;align-items:start}
    @media (max-width:62rem){ .g3d .estudio{grid-template-columns:minmax(0,1fr)} }

    .g3d .visor{
        position:relative;aspect-ratio:4/3;min-height:19rem;border:1px solid var(--rule);border-radius:8px;overflow:hidden;
        background:radial-gradient(120% 90% at 50% 35%, var(--surface) 0%, color-mix(in srgb,var(--ground) 70%,var(--rule)) 100%);
        touch-action:none;outline:none;
    }
    .g3d .visor:focus-visible{box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 45%,transparent)}
    @media (max-width:40rem){ .g3d .visor{aspect-ratio:1/1.05;min-height:0} }
    .g3d .visor canvas{position:absolute;inset:0;width:100%;height:100%;display:block;cursor:grab}
    .g3d .visor canvas:active{cursor:grabbing}
    .g3d .visor .poster{
        position:absolute;inset:8%;width:84%;height:84%;object-fit:contain;pointer-events:none;
        transition:opacity .35s ease;
    }
    .g3d .visor.cargado .poster{opacity:0}
    .g3d .visor .barra{position:absolute;left:0;top:0;height:3px;width:0;background:var(--accent);transition:width .15s ease,opacity .4s ease;z-index:3}
    .g3d .visor.cargado .barra{opacity:0}
    .g3d .visor .aviso{
        position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);z-index:4;max-width:min(26rem,86%);
        background:color-mix(in srgb,var(--surface) 94%,transparent);border:1px solid var(--rule);border-radius:8px;
        padding:1rem 1.2rem;font-size:.92rem;text-align:center;box-shadow:0 10px 30px rgba(0,0,0,.12)
    }
    .g3d .visor .aviso[hidden]{display:none}
    .g3d .visor .aviso p{margin:0 0 .6rem}
    .g3d .visor .aviso p:last-child{margin-bottom:0}
    .g3d .visor .ayuda{
        position:absolute;left:.8rem;top:.8rem;z-index:2;font-size:.78rem;color:var(--ink-soft);
        background:color-mix(in srgb,var(--surface) 85%,transparent);border:1px solid var(--rule);border-radius:999px;
        padding:.3rem .75rem;pointer-events:none;transition:opacity .5s ease;
    }
    .g3d .visor .ayuda.oculta{opacity:0}

    /* Botones redondos sobre el visor: mismo lenguaje que el selector de tema. */
    .g3d .redondo{
        display:inline-flex;align-items:center;justify-content:center;width:2.4rem;height:2.4rem;margin:0;padding:0;
        border:1px solid var(--rule);border-radius:999px;background:color-mix(in srgb,var(--surface) 90%,transparent);
        color:var(--ink-soft);cursor:pointer;backdrop-filter:blur(6px);
    }
    .g3d .redondo svg{width:1.15rem;height:1.15rem}
    .g3d .redondo:hover{color:var(--accent);border-color:color-mix(in srgb,var(--accent) 55%,var(--rule))}
    .g3d .redondo[aria-pressed="true"]{background:color-mix(in srgb,var(--accent) 16%,var(--surface));color:var(--accent);border-color:color-mix(in srgb,var(--accent) 55%,transparent)}
    .g3d .redondo:focus-visible,.g3d .chip:focus-visible,.g3d .pestana:focus-visible,.g3d .boton-ar:focus-visible{outline:2px solid var(--accent);outline-offset:2px}
    .g3d .redondo[hidden]{display:none}
    .g3d .herramientas{position:absolute;left:.7rem;bottom:.7rem;z-index:2;display:flex;gap:.35rem;flex-wrap:wrap;max-width:calc(100% - 13rem)}
    .g3d .flecha{position:absolute;top:50%;transform:translateY(-50%);z-index:2}
    .g3d .flecha.anterior{left:.7rem}
    .g3d .flecha.siguiente{right:.7rem}
    .g3d .boton-ar{
        position:absolute;right:.7rem;bottom:.7rem;z-index:2;display:inline-flex;align-items:center;gap:.45rem;
        padding:.6rem 1rem;border-radius:999px;border:0;background:var(--accent);color:var(--surface);
        font:inherit;font-size:.9rem;font-weight:600;cursor:pointer;text-decoration:none;box-shadow:0 6px 18px rgba(0,0,0,.18)
    }
    .g3d .boton-ar svg{width:1.2rem;height:1.2rem}
    .g3d .boton-ar:hover{filter:brightness(1.08)}
    .g3d .boton-ar[hidden]{display:none}
    @media (max-width:40rem){
        .g3d .herramientas{max-width:none;right:.7rem;bottom:3.9rem;justify-content:flex-start}
        .g3d .boton-ar{left:.7rem;right:.7rem;justify-content:center}
        .g3d .flecha{top:42%}
        .g3d .solo-ancho{display:none}
    }
    /* Capa por capa */
    .g3d .capas{position:absolute;left:.7rem;right:.7rem;top:.7rem;z-index:3;display:flex;align-items:center;gap:.6rem;padding:.45rem .55rem;border:1px solid var(--rule);border-radius:999px;background:color-mix(in srgb,var(--surface) 92%,transparent);backdrop-filter:blur(6px);box-shadow:0 6px 18px rgba(0,0,0,.1)}
    .g3d .capas[hidden]{display:none}
    .g3d .capas-centro{flex:1;min-width:0;display:grid;gap:.15rem}
    .g3d .capas-centro label{font-size:.78rem;color:var(--ink-soft);font-variant-numeric:tabular-nums;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .g3d .capas-centro input{width:100%;accent-color:var(--accent);margin:0}
    .g3d .visor.con-capas .ayuda{display:none}

    /* Comparar el tamaño */
    .g3d .comparar{position:absolute;left:.7rem;right:.7rem;top:.7rem;z-index:3;display:flex;align-items:center;gap:.6rem;padding:.45rem .55rem .45rem .9rem;border:1px solid var(--rule);border-radius:18px;background:color-mix(in srgb,var(--surface) 92%,transparent);backdrop-filter:blur(6px);box-shadow:0 6px 18px rgba(0,0,0,.1)}
    .g3d .comparar[hidden]{display:none}
    .g3d .comparar-centro{flex:1;min-width:0;display:grid;gap:.3rem}
    .g3d .comparar-texto{font-size:.8rem;color:var(--ink-soft);line-height:1.35}
    .g3d .comparar-chips{display:flex;flex-wrap:wrap;gap:.3rem}
    .g3d .comparar-chips .chip{padding:.25rem .65rem;font-size:.78rem}
    .g3d .visor.con-comparar .ayuda{display:none}
    @media (max-width:40rem){
        /* en el teléfono: una sola fila de objetos, que se desliza */
        .g3d .comparar{padding:.35rem .4rem .35rem .7rem;border-radius:14px}
        .g3d .comparar-texto{font-size:.72rem}
        .g3d .comparar-chips{flex-wrap:nowrap;overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch}
        .g3d .comparar-chips::-webkit-scrollbar{display:none}
        .g3d .comparar-chips .chip{flex:none;padding:.2rem .55rem;font-size:.74rem}
        .g3d .capas{padding:.35rem .4rem}
    }

    /* Pantalla completa: la API donde existe y, en el iPhone, la clase. */
    .g3d .visor:fullscreen{aspect-ratio:auto;border-radius:0;border:0}
    .g3d .visor.completa{position:fixed;inset:0;z-index:50;aspect-ratio:auto;border-radius:0;border:0;min-height:0}

    /* Me gusta, vistas, descargas y compartir */
    .g3d .social{display:flex;flex-wrap:wrap;align-items:center;gap:.45rem .6rem;margin:0 0 1rem}
    .g3d .social .cifra{display:inline-flex;align-items:center;gap:.3rem;font-size:.82rem;color:var(--muted);font-variant-numeric:tabular-nums}
    .g3d .social .cifra svg{width:1rem;height:1rem}
    .g3d .me-gusta{display:inline-flex;align-items:center;gap:.4rem;padding:.4rem .8rem;border-radius:999px;border:1px solid var(--rule);background:var(--surface);color:var(--ink-soft);font:inherit;font-size:.85rem;font-weight:600;cursor:pointer;font-variant-numeric:tabular-nums}
    .g3d .me-gusta svg{width:1.05rem;height:1.05rem}
    .g3d .me-gusta:hover{border-color:color-mix(in srgb,#e0245e 50%,var(--rule));color:#e0245e}
    .g3d .me-gusta[aria-pressed="true"]{background:color-mix(in srgb,#e0245e 12%,var(--surface));border-color:color-mix(in srgb,#e0245e 45%,transparent);color:#e0245e}
    .g3d .me-gusta[aria-pressed="true"] svg{fill:currentColor}
    .g3d .me-gusta.late svg{animation:g3d-late .35s ease}
    @keyframes g3d-late{50%{transform:scale(1.35)}}
    .g3d .compartir{display:inline-flex;gap:.35rem;margin-left:auto}
    .g3d .compartir .redondo{width:2.1rem;height:2.1rem}
    .g3d .compartir .redondo svg{width:1rem;height:1rem}
    .g3d .compartir .whatsapp:hover{color:#1da851;border-color:#1da85188}
    .g3d .compartir-estado{flex-basis:100%;margin:-.2rem 0 0;font-size:.78rem;color:var(--muted)}
    .g3d .compartir-estado:empty{display:none}
    .g3d .social[hidden]{display:none}

    /* Pestaña «Imprimir»: la calculadora */
    .g3d .calc{display:grid;gap:.9rem}
    .g3d .calc .fila{display:grid;gap:.35rem}
    .g3d .calc label.titulo,.g3d .calc .titulo{font-size:.8rem;font-weight:600;color:var(--ink-soft)}
    .g3d .calc select{font:inherit;font-size:.88rem;padding:.45rem .6rem;border:1px solid var(--rule);border-radius:6px;background:var(--surface);color:var(--ink)}
    .g3d .calc .rango{display:flex;align-items:center;gap:.6rem}
    .g3d .calc .rango input{flex:1;accent-color:var(--accent)}
    .g3d .calc .rango output{min-width:3.2rem;text-align:right;font-variant-numeric:tabular-nums;font-size:.88rem}
    .g3d .calc .resultado{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:.55rem}
    .g3d .calc .resultado div{border:1px solid var(--rule);border-radius:8px;padding:.6rem .7rem;background:color-mix(in srgb,var(--accent) 4%,var(--surface))}
    .g3d .calc .resultado strong{display:block;font-size:1.05rem;font-variant-numeric:tabular-nums;line-height:1.25}
    .g3d .calc .resultado span{font-size:.75rem;color:var(--muted)}
    .g3d .calc .resultado .ancho{grid-column:1/-1}
    .g3d .calc .nota{margin:0;font-size:.76rem;color:var(--muted);line-height:1.45}
    .g3d .calc[hidden],.g3d .calc .fila[hidden],.g3d .calc .resultado div[hidden]{display:none}

    /* La ficha del modelo. */
    .g3d .ficha{background:var(--surface);border:1px solid var(--rule);border-radius:8px;padding:1.3rem 1.4rem 1.2rem;min-width:0}
    .g3d .ficha .rotulo{margin-bottom:.4rem}
    .g3d .ficha h2{font-size:1.45rem;line-height:1.2;margin:0 0 .5rem}
    .g3d .ficha .resumen{margin:0 0 1rem;color:var(--ink-soft);font-size:.95rem}
    .g3d .pestanas{display:flex;gap:1.1rem;border-bottom:1px solid var(--rule);margin:0 0 1rem}
    .g3d .pestana{
        background:none;border:0;margin:0 0 -1px;padding:.45rem 0;font:inherit;font-size:.88rem;font-weight:600;color:var(--muted);
        cursor:pointer;border-bottom:2px solid transparent
    }
    .g3d .pestana:hover{color:var(--ink)}
    .g3d .pestana[aria-selected="true"]{color:var(--accent);border-bottom-color:var(--accent)}
    .g3d [role="tabpanel"][hidden]{display:none}
    .g3d .datos{display:grid;grid-template-columns:auto 1fr;gap:.45rem 1rem;margin:0;font-size:.88rem}
    .g3d .datos dt{color:var(--muted)}
    .g3d .datos dd{margin:0;font-variant-numeric:tabular-nums}
    .g3d .descripcion{font-size:.9rem;color:var(--ink-soft);margin:1rem 0 0}
    .g3d .descargas{display:flex;gap:.5rem;flex-wrap:wrap}
    .g3d .descargas a,.g3d .descargas button{
        display:inline-flex;align-items:center;gap:.35rem;font:inherit;font-size:.8rem;text-decoration:none;color:var(--ink-soft);
        background:none;border:1px solid var(--rule);border-radius:999px;padding:.3rem .75rem;cursor:pointer
    }
    .g3d .descargas a:hover,.g3d .descargas button:hover{color:var(--accent);border-color:var(--accent)}
    .g3d .descargas button[aria-busy="true"]{cursor:progress;opacity:.6}
    .g3d .descargas svg{width:.95rem;height:.95rem}
    .g3d .descarga-estado{font-size:.78rem;color:var(--muted);margin:.5rem 0 0;min-height:1em}
    .g3d .descarga-estado:empty{display:none}

    .g3d .subtitulo{font-size:.78rem;font-family:ui-monospace,Consolas,monospace;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin:1.1rem 0 .5rem}
    .g3d .subtitulo:first-child{margin-top:0}
    .g3d .chips{display:flex;flex-wrap:wrap;gap:.4rem}
    .g3d .chip{
        display:inline-flex;align-items:center;gap:.4rem;margin:0;padding:.32rem .8rem;border-radius:999px;cursor:pointer;
        border:1px solid var(--rule);background:transparent;color:var(--ink-soft);font:inherit;font-size:.82rem;line-height:1.3
    }
    .g3d .chip:hover{border-color:color-mix(in srgb,var(--accent) 55%,var(--rule));color:var(--ink)}
    .g3d .chip[aria-pressed="true"]{background:color-mix(in srgb,var(--accent) 14%,transparent);border-color:color-mix(in srgb,var(--accent) 55%,transparent);color:var(--accent);font-weight:600}
    .g3d .chip .muestra{width:.85rem;height:.85rem;border-radius:50%;border:1px solid rgba(0,0,0,.18);flex:none}

    .g3d .materiales{list-style:none;margin:0;padding:0;display:flex;flex-direction:column}
    .g3d .materiales li{border-top:1px solid var(--rule)}
    .g3d .materiales li:first-child{border-top:0}
    .g3d .material-boton{
        display:flex;align-items:center;gap:.6rem;width:100%;margin:0;padding:.5rem 0;background:none;border:0;cursor:pointer;
        font:inherit;font-size:.88rem;color:var(--ink);text-align:left
    }
    .g3d .material-boton .muestra{width:1.3rem;height:1.3rem;border-radius:50%;border:1px solid rgba(0,0,0,.15);flex:none;box-shadow:inset 0 -3px 6px rgba(0,0,0,.18)}
    .g3d .material-boton small{margin-left:auto;color:var(--muted);font-size:.75rem;white-space:nowrap}
    .g3d .material-boton[aria-expanded="true"]{color:var(--accent);font-weight:600}
    .g3d .editor{display:grid;grid-template-columns:auto 1fr auto;gap:.5rem .8rem;align-items:center;padding:.2rem 0 .8rem;font-size:.82rem}
    .g3d .editor[hidden]{display:none}
    .g3d .editor label{color:var(--muted)}
    .g3d .editor input[type="range"]{width:100%;accent-color:var(--accent)}
    .g3d .editor input[type="color"]{width:2.4rem;height:1.6rem;padding:0;border:1px solid var(--rule);border-radius:4px;background:none;cursor:pointer}
    .g3d .editor output{font-variant-numeric:tabular-nums;color:var(--ink-soft);min-width:2.2rem;text-align:right}
    .g3d .enlace-boton{background:none;border:0;padding:0;margin:.6rem 0 0;font:inherit;font-size:.82rem;color:var(--accent);cursor:pointer;text-decoration:underline;text-underline-offset:2px}
    .g3d .control{display:grid;grid-template-columns:6.5rem 1fr auto;gap:.8rem;align-items:center;font-size:.85rem;margin:.55rem 0}
    .g3d .control label{color:var(--muted)}
    .g3d .control input[type="range"]{width:100%;accent-color:var(--accent)}
    .g3d .interruptor{display:flex;align-items:center;gap:.5rem;font-size:.86rem;margin:.55rem 0;color:var(--ink-soft);cursor:pointer}
    .g3d .interruptor input{accent-color:var(--accent);width:1rem;height:1rem}
    .g3d .rendimiento{font-family:ui-monospace,Consolas,monospace;font-size:.74rem;color:var(--muted);margin:.9rem 0 0;line-height:1.5}

    .g3d .qr{display:flex;gap:1rem;align-items:center;margin-top:1.1rem;padding-top:1rem;border-top:1px solid var(--rule)}
    .g3d .qr[hidden]{display:none}
    .g3d .qr img{width:6.4rem;height:6.4rem;border-radius:6px;background:#fff;padding:.25rem;border:1px solid var(--rule);flex:none}
    /* QR ampliable: un clic lo muestra grande, para escanearlo desde más lejos */
    .g3d .qr-ampliar{position:relative;flex:none;padding:0;border:0;background:none;cursor:zoom-in;border-radius:8px}
    .g3d .qr-ampliar[hidden]{display:none}
    .g3d .qr-ampliar img{display:block;transition:box-shadow .15s ease,transform .15s ease}
    .g3d .qr-ampliar:hover img{transform:scale(1.03);box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 35%,transparent)}
    .g3d .qr-ampliar:focus-visible{outline:3px solid var(--accent);outline-offset:3px}
    .g3d .qr-ampliar .lupa{position:absolute;right:-.35rem;bottom:-.35rem;width:1.6rem;height:1.6rem;border-radius:50%;display:grid;place-items:center;background:var(--accent);color:#fff;box-shadow:0 2px 6px rgba(0,0,0,.2)}
    .g3d .qr-ampliar .lupa svg{width:.9rem;height:.9rem}
    .g3d .qr-grande{border:0;border-radius:1.25rem;padding:1.3rem 1.3rem 1.1rem;background:var(--surface);color:var(--ink);width:min(30rem,94vw);text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.35)}
    .g3d .qr-grande::backdrop{background:rgba(0,0,0,.6)}
    .g3d .qr-grande h2{margin:0;font-size:1.15rem}
    .g3d .qr-grande .nombre{margin:.15rem 0 0;color:var(--ink-soft);font-size:.92rem}
    .g3d .qr-grande img{display:block;width:min(24rem,80vw,60vh);height:auto;aspect-ratio:1;margin:.9rem auto;background:#fff;padding:.7rem;border-radius:.9rem;border:1px solid var(--rule)}
    .g3d .qr-grande .nota{margin:0 0 .9rem;font-size:.86rem;color:var(--ink-soft)}
    .g3d .qr-grande button{font:inherit;font-weight:600;border:0;border-radius:999px;padding:.6rem 1.4rem;cursor:pointer;background:var(--accent);color:#fff}
    .g3d .qr p{margin:0;font-size:.84rem;color:var(--ink-soft)}
    .g3d .qr strong{display:block;color:var(--ink);font-size:.9rem;margin-bottom:.2rem}
    .g3d .qr [hidden]{display:none}
    .g3d .qr code{font-size:.8rem;background:var(--ground);padding:0 .25rem;border-radius:4px}
    .g3d .qr-encender{margin-top:.6rem;font:inherit;font-size:.86rem;font-weight:600;border:0;border-radius:999px;padding:.55rem 1rem;cursor:pointer;background:var(--accent);color:#fff}
    .g3d .qr-encender[disabled]{opacity:.6;cursor:progress}
    .g3d .qr-estado{margin:.45rem 0 0!important;font-size:.8rem!important;color:var(--muted)!important}
    .g3d .qr-estado:empty{display:none}

    /* La colección. */
    .g3d .coleccion{padding-top:2.6rem}
    .g3d .coleccion .encabezado{display:flex;flex-wrap:wrap;gap:.8rem 1.4rem;align-items:end;justify-content:space-between;margin-bottom:1rem}
    .g3d .coleccion h2{margin:0}
    .g3d .buscar-y-orden{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;flex:0 1 28rem}
    .g3d .buscar{position:relative;flex:1 1 15rem}
    @media (max-width:40rem){ .g3d .buscar-y-orden{flex:1 1 100%} }
    .g3d .orden select{font:inherit;font-size:.86rem;padding:.45rem 2rem .45rem .8rem;border:1px solid var(--rule);border-radius:999px;background:var(--surface);color:var(--ink);cursor:pointer}
    .g3d .orden select:focus-visible{outline:2px solid var(--accent);outline-offset:1px}
    .g3d .conteo{margin:-.5rem 0 .9rem;font-size:.8rem;color:var(--muted)}
    .g3d .conteo:empty{display:none}
    .g3d .tarjeta .meta .corazon{color:#e0245e}
    .g3d .sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
    .g3d .buscar svg{position:absolute;left:.7rem;top:50%;transform:translateY(-50%);width:1rem;height:1rem;color:var(--muted)}
    .g3d .buscar input{
        width:100%;font:inherit;font-size:.88rem;padding:.45rem .8rem .45rem 2.1rem;border:1px solid var(--rule);border-radius:999px;
        background:var(--surface);color:var(--ink)
    }
    .g3d .buscar input:focus{outline:2px solid color-mix(in srgb,var(--accent) 55%,transparent);outline-offset:1px}
    .g3d .filtros{margin-bottom:1.1rem}
    .g3d .tarjetas{display:grid;gap:1rem;grid-template-columns:repeat(auto-fill,minmax(14.5rem,1fr))}
    @media (max-width:40rem){ .g3d .tarjetas{grid-template-columns:repeat(2,minmax(0,1fr));gap:.7rem} }
    .g3d .tarjeta{
        display:flex;flex-direction:column;background:var(--surface);border:1px solid var(--rule);border-radius:8px;
        overflow:hidden;text-decoration:none;color:var(--ink);transition:border-color .15s ease,transform .15s ease
    }
    .g3d .tarjeta:hover{border-color:var(--accent);transform:translateY(-2px)}
    .g3d .tarjeta[aria-current="true"]{border-color:var(--accent);box-shadow:0 0 0 2px color-mix(in srgb,var(--accent) 35%,transparent)}
    .g3d .tarjeta[hidden]{display:none}
    .g3d .tarjeta .foto{aspect-ratio:1/1;background:radial-gradient(100% 80% at 50% 40%, var(--surface), color-mix(in srgb,var(--ground) 80%,var(--rule)));display:flex}
    .g3d .tarjeta .foto img{width:100%;height:100%;object-fit:contain;padding:6%}
    .g3d .tarjeta .texto{padding:.8rem 1rem 1rem;display:flex;flex-direction:column;gap:.25rem}
    .g3d .tarjeta .cat{font-family:ui-monospace,Consolas,monospace;font-size:.64rem;letter-spacing:.14em;text-transform:uppercase;color:var(--accent)}
    .g3d .tarjeta h3{margin:0;font-size:1rem;line-height:1.25}
    .g3d .tarjeta .meta{display:flex;flex-wrap:wrap;gap:.3rem .6rem;font-size:.76rem;color:var(--muted);margin-top:.15rem}
    .g3d .tarjeta .meta span{display:inline-flex;align-items:center;gap:.2rem}
    .g3d .tarjeta .meta svg{width:.85rem;height:.85rem}
    .g3d .sin-resultados{background:var(--surface);border:1px solid var(--rule);border-radius:8px;padding:1.2rem;color:var(--ink-soft)}
    .g3d .sin-resultados[hidden]{display:none}

    /* Cómo se ve en cada aparato, y cómo se prepararon. */
    .g3d .como{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));padding-top:2.6rem}
    .g3d .como > div{background:var(--surface);border:1px solid var(--rule);border-radius:8px;padding:1.2rem 1.3rem}
    .g3d .como h3{font-size:1rem;margin:0 0 .4rem;display:flex;align-items:center;gap:.45rem}
    .g3d .como h3 svg{width:1.15rem;height:1.15rem;color:var(--accent)}
    .g3d .como p{margin:0;font-size:.88rem;color:var(--ink-soft)}
    .g3d .cifras{display:flex;gap:1.2rem;margin-top:.7rem}
    .g3d .cifras strong{display:block;font-size:1.35rem;letter-spacing:-.02em;color:var(--ink)}
    .g3d .cifras span{font-size:.76rem;color:var(--muted)}

    /* Realidad aumentada en la página (WebXR): lo que se ve sobre la cámara. */
    .g3d-xr{position:fixed;inset:0;z-index:60;pointer-events:none;font-family:system-ui,"Segoe UI",Arial,sans-serif}
    .g3d-xr[hidden]{display:none}
    .g3d-xr .mensaje{
        position:absolute;left:50%;bottom:6.5rem;transform:translateX(-50%);max-width:86%;text-align:center;
        background:rgba(20,22,18,.72);color:#f3f4ec;border-radius:999px;padding:.55rem 1.1rem;font-size:.92rem
    }
    .g3d-xr .acciones{position:absolute;left:0;right:0;bottom:1.6rem;display:flex;justify-content:center;gap:.6rem}
    .g3d-xr button{
        pointer-events:auto;font:inherit;font-size:.92rem;font-weight:600;border:0;border-radius:999px;padding:.7rem 1.2rem;
        background:rgba(243,244,236,.94);color:#191a16;cursor:pointer
    }
    .g3d-xr button.principal{background:#5CC9B8;color:#171A15}
    .g3d-xr .zona{position:absolute;inset:0 0 9rem 0;pointer-events:auto;touch-action:none}

    .g3d .sin-js{background:var(--surface);border:1px solid var(--rule);border-radius:8px;padding:1rem 1.2rem;margin-top:1rem;font-size:.9rem}
@endsection

@section('content')
<main class="g3d">
    <div class="cabecera">
        <p class="rotulo">Galería 3D</p>
        <h1 id="titulo" data-general="Lo que fabricamos, en tres dimensiones">{{ $esFicha ? $actual['nombre'] : 'Lo que fabricamos, en tres dimensiones' }}</h1>
        <p class="lead">
            Piezas impresas, cortadas en láser, fresadas y soldadas en el laboratorio. Gíralas, acércate
            hasta ver las capas de impresión, cambia sus materiales y, si tu teléfono lo permite, ponlas
            sobre tu mesa con realidad aumentada.
        </p>
        <ul class="pistas" aria-label="Cómo usar el visor">
            <li>{{ svg('heroicon-o-arrow-path-rounded-square', '', ['aria-hidden' => 'true']) }} Arrastra para girar</li>
            <li>{{ svg('heroicon-o-magnifying-glass-plus', '', ['aria-hidden' => 'true']) }} Rueda o pellizca para acercar</li>
            <li>{{ svg('heroicon-o-swatch', '', ['aria-hidden' => 'true']) }} Prueba otros acabados</li>
            <li>{{ svg('heroicon-o-cube', '', ['aria-hidden' => 'true']) }} Míralo en tu espacio</li>
        </ul>

        {{-- Sube tu modelo: se abre aquí mismo, en el visor, sin pasar por el
             servidor (public/3d/visor.js → procesar/importar.js). --}}
        <div class="subir" id="subir">
            <label class="subir-boton" for="subir-entrada">
                {{ svg('heroicon-o-arrow-up-tray', '', ['aria-hidden' => 'true']) }}
                <span>Sube tu modelo 3D</span>
            </label>
            <input type="file" id="subir-entrada" class="subir-entrada" multiple accept="{{ $formatosSubir }}">
            <p class="subir-texto">
                STL, STEP, IGES, OBJ, FBX, 3MF o GLB. Se abre en tu navegador para girarlo y explorarlo: no se sube a ningún servidor.
                <span class="subir-soltar">También puedes soltarlo sobre el visor.</span>
            </p>
            <p class="subir-estado" id="subir-estado" role="status" aria-live="polite"></p>
        </div>
    </div>

    <div class="estudio" id="estudio">
        {{-- El visor. Sin javascript queda la foto del modelo y los enlaces de
             descarga: se ve algo, nunca un cuadro vacío. --}}
        <div class="visor" id="visor" tabindex="0" role="application"
             aria-label="Visor 3D de {{ $actual['nombre'] }}. Flechas para girar, más y menos para acercar, R para volver a la vista inicial."
             aria-describedby="visor-ayuda">
            <img class="poster" id="visor-poster" src="{{ $actual['urls']['poster'] }}" alt="{{ $actual['nombre'] }}" width="800" height="800">
            <div class="barra" id="visor-barra" role="progressbar" aria-label="Cargando el modelo" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
            <span class="ayuda" id="visor-ayuda">Arrastra para girar · rueda o pellizca para acercar</span>
            <div class="aviso" id="visor-aviso" role="status" hidden></div>

            <button type="button" class="redondo flecha anterior" id="anterior" title="Modelo anterior" aria-label="Modelo anterior">
                {{ svg('heroicon-o-chevron-left', '', ['aria-hidden' => 'true']) }}
            </button>
            <button type="button" class="redondo flecha siguiente" id="siguiente" title="Modelo siguiente" aria-label="Modelo siguiente">
                {{ svg('heroicon-o-chevron-right', '', ['aria-hidden' => 'true']) }}
            </button>

            {{-- «¿Cómo se imprime?»: la figura se arma capa por capa (visor.js → CapaPorCapa) --}}
            <div class="capas" id="capas" role="group" aria-label="Capa por capa" hidden>
                <button type="button" class="redondo" id="capas-play" title="Pausar" aria-label="Pausar">
                    {{ svg('heroicon-s-pause', 'icono-pausa', ['aria-hidden' => 'true']) }}
                    {{ svg('heroicon-s-play', 'icono-play', ['aria-hidden' => 'true', 'style' => 'display:none']) }}
                </button>
                <div class="capas-centro">
                    <label for="capas-barra" id="capas-texto">Capa 0 de 0</label>
                    <input type="range" id="capas-barra" min="0" max="100" step="1" value="0">
                </div>
                <button type="button" class="redondo" id="capas-cerrar" title="Ver la figura completa" aria-label="Terminar y ver la figura completa">
                    {{ svg('heroicon-o-x-mark', '', ['aria-hidden' => 'true']) }}
                </button>
            </div>

            {{-- Comparar el tamaño: un objeto conocido al lado de la figura (visor.js → Comparador) --}}
            <div class="comparar" id="comparar" role="group" aria-label="Comparar el tamaño" hidden>
                <div class="comparar-centro">
                    <span class="comparar-texto" id="comparar-texto">Al lado:</span>
                    <div class="comparar-chips" id="comparar-objetos">
                        <button type="button" class="chip" data-objeto="moneda" aria-pressed="false">Moneda</button>
                        <button type="button" class="chip" data-objeto="tarjeta" aria-pressed="false">Tarjeta</button>
                        <button type="button" class="chip" data-objeto="celular" aria-pressed="false">Celular</button>
                        <button type="button" class="chip" data-objeto="lata" aria-pressed="false">Lata</button>
                        <button type="button" class="chip" data-objeto="persona" aria-pressed="false">Persona</button>
                    </div>
                </div>
                <button type="button" class="redondo" id="comparar-cerrar" title="Quitar el objeto" aria-label="Quitar el objeto de comparación">
                    {{ svg('heroicon-o-x-mark', '', ['aria-hidden' => 'true']) }}
                </button>
            </div>

            <div class="herramientas" role="toolbar" aria-label="Controles del visor">
                <button type="button" class="redondo" data-accion="girar" aria-pressed="false" title="Girar solo" aria-label="Girar solo">
                    {{ svg('heroicon-o-arrow-path', '', ['aria-hidden' => 'true']) }}
                </button>
                <button type="button" class="redondo" data-accion="animar" aria-pressed="true" title="Pausar la animación" aria-label="Pausar la animación" hidden>
                    {{ svg('heroicon-s-pause', 'icono-pausa', ['aria-hidden' => 'true']) }}
                    {{ svg('heroicon-s-play', 'icono-play', ['aria-hidden' => 'true', 'style' => 'display:none']) }}
                </button>
                <button type="button" class="redondo" data-accion="capas" aria-pressed="false" title="¿Cómo se imprime? Capa por capa" aria-label="¿Cómo se imprime? Ver la figura capa por capa">
                    {{ svg('heroicon-o-square-3-stack-3d', '', ['aria-hidden' => 'true']) }}
                </button>
                <button type="button" class="redondo" data-accion="comparar" aria-pressed="false" title="Comparar el tamaño" aria-label="Comparar el tamaño con un objeto conocido">
                    {{ svg('heroicon-o-scale', '', ['aria-hidden' => 'true']) }}
                </button>
                <button type="button" class="redondo solo-ancho" data-accion="acercar" title="Acercar" aria-label="Acercar">
                    {{ svg('heroicon-o-magnifying-glass-plus', '', ['aria-hidden' => 'true']) }}
                </button>
                <button type="button" class="redondo solo-ancho" data-accion="alejar" title="Alejar" aria-label="Alejar">
                    {{ svg('heroicon-o-magnifying-glass-minus', '', ['aria-hidden' => 'true']) }}
                </button>
                <button type="button" class="redondo" data-accion="reiniciar" title="Volver a la vista inicial" aria-label="Volver a la vista inicial">
                    {{ svg('heroicon-o-arrow-uturn-left', '', ['aria-hidden' => 'true']) }}
                </button>
                <button type="button" class="redondo solo-ancho" data-accion="captura" title="Guardar una imagen" aria-label="Guardar una imagen">
                    {{ svg('heroicon-o-camera', '', ['aria-hidden' => 'true']) }}
                </button>
                <button type="button" class="redondo" data-accion="completa" aria-pressed="false" title="Pantalla completa" aria-label="Pantalla completa">
                    {{ svg('heroicon-o-arrows-pointing-out', '', ['aria-hidden' => 'true']) }}
                </button>
            </div>

            {{-- Realidad aumentada. visor.js decide qué abre según el aparato:
                 Vista rápida en iPhone/iPad, la cámara en la página o Scene
                 Viewer en Android, y en el computador se queda oculto y se
                 ofrece el QR de la ficha. --}}
            <button type="button" class="boton-ar" id="boton-ar" hidden>
                {{ svg('heroicon-o-cube', '', ['aria-hidden' => 'true']) }}
                <span>Ver en tu espacio</span>
            </button>
        </div>

        <aside class="ficha" id="ficha" aria-live="polite">
            <p class="rotulo" id="ficha-categoria">{{ collect([$actual['categoria_nombre'], $actual['tecnica']])->filter()->implode(' · ') }}</p>
            <h2 id="ficha-nombre">{{ $actual['nombre'] }}</h2>
            <p class="resumen" id="ficha-resumen">{{ $actual['resumen'] }}</p>

            {{-- Me gusta, vistas, descargas y compartir --}}
            <div class="social" id="social">
                <button type="button" class="me-gusta" id="me-gusta" aria-pressed="{{ in_array($actual['slug'], $meGusta, true) ? 'true' : 'false' }}" title="Me gusta">
                    {{ svg('heroicon-o-heart', '', ['aria-hidden' => 'true']) }}
                    <span id="me-gusta-num">{{ $num($cifrasActual['me_gusta']) }}</span><span class="sr-only"> me gusta</span>
                </button>
                <span class="cifra" title="Vistas">{{ svg('heroicon-o-eye', '', ['aria-hidden' => 'true']) }} <span id="vistas-num">{{ $num($cifrasActual['vistas']) }}</span><span class="sr-only"> vistas</span></span>
                <span class="cifra" title="Descargas">{{ svg('heroicon-o-arrow-down-tray', '', ['aria-hidden' => 'true']) }} <span id="descargas-num">{{ $num($cifrasActual['descargas']) }}</span><span class="sr-only"> descargas</span></span>
                <span class="compartir" role="group" aria-label="Compartir">
                    <a class="redondo whatsapp" id="compartir-whatsapp" href="#" target="_blank" rel="noopener" title="Compartir por WhatsApp" aria-label="Compartir por WhatsApp">
                        <svg viewBox="0 0 24 24" aria-hidden="true" fill="currentColor"><path d="M12.04 2a9.9 9.9 0 0 0-8.5 15l-1.4 5.1 5.2-1.4A9.9 9.9 0 1 0 12.04 2Zm0 18.1a8.2 8.2 0 0 1-4.2-1.2l-.3-.2-3.1.8.8-3-.2-.3a8.2 8.2 0 1 1 7 3.9Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8-.2-.1-.4-.1-.6.1l-.8 1c-.1.2-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.3-.4.2-.4.7-1.4.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.7 11.8 11.8 0 0 0 4.5 4c1.7.7 2.3.8 3.2.6.5-.1 1.5-.6 1.7-1.2.2-.6.2-1.1.1-1.2l-.3-.3Z"/></svg>
                    </a>
                    <button type="button" class="redondo" id="compartir-copiar" title="Copiar el enlace" aria-label="Copiar el enlace">{{ svg('heroicon-o-link', '', ['aria-hidden' => 'true']) }}</button>
                    <button type="button" class="redondo" id="compartir-nativo" title="Compartir" aria-label="Compartir" hidden>{{ svg('heroicon-o-share', '', ['aria-hidden' => 'true']) }}</button>
                </span>
                <p class="compartir-estado" id="compartir-estado" role="status" aria-live="polite"></p>
            </div>

            <div class="pestanas" role="tablist" aria-label="Información del modelo">
                <button type="button" class="pestana" role="tab" id="tab-ficha" aria-controls="panel-ficha" aria-selected="true">Ficha</button>
                <button type="button" class="pestana" role="tab" id="tab-imprimir" aria-controls="panel-imprimir" aria-selected="false" tabindex="-1">Imprimir</button>
                <button type="button" class="pestana" role="tab" id="tab-materiales" aria-controls="panel-materiales" aria-selected="false" tabindex="-1">Materiales</button>
                <button type="button" class="pestana" role="tab" id="tab-vista" aria-controls="panel-vista" aria-selected="false" tabindex="-1">Vista</button>
            </div>

            <div role="tabpanel" id="panel-ficha" aria-labelledby="tab-ficha">
                <dl class="datos">
                    <dt>Técnica</dt><dd id="dato-tecnica">{{ $actual['tecnica'] ?: '—' }}</dd>
                    <dt>Máquina</dt><dd id="dato-maquina">{{ $actual['maquina'] ?: '—' }}</dd>
                    <dt>Material</dt><dd id="dato-material">{{ $actual['material'] ?: '—' }}</dd>
                    <dt>Medidas</dt><dd id="dato-medidas">{{ $medidas($actual) }}</dd>
                    <dt>Triángulos</dt><dd id="dato-triangulos">{{ $actual['triangulos'] ? number_format($actual['triangulos'], 0, ',', '.') : '—' }}</dd>
                    <dt>Archivo</dt><dd id="dato-peso">{{ Galeria3d::peso($actual['bytes']['glb']) }}</dd>
                </dl>
                <p class="descripcion" id="ficha-descripcion">{{ $actual['descripcion'] }}</p>
                {{-- STEP, STL, 3MF y FBX se generan en el navegador (public/3d/exportar.js) a
                     partir del .glb; el .glb se descarga tal cual. --}}
                <p class="subtitulo">Descargar</p>
                <div class="descargas" id="descargas" role="group" aria-label="Descargar el modelo">
                    <button type="button" data-formato="step" title="STEP en milímetros, para SolidWorks, Fusion, FreeCAD o Inventor (geometría facetada)">
                        {{ svg('heroicon-o-arrow-down-tray', '', ['aria-hidden' => 'true']) }} STEP
                    </button>
                    <button type="button" data-formato="stl" title="STL en milímetros, listo para el laminador de la impresora 3D">
                        {{ svg('heroicon-o-arrow-down-tray', '', ['aria-hidden' => 'true']) }} STL
                    </button>
                    <button type="button" data-formato="3mf" title="3MF en milímetros, con las piezas y sus colores: PrusaSlicer, Bambu Studio, Cura o Windows">
                        {{ svg('heroicon-o-arrow-down-tray', '', ['aria-hidden' => 'true']) }} 3MF
                    </button>
                    <a id="descarga-glb" href="{{ $actual['urls']['glb_ar'] }}" download="{{ $actual['slug'] }}.glb" title="glTF binario con materiales y texturas, para Blender o la web">
                        {{ svg('heroicon-o-arrow-down-tray', '', ['aria-hidden' => 'true']) }} GLB
                    </a>
                    <button type="button" data-formato="fbx" title="FBX con colores y texturas, para Blender, Unity, 3ds Max o Maya">
                        {{ svg('heroicon-o-arrow-down-tray', '', ['aria-hidden' => 'true']) }} FBX
                    </button>
                </div>
                <p class="descarga-estado" id="descarga-estado" role="status" aria-live="polite"></p>

                {{-- Solo con un modelo subido desde el dispositivo. --}}
                <div class="propio" id="propio" hidden>
                    <p><strong>Solo lo ves tú</strong>Este modelo está abierto en tu navegador y no se guardó en ningún lado: si recargas la página, desaparece.</p>
                    @if ($datosPagina['subir']['publicar'])
                        <button type="button" class="qr-encender" id="propio-publicar">Publicar en la galería</button>
                        <p class="propio-nota">Lo lleva al panel, donde se revisa su tamaño, se le pone ficha y se optimiza para todos.</p>
                    @endif
                    <button type="button" class="enlace-boton" id="propio-quitar">Quitar este modelo</button>
                </div>
            </div>

            {{-- Calculadora de impresión: con el volumen del modelo (lo mide el visor al cargarlo) --}}
            <div role="tabpanel" id="panel-imprimir" aria-labelledby="tab-imprimir" hidden>
                <div class="calc" id="calc">
                    <div class="fila">
                        <label class="titulo" for="calc-escala">Tamaño</label>
                        <div class="rango">
                            <input type="range" id="calc-escala" min="25" max="300" step="5" value="100">
                            <output for="calc-escala" id="calc-escala-valor">100 %</output>
                        </div>
                    </div>
                    <div class="fila">
                        <label class="titulo" for="calc-material">Material</label>
                        <select id="calc-material">
                            @foreach ($impresion['materiales'] as $clave => $mat)
                                <option value="{{ $clave }}">{{ $mat['nombre'] }}{{ $mat['tipo'] === 'resina' ? ' (impresora de resina)' : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="fila" id="calc-fila-relleno">
                        <label class="titulo" for="calc-relleno">Relleno</label>
                        <div class="rango">
                            <input type="range" id="calc-relleno" min="5" max="100" step="5" value="20">
                            <output for="calc-relleno" id="calc-relleno-valor">20 %</output>
                        </div>
                    </div>
                    <div class="resultado" aria-live="polite">
                        <div class="ancho"><strong id="calc-medidas">—</strong><span>ancho × fondo × alto</span></div>
                        <div><strong id="calc-gramos">—</strong><span id="calc-gramos-texto">de material</span></div>
                        <div><strong id="calc-tiempo">—</strong><span>de impresión</span></div>
                        <div id="calc-caja-metros"><strong id="calc-metros">—</strong><span>de filamento de 1,75 mm</span></div>
                        <div id="calc-caja-costo" hidden><strong id="calc-costo">—</strong><span>costo aproximado</span></div>
                    </div>
                    <p class="nota" id="calc-nota">Se calcula con el volumen real del modelo, boquilla de 0,4 mm y capas de 0,2 mm. Es una aproximación: el laminador da la cifra exacta.</p>
                </div>
            </div>

            <div role="tabpanel" id="panel-materiales" aria-labelledby="tab-materiales" hidden>
                <p class="subtitulo">Acabados</p>
                <div class="chips" id="acabados" role="group" aria-label="Acabados"></div>
                <p class="subtitulo">Materiales del modelo</p>
                <ul class="materiales" id="materiales"><li><span style="font-size:.85rem;color:var(--muted)">Se listan al cargar el modelo.</span></li></ul>
                <button type="button" class="enlace-boton" id="restaurar">Volver a los materiales originales</button>
            </div>

            <div role="tabpanel" id="panel-vista" aria-labelledby="tab-vista" hidden>
                <p class="subtitulo">Modo</p>
                <div class="chips" role="group" aria-label="Modo de vista" id="modos">
                    <button type="button" class="chip" data-modo="realista" aria-pressed="true">Realista</button>
                    <button type="button" class="chip" data-modo="arcilla" aria-pressed="false">Arcilla</button>
                    <button type="button" class="chip" data-modo="malla" aria-pressed="false">Malla</button>
                    <button type="button" class="chip" data-modo="normales" aria-pressed="false">Normales</button>
                </div>
                <p class="subtitulo">Luz</p>
                <div class="chips" role="group" aria-label="Iluminación" id="luces">
                    <button type="button" class="chip" data-luz="estudio" aria-pressed="true">Estudio</button>
                    <button type="button" class="chip" data-luz="exterior" aria-pressed="false">Exterior</button>
                    <button type="button" class="chip" data-luz="atardecer" aria-pressed="false">Atardecer</button>
                </div>
                <div class="control">
                    <label for="exposicion">Exposición</label>
                    <input type="range" id="exposicion" min="0.3" max="2" step="0.05" value="1">
                    <output for="exposicion" id="exposicion-valor">1,00</output>
                </div>
                <label class="interruptor"><input type="checkbox" id="sombra" checked> Sombra en el suelo</label>
                <label class="interruptor"><input type="checkbox" id="medidas"> <span id="medidas-texto">Mostrar caja de medidas ({{ $medidas($actual) }})</span></label>
                <p class="rendimiento" id="rendimiento" aria-live="off">—</p>
            </div>

            {{-- En el computador no hay cámara que apunte a la mesa: se ofrece
                 abrir el mismo modelo en el teléfono. --}}
            <div class="qr" id="qr" hidden>
                <button type="button" class="qr-ampliar" id="qr-ampliar" aria-haspopup="dialog" aria-controls="qr-grande" title="Ampliar el código QR">
                    <img id="qr-imagen" src="" alt="Código QR para abrir este modelo en el teléfono (toca para ampliarlo)" width="240" height="240" loading="lazy">
                    <span class="lupa" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2M11 8v6M8 11h6"/></svg></span>
                </button>
                <p id="qr-propio" hidden><strong>Míralo en tu espacio</strong>Tu archivo solo está en este navegador, así que no hay QR que lo abra en el teléfono. Para verlo en realidad aumentada, abre la galería en el celular y súbelo allí con «Sube tu modelo 3D».</p>
                <p id="qr-texto"><strong>Míralo en tu espacio</strong>Escanea el código con la cámara del teléfono: se abre la realidad aumentada de esta figura. Funciona en Android con ARCore y en iPhone o iPad.</p>
                {{-- Abierta como localhost, el QR llevaría a una dirección que el
                     teléfono no puede abrir: se explica qué hacer. --}}
                <div id="qr-local" hidden>
                    @if (app()->environment('local'))
                        <p><strong>Para verlo en el celular</strong>Esta página está abierta como <code>localhost</code>, y el teléfono no puede abrir esa dirección. Enciende el enlace público con un clic y aquí aparecerá el código QR.</p>
                        <button type="button" class="qr-encender" id="qr-encender">Encender el QR para el celular</button>
                        <p class="qr-estado" id="qr-estado" role="status" aria-live="polite"></p>
                    @else
                        <p><strong>Para verlo en el celular</strong>Esta página está abierta con una dirección que el teléfono no puede abrir. Ábrela con la dirección pública del sitio y aquí aparecerá el código QR.</p>
                    @endif
                </div>
            </div>
        </aside>
    </div>

    {{-- El QR en grande (visor.js → abrirQrGrande): para escanearlo desde más lejos o mostrarlo en una pantalla --}}
    <dialog class="qr-grande" id="qr-grande" aria-labelledby="qr-grande-titulo">
        <h2 id="qr-grande-titulo">Escanéalo con el celular</h2>
        <p class="nombre" id="qr-grande-nombre"></p>
        <img id="qr-grande-imagen" src="" alt="Código QR ampliado para abrir este modelo en el teléfono" width="480" height="480">
        <p class="nota">Apunta la cámara del teléfono al código: se abre la realidad aumentada de esta figura.</p>
        <button type="button" id="qr-grande-cerrar">Cerrar</button>
    </dialog>

    <noscript>
        <div class="sin-js">
            El visor 3D necesita javascript. Puedes descargar el modelo desde su ficha y, en iPhone o iPad,
            tocar la imagen para verlo en realidad aumentada:
            <a rel="ar" href="{{ $actual['urls']['usdz'] }}"><img src="{{ $actual['urls']['poster'] }}" alt="{{ $actual['nombre'] }} en realidad aumentada" width="160" height="160" style="display:block;margin-top:.6rem"></a>
        </div>
    </noscript>

    <section class="coleccion" aria-labelledby="titulo-coleccion" style="padding-bottom:0">
        <div class="encabezado">
            <div>
                <p class="rotulo">La colección</p>
                <h2 id="titulo-coleccion">{{ $modelos->count() }} modelos para explorar</h2>
            </div>
            <div class="buscar-y-orden">
                <div class="buscar">
                    {{ svg('heroicon-o-magnifying-glass', '', ['aria-hidden' => 'true']) }}
                    <input type="search" id="buscar" placeholder="Buscar por nombre, material o técnica" aria-label="Buscar modelos">
                </div>
                <label class="orden">
                    <span class="sr-only">Ordenar</span>
                    <select id="orden" aria-label="Ordenar los modelos">
                        <option value="">Destacadas</option>
                        <option value="populares">Más populares</option>
                        <option value="vistas">Más vistas</option>
                        <option value="descargas">Más descargadas</option>
                        <option value="nombre">De la A a la Z</option>
                    </select>
                </label>
            </div>
        </div>
        <div class="chips filtros" id="filtros" role="group" aria-label="Filtrar por técnica">
            <button type="button" class="chip" data-categoria="" aria-pressed="true">Todas</button>
            @foreach ($categorias as $clave => $nombre)
                <button type="button" class="chip" data-categoria="{{ $nombre }}" aria-pressed="false">{{ $nombre }}</button>
            @endforeach
        </div>
        <p class="conteo" id="conteo" aria-live="polite"></p>

        <div class="tarjetas" id="tarjetas">
            @foreach ($modelos as $m)
                @php $c = $contadores[$m['slug']] ?? ['vistas' => 0, 'me_gusta' => 0, 'descargas' => 0]; @endphp
                <a class="tarjeta" href="{{ route('galeria.show', $m['slug']) }}" data-slug="{{ $m['slug'] }}"
                   data-categoria="{{ $m['categoria_nombre'] }}"
                   data-etiquetas="{{ implode(' ', array_keys(Galeria3d::etiquetas($m))) }}"
                   data-orden="{{ $loop->index }}" data-vistas="{{ $c['vistas'] }}" data-me-gusta="{{ $c['me_gusta'] }}" data-descargas="{{ $c['descargas'] }}"
                   data-buscar="{{ \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii($m['nombre'] . ' ' . $m['material'] . ' ' . $m['tecnica'] . ' ' . $m['maquina'] . ' ' . $m['categoria_nombre'] . ' ' . $m['resumen'] . ' ' . implode(' ', Galeria3d::etiquetas($m)))) }}"
                   @if ($m['slug'] === $actual['slug']) aria-current="true" @endif>
                    <span class="foto"><img src="{{ $m['urls']['poster'] }}" alt="" loading="lazy" decoding="async" width="800" height="800"></span>
                    <span class="texto">
                        <span class="cat">{{ $m['categoria_nombre'] }}</span>
                        <h3>{{ $m['nombre'] }}</h3>
                        <span class="meta">
                            <span>{{ Galeria3d::peso($m['bytes']['glb']) }}</span>
                            <span title="Se puede ver en realidad aumentada">{{ svg('heroicon-o-cube', '', ['aria-hidden' => 'true']) }} AR</span>
                            @if ($m['animado'])
                                <span>{{ svg('heroicon-o-play', '', ['aria-hidden' => 'true']) }} Animado</span>
                            @endif
                            <span class="corazon" title="Me gusta">{{ svg('heroicon-o-heart', '', ['aria-hidden' => 'true']) }} <span class="num-me-gusta">{{ $num($c['me_gusta']) }}</span></span>
                        </span>
                    </span>
                </a>
            @endforeach
        </div>
        <p class="sin-resultados" id="sin-resultados" hidden>No hay modelos que coincidan con la búsqueda.</p>
    </section>

    <div class="como">
        <div>
            <h3>{{ svg('heroicon-o-cube-transparent', '', ['aria-hidden' => 'true']) }} En el navegador</h3>
            <p>El visor funciona en cualquier navegador actual —Chrome, Edge, Firefox, Safari— en computador, tableta y teléfono, sin instalar nada.</p>
        </div>
        <div>
            <h3>{{ svg('heroicon-o-device-phone-mobile', '', ['aria-hidden' => 'true']) }} Realidad aumentada</h3>
            <p>En Android con ARCore se abre la cámara dentro de la página (o en la app de Google). En iPhone y iPad se abre la Vista rápida de Apple. Apunta al suelo o a la mesa, espera a que lo reconozca y toca para colocar.</p>
        </div>
        @if ($optimizacion['modelos'])
            <div>
                <h3>{{ svg('heroicon-o-adjustments-horizontal', '', ['aria-hidden' => 'true']) }} Preparados para la web</h3>
                <p>Cada modelo se simplificó, se le juntaron las piezas que comparten material y se comprimió su geometría, para que cargue rápido también con datos móviles.</p>
                <div class="cifras">
                    <div><strong>{{ number_format(100 - $optimizacion['bytes'] / max(1, $optimizacion['bytes_antes']) * 100, 0) }} %</strong><span>menos peso</span></div>
                    <div><strong>{{ Galeria3d::peso($optimizacion['bytes']) }}</strong><span>los {{ $optimizacion['modelos'] }} modelos</span></div>
                </div>
            </div>
        @endif
    </div>
</main>

{{-- La capa que se ve sobre la cámara en la realidad aumentada de Android (WebXR). --}}
<div class="g3d-xr" id="xr-capa" hidden>
    <div class="zona" id="xr-zona"></div>
    <p class="mensaje" id="xr-mensaje">Mueve el teléfono despacio para encontrar el suelo</p>
    <div class="acciones">
        <button type="button" id="xr-reiniciar" hidden>Colocar de nuevo</button>
        <button type="button" class="principal" id="xr-salir">Salir</button>
    </div>
</div>

<script type="application/json" id="galeria-datos">@json($datosPagina)</script>
<script type="importmap">
{
    "imports": {
        "three": "{{ $vendor }}/three.module.min.js",
        "three/addons/": "{{ $vendor }}/addons/",
        "fabos/capacidad": "{{ asset('3d/capacidad.js') }}?v={{ filemtime(public_path('3d/capacidad.js')) }}"
    }
}
</script>
<script type="module" src="{{ asset('3d/visor.js') }}?v={{ filemtime(public_path('3d/visor.js')) }}"></script>
@endsection
