{{-- Estilos propios del panel, en linea: el arranque no depende de compilar
     un tema. El semaforo de las entregas tiñe la fila entera con un fondo
     suave y una raya a la izquierda; sutil, para que se vea sin gritar. --}}
<style>
    tr.entrega-vencida > td:first-child,
    tr.entrega-hoy > td:first-child,
    tr.entrega-manana > td:first-child,
    tr.entrega-pasado > td:first-child { box-shadow: inset 3px 0 0 var(--semaforo); }
    tr.entrega-vencida { --semaforo: #C53030; background: color-mix(in srgb, #C53030 9%, transparent); }
    tr.entrega-hoy     { --semaforo: #DD6B20; background: color-mix(in srgb, #DD6B20 10%, transparent); }
    tr.entrega-manana  { --semaforo: #D69E2E; background: color-mix(in srgb, #D69E2E 10%, transparent); }
    tr.entrega-pasado  { --semaforo: #2F855A; background: color-mix(in srgb, #2F855A 9%, transparent); }
    .dark tr.entrega-vencida { background: color-mix(in srgb, #FC8181 14%, transparent); }
    .dark tr.entrega-hoy     { background: color-mix(in srgb, #F6AD55 14%, transparent); }
    .dark tr.entrega-manana  { background: color-mix(in srgb, #F6E05E 12%, transparent); }
    .dark tr.entrega-pasado  { background: color-mix(in srgb, #68D391 12%, transparent); }

    /* Las alianzas, en azul. Va despues del semaforo a proposito: gana la
       franja, porque «esto no es un encargo» es lo que cuesta ver en una lista
       de cincuenta, y el color de la entrega sigue tiñendo la fila igual. */
    tr.es-alianza > td:first-child { box-shadow: inset 3px 0 0 #2B6CB0; }
    .dark tr.es-alianza > td:first-child { box-shadow: inset 3px 0 0 #63B3ED; }

    /* En pausa, en gris: sigue ahí porque no está muerto, pero no compite con
       lo que hay que mover hoy. Las acciones no se apagan: reanudar es justo
       lo que hay que poder pulsar. */
    tr.en-pausa > td { background-color: color-mix(in srgb, #6B7280 9%, transparent); }
    tr.en-pausa > td:not(:last-child) { opacity: .62; }
    tr.en-pausa > td:first-child { box-shadow: inset 3px 0 0 #9CA3AF; }

    /* Sin respuesta hace más de un día hábil: la fila late. Es lo único del
       panel que se mueve, y por eso se ve; va lento y suave para que avise
       sin que la lista entera se vuelva ilegible. Gana al semáforo: una
       entrega para pasado mañana importa menos que alguien esperando. */
    @keyframes sin-respuesta { 0%, 100% { background-color: color-mix(in srgb, #E53E3E 6%, transparent); }
                               50%      { background-color: color-mix(in srgb, #E53E3E 26%, transparent); } }
    tr.sin-respuesta { animation: sin-respuesta 1.4s ease-in-out infinite; }
    tr.sin-respuesta > td:first-child { box-shadow: inset 4px 0 0 #E53E3E; }
    .aviso-sin-respuesta { display: block; width: fit-content; box-sizing: content-box; margin-top: .3rem;
                           padding: .15rem .7rem; border-radius: 999px; background: #C53030; color: #fff;
                           font-size: .62rem; line-height: 1.4; font-weight: 600; letter-spacing: .01em;
                           white-space: nowrap; }
    /* Quien pidió menos movimiento en su sistema lo ve fijo, pero lo ve. */
    @media (prefers-reduced-motion: reduce) {
        tr.sin-respuesta { animation: none; background-color: color-mix(in srgb, #E53E3E 18%, transparent); }
    }

    /* En la lista, debajo del nombre: cómo le decimos al proyecto entre
       nosotros (la nota interna), y lo último que se dijo con quien lo pidió. */
    .proyecto-nota { display: block; margin-top: .2rem; color: #B7791F; font-size: .75rem; font-weight: 600; }
    .dark .proyecto-nota { color: #F6E05E; }
    .proyecto-mensaje { display: block; margin-top: .15rem; color: #6B7280; font-size: .72rem; font-style: italic;
                        overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
    .proyecto-mensaje b { font-style: normal; font-weight: 600; }

    /* La barra para pasar de un proyecto a otro: fija bajo la barra superior. */
    .recorrido-proyectos { position: sticky; top: 4rem; z-index: 20; display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 2.2fr) minmax(0, 1fr);
                           align-items: center; gap: .75rem; padding: .45rem .6rem; margin-bottom: -.5rem; border-radius: .75rem;
                           background: rgba(255, 255, 255, .94); backdrop-filter: blur(6px);
                           box-shadow: 0 1px 2px rgba(0, 0, 0, .06), 0 0 0 1px rgba(3, 7, 18, .06); }
    .dark .recorrido-proyectos { background: rgba(24, 24, 27, .94); box-shadow: 0 0 0 1px rgba(255, 255, 255, .1); }
    .recorrido-paso { display: flex; align-items: center; gap: .4rem; min-width: 0; padding: .3rem .5rem; border-radius: .5rem; color: inherit; }
    .recorrido-paso.is-siguiente { justify-content: flex-end; text-align: right; }
    a.recorrido-paso:hover { background: color-mix(in srgb, #D69E2E 14%, transparent); }
    .recorrido-icono { width: 1.1rem; height: 1.1rem; flex: none; color: #B7791F; }
    .recorrido-vecino { display: flex; flex-direction: column; min-width: 0; line-height: 1.2; }
    .recorrido-codigo { font-size: .68rem; color: #6B7280; font-variant-numeric: tabular-nums; }
    .recorrido-nombre { font-size: .8rem; font-weight: 500; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .recorrido-actual { min-width: 0; text-align: center; line-height: 1.25; }
    .recorrido-titulo { display: flex; justify-content: center; align-items: baseline; gap: .5rem; white-space: nowrap; overflow: hidden; }
    .recorrido-titulo strong { overflow: hidden; text-overflow: ellipsis; font-size: .95rem; }
    .recorrido-etapa { font-size: .68rem; padding: .05rem .5rem; border-radius: 999px; background: color-mix(in srgb, #D69E2E 18%, transparent); }
    .recorrido-detalle { font-size: .72rem; color: #6B7280; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .recorrido-nota { color: #B7791F; font-weight: 600; }
    .dark .recorrido-nota, .dark .recorrido-icono { color: #F6E05E; }
    @media (max-width: 640px) {
        .recorrido-proyectos { grid-template-columns: auto minmax(0, 1fr) auto; }
        .recorrido-vecino { display: none; }
    }
</style>
