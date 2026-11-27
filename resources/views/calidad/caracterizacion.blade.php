@php
    $c = $cifras;
    $e = $enlaces;
    $tz = config('fabos.lab.timezone');
    $n = fn ($v) => number_format((int) $v, 0, ',', '.');
    $desde = $c['reservas_desde'] ? \Illuminate\Support\Carbon::parse($c['reservas_desde'])->timezone($tz)->locale('es')->translatedFormat('F Y') : null;
    $primeraOrden = $c['primera_orden'] ? \Illuminate\Support\Carbon::parse($c['primera_orden'])->timezone($tz)->locale('es')->translatedFormat('F Y') : null;

    // Lo estimado antes de FabOS: se suma en la portada y va aparte en cada evidencia.
    $antes = fn (string ...$claves) => array_sum(array_map(fn ($k) => $historico['valores'][$k] ?? 0, $claves));
    $periodoAntes = $historico['periodo'];
    // Lo que se usaba antes de FabOS: Microsoft Bookings por QR, como lo enlazan los documentos.
    $bookingsLab = 'https://outlook.office365.com/owa/calendar/Laboratoriosdeinnovacin1@universidadean.edu.co/bookings/';
    $bookingsVr = 'https://outlook.office365.com/owa/calendar/Solicitudreservarealidadvirtual@universidadean.edu.co/bookings/';
    $agendamientos = 'https://universidadeaneduco-my.sharepoint.com/personal/prototipado_universidadean_edu_co/_layouts/15/onedrive.aspx?id=%2Fpersonal%2Fprototipado%5Funiversidadean%5Fedu%5Fco%2FDocuments%2FAgendamientos%20laboratorios%20de%20innovaci%C3%B3n&ga=1';
    $metro = ($pm = \App\Models\Project::where('name', 'VR Metro')->first())
        ? \App\Filament\Resources\Projects\ProjectResource::getUrl('edit', ['record' => $pm]) : null;
    $kpis = [
        ['url' => $e['reservas'], 'hoy' => $c['reservas_espacios'] + $c['reservas_herramientas'] + $c['reservas_prototipado'],
            'antes' => $antes('reservas_espacios', 'reservas_herramientas', 'reservas_prototipado'),
            'texto' => 'reservas realizadas', 'desde' => $desde],
        ['url' => $e['proyectos'], 'hoy' => $c['proyectos'], 'antes' => $antes('proyectos'),
            'texto' => 'proyectos recibidos · ' . $n($c['proyectos_externos'] + $antes('proyectos_externos')) . ' externos', 'desde' => null],
        ['url' => $e['ordenes'], 'hoy' => $c['preventivas'] + $c['correctivas'], 'antes' => $antes('preventivas', 'correctivas'),
            'texto' => 'mantenimientos cerrados', 'desde' => $primeraOrden],
        ['url' => $e['activos'], 'hoy' => $c['fijos'], 'antes' => 0,
            'texto' => 'activos fijos con ficha y QR', 'desde' => null],
    ];

    /*
     * Los cinco procedimientos del FABLAB dentro de la caracterización. Cada
     * uno: lo aprobado, cómo se cumple hoy, las evidencias y lo que se ajustó.
     */
    $procedimientos = [
        [
            'fila' => 6, 'codigo' => 'INV-114', 'nombre' => 'Servicios de impresión a terceros', 'version' => 'v0 · 14/Jul/2023',
            'estado' => 'cumple',
            'aprobado' => 'Solicitud del cliente externo, estimación del costo por la Unidad, propuesta, relación contractual, revisión del modelo con autorización del cliente, impresión y postproceso, entrega con aceptación por escrito y factura.',
            'hoy' => 'Cada encargo externo es un proyecto en FabOS con cliente, propuesta (cotizador), aceptación registrada, contrato, ejecución con reservas de máquina, entrega y pago. La solicitud entra por el formulario público o la registra el equipo cuando llega por correo o por Gerencia.',
            'evidencias' => [
                ['Proyectos con cliente externo', $c['proyectos_externos'], $e['proyectos'], 'proyectos_externos'],
                ['Externos finalizados', $c['proyectos_externos_fin'], $e['proyectos'], 'proyectos_externos_fin'],
                ['Formulario de solicitud', null, $e['solicitar']],
            ],
            'ajustes' => [],
            'situacion' => ['Desde el año pasado no han llegado nuevas solicitudes del área comercial de la Gerencia de Investigaciones. Por eso se está consolidando una estrategia que integre nuevos canales para recibir clientes externos, con la misma secuencia de actividades.'],
        ],
        [
            'fila' => 6, 'codigo' => 'INV-115', 'nombre' => 'Préstamo de espacios físicos, equipos y/o herramientas', 'version' => 'v0 · 25/Jul/2023',
            'estado' => 'ajustado',
            'aprobado' => 'Agendamiento por QR, espacio apartado automáticamente, acompañamiento del personal, herramientas de riesgo operadas solo por el equipo, cierre verificando el estado. El formato INV-115-f1 solo para lo que sale del laboratorio.',
            'hoy' => 'Reservas de espacios y herramientas en FabOS (QR en el laboratorio y en cada equipo), con llegada y salida registradas. Las máquinas de riesgo exigen certifab. Lo que sale del laboratorio se documenta con el acta FRF-207-f7 de Planta Física, firmada en pantalla y con su devolución.',
            'evidencias' => [
                ['Reservas de espacios realizadas', $c['reservas_espacios'], $e['reservas'], 'reservas_espacios'],
                ['Préstamos de herramientas realizados', $c['reservas_herramientas'], $e['reservas'], 'reservas_herramientas'],
                ['Actas de préstamo (FRF-207-f7)', $c['actas_prestamo'], $e['actas'], 'actas_prestamo', 'Actas de préstamo'],
                ['Etiquetas QR de los equipos', null, $e['etiquetas']],
            ],
            'ajustes' => ['El formato INV-115-f1 dejó de usarse: se reemplazó por el FRF-207-f7 de Planta Física para los activos clave, en acuerdo con esa área.'],
            'situacion' => [
                'Las reservas de espacios y herramientas se muestran en la nueva plataforma y en la anterior.',
                'El formato de préstamo INV-115-f1 no se aplicó: este año los activos que lo requerían fueron manipulados por personal interno y capacitado, en casos excepcionales y justificados. Las solicitudes en que el activo salía de la Universidad siguieron el protocolo de Planta Física (FRF-207-f7).',
                'Oportunidad de mejora: se está analizando usar ese mismo formato para los préstamos internos; ya se están haciendo pruebas.',
            ],
            'plataformas' => ['antes' => $bookingsLab, 'carpeta' => $agendamientos],
        ],
        [
            'fila' => 6, 'codigo' => 'INV-116', 'nombre' => 'Préstamo de servicios de prototipado rápido', 'version' => 'v0 · 12/Ago/2023',
            'estado' => 'cumple',
            'aprobado' => 'Agendamiento de asesoría o servicio (impresión 3D y corte láser), envío del archivo, verificación del material, asesoría y fabricación, cierre con la pieza recibida a satisfacción.',
            'hoy' => 'Las reservas de impresión 3D y corte láser llevan el archivo, el propósito y el material estimado y usado; las asesorías se agendan con el equipo. La reserva se cierra al completarse, y la inasistencia queda registrada.',
            'evidencias' => [
                ['Reservas de impresión 3D y láser realizadas', $c['reservas_prototipado'], $e['reservas'], 'reservas_prototipado'],
                ['Asesorías realizadas', $c['asesorias'], $e['reservas'], 'asesorias'],
                ['Página pública de reservas', null, $e['reservar']],
            ],
            'ajustes' => [],
            'situacion' => ['Las reservas de prototipado y las asesorías se muestran en la nueva plataforma y en la anterior.'],
            'plataformas' => ['antes' => $bookingsLab, 'carpeta' => $agendamientos],
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
                ['Mantenimientos preventivos cerrados', $c['preventivas'], $e['preventivas'], 'preventivas'],
                ['Mantenimientos correctivos cerrados', $c['correctivas'], $e['correctivas'], 'correctivas'],
                ['Actas de baja (ADF-207-f3)', $c['actas_baja'], $e['actas_baja'], 'actas_baja'],
            ],
            'ajustes' => [
                'Hoja de vida INV-113-f1 generada por FabOS: el historial de mantenimientos es un enlace (y un QR) a las órdenes de esa máquina, no una tabla copiada a mano.',
                'Acta de baja ADF-207-f3, generada desde el acta de movimiento de tipo baja.',
            ],
            'situacion' => [
                'La hoja de vida de cada equipo se genera actualizada desde FabOS, enlazada con su historial de mantenimientos.',
                'Los informes de mantenimiento realizados se cargan en el historial del activo respectivo; los nuevos se registran directamente en FabOS y se descargan en el formato del laboratorio.',
            ],
        ],
        [
            'fila' => 7, 'codigo' => 'INV-113', 'nombre' => 'Elaboración de escenarios de realidad virtual', 'version' => 'v0 · 14/Jul/2023',
            'estado' => 'sin_casos',
            'aprobado' => 'Notificación del ganador de la convocatoria interna, reunión y acta con el plan de trabajo, desarrollo, entrega formal de la experiencia y cierre por correo.',
            'hoy' => 'No llegaron solicitudes por el protocolo anterior, así que no hay casos pasados que mostrar. Hoy toda solicitud, interna o externa, entra como proyecto del área VR: propuesta, plan de trabajo con tareas y responsables, reservas de la sala, entrega y cierre. Es el mismo flujo que ya se usa con los encargos externos del laboratorio.',
            'evidencias' => [
                ['Proyectos del área de realidad virtual', $c['proyectos_vr'], $e['proyectos'], 'proyectos_vr'],
                ['Reservas de equipos de realidad virtual', $c['reservas_vr'], $e['reservas'], 'reservas_vr'],
                ['Formulario de solicitud de proyecto', null, $e['solicitar']],
            ],
            'ajustes' => [],
            'situacion' => [
                'No se ejecutaron convocatorias por el cambio de gerente; por eso se plantea un plan de mejora para recibir solicitudes externas.',
                'El proyecto VR Metro llegó por una fuente distinta a la convocatoria oficial y se desarrolló con este protocolo.',
            ],
            'casos' => $metro ? [['Proyecto VR Metro', $metro]] : [],
            'plataformas' => ['antes' => $bookingsVr],
        ],
    ];

    $estados = [
        'cumple'    => ['Cumple', 'ok'],
        'ajustado'  => ['Cumple · ajustado', 'ajuste'],
        'sin_casos' => ['Listo · sin casos', 'nada'],
    ];

    /*
     * La del auditor sigue el documento del proceso: lo aprobado y sus
     * registros, todos vigentes. Sin estados, ajustes ni hallazgos: esos son
     * del equipo, que plantea las mejoras.
     */
    if (! $interna) {
        $estados = array_map(fn () => ['Vigente', 'ok'], $estados);
    }

    /*
     * Los registros de FabOS que evidencian cada actividad de los documentos
     * (App\Support\ProcedimientosInv200): nombre, cifra y enlace al panel.
     */
    $registros = [
        'solicitar'               => ['Formulario de solicitud', null, $e['solicitar']],
        // El cuarto dato es el desglose: lo que de esa cifra pasó por el paso dentro de FabOS.
        'proyectos_externos'      => ['Proyectos externos recibidos', $c['proyectos_externos'], $e['proyectos'],
            $n($c['ext_propuesta_fabos']) . ' con la propuesta enviada desde FabOS'],
        'ext_contratados'         => ['Proyectos externos contratados', $c['ext_contratados'], $e['proyectos'],
            $n($c['ext_contratados_aceptados']) . ' con la aceptación firmada en FabOS; los demás se registraron ya contratados'],
        'ext_entregados'          => ['Proyectos externos entregados', $c['ext_entregados'], $e['proyectos'],
            $n($c['ext_entregados_aceptados']) . ' con la aceptación firmada en FabOS; los demás se registraron ya entregados'],
        'actas_prestamo'          => ['Actas de préstamo', $c['actas_prestamo'], $e['actas_prestamo']],
        'reservas_espacios'       => ['Reservas de espacios realizadas', $c['reservas_espacios'], $e['reservas']],
        'reservas_herramientas'   => ['Préstamos de herramientas realizados', $c['reservas_herramientas'], $e['reservas']],
        'reservar'                => ['Agendamiento en línea (QR)', null, $e['reservar']],
        'etiquetas'               => ['Códigos QR de los equipos', null, $e['etiquetas']],
        'reservas_prototipado'    => ['Reservas de impresión 3D y láser', $c['reservas_prototipado'], $e['reservas']],
        'asesorias'               => ['Asesorías realizadas', $c['asesorias'], $e['reservas']],
        'pauta'                   => ['Cronograma de mantenimiento preventivo', null, $e['pauta']],
        'con_plan'                => ['Equipos en el cronograma preventivo', $c['con_plan'], $e['pauta']],
        'con_color'               => ['Activos con esquema de color', $c['con_color'], $e['activos']],
        'fijos'                   => ['Activos con hoja de vida y QR', $c['fijos'], $e['activos']],
        'de_apoyo'                => ['Equipos de apoyo (Anexo 2)', $c['de_apoyo'], $e['activos_apoyo']],
        'preventivas'             => ['Mantenimientos preventivos cerrados', $c['preventivas'], $e['preventivas']],
        'correctivas'             => ['Mantenimientos correctivos cerrados', $c['correctivas'], $e['correctivas']],
        'actas_baja'              => ['Actas de baja (ADF-207-f3)', $c['actas_baja'], $e['actas_baja']],
        'proyectos_vr'            => ['Proyectos de realidad virtual', $c['proyectos_vr'], $e['proyectos']],
        'reservas_vr'             => ['Reservas de equipos de realidad virtual', $c['reservas_vr'], $e['reservas']],
    ];

    // La auditoría que viene (o la última), para el antetítulo de la portada.
    $proxima = $auditorias->where('resultado', 'pendiente')->sortBy('realizada_at')->first() ?? $auditorias->first();
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $interna ? 'INV-200 · versión interna' : 'Caracterización INV-200' }} · FABLAB</title>
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
        .proc.dos{grid-template-columns:1fr}
        .proc.dos > div{max-width:58rem}
        .formatos.dos{grid-template-columns:repeat(2,1fr)}
        .interna-aviso{display:inline-block;background:#facc15;color:#3b2f00;font-weight:800;border-radius:99px;padding:.1rem .6rem;letter-spacing:.04em}
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
        .situacion{margin-top:.8rem;background:var(--verde-cl);border-left:3px solid var(--verde);border-radius:4px;padding:.55rem .75rem;font-size:.86rem}
        .situacion h4{margin-bottom:.3rem}
        .situacion p{margin:0 0 .35rem}
        .situacion p:last-of-type{margin-bottom:0}
        .situacion .plat{display:flex;flex-wrap:wrap;gap:.3rem 1rem;margin-top:.5rem;font-size:.8rem}
        .situacion .plat i{font-style:normal;color:var(--suave);margin-right:.25rem}
        .sit-int{grid-column:1/-1}
        .sit-int .situacion{margin-top:0}
        .paso .regs a b small.antes{display:block;text-align:right;font-weight:400}
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
        .marca{gap:1.3rem}
        .logo{display:inline-grid;place-items:center;line-height:0}
        .logo img{width:auto;object-fit:contain}
        /* El de la Universidad viene negro: en blanco sobre el verde. */
        .logo.ean img{height:3.6rem;filter:brightness(0) invert(1)}
        /* El símbolo del FABLAB, cuadrado, separado por una línea fina. */
        .logo.fab,.marca-txt.fab{border-left:1px solid rgba(255,255,255,.3);padding-left:1.3rem}
        .logo.fab img{height:4.4rem}
        .logo.fab.invertir img{filter:invert(1)}
        .logo.tarjeta img{background:#fff;border-radius:10px;padding:.35rem}
        .marca-txt{line-height:1.25;font-size:1rem;color:#fff}
        .marca-txt small{font-size:.75rem;color:#a7e8da;letter-spacing:.08em}
        @media (max-width:560px){.logo.ean img{height:2.6rem}.logo.fab img{height:3.2rem}.logo.fab{padding-left:.9rem}.marca{gap:.9rem}}
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
        .cifras span.desglose{margin-top:.15rem;font-size:.74rem;color:#94d6c8}
        .ev .val{text-align:right;white-space:nowrap}
        .ev .val small{display:block;font-size:.72rem;color:var(--suave);font-weight:400}
        .pie-intro{display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;margin-top:2.4rem;font-size:.78rem;color:#94d6c8;letter-spacing:.03em}
        .bajar{position:absolute;left:50%;bottom:1.2rem;transform:translateX(-50%);color:#bdf3e7;width:2.6rem;height:2.6rem;display:grid;place-items:center;
            border:1px solid rgba(255,255,255,.25);border-radius:50%;animation:flota 2.2s ease-in-out infinite}
        @keyframes flota{50%{transform:translate(-50%,6px)}}
        @media (prefers-reduced-motion:reduce){.bajar{animation:none}html{scroll-behavior:auto}}
        @media (max-width:1000px){.semaforo{grid-template-columns:1fr 1fr}.cifras{grid-template-columns:1fr 1fr}.cifras a:nth-child(3){padding-left:0;border-left:0}}
        @media (max-width:560px){.semaforo{grid-template-columns:1fr}.antetitulo{margin-top:2rem}}
        @media print{.intro{min-height:auto;break-after:page;-webkit-print-color-adjust:exact;print-color-adjust:exact}.bajar{display:none}}

        /* ------------------------------------------------ el documento, punto por punto */
        .abre-doc{font:inherit;font-weight:700;color:inherit;background:none;border:0;border-bottom:1px dashed currentColor;padding:0;cursor:pointer}
        .abre-doc:hover{color:var(--verde)}
        .ver-doc{margin-right:.15rem;font:inherit;font-size:.78rem;font-weight:600;color:var(--verde);background:var(--verde-cl);border:1px solid var(--borde);border-radius:99px;padding:.15rem .7rem;cursor:pointer;display:inline-flex;align-items:center;gap:.35rem}
        .ver-doc:hover{border-color:var(--verde)}
        .ver-doc svg{width:14px;height:14px}
        dialog.doc{width:min(72rem,calc(100vw - 2rem));height:min(88vh,60rem);padding:0;border:1px solid var(--borde);border-radius:10px;background:var(--papel);color:var(--tinta);box-shadow:0 20px 60px rgba(0,0,0,.35)}
        dialog.doc[open]{display:flex;flex-direction:column}
        dialog.doc::backdrop{background:rgba(6,20,17,.6)}
        .doc-cab{display:flex;gap:1rem;align-items:flex-start;padding:1rem 1.2rem .6rem;border-bottom:3px solid var(--verde)}
        .doc-cab .cod{font-weight:800;color:var(--verde);font-size:.85rem;letter-spacing:.04em}
        .doc-cab h2{margin:.1rem 0 .15rem;font-size:1.2rem;line-height:1.25}
        .doc-cab .ver{font-size:.8rem;color:var(--suave)}
        .doc-cerrar{margin-left:auto;flex:none;width:2.2rem;height:2.2rem;border-radius:50%;border:1px solid var(--borde);background:var(--papel);color:var(--tinta);font-size:1.3rem;line-height:1;cursor:pointer}
        .doc-tabs{display:flex;gap:.3rem;padding:.5rem 1.2rem 0;border-bottom:1px solid var(--borde)}
        .doc-tabs button{font:inherit;font-size:.85rem;font-weight:600;background:none;border:0;border-bottom:3px solid transparent;padding:.4rem .7rem;color:var(--suave);cursor:pointer;margin-bottom:-1px}
        .doc-tabs button[aria-selected="true"]{color:var(--verde);border-bottom-color:var(--verde)}
        .doc-cuerpo{flex:1;overflow:auto;padding:1rem 1.2rem 1.4rem}
        .doc-cuerpo[hidden]{display:none}
        .doc-pdf{padding:0;overflow:hidden}
        .doc-pdf iframe{width:100%;height:100%;border:0;display:block}
        .doc-obj{font-size:.88rem;color:var(--suave);margin:0 0 .8rem}
        .doc-obj b{color:var(--tinta)}
        .doc-resumen{display:flex;flex-wrap:wrap;gap:.5rem;margin:0 0 1rem;font-size:.8rem}
        .doc-resumen span{background:var(--verde-cl);color:var(--verde-osc);border-radius:99px;padding:.15rem .65rem;font-weight:600}
        .doc-resumen span.falta{background:var(--aj-cl);color:var(--aj)}
        .paso{display:grid;grid-template-columns:4.2rem 1fr 17rem;gap:.9rem;padding:.85rem 0;border-top:1px solid var(--borde)}
        .paso .num{font-weight:800;color:var(--verde);font-variant-numeric:tabular-nums;font-size:.95rem}
        .paso h3{margin:0 0 .3rem;font-size:.98rem}
        .paso p{margin:0 0 .45rem;font-size:.88rem;line-height:1.55}
        .paso mark{background:#fff3b0;color:inherit;border-radius:3px;padding:0 .15rem;box-decoration-break:clone;-webkit-box-decoration-break:clone}
        @media (prefers-color-scheme: dark){:root:not([data-theme="light"]) .paso mark{background:#5b4a00;color:#fff7d1}}
        .paso .regs h4{margin:0 0 .3rem;font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:var(--suave)}
        .paso .regs a{display:flex;justify-content:space-between;gap:.5rem;align-items:baseline;text-decoration:none;color:inherit;font-size:.82rem;background:var(--fondo);border:1px solid var(--borde);border-left:3px solid var(--ok);border-radius:5px;padding:.35rem .55rem;margin-bottom:.35rem}
        .paso .regs a:hover{border-color:var(--verde)}
        .paso .regs a b{color:var(--verde-osc);font-variant-numeric:tabular-nums}
        .paso .regs a small{display:block;font-size:.72rem;color:var(--suave);margin-top:.1rem;line-height:1.35}
        .paso .regs .sin{font-size:.8rem;background:var(--aj-cl);color:var(--aj);border-radius:5px;padding:.35rem .55rem;font-weight:600}
        @media (max-width:760px){.paso{grid-template-columns:1fr;gap:.4rem}}
        @media print{dialog.doc{display:none!important}.ver-doc{display:none}}

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
            {{-- Primero la Universidad, después el símbolo del FABLAB. --}}
            @if ($logoEan)
                <span class="logo ean"><img src="{{ $logoEan }}" alt="{{ config('fabos.lab.institution') }}"></span>
            @else
                <span class="marca-txt"><small>{{ config('fabos.lab.institution') }}</small></span>
            @endif
            @if ($logo)
                <span class="logo fab {{ $logo['tarjeta'] ? 'tarjeta' : '' }} {{ $logo['invertir'] ? 'invertir' : '' }}"><img src="{{ $logo['url'] }}" alt="{{ config('fabos.lab.name') }}"></span>
            @else
                <span class="marca-txt fab">FABLAB</span>
            @endif
        </div>

        <p class="antetitulo">
            {{ $proxima ? 'Auditoría ' . mb_strtolower(\App\Models\Auditoria::TIPOS[$proxima->tipo] ?? '') . ' · ' . $proxima->realizada_at->timezone($tz)->locale('es')->translatedFormat('l j \d\e F, g:i a') : 'Auditoría de calidad' }}
            · ISO 9001:2015
        </p>
        @if ($interna)
            <h1>Cómo opera hoy<br>lo aprobado en el <em>INV-200</em></h1>
            <p class="bajada">Caracterización del proceso de Innovación, Desarrollo y Transferencia de Conocimiento: los servicios del laboratorio de investigación y la elaboración de escenarios de realidad virtual, con cada evidencia en FabOS.</p>
        @else
            <h1>Caracterización<br>del proceso <em>INV-200</em></h1>
            <p class="bajada">Innovación, Desarrollo y Transferencia de Conocimiento: los servicios de los laboratorios de investigación y la elaboración de escenarios de realidad virtual, con sus registros en FabOS.</p>
        @endif

        <div class="semaforo">
            @foreach ($procedimientos as $p)
                <a href="#{{ $p['codigo'] }}" class="luz {{ $estados[$p['estado']][1] }}">
                    <span class="foco"></span>
                    <span><b>{{ $p['codigo'] }}</b>{{ $p['nombre'] }}</span>
                    <small>{{ $estados[$p['estado']][0] }}</small>
                </a>
            @endforeach
        </div>

        @if ($interna)
        <div class="cifras">
            @foreach ($kpis as $k)
                <a href="{{ $k['url'] }}" target="_blank">
                    <b>{{ $k['antes'] ? '≈' : '' }}{{ $n($k['hoy'] + $k['antes']) }}</b>
                    <span>{{ $k['texto'] }}</span>
                    @if ($k['antes'])
                        <span class="desglose">{{ $n($k['hoy']) }} en FabOS{{ $k['desde'] ? ' desde ' . $k['desde'] : '' }} · ≈{{ $n($k['antes']) }} antes{{ $periodoAntes ? ' (' . $periodoAntes . ')' : '' }}</span>
                    @elseif ($k['desde'])
                        <span class="desglose">en FabOS desde {{ $k['desde'] }}</span>
                    @endif
                </a>
            @endforeach
        </div>
        @endif

        <div class="pie-intro">
            <span>@if ($interna)<span class="interna-aviso">VERSIÓN INTERNA</span> · @endif INV-200 · Versión 5 · filas 6 y 7</span>
            <span>{{ $interna ? 'Cifras en vivo' : 'Registros en FabOS' }} · {{ $generada->format('d/m/Y H:i') }}</span>
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
            <div>Fomentar el desarrollo y el potencial transferible de la investigación, y asimilar, adaptar, generar y divulgar el conocimiento; <span class="fablab">el FABLAB lo hace prestando su laboratorio, fabricando prototipos y desarrollando escenarios de realidad virtual</span>.</div>
        </div>
        <div class="tarjeta">
            <h2>ALCANCE (lo del FABLAB)</h2>
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
                <b>Cargo responsable 2:</b> Coordinadores de área · <span class="fablab">Coordinación FABLAB</span>
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
                <div><i>Documento</i>@foreach (['INV-114', 'INV-115', 'INV-116', 'INV-118'] as $cod)<button type="button" class="abre-doc" data-doc="{{ $cod }}" data-abrir="pdf" title="Ver el documento completo">{{ $cod }}</button>{{ $loop->last ? '' : ' · ' }}@endforeach</div>
                <div><i>Salida</i>Prototipo recibido por el cliente; equipos y herramientas en préstamo</div>
                <div><i>Cliente</i>Cadena de valor · entidades externas</div>
            @else
                <div><i>Proveedor</i>Cadena de valor</div>
                <div><i>Entrada</i>Solicitud propuesta de servicio</div>
                <div><i>Actividad</i>Diseño, estructuración y realización de escenarios de realidad virtual</div>
                <div><i>Documento</i><button type="button" class="abre-doc" data-doc="INV-113" data-abrir="pdf" title="Ver el documento completo">INV-113</button></div>
                <div><i>Salida</i>Programa o ejecutable con la experiencia, a satisfacción del cliente</div>
                <div><i>Cliente</i>Cadena de valor</div>
            @endif
        </div>
        @foreach (collect($procedimientos)->where('fila', $fila) as $p)
            <article class="proc {{ $interna ? '' : 'dos' }}" id="{{ $p['codigo'] }}">
                <h3>
                    @if (isset($documentos[$p['codigo']]))
                        <button type="button" class="ver-doc" data-doc="{{ $p['codigo'] }}"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/></svg>Documento punto por punto</button>
                    @endif
                    <span class="cod">{{ $p['codigo'] }}</span> {{ $p['nombre'] }}
                    <span class="ver">{{ $p['version'] }}</span>
                    @if ($interna)<span class="estado {{ $estados[$p['estado']][1] }}">{{ $estados[$p['estado']][0] }}</span>@endif
                </h3>
                @if ($interna)
                    <div><h4>Lo aprobado</h4><p>{{ $p['aprobado'] }}</p></div>
                    <div><h4>Cómo se cumple hoy en FabOS</h4><p>{{ $p['hoy'] }}</p></div>
                @else
                    <div>
                        <h4>Procedimiento</h4><p>{{ $p['aprobado'] }}</p>
                        @include('calidad.partials.situacion', ['p' => $p, 'reservar' => $e['reservar']])
                    </div>
                @endif
                {{-- La del auditor no lista registros: están en el documento punto por punto. --}}
                @if ($interna)
                <div>
                    <h4>Evidencias</h4>
                    <ul class="ev">
                        @foreach ($p['evidencias'] as $fila)
                            @php
                                [$texto, $valor, $url] = $fila;
                                $texto = ! $interna && isset($fila[4]) ? $fila[4] : $texto;
                                $previo = isset($fila[3]) ? ($historico['valores'][$fila[3]] ?? 0) : 0;
                            @endphp
                            <li>
                                <a href="{{ $url }}" target="_blank">{{ $texto }}</a>
                                @if ($interna && $valor !== null)
                                    <span class="val">
                                        <b>{{ is_numeric($valor) ? $n($valor) : $valor }}</b>
                                        @if ($previo)<small title="Estimado antes de FabOS{{ $periodoAntes ? ' · ' . $periodoAntes : '' }}">+≈{{ $n($previo) }} antes</small>@endif
                                    </span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
                @endif
                @if ($interna && ! empty($p['situacion']))
                    <div class="sit-int">@include('calidad.partials.situacion', ['p' => $p, 'reservar' => $e['reservar']])</div>
                @endif
                @if ($interna && $p['ajustes'])
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
    <div class="formatos {{ $interna ? '' : 'dos' }}">
        <div class="formato">
            <span class="tag" style="background:var(--ok-cl);color:var(--ok)">En FabOS</span>
            <b>INV-113-f1</b> · Hoja de vida de equipos y herramientas<br>
            Se genera desde la ficha de cada activo fijo (botón «Hoja de vida»), con el registro fotográfico, los accesorios, las observaciones y el enlace al historial de mantenimientos.
            <br><a href="{{ $e['activos'] }}" target="_blank">Ver activos →</a>
        </div>
        <div class="formato">
            <span class="tag" style="background:var(--ok-cl);color:var(--ok)">{{ $interna ? 'Creado' : 'En FabOS' }}</span>
            <b>ADF-207-f3</b> · Acta de baja de activos<br>
            Sale del acta de movimiento de tipo «baja»: los activos, el motivo y las firmas de la Vicerrectoría Administrativa y Financiera.
            <br><a href="{{ $e['actas_baja'] }}" target="_blank">Ver actas de baja →</a>
        </div>
        @if ($interna)
        <div class="formato">
            <span class="tag" style="background:var(--na-cl);color:var(--na)">Acoplado</span>
            <b>INV-115-f1</b> · Préstamo de equipos<br>
            Ya no se usa: los préstamos de activos clave se documentan con el formato FRF-207-f7 de Planta Física, firmado en pantalla y con su devolución.
            <br><a href="{{ $e['actas'] }}" target="_blank">Ver actas de movimiento →</a>
        </div>
        @endif
    </div>

    @if ($interna)
    <h2 class="sec">Auditorías del laboratorio</h2>
    <p class="nota">Internas y externas, con su resultado y sus mejoras.@if ($delEquipo) <a href="{{ $e['nueva_auditoria'] }}" target="_blank">Registrar una</a> · <a href="{{ $e['auditorias'] }}" target="_blank">Ver todas</a>@endif</p>
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
    @endif

    <footer>
        @if ($interna)
            FabOS · FABLAB {{ config('fabos.lab.institution') }} · versión interna de trabajo, solo para el equipo. La del auditor: <a href="{{ route('calidad.caracterizacion') }}" target="_blank">{{ route('calidad.caracterizacion') }}</a>.
        @else
            FabOS · FABLAB {{ config('fabos.lab.institution') }} · caracterización INV-200 para la auditoría de calidad (ISO 9001:2015). Los enlaces abren el panel y piden sesión.
        @endif
    </footer>
</div>
@foreach ($documentos as $cod => $d)
    @php
        $conPdf = \App\Support\ProcedimientosInv200::pdf($cod) !== null;
        $claves = substr_count(implode(' ', array_column($d['pasos'], 'texto')), '**') / 2;
        $sinRegistro = collect($d['pasos'])->filter(fn ($p) => ! $p['registros'])->count();
    @endphp
    <dialog class="doc" id="doc-{{ $cod }}" aria-labelledby="doc-{{ $cod }}-t">
        <div class="doc-cab">
            <div>
                <div class="cod">PROCEDIMIENTO {{ $cod }}</div>
                <h2 id="doc-{{ $cod }}-t">{{ $d['titulo'] }}</h2>
                <div class="ver">Versión {{ $d['version'] }} · {{ $d['fecha'] }} · 6. Secuencia de actividades</div>
            </div>
            <button type="button" class="doc-cerrar" data-cerrar aria-label="Cerrar">×</button>
        </div>
        <div class="doc-tabs" role="tablist">
            <button type="button" role="tab" aria-selected="true" data-tab="pasos">Punto por punto</button>
            @if ($conPdf)<button type="button" role="tab" aria-selected="false" data-tab="pdf">Documento original</button>@endif
        </div>
        <div class="doc-cuerpo" data-panel="pasos">
            <p class="doc-obj"><b>Objetivo.</b> {{ $d['objetivo'] }}</p>
            <div class="doc-resumen">
                <span>{{ count($d['pasos']) }} actividades</span>
                <span>{{ $claves }} puntos clave resaltados</span>
                @if ($interna && $sinRegistro)<span class="falta">{{ $sinRegistro }} sin registro en FabOS</span>@endif
            </div>
            @foreach ($d['pasos'] as $paso)
                <div class="paso">
                    <div class="num">{{ $paso['num'] }}</div>
                    <div>
                        <h3>{{ $paso['titulo'] }}</h3>
                        {!! \App\Support\ProcedimientosInv200::html($paso['texto']) !!}
                    </div>
                    <div class="regs">
                        <h4>Registro en FabOS</h4>
                        @forelse ($paso['registros'] as $r)
                            @php [$rt, $rv, $ru] = $registros[$r]; $rd = $registros[$r][3] ?? null; @endphp
                            {{-- La del auditor, sin números: solo el registro y su enlace. --}}
                            <a href="{{ $ru }}" target="_blank"><span>{{ $rt }}@if ($interna && $rd)<small>{{ $rd }}</small>@endif</span>@if ($interna && $rv !== null)<b>{{ $n($rv) }}@if ($hv = $historico['valores'][$r] ?? 0)<small class="antes">+≈{{ $n($hv) }} antes{{ $periodoAntes ? ' · ' . $periodoAntes : '' }}</small>@endif</b>@endif</a>
                        @empty
                            @if ($interna)<div class="sin">Sin registro en FabOS</div>@endif
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
        @if ($conPdf)
            <div class="doc-cuerpo doc-pdf" data-panel="pdf" hidden data-src="{{ route('calidad.documento', $cod) }}"></div>
        @endif
    </dialog>
@endforeach
<script>
(function () {
    // Los códigos de la caracterización abren el documento completo; el botón de cada procedimiento, punto por punto.
    function abrir(cod, tab) {
        var d = document.getElementById('doc-' + cod);
        if (!d) return;
        mostrar(d, tab && d.querySelector('[data-panel="' + tab + '"]') ? tab : 'pasos');
        d.showModal();
    }
    function mostrar(d, tab) {
        d.querySelectorAll('[data-tab]').forEach(function (b) { b.setAttribute('aria-selected', b.dataset.tab === tab ? 'true' : 'false'); });
        d.querySelectorAll('[data-panel]').forEach(function (p) {
            p.hidden = p.dataset.panel !== tab;
            // El PDF se carga la primera vez que se pide.
            if (!p.hidden && p.dataset.src && !p.firstChild) {
                var f = document.createElement('iframe');
                f.src = p.dataset.src;
                f.title = 'Documento original';
                p.appendChild(f);
            }
        });
    }
    document.querySelectorAll('[data-doc]').forEach(function (b) {
        b.addEventListener('click', function () { abrir(b.dataset.doc, b.dataset.abrir); });
    });
    document.querySelectorAll('dialog.doc').forEach(function (d) {
        d.querySelectorAll('[data-tab]').forEach(function (b) { b.addEventListener('click', function () { mostrar(d, b.dataset.tab); }); });
        d.querySelector('[data-cerrar]').addEventListener('click', function () { d.close(); });
        // Clic fuera del recuadro: cierra.
        d.addEventListener('click', function (ev) { if (ev.target === d) d.close(); });
    });
})();
</script>
<button class="imprimir" type="button" onclick="window.print()">Imprimir</button>
</body>
</html>
