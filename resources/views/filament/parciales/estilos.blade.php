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
</style>
