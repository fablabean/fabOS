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

    /* Sin respuesta hace más de un día hábil: la fila late. Es lo único del
       panel que se mueve, y por eso se ve; va lento y suave para que avise
       sin que la lista entera se vuelva ilegible. Gana al semáforo: una
       entrega para pasado mañana importa menos que alguien esperando. */
    @keyframes sin-respuesta { 0%, 100% { background-color: color-mix(in srgb, #E53E3E 6%, transparent); }
                               50%      { background-color: color-mix(in srgb, #E53E3E 26%, transparent); } }
    tr.sin-respuesta { animation: sin-respuesta 1.4s ease-in-out infinite; }
    tr.sin-respuesta > td:first-child { box-shadow: inset 4px 0 0 #E53E3E; }
    .aviso-sin-respuesta { display: table; margin-top: .3rem; padding: .2rem .65rem !important; border-radius: 999px;
                           background: #C53030; color: #fff; font-size: .62rem !important; line-height: 1.3; font-weight: 600;
                           letter-spacing: .01em; white-space: nowrap; }
    /* Quien pidió menos movimiento en su sistema lo ve fijo, pero lo ve. */
    @media (prefers-reduced-motion: reduce) {
        tr.sin-respuesta { animation: none; background-color: color-mix(in srgb, #E53E3E 18%, transparent); }
    }
</style>
