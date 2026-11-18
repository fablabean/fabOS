<style>
    .sem { --borde: rgb(229 231 235); --suave: rgb(107 114 128); --pres: rgb(5 150 105); --rem: rgb(37 99 235); font-size: .9rem; }
    .dark .sem { --borde: rgb(55 65 81); --suave: rgb(156 163 175); }
    .sem .nav { display:flex; align-items:center; gap:.5rem; flex-wrap:wrap; margin-bottom:1rem; }
    .sem .nav h2 { font-weight:700; font-size:1.05rem; margin-right:auto; }
    .sem .nav button { border:1px solid var(--borde); border-radius:.5rem; padding:.35rem .75rem; font-weight:600; }
    .sem .leyenda { display:flex; gap:1rem; flex-wrap:wrap; color:var(--suave); font-size:.8rem; margin-bottom:1rem; }
    .sem .b { display:inline-flex; align-items:center; gap:.3rem; border-radius:.4rem; padding:.15rem .45rem; font-size:.78rem;
        font-variant-numeric:tabular-nums; white-space:nowrap; font-weight:600; }
    .sem .b.presencial { background:rgba(5,150,105,.12); color:var(--pres); }
    .sem .b.remota { background:rgba(37,99,235,.12); color:var(--rem); }
    .sem .b.ausente { background:rgba(107,114,128,.15); color:var(--suave); }
    .sem .b.alerta { background:rgba(220,38,38,.12); color:rgb(220 38 38); }
    .sem .punto { width:.55rem; height:.55rem; border-radius:999px; display:inline-block; }
    .sem .cambio { font-size:.68rem; color:rgb(217 119 6); font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
    .sem .suave { color:var(--suave); font-size:.8rem; }
    .sem .tabla { overflow-x:auto; border:1px solid var(--borde); border-radius:.75rem; }
    .sem table { width:100%; border-collapse:collapse; }
    .sem th, .sem td { padding:.55rem .6rem; border-bottom:1px solid var(--borde); text-align:left; vertical-align:top; }
    .sem th { font-size:.7rem; text-transform:uppercase; letter-spacing:.05em; color:var(--suave); font-weight:600; }
    .sem tr:last-child td { border-bottom:0; }
    .sem td.hoy, .sem th.hoy { background:rgba(217,119,6,.06); }
    .sem .celda { display:flex; flex-direction:column; gap:.25rem; align-items:flex-start; min-width:7.5rem; }
    .sem .dias { display:grid; grid-template-columns:repeat(auto-fit,minmax(12rem,1fr)); gap:.75rem; }
    .sem .dia { border:1px solid var(--borde); border-radius:.75rem; padding:.8rem .9rem; display:flex; flex-direction:column; gap:.45rem; }
    .sem .dia.hoy { border-color:rgb(217 119 6); }
    .sem .dia h3 { font-weight:700; }
    .sem .tarjeta { border:1px solid var(--borde); border-radius:.75rem; padding:.8rem 1rem; }
    .sem input[type=url] { width:100%; border:1px solid var(--borde); border-radius:.5rem; padding:.4rem .6rem; background:transparent; }
    .sem .fila { display:flex; gap:.5rem; align-items:center; flex-wrap:wrap; }
</style>
