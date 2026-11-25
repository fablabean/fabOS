<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="robots" content="noindex">
<title>Asistente · Bootcamp AGENTIC</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
  :root{--bg:#0E1513;--bg2:#141D1A;--card:#18231F;--rule:#2A3832;--ink:#ECF1EE;--soft:#B7C4BE;--muted:#83948C;--accent:#5CE0B8;--accent2:#FF7A3D;--warn:#F4C14B;--bad:#FF6B6B;--code:#0A100E}
  *{box-sizing:border-box}
  html,body{height:100%}
  body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.6 Inter,system-ui,sans-serif;display:flex;flex-direction:column}
  a{color:var(--accent)}
  code,pre,.mono{font-family:"JetBrains Mono",ui-monospace,Consolas,monospace}
  .barra{border-bottom:1px solid var(--rule);background:color-mix(in srgb,var(--bg) 92%,transparent)}
  .barra .in{max-width:76rem;margin:0 auto;padding:.6rem 1.2rem;display:flex;gap:.8rem 1.2rem;align-items:center;justify-content:space-between;flex-wrap:wrap}
  .marca{font-weight:800;letter-spacing:.04em;font-size:.9rem} .marca b{color:var(--accent2)}
  .so{display:inline-flex;border:1px solid var(--rule);border-radius:99px;overflow:hidden}
  .so button{background:none;border:0;color:var(--soft);padding:.35rem .85rem;font:600 .82rem Inter,sans-serif;cursor:pointer}
  .so button[aria-pressed=true]{background:var(--accent);color:#06231A}
  .boton{display:inline-flex;align-items:center;gap:.4rem;cursor:pointer;border:1px solid var(--rule);border-radius:99px;padding:.4rem .9rem;background:var(--bg2);color:var(--ink);font:600 .82rem Inter,sans-serif}
  .boton:hover{border-color:var(--accent)}
  .lienzo{flex:1;min-height:0;max-width:76rem;width:100%;margin:0 auto;padding:1rem 1.2rem;display:grid;grid-template-columns:minmax(0,1fr) 19rem;gap:1.2rem}
  @media (max-width:900px){.lienzo{grid-template-columns:1fr}.lado{order:2}}
  .chat{display:flex;flex-direction:column;min-height:0;border:1px solid var(--rule);border-radius:16px;background:var(--bg2);overflow:hidden;min-height:70vh}
  .hilo{flex:1;overflow-y:auto;padding:1.2rem;display:flex;flex-direction:column;gap:.9rem}
  .msj{max-width:88%;padding:.7rem .95rem;border-radius:14px;white-space:normal;word-wrap:break-word}
  .msj.yo{align-self:flex-end;background:#0e3a5c;color:#e8f2ff;border-bottom-right-radius:4px}
  .msj.ia{align-self:flex-start;background:var(--card);border:1px solid var(--rule);border-bottom-left-radius:4px;color:var(--soft)}
  .msj.ia strong{color:var(--ink)}
  .msj p{margin:.2rem 0} .msj ul,.msj ol{margin:.3rem 0;padding-left:1.2rem}
  .msj pre{background:var(--code);border:1px solid var(--rule);border-radius:8px;padding:.6rem .75rem;overflow-x:auto;font-size:.84rem;color:#D8F5EA;white-space:pre-wrap}
  .msj code{font-size:.88em;background:var(--code);padding:.05rem .3rem;border-radius:4px;color:#D8F5EA}
  .msj pre code{background:none;padding:0}
  .msj.error{border-color:var(--bad);color:var(--bad)}
  .pensando::after{content:"Pensando…";color:var(--muted);font-style:italic;animation:late 1.2s ease-in-out infinite}
  @keyframes late{50%{opacity:.4}}
  .bienvenida{color:var(--soft);max-width:44rem}
  .bienvenida h1{font-size:clamp(1.6rem,4vw,2.4rem);line-height:1.08;margin:.3rem 0 .6rem;letter-spacing:-.02em;color:var(--ink)}
  .bienvenida h1 span{color:var(--accent)}
  .kicker{font:600 .75rem "JetBrains Mono",monospace;letter-spacing:.14em;text-transform:uppercase;color:var(--accent2)}
  .chips{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:1rem}
  .chips button{cursor:pointer;border:1px solid var(--rule);background:var(--card);color:var(--ink);border-radius:12px;padding:.5rem .8rem;font:500 .88rem Inter,sans-serif;text-align:left}
  .chips button:hover{border-color:var(--accent)}
  .entrada{border-top:1px solid var(--rule);padding:.8rem;display:flex;gap:.6rem;align-items:flex-end;background:var(--bg)}
  .entrada textarea{flex:1;resize:none;min-height:2.8rem;max-height:14rem;background:var(--code);border:1px solid var(--rule);border-radius:12px;color:var(--ink);padding:.7rem .85rem;font:15px/1.5 Inter,sans-serif}
  .entrada textarea:focus{outline:2px solid color-mix(in srgb,var(--accent) 60%,transparent);border-color:transparent}
  .enviar{border:0;border-radius:12px;background:var(--accent);color:#06231A;font:700 .9rem Inter,sans-serif;padding:.75rem 1.1rem;cursor:pointer}
  .enviar[disabled]{opacity:.5;cursor:default}
  .aviso{font-size:.8rem;color:var(--muted);padding:0 .9rem .7rem;background:var(--bg)}
  .aviso.mal{color:var(--bad)}
  .lado{display:flex;flex-direction:column;gap:.9rem}
  .caja{border:1px solid var(--rule);border-radius:14px;padding:.9rem 1rem;background:var(--bg2);font-size:.9rem;color:var(--soft)}
  .caja h3{margin:0 0 .45rem;font-size:.95rem;color:var(--ink)}
  .caja ul{margin:0;padding-left:1.1rem} .caja li{margin:.25rem 0}
  .caja.si{border-color:color-mix(in srgb,var(--accent) 50%,var(--rule))} .caja.si h3{color:var(--accent)}
  .caja.tu{border-color:color-mix(in srgb,var(--warn) 50%,var(--rule))} .caja.tu h3{color:var(--warn)}
  details summary{cursor:pointer;font-weight:600;color:var(--ink)}
  details pre{white-space:pre-wrap;font-size:.76rem;line-height:1.5;color:var(--soft);background:var(--code);border:1px solid var(--rule);border-radius:8px;padding:.6rem;max-height:22rem;overflow:auto}
  .pie{font-size:.78rem;color:var(--muted)}
  /* Entrar con el ID */
  .puerta{flex:1;display:grid;place-items:center;padding:2rem 1.2rem}
  .puerta .tarjeta{width:min(30rem,100%);border:1px solid var(--rule);border-radius:18px;background:var(--bg2);padding:1.6rem 1.6rem 1.4rem}
  .puerta h1{font-size:1.6rem;line-height:1.15;margin:.3rem 0 .6rem;letter-spacing:-.02em} .puerta h1 span{color:var(--accent)}
  .puerta p{color:var(--soft);margin:.5rem 0}
  .puerta form{display:flex;gap:.6rem;margin:1.1rem 0 .4rem;flex-wrap:wrap}
  .puerta input{flex:0 1 10rem;min-width:0;padding:.7rem .9rem;border-radius:12px;border:1px solid var(--rule);background:var(--code);color:var(--ink);
    font:700 1.3rem "JetBrains Mono",monospace;letter-spacing:.3em;text-transform:uppercase}
  .puerta input:focus{outline:2px solid var(--accent);outline-offset:1px}
  .puerta .error{color:var(--bad);font-size:.88rem}
  .puerta .reloj{display:flex;gap:.7rem;align-items:center;border:1px dashed var(--rule);border-radius:12px;padding:.6rem .8rem;margin-top:1rem;font-size:.88rem;color:var(--soft)}
  .puerta .reloj svg{flex:0 0 auto;width:1.4rem;height:1.4rem;fill:none;stroke:var(--accent);stroke-width:1.8;stroke-linecap:round}
  .apagado{border-color:color-mix(in srgb,var(--warn) 55%,var(--rule)) !important;color:var(--warn) !important}
  .quien{display:flex;align-items:center;gap:.7rem;font-size:.85rem;color:var(--soft);flex-wrap:wrap}
  .quien b{color:var(--ink)} .quien .id{font:700 .85rem "JetBrains Mono",monospace;letter-spacing:.12em;color:var(--accent)}
  .tiempo{display:inline-flex;align-items:center;gap:.35rem;border:1px solid var(--rule);border-radius:99px;padding:.25rem .7rem;font-size:.8rem}
  .tiempo.poco{border-color:var(--warn);color:var(--warn)} .tiempo.nada{border-color:var(--bad);color:var(--bad)}
  .salir{background:none;border:0;color:var(--muted);text-decoration:underline;cursor:pointer;font:inherit;font-size:.8rem;padding:0}
</style>
</head>
<body>

<div class="barra"><div class="in">
  <div class="marca">BOOTCAMP <b>AGENTIC</b> · asistente</div>
  @if ($participante)
  <div class="quien">
    <span>{{ $participante->nombre }} · <span class="id">{{ $participante->codigo }}</span></span>
    <span class="tiempo {{ $hoy['agotado'] ? 'nada' : ($hoy['empezo'] && $hoy['quedan'] <= 10 ? 'poco' : '') }}" id="tiempo"
      data-quedan="{{ $hoy['quedan'] }}" data-empezo="{{ $hoy['empezo'] ? 1 : 0 }}">
      {{ $hoy['agotado'] ? 'Se acabó tu hora de hoy' : ($hoy['empezo'] ? 'Te quedan ' . $hoy['quedan'] . ' min hoy' : 'Tienes ' . $minutos . ' min hoy') }}
    </span>
    <form method="post" action="{{ route('asistente.agentic.salir') }}">@csrf<button class="salir" type="submit">No soy yo</button></form>
  </div>
  @endif
  <div style="display:flex;gap:.6rem;align-items:center;flex-wrap:wrap">
    @if ($participante)<button type="button" class="boton" id="nueva">Nueva conversación</button>@endif
  </div>
</div></div>

@if (! $participante)
<div class="puerta">
  <div class="tarjeta">
    <div class="kicker">Asistente del bootcamp</div>
    <h1>Entra con tu <span>ID del bootcamp</span></h1>
    <p>Son 4 letras o números. Los recibiste al escribir tu nombre en la presentación del día 2 (también están en la barra de arriba de esa página).</p>
    @if (! $activo)
      <p class="reloj apagado">El asistente está apagado por ahora. Quien dicta el bootcamp lo vuelve a prender.</p>
    @endif
    <form method="post" action="{{ route('asistente.agentic.entrar') }}">
      @csrf
      <input name="codigo" maxlength="4" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="K7Q2" aria-label="Tu ID" value="{{ old('codigo') }}" autofocus required>
      <button class="enviar" type="submit">Entrar</button>
    </form>
    @if (session('error_id'))<p class="error">{{ session('error_id') }}</p>@endif
    <div class="reloj"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
      <span>Cada ID tiene <b>{{ $minutos }} minutos de trabajo al día</b> con el asistente. Empiezan a contar con tu primer mensaje.</span></div>
  </div>
</div>
@else
<div class="lienzo">
  <section class="chat" aria-label="Conversación">
    <div class="hilo" id="hilo" aria-live="polite">
      <div class="bienvenida" id="bienvenida">
        <div class="kicker">Trabajo autónomo</div>
        <h1>Un asistente que <span>te ayuda a pensar</span>, no que piensa por ti.</h1>
        <p>Cuéntale en qué vas con tu proyecto, pégale un error o el contenido de tu <span class="mono">PROYECTO.md</span>. Te va a preguntar, dar pistas y revisar lo que hiciste. El trabajo, y las decisiones, siguen siendo tuyos.</p>
        <div class="chips">
          <button type="button" data-pedido="Este es mi PROYECTO.md. ¿Qué le falta para que el objetivo esté claro?&#10;&#10;">Revisa mi PROYECTO.md</button>
          <button type="button" data-pedido="Me salió este error y no sé qué significa:&#10;&#10;">Tengo un error</button>
          <button type="button" data-pedido="Ayúdame a partir mi objetivo en pasos pequeños para pedírselos a la IA de mi editor. Mi objetivo es: ">Partir mi objetivo en pasos</button>
          <button type="button" data-pedido="Quiero pedirle esto a la IA de mi editor. ¿Cómo lo escribo mejor?&#10;&#10;">Mejorar un pedido</button>
          <button type="button" data-pedido="En mi proyecto, ¿qué parte debería hacer la IA y qué parte debería hacer yo? Mi proyecto es: ">¿Qué hace la IA y qué hago yo?</button>
        </div>
      </div>
    </div>
    <div class="entrada">
      <textarea id="texto" rows="1" placeholder="Escribe aquí… (Enter envía, Shift+Enter hace salto de línea)" aria-label="Tu mensaje"></textarea>
      <button type="button" class="enviar" id="enviar">Enviar</button>
    </div>
    <div class="aviso" id="aviso">No pegues tu llave de API ni contraseñas. La conversación no se guarda: se borra al cerrar o recargar la página.</div>
  </section>

  <aside class="lado">
    <div class="caja si">
      <h3>Lo que hace este asistente</h3>
      <ul>
        <li>Te pregunta para entender qué quieres.</li>
        <li>Te da pistas y el siguiente paso pequeño.</li>
        <li>Explica errores en palabras simples.</li>
        <li>Revisa lo que hiciste y mejora tus pedidos.</li>
      </ul>
    </div>
    <div class="caja tu">
      <h3>Lo que sigue siendo tuyo</h3>
      <ul>
        <li>Conocer el problema y a las personas.</li>
        <li>Decidir qué es correcto y qué no.</li>
        <li>Probar con datos reales.</li>
        <li>Responder por el resultado.</li>
      </ul>
      <p style="margin:.6rem 0 0">La IA se equivoca con seguridad y no sabe lo que no le cuentas. Por eso asiste, y no reemplaza.</p>
    </div>
    <div class="caja">
      <details>
        <summary>Ver las instrucciones que le dimos</summary>
        <p class="pie">Esto es lo que lee el modelo antes de cada conversación. Cambiar estas instrucciones cambia cómo se comporta: es lo mismo que harás con tus propios pedidos.</p>
        <pre>{{ $instrucciones }}</pre>
      </details>
    </div>
    <p class="pie">Corre en el servidor de IA del FabLab EAN (modelo <span class="mono">{{ $modelo }}</span>), dentro del laboratorio: no es un servicio de afuera. Bootcamp AGENTIC · eanX · Universidad Ean.</p>
  </aside>
</div>
@endif

@if ($participante)
<script>
(function () {
  var hilo = document.getElementById('hilo');
  var texto = document.getElementById('texto');
  var enviar = document.getElementById('enviar');
  var aviso = document.getElementById('aviso');
  var avisoBase = aviso.textContent;
  var token = document.querySelector('meta[name=csrf-token]').content;
  var mensajes = [];
  var ocupado = false;
  var tiempo = document.getElementById('tiempo');
  function pintarTiempo(quedan) {
    tiempo.textContent = quedan > 0 ? 'Te quedan ' + quedan + ' min hoy' : 'Se acabó tu hora de hoy';
    tiempo.className = 'tiempo' + (quedan <= 0 ? ' nada' : quedan <= 10 ? ' poco' : '');
  }
  function cerrar(msj) { texto.disabled = true; enviar.disabled = true; texto.placeholder = msj; }
  if (tiempo.classList.contains('nada')) { cerrar('Se acabó tu hora de hoy. Mañana vuelves a tener tu tiempo.'); }
  var LLAVE = /\b(sk-ant-[A-Za-z0-9_\-]{10,}|sk-(proj-)?[A-Za-z0-9_\-]{20,}|AIza[0-9A-Za-z_\-]{30,})\b/;


  // Un markdown pequeño: negritas, código, listas y párrafos. Todo escapado primero.
  function esc(s) { return s.replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
  function md(s) {
    var bloques = [];
    s = esc(s).replace(/```[\w-]*\n?([\s\S]*?)(```|$)/g, function (_, c) { bloques.push('<pre><code>' + c.replace(/\n$/, '') + '</code></pre>'); return '\u0000' + (bloques.length - 1) + '\u0000'; });
    s = s.replace(/`([^`\n]+)`/g, '<code>$1</code>').replace(/\*\*([^*\n]+)\*\*/g, '<strong>$1</strong>');
    var html = '', lista = null;
    s.split('\n').forEach(function (l) {
      var m = l.match(/^\s*(?:[-*•]|(\d+)[.)])\s+(.*)$/);
      if (m) {
        var tipo = m[1] ? 'ol' : 'ul';
        if (lista !== tipo) { if (lista) { html += '</' + lista + '>'; } html += '<' + tipo + '>'; lista = tipo; }
        html += '<li>' + m[2] + '</li>';
        return;
      }
      if (lista) { html += '</' + lista + '>'; lista = null; }
      if (l.trim() === '') { return; }
      html += /^\s*#{1,4}\s+/.test(l) ? '<p><strong>' + l.replace(/^\s*#{1,4}\s+/, '') + '</strong></p>' : '<p>' + l + '</p>';
    });
    if (lista) { html += '</' + lista + '>'; }
    return html.replace(/<p>\u0000(\d+)\u0000<\/p>|\u0000(\d+)\u0000/g, function (_, a, b) { return bloques[a || b]; });
  }

  function burbuja(clase, contenido) {
    var b = document.getElementById('bienvenida'); if (b) { b.remove(); }
    var d = document.createElement('div'); d.className = 'msj ' + clase;
    if (clase === 'yo') { d.textContent = contenido; d.style.whiteSpace = 'pre-wrap'; } else { d.innerHTML = contenido; }
    hilo.appendChild(d); hilo.scrollTop = hilo.scrollHeight; return d;
  }

  function ajustar() { texto.style.height = 'auto'; texto.style.height = Math.min(texto.scrollHeight, 224) + 'px'; }
  texto.addEventListener('input', function () {
    ajustar();
    var llave = LLAVE.test(texto.value);
    aviso.classList.toggle('mal', llave);
    aviso.textContent = llave ? 'Eso parece una llave de API: bórrala. Va solo en tu archivo .env; si ya la compartiste, revócala y crea otra.' : avisoBase;
  });
  texto.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); mandar(); } });
  enviar.addEventListener('click', mandar);
  document.querySelectorAll('[data-pedido]').forEach(function (b) {
    b.addEventListener('click', function () { texto.value = b.dataset.pedido; ajustar(); texto.focus(); texto.setSelectionRange(texto.value.length, texto.value.length); });
  });
  document.getElementById('nueva').addEventListener('click', function () { if (!ocupado) { location.reload(); } });

  function mandar() {
    var t = texto.value.trim();
    if (!t || ocupado) { return; }
    if (LLAVE.test(t)) { texto.focus(); return; }
    ocupado = true; enviar.disabled = true;
    burbuja('yo', t); mensajes.push({ role: 'user', content: t });
    texto.value = ''; ajustar();
    var resp = burbuja('ia pensando', ''), acumulado = '';
    fetch('{{ route('asistente.agentic.mensaje') }}', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json, text/event-stream','X-CSRF-TOKEN': token },
      body: JSON.stringify({ mensajes: mensajes })
    }).then(function (r) {
      if (r.status === 403) {
        return r.json().then(function (j) {
          if (j.agotado) { pintarTiempo(0); cerrar('Se acabó tu hora de hoy. Mañana vuelves a tener tu tiempo.'); }
          if (j.sin_id) { setTimeout(function () { location.reload(); }, 2500); }
          throw new Error(j.error || 'No puedes usar el asistente ahora.');
        });
      }
      if (r.status === 429) { throw new Error('Vas muy rápido: espera unos minutos y vuelve a intentar.'); }
      if (r.status === 422) { throw new Error('El mensaje es demasiado largo o la conversación creció mucho: empieza una nueva.'); }
      if (r.status === 419) { throw new Error('La página caducó. Recárgala y vuelve a escribir.'); }
      if (!r.ok || !r.body) { throw new Error('No se pudo hablar con el asistente (' + r.status + ').'); }
      var lector = r.body.getReader(), dec = new TextDecoder(), resto = '';
      function leer() {
        return lector.read().then(function (p) {
          if (p.done) { return; }
          resto += dec.decode(p.value, { stream: true });
          var partes = resto.split('\n\n'); resto = partes.pop();
          partes.forEach(function (ev) {
            var linea = ev.replace(/^data:\s*/, ''); if (!linea) { return; }
            var d; try { d = JSON.parse(linea); } catch (_) { return; }
            if (d.error) { throw new Error(d.error); }
            if (typeof d.quedan === 'number') { pintarTiempo(d.quedan); }
            if (d.texto) { resp.classList.remove('pensando'); acumulado += d.texto; resp.innerHTML = md(acumulado); hilo.scrollTop = hilo.scrollHeight; }
          });
          return leer();
        });
      }
      return leer();
    }).then(function () {
      if (!acumulado) { throw new Error('El asistente no respondió. Intenta de nuevo.'); }
      mensajes.push({ role: 'assistant', content: acumulado });
    }).catch(function (e) {
      resp.classList.remove('pensando'); resp.classList.add('error'); resp.textContent = e.message;
      mensajes.pop();
    }).then(function () { ocupado = false; if (!texto.disabled) { enviar.disabled = false; texto.focus(); } });
  }
})();
</script>
@endif
</body>
</html>
