@php
    $c = $cifras;
    $e = $enlaces;
    $tz = config('fabos.lab.timezone');
    $n = fn ($v) => number_format((int) $v, 0, ',', '.');
    $desde = $c['reservas_desde'] ? \Illuminate\Support\Carbon::parse($c['reservas_desde'])->timezone($tz)->locale('es')->translatedFormat('F Y') : null;
    $primeraOrden = $c['primera_orden'] ? \Illuminate\Support\Carbon::parse($c['primera_orden'])->timezone($tz)->locale('es')->translatedFormat('F Y') : null;

    /*
     * Los cinco procedimientos del FabLab dentro de la caracterización. Cada
     * uno: lo aprobado, cómo se cumple hoy, las evidencias y lo que se ajustó.
     */
    $procedimientos = [
        [
            'fila' => 6, 'codigo' => 'INV-114', 'nombre' => 'Servicios de impresión a terceros', 'version' => 'v0 · 14/Jul/2023',
            'estado' => 'cumple',
            'aprobado' => 'Solicitud del cliente externo, estimación del costo por la Unidad, propuesta, relación contractual, revisión del modelo con autorización del cliente, impresión y postproceso, entrega con aceptación por escrito y factura.',
            'hoy' => 'Cada encargo externo es un proyecto en FabOS con cliente, propuesta (cotizador), aceptación registrada, contrato, ejecución con reservas de máquina, entrega y pago. La solicitud entra por el formulario público o la registra el equipo cuando llega por correo o por Gerencia.',
            'evidencias' => [
                ['Proyectos con cliente externo', $c['proyectos_externos'], $e['proyectos']],
                ['Externos finalizados', $c['proyectos_externos_fin'], $e['proyectos']],
                ['Formulario de solicitud', null, $e['solicitar']],
            ],
            'ajustes' => [],
        ],
        [
            'fila' => 6, 'codigo' => 'INV-115', 'nombre' => 'Préstamo de espacios físicos, equipos y/o herramientas', 'version' => 'v0 · 25/Jul/2023',
            'estado' => 'ajustado',
            'aprobado' => 'Agendamiento por QR, espacio apartado automáticamente, acompañamiento del personal, herramientas de riesgo operadas solo por el equipo, cierre verificando el estado. El formato INV-115-f1 solo para lo que sale del laboratorio.',
            'hoy' => 'Reservas de espacios y herramientas en FabOS (QR en cada laboratorio y en cada equipo), con llegada y salida registradas. Las máquinas de riesgo exigen certifab. Lo que sale del laboratorio se documenta con el acta FRF-207-f7 de Planta Física, firmada en pantalla y con su devolución.',
            'evidencias' => [
                ['Reservas de espacios realizadas', $c['reservas_espacios'], $e['reservas']],
                ['Préstamos de herramientas realizados', $c['reservas_herramientas'], $e['reservas']],
                ['Actas de préstamo (FRF-207-f7)', $c['actas_prestamo'], $e['actas']],
                ['Etiquetas QR de los equipos', null, $e['etiquetas']],
            ],
            'ajustes' => ['El formato INV-115-f1 dejó de usarse: se reemplazó por el FRF-207-f7 de Planta Física para los activos clave, en acuerdo con esa área.'],
        ],
        [
            'fila' => 6, 'codigo' => 'INV-116', 'nombre' => 'Préstamo de servicios de prototipado rápido', 'version' => 'v0 · 12/Ago/2023',
            'estado' => 'cumple',
            'aprobado' => 'Agendamiento de asesoría o servicio (impresión 3D y corte láser), envío del archivo, verificación del material, asesoría y fabricación, cierre con la pieza recibida a satisfacción.',
            'hoy' => 'Las reservas de impresión 3D y corte láser llevan el archivo, el propósito y el material estimado y usado; las asesorías se agendan con el equipo. La reserva se cierra al completarse, y la inasistencia queda registrada.',
            'evidencias' => [
                ['Reservas de impresión 3D y láser realizadas', $c['reservas_prototipado'], $e['reservas']],
                ['Asesorías realizadas', $c['asesorias'], $e['reservas']],
                ['Página pública de reservas', null, $e['reservar']],
            ],
            'ajustes' => [],
        ],
        [
            'fila' => 6, 'codigo' => 'INV-118', 'nombre' => 'Mantenimiento de equipos de laboratorios', 'version' => 'v0 · 11/Sep/2024',
            'estado' => 'ajustado',
            'aprobado' => 'Hoja de vida por equipo (INV-113-f1), revisión rutinaria según esquema de color (verde, amarillo, rojo) con frecuencia A, B o C, diagnóstico, mantenimiento preventivo y correctivo con informe anexado a la hoja de vida. Los equipos de apoyo (Anexo 2) no llevan hoja de vida. Acta de baja ADF-207-f3.',
            'hoy' => 'Cada activo tiene ficha y QR para reportar una falla. La pauta preventiva (mensual, bimestral, semestral = A, B, C) abre las órdenes sola el día que toca, con la lista de chequeo de su color. La orden correctiva con paro bloquea la agenda hasta cerrarse con el trabajo hecho y sus fotos. La hoja de vida se genera desde la ficha y enlaza el historial completo.',
            'evidencias' => [
                ['Activos fijos en inventario', $c['fijos'], $e['activos']],
                ['Equipos de apoyo (Anexo 2)', $c['de_apoyo'], $e['activos_apoyo']],
                ['Equipos en la pauta preventiva', $c['con_plan'], $e['pauta']],
                ['Mantenimientos preventivos cerrados', $c['preventivas'], $e['preventivas']],
                ['Mantenimientos correctivos cerrados', $c['correctivas'], $e['correctivas']],
                ['Actas de baja (ADF-207-f3)', $c['actas_baja'], $e['actas_baja']],
            ],
            'ajustes' => [
                'Hoja de vida INV-113-f1 generada por FabOS: el historial de mantenimientos es un enlace (y un QR) a las órdenes de esa máquina, no una tabla copiada a mano.',
                'Acta de baja ADF-207-f3, generada desde el acta de movimiento de tipo baja.',
            ],
        ],
        [
            'fila' => 7, 'codigo' => 'INV-113', 'nombre' => 'Elaboración de escenarios de realidad virtual', 'version' => 'v0 · 14/Jul/2023',
            'estado' => 'sin_casos',
            'aprobado' => 'Notificación del ganador de la convocatoria interna, reunión y acta con el plan de trabajo, desarrollo, entrega formal de la experiencia y cierre por correo.',
            'hoy' => 'No llegaron solicitudes por el protocolo anterior, así que no hay casos pasados que mostrar. Hoy toda solicitud, interna o externa, entra como proyecto del área VR: propuesta, plan de trabajo con tareas y responsables, reservas de la sala, entrega y cierre. Es el mismo flujo que ya se usa con los encargos externos de los laboratorios.',
            'evidencias' => [
                ['Proyectos del área de realidad virtual', $c['proyectos_vr'], $e['proyectos']],
                ['Reservas de equipos de realidad virtual', $c['reservas_vr'], $e['reservas']],
                ['Formulario de solicitud de proyecto', null, $e['solicitar']],
            ],
            'ajustes' => [],
        ],
    ];

    $estados = [
        'cumple'    => ['Cumple', 'ok'],
        'ajustado'  => ['Cumple · ajustado', 'ajuste'],
        'sin_casos' => ['Listo · sin casos', 'nada'],
    ];

    // La auditoría que viene (o la última), para el antetítulo de la portada.
    $proxima = $auditorias->where('resultado', 'pendiente')->sortBy('realizada_at')->first() ?? $auditorias->first();
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Caracterización INV-200 · FabLab</title>
    <style>
        :root{
            --verde:#00806b; --verde-osc:#00594a; --verde-cl:#e3f2ef; --azul:#0b5a7a;
            --tinta:#17201e; --suave:#5b6b67; --borde:#d4e2de; --fondo:#f4f7f6; --papel:#fff;
            --ok:#15803d; --ok-cl:#dcfce7; --aj:#a16207; --aj-cl:#fef3c7; --na:#475569; --na-cl:#e2e8f0;
        }
        @media (prefers-color-scheme: dark){
            :root:not([data-theme="light"]){
                --verde:#2bb39a; --verde-osc:#7fd8c6; --verde-cl:#0f2c27; --azul:#7cc4e2;
                --tinta:#e6efed; --suave:#9db1ac; --borde:#29403b; --fondo:#0c1513; --papel:#13201d;
                --ok:#4ade80; --ok-cl:#12301d; --aj:#fbbf24; --aj-cl:#33270b; --na:#cbd5e1; --na-cl:#1e2933;
            }
        }
        *{box-sizing:border-box}
        body{margin:0;background:var(--fondo);color:var(--tinta);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
        a{color:var(--verde)}
        .pag{max-width:76rem;margin:0 auto;padding:1.5rem 1rem 3rem}
        header.cab{display:grid;grid-template-columns:1fr auto;gap:1rem;align-items:end;border-bottom:3px solid var(--verde);padding-bottom:1rem}
        .cab h1{margin:0;font-size:1.65rem;color:var(--verde);letter-spacing:.01em}
        .cab .sub{color:var(--suave);font-size:.95rem;text-transform:uppercase;letter-spacing:.04em}
        .cab .meta{font-size:.85rem;color:var(--suave);text-align:right;border-left:3px solid var(--verde);padding-left:.8rem}
        .tarjetas{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin:1.2rem 0}
        .tarjeta{background:var(--papel);border:1px solid var(--borde);border-radius:6px;overflow:hidden}
        .tarjeta h2{margin:0;background:var(--verde);color:#fff;font-size:.95rem;padding:.55rem .9rem;letter-spacing:.03em}
        .tarjeta div{padding:.7rem .9rem;font-size:.88rem}
        .tarjeta ul{margin:.2rem 0 0;padding-left:1.1rem}
        .tarjeta .fablab{background:var(--verde-cl);border-radius:4px;padding:.15rem .4rem;font-weight:600}
        .kpis{display:grid;grid-template-columns:repeat(4,1fr);gap:.8rem;margin:1.4rem 0}
        .kpi{background:var(--papel);border:1px solid var(--borde);border-left:4px solid var(--verde);border-radius:6px;padding:.7rem .9rem;text-decoration:none;color:inherit}
        .kpi b{display:block;font-size:1.7rem;line-height:1.1;color:var(--verde-osc);font-variant-numeric:tabular-nums}
        .kpi span{font-size:.82rem;color:var(--suave)}
        .kpi:hover{border-color:var(--verde)}
        .banda{background:var(--verde);color:#fff;text-align:center;font-weight:700;letter-spacing:.05em;padding:.55rem;margin:1.8rem 0 0;border-radius:6px 6px 0 0;font-size:.95rem}
        .flujo{display:grid;grid-template-columns:repeat(6,1fr);background:var(--papel);border:1px solid var(--borde);border-top:0;font-size:.8rem}
        .flujo div{padding:.55rem .65rem;border-right:1px solid var(--borde)}
        .flujo div:last-child{border-right:0}
        .flujo i{display:block;font-style:normal;font-weight:700;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:#fff;margin:-.55rem -.65rem .45rem;padding:.3rem .65rem}
        .flujo div:nth-child(odd) i{background:var(--azul)} .flujo div:nth-child(even) i{background:var(--verde)}
        .proc{background:var(--papel);border:1px solid var(--borde);border-top:0;padding:1rem 1.1rem;display:grid;grid-template-columns:1.05fr 1.05fr .9fr;gap:1.1rem}
        .proc:last-of-type{border-radius:0 0 6px 6px}
        .proc h3{grid-column:1/-1;margin:0;display:flex;flex-wrap:wrap;gap:.5rem;align-items:baseline;font-size:1.08rem}
        .proc h3 .cod{color:var(--verde);font-weight:800}
        .proc h3 .ver{font-weight:400;font-size:.8rem;color:var(--suave)}
        .estado{margin-left:auto;font-size:.75rem;font-weight:700;padding:.15rem .6rem;border-radius:99px}
        .estado.ok{background:var(--ok-cl);color:var(--ok)} .estado.ajuste{background:var(--aj-cl);color:var(--aj)} .estado.nada{background:var(--na-cl);color:var(--na)}
        .proc h4{margin:0 0 .25rem;font-size:.72rem;text-transform:uppercase;letter-spacing:.06em;color:var(--suave)}
        .proc p{margin:0;font-size:.88rem}
        .ev{list-style:none;margin:0;padding:0;font-size:.86rem}
        .ev li{display:flex;justify-content:space-between;gap:.6rem;padding:.28rem 0;border-bottom:1px dashed var(--borde)}
        .ev li:last-child{border-bottom:0}
        .ev b{font-variant-numeric:tabular-nums;color:var(--verde-osc);white-space:nowrap}
        .ajustes{grid-column:1/-1;background:var(--aj-cl);border-radius:4px;padding:.55rem .8rem;font-size:.85rem}
        .ajustes ul{margin:.2rem 0 0;padding-left:1.1rem}
        .formatos{display:grid;grid-template-columns:repeat(3,1fr);gap:1rem;margin-top:.8rem}
        .formato{background:var(--papel);border:1px solid var(--borde);border-radius:6px;padding:.8rem .9rem;font-size:.87rem}
        .formato b{color:var(--verde)}
        .formato .tag{float:right;font-size:.72rem;font-weight:700;padding:.1rem .5rem;border-radius:99px}
        h2.sec{font-size:1.15rem;margin:2rem 0 .2rem;color:var(--verde-osc)}
        .sec+.nota{color:var(--suave);font-size:.88rem;margin:0}
        table.aud{width:100%;border-collapse:collapse;background:var(--papel);border:1px solid var(--borde);margin-top:.8rem;font-size:.87rem}
        .aud th{background:var(--verde);color:#fff;text-align:left;padding:.45rem .6rem;font-weight:600}
        .aud td{padding:.45rem .6rem;border-top:1px solid var(--borde);vertical-align:top}
        .pend{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:.8rem}
        .pend div{background:var(--papel);border:1px solid var(--borde);border-radius:6px;padding:.8rem .9rem;font-size:.87rem}
        .pend ul{margin:.3rem 0 0;padding-left:1.1rem}
        /* ------------------------------------------------ la portada */
        html{scroll-behavior:smooth}
        .intro{position:relative;min-height:100vh;min-height:100svh;display:flex;align-items:center;color:#eafaf6;overflow:hidden;
            background:
                radial-gradient(1200px 600px at 85% -10%, rgba(94,234,212,.28), transparent 60%),
                radial-gradient(900px 500px at -10% 110%, rgba(11,90,122,.55), transparent 60%),
                linear-gradient(160deg,#00473b 0%,#006b59 48%,#00806b 100%)}
        /* Los puntos del logo de la Universidad, como trama de fondo. */
        .intro::before{content:"";position:absolute;inset:0;opacity:.13;pointer-events:none;
            background-image:radial-gradient(circle,#fff 1.6px,transparent 2px);background-size:26px 26px;
            -webkit-mask-image:linear-gradient(115deg,transparent 35%,#000 75%);mask-image:linear-gradient(115deg,transparent 35%,#000 75%)}
        .intro-in{position:relative;width:100%;max-width:76rem;margin:0 auto;padding:3rem 1rem 4.5rem}
        .marca{display:flex;align-items:center;gap:.7rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;font-size:.85rem;color:#bdf3e7}
        .puntos{display:grid;grid-template-columns:repeat(3,6px);gap:4px}
        .puntos i{width:6px;height:6px;border-radius:50%;background:#7fe3cf}
        .puntos i:nth-child(2),.puntos i:nth-child(6){opacity:.45}
        .antetitulo{margin:3.2rem 0 .6rem;font-size:.95rem;letter-spacing:.04em;color:#a7e8da;text-transform:uppercase}
        .intro h1{margin:0;font-size:clamp(2.3rem,6vw,4.6rem);line-height:1.02;letter-spacing:-.025em;font-weight:800;color:#fff}
        .intro h1 em{font-style:normal;background:linear-gradient(90deg,#7fe3cf,#c8f7ec);-webkit-background-clip:text;background-clip:text;color:transparent}
        .bajada{max-width:44rem;margin:1.3rem 0 0;font-size:clamp(1rem,1.6vw,1.15rem);line-height:1.55;color:#cdeee6}
        .semaforo{display:grid;grid-template-columns:repeat(5,1fr);gap:.7rem;margin-top:2.6rem}
        .luz{display:grid;grid-template-columns:auto 1fr;grid-template-rows:auto auto;column-gap:.65rem;row-gap:.35rem;align-items:start;
            text-decoration:none;color:#eafaf6;background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.14);
            border-radius:12px;padding:.8rem .85rem;backdrop-filter:blur(6px);transition:transform .15s,background .15s}
        .luz:hover{transform:translateY(-2px);background:rgba(255,255,255,.12)}
        .luz .foco{grid-row:1/3;width:.85rem;height:.85rem;border-radius:50%;margin-top:.25rem}
        .luz.ok .foco{background:#4ade80;box-shadow:0 0 0 4px rgba(74,222,128,.18),0 0 14px #4ade80}
        .luz.ajuste .foco{background:#facc15;box-shadow:0 0 0 4px rgba(250,204,21,.18),0 0 14px #facc15}
        .luz.nada .foco{background:#cbd5e1;box-shadow:0 0 0 4px rgba(203,213,225,.15)}
        .luz span:not(.foco){font-size:.8rem;line-height:1.3;color:#cdeee6}
        .luz b{display:block;font-size:1rem;color:#fff;letter-spacing:.02em}
        .luz small{grid-column:2;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#a7e8da}
        .cifras{display:grid;grid-template-columns:repeat(4,1fr);margin-top:2.2rem;border-top:1px solid rgba(255,255,255,.18)}
        .cifras a{padding:1.1rem 1rem 0 0;text-decoration:none;color:inherit}
        .cifras a+a{padding-left:1.2rem;border-left:1px solid rgba(255,255,255,.12)}
        .cifras b{display:block;font-size:clamp(2rem,4vw,3rem);font-weight:800;line-height:1;color:#fff;font-variant-numeric:tabular-nums;letter-spacing:-.02em}
        .cifras span{display:block;margin-top:.35rem;font-size:.85rem;color:#bfe9df}
        .cifras a:hover b{color:#7fe3cf}
        .pie-intro{display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-top:2.4rem;font-size:.78rem;color:#94d6c8;letter-spacing:.03em}
        .bajar{position:absolute;left:50%;bottom:1.2rem;transform:translateX(-50%);color:#bdf3e7;width:2.6rem;height:2.6rem;display:grid;place-items:center;
            border:1px solid rgba(255,255,255,.25);border-radius:50%;animation:flota 2.2s ease-in-out infinite}
        @keyframes flota{50%{transform:translate(-50%,6px)}}
        @media (prefers-reduced-motion:reduce){.bajar{animation:none}html{scroll-behavior:auto}}
        @media (max-width:1000px){.semaforo{grid-template-columns:1fr 1fr}.cifras{grid-template-columns:1fr 1fr}.cifras a:nth-child(3){padding-left:0;border-left:0}}
        @media (max-width:560px){.semaforo{grid-template-columns:1fr}.antetitulo{margin-top:2rem}}
        @media print{.intro{min-height:auto;break-after:page;-webkit-print-color-adjust:exact;print-color-adjust:exact}.bajar{display:none}}

        footer{margin-top:2rem;color:var(--suave);font-size:.78rem;text-align:center;border-top:1px solid var(--borde);padding-top:.6rem}
        .imprimir{position:fixed;right:1rem;bottom:1rem;background:var(--verde);color:#fff;border:0;border-radius:99px;padding:.55rem 1.1rem;font:inherit;font-weight:600;cursor:pointer;box-shadow:0 2px 10px rgba(0,0,0,.2)}
        @media (max-width:900px){
            .tarjetas,.formatos,.pend{grid-template-columns:1fr}
            .kpis{grid-template-columns:repeat(2,1fr)}
            .flujo{grid-template-columns:1fr 1fr}
            .flujo div{border-bottom:1px solid var(--borde)}
            .proc{grid-template-columns:1fr}
            header.cab{grid-template-columns:1fr}
            .cab .meta{text-align:left}
        }
        @media print{
            body{background:#fff;font-size:12px} .imprimir{display:none}
            .proc,.tarjeta,.formato,.kpi{break-inside:avoid}
            a{text-decoration:none}
        }
    </style>
</head>
<body>
<header class="intro">
    <div class="intro-in">
        <div class="marca">
            <span class="puntos" aria-hidden="true"><i></i><i></i><i></i><i></i><i></i><i></i></span>
            FabLab · {{ config('fabos.lab.institution') }}
        </div>

        <p class="antetitulo">
            {{ $proxima ? 'Auditoría ' . mb_strtolower(\App\Models\Auditoria::TIPOS[$proxima->tipo] ?? '') . ' · ' . $proxima->realizada_at->timezone($tz)->locale('es')->translatedFormat('l j \d\e F, g:i a') : 'Auditoría de calidad' }}
            · ISO 9001:2015
        </p>
        <h1>Cómo opera hoy<br>lo aprobado en el <em>INV-200</em></h1>
        <p class="bajada">Caracterización del proceso de Innovación, Desarrollo y Transferencia de Conocimiento: los servicios de los laboratorios de investigación y la elaboración de escenarios de realidad virtual, con cada evidencia en FabOS.</p>

        <div class="semaforo">
            @foreach ($procedimientos as $p)
                <a href="#{{ $p['codigo'] }}" class="luz {{ $estados[$p['estado']][1] }}">
                    <span class="foco"></span>
                    <span><b>{{ $p['codigo'] }}</b>{{ $p['nombre'] }}</span>
                    <small>{{ $estados[$p['estado']][0] }}</small>
                </a>
            @endforeach
        </div>

        <div class="cifras">
            <a href="{{ $e['reservas'] }}" target="_blank"><b>{{ $n($c['reservas_espacios'] + $c['reservas_herramientas'] + $c['reservas_prototipado']) }}</b><span>reservas realizadas{{ $desde ? ' desde ' . $desde : '' }}</span></a>
            <a href="{{ $e['proyectos'] }}" target="_blank"><b>{{ $n($c['proyectos']) }}</b><span>proyectos recibidos · {{ $n($c['proyectos_externos']) }} externos</span></a>
            <a href="{{ $e['ordenes'] }}" target="_blank"><b>{{ $n($c['preventivas'] + $c['correctivas']) }}</b><span>mantenimientos cerrados{{ $primeraOrden ? ' desde ' . $primeraOrden : '' }}</span></a>
            <a href="{{ $e['activos'] }}" target="_blank"><b>{{ $n($c['fijos']) }}</b><span>activos fijos con ficha y QR</span></a>
        </div>

        <div class="pie-intro">
            <span>INV-200 · Versión 5 · filas 6 y 7</span>
            <span>Cifras en vivo · {{ $generada->format('d/m/Y H:i') }}</span>
        </div>
    </div>
    <a class="bajar" href="#detalle" aria-label="Ver el detalle">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9l6 6 6-6"/></svg>
    </a>
</header>

<div class="pag" id="detalle">

    <section class="tarjetas">
        <div class="tarjeta">
            <h2>OBJETIVO / PROPÓSITO</h2>
            <div>Fomentar el desarrollo y el potencial transferible de la investigación, y asimilar, adaptar, generar y divulgar el conocimiento; <span class="fablab">el FabLab lo hace prestando sus laboratorios, fabricando prototipos y desarrollando escenarios de realidad virtual</span>.</div>
        </div>
        <div class="tarjeta">
            <h2>ALCANCE (lo del FabLab)</h2>
            <div>
                <ul>
                    <li><b>Servicios laboratorios de investigación</b>: INV-114, INV-115, INV-116 e INV-118.</li>
                    <li><b>Elaboración de escenarios de realidad virtual</b>: INV-113.</li>
                </ul>
            </div>
        </div>
        <div class="tarjeta">
            <h2>RESPONSABLE</h2>
            <div>
                <b>Cargo responsable 1:</b> Gerente de Investigación y Transferencia<br>
                <b>Cargo responsable 2:</b> Coordinadores de área · <span class="fablab">Coordinación FabLab</span>
            </div>
        </div>
    </section>


    @foreach ([6 => 'SERVICIOS LABORATORIOS DE INVESTIGACIÓN', 7 => 'ELABORACIÓN DE ESCENARIOS DE REALIDAD VIRTUAL'] as $fila => $titulo)
        <div class="banda">{{ $fila }} · {{ $titulo }}</div>
        <div class="flujo">
            @if ($fila === 6)
                <div><i>Proveedor</i>Cadena de valor · entidades externas</div>
                <div><i>Entrada</i>Solicitud de agendamiento</div>
                <div><i>Actividad</i>Revisión y elaboración de prototipos e impresión 3D; préstamo de equipos y herramientas</div>
                <div><i>Documento</i>INV-114 · INV-115 · INV-116 · INV-118</div>
                <div><i>Salida</i>Prototipo recibido por el cliente; equipos y herramientas en préstamo</div>
                <div><i>Cliente</i>Cadena de valor · entidades externas</div>
            @else
                <div><i>Proveedor</i>Cadena de valor</div>
                <div><i>Entrada</i>Solicitud propuesta de servicio</div>
                <div><i>Actividad</i>Diseño, estructuración y realización de escenarios de realidad virtual</div>
                <div><i>Documento</i>INV-113</div>
                <div><i>Salida</i>Programa o ejecutable con la experiencia, a satisfacción del cliente</div>
                <div><i>Cliente</i>Cadena de valor</div>
            @endif
        </div>
        @foreach (collect($procedimientos)->where('fila', $fila) as $p)
            <article class="proc" id="{{ $p['codigo'] }}">
                <h3>
                    <span class="cod">{{ $p['codigo'] }}</span> {{ $p['nombre'] }}
                    <span class="ver">{{ $p['version'] }}</span>
                    <span class="estado {{ $estados[$p['estado']][1] }}">{{ $estados[$p['estado']][0] }}</span>
                </h3>
                <div><h4>Lo aprobado</h4><p>{{ $p['aprobado'] }}</p></div>
                <div><h4>Cómo se cumple hoy en FabOS</h4><p>{{ $p['hoy'] }}</p></div>
                <div>
                    <h4>Evidencias</h4>
                    <ul class="ev">
                        @foreach ($p['evidencias'] as [$texto, $valor, $url])
                            <li><a href="{{ $url }}" target="_blank">{{ $texto }}</a>@if ($valor !== null)<b>{{ is_numeric($valor) ? $n($valor) : $valor }}</b>@endif</li>
                        @endforeach
                    </ul>
                </div>
                @if ($p['ajustes'])
                    <div class="ajustes">
                        <b>Ajustado</b>
                        <ul>@foreach ($p['ajustes'] as $a)<li>{{ $a }}</li>@endforeach</ul>
                    </div>
                @endif
            </article>
        @endforeach
    @endforeach

    <h2 class="sec">Formatos</h2>
    <p class="nota">Los formatos de los procedimientos y cómo se llevan hoy.</p>
    <div class="formatos">
        <div class="formato">
            <span class="tag" style="background:var(--ok-cl);color:var(--ok)">En FabOS</span>
            <b>INV-113-f1</b> · Hoja de vida de equipos y herramientas<br>
            Se genera desde la ficha de cada activo fijo (botón «Hoja de vida»), con el registro fotográfico, los accesorios, las observaciones y el enlace al historial de mantenimientos.
            <br><a href="{{ $e['activos'] }}" target="_blank">Ver activos →</a>
        </div>
        <div class="formato">
            <span class="tag" style="background:var(--ok-cl);color:var(--ok)">Creado</span>
            <b>ADF-207-f3</b> · Acta de baja de activos<br>
            Sale del acta de movimiento de tipo «baja»: los activos, el motivo y las firmas de la Vicerrectoría Administrativa y Financiera.
            <br><a href="{{ $e['actas_baja'] }}" target="_blank">Ver actas de baja →</a>
        </div>
        <div class="formato">
            <span class="tag" style="background:var(--na-cl);color:var(--na)">Acoplado</span>
            <b>INV-115-f1</b> · Préstamo de equipos<br>
            Ya no se usa: los préstamos de activos clave se documentan con el formato FRF-207-f7 de Planta Física, firmado en pantalla y con su devolución.
            <br><a href="{{ $e['actas'] }}" target="_blank">Ver actas de movimiento →</a>
        </div>
    </div>

    <h2 class="sec">Auditorías del laboratorio</h2>
    <p class="nota">Internas y externas, con su resultado y sus mejoras. <a href="{{ $e['nueva_auditoria'] }}" target="_blank">Registrar una</a> · <a href="{{ $e['auditorias'] }}" target="_blank">Ver todas</a></p>
    @if ($auditorias->isEmpty())
        <p style="font-size:.9rem;color:var(--suave)">Todavía no hay auditorías registradas.</p>
    @else
        <table class="aud">
            <tr><th>Fecha y hora</th><th>Tipo</th><th>Auditoría</th><th>Resultado</th><th>Hallazgos y mejoras</th></tr>
            @foreach ($auditorias as $a)
                <tr>
                    <td>{{ $a->realizada_at?->timezone($tz)->format('d/m/Y H:i') }}</td>
                    <td>{{ \App\Models\Auditoria::TIPOS[$a->tipo] ?? $a->tipo }}</td>
                    <td><b>{{ $a->titulo }}</b>@if ($a->auditor)<br><span style="color:var(--suave)">{{ $a->auditor }}</span>@endif</td>
                    <td>{{ \App\Models\Auditoria::RESULTADOS[$a->resultado] ?? $a->resultado }}</td>
                    <td>
                        @forelse ($a->hallazgos ?? [] as $h)
                            <div>· <b>{{ \App\Models\Auditoria::TIPOS_DE_HALLAZGO[$h['tipo'] ?? ''] ?? 'Hallazgo' }}</b>{{ filled($h['procedimiento'] ?? null) ? ' (' . $h['procedimiento'] . ')' : '' }}: {{ $h['descripcion'] ?? '' }}@if (filled($h['accion'] ?? null)) → {{ $h['accion'] }} <i>[{{ \App\Models\Auditoria::ESTADOS_DE_MEJORA[$h['estado'] ?? 'abierta'] ?? '' }}]</i>@endif</div>
                        @empty
                            —
                        @endforelse
                    </td>
                </tr>
            @endforeach
        </table>
    @endif

    <footer>FabOS · FabLab {{ config('fabos.lab.institution') }} · documento de trabajo para la auditoría de calidad (ISO 9001:2015). Los enlaces abren el panel y piden sesión.</footer>
</div>
<button class="imprimir" type="button" onclick="window.print()">Imprimir</button>
</body>
</html>
