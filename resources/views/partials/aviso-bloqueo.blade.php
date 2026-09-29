{{-- El laboratorio cerrado a las reservas (App\Support\BloqueoDeReservas).

     Dos piezas: una franja fija arriba, en todas las páginas, y una ventana
     que sale la primera vez que alguien entra —y otra vez si el bloqueo
     cambia—. La ventana se cierra y no persigue a nadie de página en página;
     la franja sí se queda, porque es lo que se ve justo al ir a reservar. --}}
@if (\App\Support\BloqueoDeReservas::activo())
    @php
        $bq = \App\Support\BloqueoDeReservas::class;
        $cuando = $bq::hastaLegible();
    @endphp
    <style>
        .bq-franja{background:#B91C1C;color:#fff;padding:.55rem 1rem;text-align:center;font-size:.9rem;
                   font-family:system-ui,"Segoe UI",Arial,sans-serif;line-height:1.4;position:relative;z-index:30}
        .bq-franja button{background:none;border:0;color:#fff;text-decoration:underline;cursor:pointer;font:inherit;padding:0 0 0 .4rem}
        .bq-modal{border:0;border-radius:16px;padding:0;max-width:min(30rem,calc(100vw - 2rem));width:100%;
                  box-shadow:0 30px 70px -20px rgba(0,0,0,.55);font-family:system-ui,"Segoe UI",Arial,sans-serif;
                  background:#fff;color:#191A16}
        /* Blindado contra los estilos de la página de debajo: sin
           box-sizing el botón medía más que la ventana y salía una barra de
           desplazamiento, y el h2 heredaba la letra gris de los títulos. */
        .bq-modal,.bq-modal *{box-sizing:border-box}
        .bq-modal{overflow:hidden;max-height:calc(100vh - 2rem)}
        .bq-modal form{margin:0;padding:0}
        .bq-modal h2#bq-titulo{color:#fff;font-family:system-ui,"Segoe UI",Arial,sans-serif;font-weight:700;
                               text-transform:none;letter-spacing:-.01em;font-size:1.3rem;margin:0}
        .bq-modal::backdrop{background:rgba(15,15,15,.6);backdrop-filter:blur(3px)}
        .bq-modal .bq-cabeza{background:#B91C1C;color:#fff;padding:1.4rem 1.5rem 1.2rem;text-align:center}
        .bq-modal .bq-icono{font-size:2.6rem;line-height:1;display:block;margin-bottom:.4rem;animation:bq-latido 1.4s ease-in-out infinite}
        .bq-modal h2{margin:0;font-size:1.3rem;letter-spacing:-.01em}
        .bq-modal .bq-cuerpo{padding:1.3rem 1.5rem 1.5rem}
        .bq-modal .bq-motivo{font-size:1.05rem;margin:0 0 .8rem;white-space:pre-line}
        .bq-modal .bq-cuando{font-size:.92rem;color:#555;margin:0 0 1.2rem}
        .bq-modal .bq-ok{display:block;width:100%;background:#191A16;color:#fff;border:0;border-radius:8px;
                         padding:.75rem;font:inherit;font-weight:600;cursor:pointer}
        @keyframes bq-latido{0%,100%{transform:scale(1)}50%{transform:scale(1.12)}}
        @media (prefers-reduced-motion:reduce){.bq-modal .bq-icono{animation:none}}
    </style>

    <div class="bq-franja" role="alert">
        <strong>Reservas bloqueadas:</strong> {{ $bq::motivo() }}
        @if ($cuando) · Se reabren el {{ $cuando }}. @endif
        <button type="button" onclick="document.getElementById('bq-modal').showModal()">Ver aviso</button>
    </div>

    <dialog class="bq-modal" id="bq-modal" aria-labelledby="bq-titulo">
        <div class="bq-cabeza">
            <span class="bq-icono" aria-hidden="true">⚠️</span>
            <h2 id="bq-titulo">Reservas bloqueadas</h2>
        </div>
        <div class="bq-cuerpo">
            <p class="bq-motivo">{{ $bq::motivo() }}</p>
            <p class="bq-cuando">
                Mientras tanto nadie puede reservar ni usar equipos, espacios, herramientas ni asesorías.
                {{ $cuando ? 'Se reabren el ' . $cuando . ': desde ya puedes reservar a partir de esa hora.' : 'Avisaremos cuando se reabran.' }}
            </p>
            <form method="dialog"><button class="bq-ok" autofocus>Entendido</button></form>
        </div>
    </dialog>

    <script>
    (function () {
        var clave = 'fabos-bloqueo-visto';
        var version = @json($bq::version());
        var visto = null;
        try { visto = sessionStorage.getItem(clave); } catch (e) {}
        var modal = document.getElementById('bq-modal');
        if (visto !== version && modal && modal.showModal) {
            modal.showModal();
            modal.addEventListener('close', function () {
                try { sessionStorage.setItem(clave, version); } catch (e) {}
            });
        }
    })();
    </script>
@endif
