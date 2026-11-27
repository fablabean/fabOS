<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="robots" content="noindex">
<title>Evaluación · Bootcamp AGENTIC</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
  :root{--bg:#0E1513;--bg2:#141D1A;--card:#18231F;--rule:#2A3832;--ink:#ECF1EE;--soft:#B7C4BE;--muted:#83948C;--accent:#5CE0B8;--accent2:#FF7A3D;--bad:#FF6B6B}
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 Inter,system-ui,sans-serif}
  a{color:var(--accent)}
  .barra{border-bottom:1px solid var(--rule)}
  .barra .in{max-width:46rem;margin:0 auto;padding:.7rem 1rem;display:flex;justify-content:space-between;align-items:center;gap:1rem}
  .marca{font-weight:800;letter-spacing:.04em;font-size:.9rem} .marca b{color:var(--accent2)}
  .barra small{color:var(--muted);font-size:.8rem}
  main{max-width:46rem;margin:0 auto;padding:1.6rem 1rem 4rem}
  .kicker{font:600 .75rem "JetBrains Mono",monospace;letter-spacing:.14em;text-transform:uppercase;color:var(--accent2)}
  h1{font-size:clamp(1.7rem,5vw,2.5rem);line-height:1.08;margin:.4rem 0 .7rem;letter-spacing:-.02em}
  h1 span{color:var(--accent)}
  .intro{color:var(--soft);margin:0 0 1.6rem}
  .caja{border:1px solid var(--rule);border-radius:16px;background:var(--bg2);padding:1.1rem 1.2rem;margin-bottom:1rem}
  .quien{display:grid;grid-template-columns:1fr 9rem;gap:.8rem}
  @media (max-width:560px){.quien{grid-template-columns:1fr}}
  label.campo{display:grid;gap:.3rem;font-size:.85rem;font-weight:600;color:var(--soft)}
  label.campo small{font-weight:400;color:var(--muted)}
  input[type=text]{width:100%;background:#0A100E;border:1px solid var(--rule);border-radius:10px;color:var(--ink);padding:.65rem .8rem;font:15px Inter,sans-serif}
  input[type=text]:focus{outline:2px solid color-mix(in srgb,var(--accent) 60%,transparent);border-color:transparent}
  #codigo{font-family:"JetBrains Mono",monospace;text-transform:uppercase;letter-spacing:.2em}
  fieldset{border:1px solid var(--rule);border-radius:16px;background:var(--bg2);padding:1.1rem 1.2rem 1rem;margin:0 0 1rem;min-width:0}
  legend{padding:0;float:left;width:100%}
  legend + *{clear:both}
  .barra small{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .num{font:600 .72rem "JetBrains Mono",monospace;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);display:block;margin-bottom:.35rem}
  .enunciado{font-weight:700;font-size:1.04rem;line-height:1.4;margin:0 0 .8rem;display:block}
  .op{display:flex;gap:.7rem;align-items:flex-start;padding:.6rem .75rem;border:1px solid var(--rule);border-radius:12px;margin-top:.5rem;cursor:pointer;color:var(--soft);background:var(--card)}
  .op:hover{border-color:color-mix(in srgb,var(--accent) 55%,var(--rule))}
  .op input{margin:.3rem 0 0;accent-color:var(--accent);flex:none}
  .op:has(input:checked){border-color:var(--accent);color:var(--ink)}
  .op.bien{border-color:var(--accent);background:color-mix(in srgb,var(--accent) 14%,var(--card));color:var(--ink)}
  .op.mal{border-color:var(--bad);background:color-mix(in srgb,var(--bad) 12%,var(--card))}
  .por-que{display:none;margin:.8rem 0 0;padding:.7rem .85rem;border-left:3px solid var(--accent);background:#0A100E;border-radius:0 10px 10px 0;font-size:.92rem;color:var(--soft)}
  .por-que b{color:var(--ink)}
  fieldset.mal .por-que{border-left-color:var(--bad)}
  .calificada .por-que{display:block}
  .calificada .op{cursor:default}
  .enviar{width:100%;border:0;border-radius:14px;background:var(--accent);color:#06231A;font:800 1rem Inter,sans-serif;padding:.95rem 1.2rem;cursor:pointer}
  .enviar[disabled]{opacity:.5;cursor:default}
  .aviso{color:var(--bad);font-size:.9rem;margin:.6rem 0 0;min-height:1.2em}
  .resultado{display:none;text-align:center;border-color:color-mix(in srgb,var(--accent) 50%,var(--rule))}
  .resultado.ver{display:block}
  .resultado .grande{font-size:3rem;font-weight:800;line-height:1;margin:.3rem 0;color:var(--accent)}
  .resultado p{margin:.4rem 0;color:var(--soft)}
  .otra{background:none;border:1px solid var(--rule);color:var(--ink);border-radius:99px;padding:.45rem 1rem;font:600 .85rem Inter,sans-serif;cursor:pointer;margin-top:.6rem}
  .pie{color:var(--muted);font-size:.8rem;text-align:center;margin-top:2rem}
</style>
</head>
<body>
<header class="barra"><div class="in">
  <span class="marca">BOOTCAMP <b>AGENTIC</b></span>
  <small>FabLab EAN · Universidad Ean</small>
</div></header>

<main>
  <div class="kicker">Evaluación final del bootcamp</div>
  <h1>¿Qué te llevas del <span>bootcamp</span>?</h1>
  <p class="intro">Diez preguntas sobre lo que vimos: el problema y el objetivo, el ciclo de trabajo con la IA, la API, el agente y MCP. Al enviar ves tu resultado y la explicación de cada pregunta.</p>

  <div class="caja resultado" id="resultado" aria-live="polite">
    <div class="kicker">Tu resultado</div>
    <div class="grande" id="r-aciertos"></div>
    <p id="r-nota"></p>
    <p id="r-mensaje"></p>
    <p style="font-size:.88rem">Abajo está la explicación de cada pregunta y el bloque de donde sale.</p>
    <button type="button" class="otra" id="otra">Responder otra vez</button>
  </div>

  <form id="evaluacion" novalidate>
    <div class="caja quien">
      <label class="campo">Tu nombre
        <input type="text" id="nombre" name="nombre" maxlength="80" autocomplete="name" required>
      </label>
      <label class="campo">Tu ID <small>si lo tienes</small>
        <input type="text" id="codigo" name="codigo" maxlength="4" autocomplete="off" placeholder="····">
      </label>
    </div>

    @foreach ($preguntas as $i => $p)
      <fieldset data-i="{{ $i }}">
        <legend>
          <span class="num">Pregunta {{ $i + 1 }} de {{ count($preguntas) }}</span>
          <span class="enunciado">{{ $p['pregunta'] }}</span>
        </legend>
        @foreach ($p['opciones'] as $letra => $texto)
          <label class="op" data-letra="{{ $letra }}">
            <input type="radio" name="p{{ $i }}" value="{{ $letra }}">
            <span>{{ $texto }}</span>
          </label>
        @endforeach
        <div class="por-que"></div>
      </fieldset>
    @endforeach

    <button type="submit" class="enviar" id="enviar">Enviar mis respuestas</button>
    <p class="aviso" id="aviso" role="alert"></p>
  </form>

  <p class="pie">Bootcamp AGENTIC · eanX · Universidad Ean · FabLab EAN</p>
</main>

<script>
(function () {
  var form = document.getElementById('evaluacion');
  var total = {{ count($preguntas) }};
  var aviso = document.getElementById('aviso');
  var boton = document.getElementById('enviar');
  var token = document.querySelector('meta[name=csrf-token]').content;

  // El ID del bootcamp, si esta persona ya lo recibió en las presentaciones.
  try {
    var guardado = ((location.hash.match(/(?:^#|&)id=([A-Za-z0-9]{4})\b/) || [])[1]
      || localStorage.getItem('agentic-id') || localStorage.getItem('agentic-d3-id') || localStorage.getItem('agentic-d2-id') || '').toUpperCase();
    if (/^[A-Z0-9]{4}$/.test(guardado)) document.getElementById('codigo').value = guardado;
    var nombre = localStorage.getItem('agentic-nombre') || '';
    if (nombre) document.getElementById('nombre').value = nombre;
  } catch (e) {}

  function texto(el, t) { el.textContent = t; }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    var nombre = document.getElementById('nombre').value.trim();
    if (nombre.length < 2) { texto(aviso, 'Escribe tu nombre antes de enviar.'); document.getElementById('nombre').focus(); return; }
    var respuestas = [], faltan = [];
    for (var i = 0; i < total; i++) {
      var marcada = form.querySelector('input[name=p' + i + ']:checked');
      respuestas.push(marcada ? marcada.value : '');
      if (!marcada) faltan.push(i + 1);
    }
    if (faltan.length) {
      texto(aviso, (faltan.length === 1 ? 'Te falta la pregunta ' : 'Te faltan las preguntas ') + faltan.join(', ') + '.');
      form.querySelector('fieldset[data-i="' + (faltan[0] - 1) + '"]').scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    texto(aviso, '');
    boton.disabled = true; texto(boton, 'Enviando…');

    var datos = new URLSearchParams();
    datos.append('nombre', nombre);
    datos.append('codigo', document.getElementById('codigo').value.trim());
    respuestas.forEach(function (r, i) { datos.append('respuestas[' + i + ']', r); });

    fetch(@json(route('evaluacion.agentic.responder')), {
      method: 'POST', body: datos,
      headers: { 'X-CSRF-TOKEN': token, 'Accept': 'application/json' }
    }).then(function (r) {
      return r.json().then(function (j) { if (!r.ok) throw j; return j; });
    }).then(function (res) {
      try { localStorage.setItem('agentic-nombre', nombre); if (res.codigo) localStorage.setItem('agentic-id', res.codigo); } catch (e) {}
      mostrar(res);
    }).catch(function (err) {
      boton.disabled = false; texto(boton, 'Enviar mis respuestas');
      var m = err && err.errors ? Object.values(err.errors)[0][0] : (err && err.message === 'CSRF token mismatch.' ? 'La página se venció: recárgala y vuelve a enviar.' : 'No se pudo enviar. Revisa tu conexión y vuelve a intentar.');
      texto(aviso, m);
    });
  });

  function mostrar(res) {
    form.classList.add('calificada');
    form.querySelectorAll('input').forEach(function (i) { i.disabled = true; });
    res.detalle.forEach(function (d, i) {
      var fs = form.querySelector('fieldset[data-i="' + i + '"]');
      fs.classList.add(d.bien ? 'bien' : 'mal');
      fs.querySelectorAll('.op').forEach(function (op) {
        if (op.dataset.letra === d.correcta) op.classList.add('bien');
        else if (op.dataset.letra === d.elegida) op.classList.add('mal');
      });
      var pq = fs.querySelector('.por-que');
      pq.innerHTML = '';
      var b = document.createElement('b');
      b.textContent = (d.bien ? '✓ Correcta. ' : '✗ Repasa: ') + 'Día ' + d.dia + ' · ' + d.bloque + '. ';
      pq.appendChild(b);
      pq.appendChild(document.createTextNode(d.explicacion));
    });
    boton.style.display = 'none';
    texto(document.getElementById('r-aciertos'), res.aciertos + ' de ' + res.total);
    texto(document.getElementById('r-nota'), 'Nota: ' + res.nota + ' sobre 5,0');
    texto(document.getElementById('r-mensaje'),
      res.aciertos === res.total ? '¡Todas! Te llevas el bootcamp completo.' :
      res.aciertos >= 8 ? 'Muy bien: repasa las que quedaron en rojo.' :
      res.aciertos >= 6 ? 'Vas bien. Las explicaciones de abajo te dicen qué bloque repasar.' :
      'Vale la pena repasar: el material sigue en la zona del Bootcamp AGENTIC.');
    var r = document.getElementById('resultado');
    r.classList.add('ver');
    r.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  document.getElementById('otra').addEventListener('click', function () { location.reload(); });
})();
</script>
</body>
</html>
