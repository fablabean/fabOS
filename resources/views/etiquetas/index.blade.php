<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Etiquetas QR · fabOS</title>
    <style>
        *{box-sizing:border-box}
        body{margin:0;background:#E8E8E2;color:#191A16;
             font-family:system-ui,"Segoe UI",Arial,sans-serif}

        .barra{
            background:#F6F6F2;border-bottom:1px solid #C7C7BD;padding:1rem 1.4rem;
            display:flex;gap:1rem;align-items:center;flex-wrap:wrap;
        }
        .barra form{display:flex;gap:.6rem;align-items:center;flex-wrap:wrap}
        select,button{font-family:inherit;font-size:.9rem;padding:.45rem .7rem;
                      border:1px solid #C7C7BD;border-radius:4px;background:#fff}
        button{background:#0D6E63;color:#F6F6F2;border:0;cursor:pointer;font-weight:600}
        .nota{font-size:.82rem;color:#6E7066}

        .hoja{max-width:21cm;margin:1.4rem auto;padding:0 1rem}
        .grilla{display:grid;grid-template-columns:repeat(3,1fr);gap:.5rem}

        .etiqueta{
            background:#fff;border:1px solid #C7C7BD;border-radius:4px;
            padding:.5rem;display:flex;gap:.55rem;align-items:center;
            break-inside:avoid;page-break-inside:avoid;min-height:2.9cm;
        }
        .etiqueta svg{flex:0 0 auto}
        .datos{min-width:0}
        .nombre{font-weight:700;font-size:.78rem;line-height:1.2;
                overflow-wrap:anywhere;margin-bottom:.15rem}
        .area{font-size:.62rem;color:#6E7066;text-transform:uppercase;letter-spacing:.08em}
        .marca{font-size:.58rem;color:#6E7066;margin-top:.25rem;
               font-family:ui-monospace,Consolas,monospace}
        .no-reserv{font-size:.58rem;color:#A45A17;margin-top:.15rem}

        @media print{
            .barra{display:none}
            body{background:#fff}
            .hoja{margin:0;padding:0;max-width:none}
            .etiqueta{border-color:#999}
            @page{margin:1cm}
        }
    </style>
</head>
<body>

<div class="barra">
    <strong>Etiquetas QR</strong>
    <form method="GET">
        <select name="area">
            <option value="">Todas las áreas</option>
            @foreach ($areas as $a)
                <option value="{{ $a->id }}" @selected(request('area') == $a->id)>{{ $a->name }}</option>
            @endforeach
        </select>
        <label style="font-size:.85rem;display:flex;gap:.3rem;align-items:center">
            <input type="checkbox" name="solo_reservables" value="1" @checked(request()->boolean('solo_reservables'))>
            Solo reservables
        </label>
        <label style="font-size:.85rem;display:flex;gap:.3rem;align-items:center">
            <input type="checkbox" name="ubicaciones" value="1" @checked(request()->boolean('ubicaciones'))>
            Incluir ubicaciones
        </label>
        <button type="submit">Filtrar</button>
    </form>
    <button type="button" onclick="window.print()">Imprimir</button>
    <span class="nota">{{ $equipos->count() }} etiquetas · pega cada una en su máquina</span>
</div>

<div class="hoja">
    <div class="grilla">
        @foreach ($equipos as $equipo)
            <div class="etiqueta">
                {{-- El QR apunta a /e/{token}: escanear abre la ficha del equipo. --}}
                {!! $qr->svg(route('escaneo.equipo', $equipo->qr_token), 88) !!}
                <div class="datos">
                    <div class="area">{{ $equipo->area?->name }}</div>
                    <div class="nombre">{{ $equipo->name }}</div>
                    @if ($equipo->asset_tag)
                        <div class="marca">{{ $equipo->asset_tag }}</div>
                    @endif
                    @unless ($equipo->is_reservable)
                        <div class="no-reserv">no se reserva</div>
                    @endunless
                </div>
            </div>
        @endforeach
    </div>

    @if ($ubicaciones->isNotEmpty())
        <h2 style="font-size:.9rem;text-transform:uppercase;letter-spacing:.1em;color:#6E7066;
                   margin:1.6rem 0 .6rem;break-before:page">Ubicaciones</h2>
        <div class="grilla">
            @foreach ($ubicaciones as $u)
                <div class="etiqueta">
                    {{-- Escanear una ubicación abre su inventario cíclico. --}}
                    {!! $qr->svg(route('inventario.ubicacion', $u->qr_token), 88) !!}
                    <div class="datos">
                        <div class="area">Ubicación</div>
                        <div class="nombre">{{ $u->name }}</div>
                        @if ($u->parent)
                            <div class="marca">en {{ $u->parent->name }}</div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

</body>
</html>
