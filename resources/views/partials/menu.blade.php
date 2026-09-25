{{--
    El menú, uno solo para las dos plantillas (§19).

    Había dos listas: la pública traía «Preguntas» y la de dentro «Proyectos»,
    «Tienda» y «Aportes». Cambiaban al navegar sin que nadie lo hubiera decidido
    —entrabas a una reserva y desaparecían secciones—, y eso hace dudar de si
    te falta un permiso o te equivocaste de sitio.

    Aquí está la lista una vez. Lo que depende de quién mira son unos pocos
    enlaces marcados, no el menú entero.

    En el teléfono se pliega tras un botón: ocho enlaces en una fila obligan al
    navegador a apretarlos hasta que no se pueden pulsar sin acertar, o a
    empujar el logo fuera de la pantalla.
--}}
<button type="button" class="menu-boton" aria-expanded="false" aria-controls="menu-enlaces">
    <span class="rayas" aria-hidden="true"></span>
    Menú
</button>

<div class="menu-enlaces" id="menu-enlaces">
    <a href="{{ route('publico.reservas') }}">Reservas</a>
    <a href="{{ route('formacion') }}">Formación</a>
    <a href="{{ route('proyectos.solicitar') }}">Proyectos</a>
    <a href="{{ route('alianzas.index') }}">Alianzas</a>
    <a href="{{ route('tienda.publica') }}">Tienda</a>
    <a href="{{ route('preguntas.index') }}">Preguntas</a>

    {{-- Claro, oscuro o el del sistema, como en el panel. Fuera de @auth a
         propósito: quien llega sin cuenta es justo quien más lo necesita —el
         panel no lo verá nunca— y leer una portada en el modo equivocado no
         depende de tener usuario. --}}
    <div class="tema" role="group" aria-label="Tema del sitio">
        <button type="button" data-tema="light" title="Claro" aria-label="Tema claro">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                <circle cx="12" cy="12" r="4"/>
                <path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M19.1 4.9l-1.4 1.4M6.3 17.7l-1.4 1.4"/>
            </svg>
        </button>
        <button type="button" data-tema="dark" title="Oscuro" aria-label="Tema oscuro">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>
            </svg>
        </button>
        <button type="button" data-tema="system" title="El del sistema" aria-label="El tema del sistema">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>
            </svg>
        </button>
    </div>

    @auth
        {{-- Aportar exige cuenta: lo que se sube queda atribuido a quien lo
             subió, que es lo que permite reconocérselo después. --}}
        <a href="{{ route('contenido.index') }}">Aportes</a>

        {{-- La cuenta, compacta: un círculo con la foto o las iniciales que
             despliega lo suyo. «Mi cuenta», el correo y «Salir» ocupaban
             media barra. --}}
        {{-- El saldo, al lado del círculo: lo que más se pregunta. Al
             pulsarlo se abre el detalle con los últimos movimientos. --}}
        @php
            $libro = app(\App\Services\Ledger\LedgerService::class);
            $saldoMenor = $libro->saldoDe(auth()->user());
            $unidadesFbc = config('fabos.currency.minor_units');
            $ultimosMovimientos = $libro->cuentaDe(auth()->user())->entries()->with('transaction')->latest('id')->limit(5)->get();
            $tzFbc = config('fabos.lab.timezone');
        @endphp
        <div class="saldo">
            <button type="button" class="saldo-boton" aria-haspopup="true" aria-expanded="false" aria-controls="menu-saldo"
                    title="Mi saldo en {{ config('fabos.currency.name') }}s">
                <strong>{{ number_format($saldoMenor / $unidadesFbc, 2, ',', '.') }}</strong>
                <span>{{ config('fabos.currency.code') }}</span>
            </button>
            <div class="menu-saldo" id="menu-saldo" hidden>
                <div class="cifra">
                    <strong>{{ number_format($saldoMenor / $unidadesFbc, 2, ',', '.') }}</strong>
                    <span>{{ config('fabos.currency.code') }}</span>
                </div>
                @if ($ultimosMovimientos->isEmpty())
                    <p class="vacio">Todavía no hay movimientos.</p>
                @else
                    <ul>
                        @foreach ($ultimosMovimientos as $m)
                            <li>
                                <span class="que">
                                    {{ \App\Models\LedgerTransaction::TIPOS[$m->transaction?->kind] ?? $m->transaction?->kind }}
                                    <small>{{ $m->transaction?->occurred_at?->timezone($tzFbc)->format('d/m/Y') }}</small>
                                </span>
                                <span class="cuanto {{ $m->direction === 'C' ? 'mas' : 'menos' }}">
                                    {{ $m->direction === 'C' ? '+' : '−' }}{{ number_format($m->amount_minor / $unidadesFbc, 2, ',', '.') }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <p class="nota">Próximamente podrás adquirir {{ config('fabos.currency.name') }}s.</p>
            </div>
        </div>

        <div class="usuario">
            <button type="button" class="avatar-boton" aria-haspopup="true" aria-expanded="false"
                    aria-controls="menu-usuario" title="{{ auth()->user()->name }}">
                <x-avatar :usuario="auth()->user()"/>
                <span class="nombre-corto">{{ auth()->user()->name }}</span>
            </button>

            <div class="menu-usuario" id="menu-usuario" hidden>
                <div class="quien">
                    <strong>{{ auth()->user()->name }}</strong>
                    <span>{{ auth()->user()->email }}</span>
                </div>
                <a href="{{ route('home') }}">Mi cuenta</a>
                <a href="{{ route('cuenta.perfil') }}">Editar perfil</a>
                @if (auth()->user()->hasAnyRole(\App\Models\User::rolesDelEquipo()))
                    <a href="/admin">Backoffice</a>
                @endif
                <form method="POST" action="{{ route('logout') }}" class="salir">
                    @csrf
                    <button type="submit">Salir</button>
                </form>
            </div>
        </div>
    @else
        <a class="btn" href="{{ route('login') }}">Ingresar</a>
    @endauth
</div>

<style>
    .menu-enlaces{display:flex;gap:1rem;align-items:center}
    .menu-boton{display:none}
    .menu-enlaces .salir{display:inline;margin:0}
    .menu-enlaces .salir button{margin:0;padding:.3rem .7rem;font-size:.8rem}

    /* El círculo de la persona y su menú. */
    .avatar{
        display:inline-flex;align-items:center;justify-content:center;border-radius:50%;
        background:var(--accent);color:#fff;font-weight:700;font-size:.8rem;letter-spacing:.02em;
        object-fit:cover;flex:none;line-height:1;
    }
    .usuario{position:relative;margin-left:.4rem}
    .avatar-boton{
        display:inline-flex;align-items:center;gap:.5rem;background:none;border:0;padding:.15rem;margin:0;
        cursor:pointer;border-radius:999px;color:inherit;font:inherit;
    }
    .avatar-boton:hover .avatar,.avatar-boton[aria-expanded="true"] .avatar{box-shadow:0 0 0 3px color-mix(in srgb,var(--accent) 35%,transparent)}
    .avatar-boton .nombre-corto{display:none}
    .menu-usuario{
        position:absolute;right:0;top:calc(100% + .5rem);min-width:16rem;z-index:30;
        display:flex;flex-direction:column;background:var(--surface);border:1px solid var(--rule);
        border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,.14);padding:.35rem 0;
    }
    .menu-usuario[hidden]{display:none}
    .menu-usuario .quien{display:flex;flex-direction:column;gap:.1rem;padding:.6rem 1rem .6rem;border-bottom:1px solid var(--rule);font-size:.85rem}
    .menu-usuario .quien span{color:var(--muted);font-family:ui-monospace,Consolas,monospace;font-size:.75rem;word-break:break-all}
    .menu-usuario a,.menu-usuario .salir button{
        display:block;width:100%;text-align:left;padding:.6rem 1rem;margin:0;background:none;border:0;
        font:inherit;font-size:.9rem;color:var(--ink);cursor:pointer;border-radius:0;
    }
    .menu-usuario a:hover,.menu-usuario .salir button:hover{background:color-mix(in srgb,var(--accent) 10%,transparent);color:var(--ink)}
    .menu-usuario .salir{display:block;border-top:1px solid var(--rule);margin-top:.25rem;padding-top:.25rem}

    /* El saldo y su detalle. */
    /* El selector de tema: tres botones pegados, como en el panel. */
    .tema{display:inline-flex;gap:.1rem;padding:.15rem;border:1px solid var(--rule);border-radius:999px}
    .tema button{
        display:inline-flex;align-items:center;justify-content:center;margin:0;
        width:1.75rem;height:1.75rem;padding:0;border:0;border-radius:999px;
        background:transparent;color:var(--muted);cursor:pointer;
    }
    .tema button svg{width:.95rem;height:.95rem;display:block}
    .tema button:hover{color:var(--ink)}
    /* El elegido, marcado. Lo pone el guion al arrancar: sin él los tres se
       ven igual y no hay forma de saber en cuál está. */
    .tema button[aria-pressed="true"]{
        background:color-mix(in srgb,var(--accent) 16%,transparent);color:var(--accent);
    }

    .saldo{position:relative}
    .saldo-boton{
        display:inline-flex;align-items:baseline;gap:.3rem;background:color-mix(in srgb,var(--accent) 12%,transparent);
        border:1px solid color-mix(in srgb,var(--accent) 35%,transparent);border-radius:999px;padding:.3rem .75rem;margin:0;
        font:inherit;font-size:.82rem;color:var(--ink);cursor:pointer;white-space:nowrap;
    }
    .saldo-boton span{font-family:ui-monospace,Consolas,monospace;font-size:.66rem;letter-spacing:.08em;color:var(--muted)}
    .saldo-boton:hover,.saldo-boton[aria-expanded="true"]{background:color-mix(in srgb,var(--accent) 22%,transparent)}
    .menu-saldo{
        position:absolute;right:0;top:calc(100% + .5rem);min-width:18rem;z-index:30;
        background:var(--surface);border:1px solid var(--rule);border-radius:8px;box-shadow:0 10px 30px rgba(0,0,0,.14);
        padding:.8rem 1rem .6rem;display:flex;flex-direction:column;gap:.5rem;
    }
    .menu-saldo[hidden]{display:none}
    .menu-saldo .cifra strong{font-size:1.6rem;letter-spacing:-.02em}
    .menu-saldo .cifra span{font-size:.8rem;color:var(--muted);margin-left:.3rem}
    .menu-saldo ul{list-style:none;margin:0;padding:0;display:flex;flex-direction:column}
    .menu-saldo li{display:flex;justify-content:space-between;gap:.8rem;padding:.4rem 0;border-top:1px solid var(--rule);font-size:.85rem}
    .menu-saldo li .que{display:flex;flex-direction:column}
    .menu-saldo li small{color:var(--muted);font-size:.72rem}
    .menu-saldo li .cuanto{font-variant-numeric:tabular-nums;white-space:nowrap}
    .menu-saldo li .cuanto.mas{color:var(--accent)}
    .menu-saldo .vacio{margin:0;color:var(--muted);font-size:.85rem}
    .menu-saldo .nota{margin:0;padding-top:.5rem;border-top:1px solid var(--rule);color:var(--muted);font-size:.78rem}

    /* Bajo esta anchura no caben ocho enlaces en una fila: el navegador los
       aprieta hasta que no se pueden pulsar sin acertar. */
    @media (max-width:52rem){
        .menu-boton{
            display:inline-flex;align-items:center;gap:.5rem;margin:0 0 0 auto;
            padding:.45rem .8rem;font-size:.85rem;background:transparent;
            color:var(--ink-soft);border:1px solid var(--rule);border-radius:6px;
        }
        .menu-boton .rayas,
        .menu-boton .rayas::before,
        .menu-boton .rayas::after{
            display:block;width:1rem;height:2px;background:currentColor;content:"";
        }
        .menu-boton .rayas{position:relative}
        .menu-boton .rayas::before{position:absolute;top:-5px}
        .menu-boton .rayas::after{position:absolute;top:5px}

        /* Cerrado no está «escondido con CSS»: está fuera del orden de
           tabulación, para que no se navegue con el teclado a enlaces que no
           se ven. */
        .menu-enlaces[hidden]{display:none}
        .menu-enlaces{
            flex-direction:column;align-items:stretch;gap:0;
            position:absolute;left:0;right:0;top:100%;z-index:20;
            background:var(--surface);border-bottom:1px solid var(--rule);
            padding:.4rem 1.2rem 1rem;
        }
        .menu-enlaces > *{padding:.7rem 0;border-bottom:1px solid var(--rule)}
        .menu-enlaces > *:last-child{border-bottom:none}
        .menu-enlaces .btn{text-align:center;margin-top:.6rem;padding:.7rem}

        /* En el teléfono no hay desplegable: el bloque de la persona va
           abierto dentro del menú, con su nombre al lado del círculo. El
           saldo se queda como una línea más, y su detalle abre debajo. */
        .saldo-boton{margin:.3rem 0}
        .menu-saldo{position:static;min-width:0;box-shadow:none;margin-top:.5rem}
        .usuario{margin-left:0}
        .avatar-boton{pointer-events:none;padding:0}
        .avatar-boton .nombre-corto{display:inline;font-weight:600}
        .menu-usuario,.menu-usuario[hidden]{
            display:flex;position:static;min-width:0;border:0;box-shadow:none;padding:.2rem 0 0;
        }
        .menu-usuario .quien{display:none}
        .menu-usuario a,.menu-usuario .salir button{padding:.6rem 0}
        .menu-usuario .salir{border-top:0;margin-top:0;padding-top:0}
    }
</style>

<script>
    (function () {
        var boton = document.querySelector('.menu-boton');
        var enlaces = document.getElementById('menu-enlaces');

        if (!boton || !enlaces) return;

        // El estado inicial lo pone el javascript y no el HTML: si no cargara,
        // el menú se quedaría cerrado para siempre y sin forma de abrirlo.
        function estrecho() {
            return window.matchMedia('(max-width:52rem)').matches;
        }

        function pintar(abierto) {
            enlaces.hidden = estrecho() && !abierto;
            boton.setAttribute('aria-expanded', abierto ? 'true' : 'false');
        }

        pintar(false);

        boton.addEventListener('click', function () {
            pintar(boton.getAttribute('aria-expanded') !== 'true');
        });

        // Al girar el teléfono o ensanchar la ventana, el menú vuelve a caber:
        // dejarlo oculto ahí seria esconderlo sin botón que lo devuelva.
        window.addEventListener('resize', function () {
            pintar(!estrecho() ? true : boton.getAttribute('aria-expanded') === 'true');
        });
    })();

    // Los desplegables de la barra —la persona y el saldo—: se abren con su
    // botón, se cierran al pulsar fuera o con Escape, y abrir uno cierra el
    // otro. En el teléfono el de la persona va abierto dentro del menú.
    (function () {
        var pares = [
            ['.avatar-boton', 'menu-usuario'],
            ['.saldo-boton', 'menu-saldo'],
        ].map(function (p) {
            return { boton: document.querySelector(p[0]), menu: document.getElementById(p[1]) };
        }).filter(function (p) { return p.boton && p.menu; });

        if (!pares.length) return;

        function abrir(par, si) {
            par.menu.hidden = !si;
            par.boton.setAttribute('aria-expanded', si ? 'true' : 'false');
        }

        pares.forEach(function (par) {
            par.boton.addEventListener('click', function (e) {
                e.stopPropagation();
                var estabaCerrado = par.menu.hidden;
                pares.forEach(function (otro) { abrir(otro, false); });
                abrir(par, estabaCerrado);
            });
        });

        document.addEventListener('click', function (e) {
            pares.forEach(function (par) {
                if (!par.menu.hidden && !par.menu.contains(e.target)) abrir(par, false);
            });
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            pares.forEach(function (par) {
                if (!par.menu.hidden) { abrir(par, false); par.boton.focus(); }
            });
        });
    })();

    /*
     * El tema: claro, oscuro o el del sistema.
     *
     * Lo elegido se guarda en el navegador y no en la cuenta, a propósito:
     * quien llega sin cuenta también elige, y la elección es de este aparato
     * —el portátil del laboratorio a pleno sol y el teléfono de noche no
     * quieren lo mismo—, no de la persona.
     *
     * Aplicarlo lo hace el guion del <head>, que corre antes de pintar. Aquí
     * sólo se guarda, se marca cuál está puesto y se aplica al vuelo cuando
     * se pulsa, para no tener que recargar.
     */
    (function () {
        var CLAVE = 'fabos-tema';
        var grupo = document.querySelector('.tema');

        if (!grupo) return;

        function guardado() {
            try { return localStorage.getItem(CLAVE) || 'system'; } catch (e) { return 'system'; }
        }

        function poner(cual) {
            if (cual === 'system') {
                delete document.documentElement.dataset.theme;
            } else {
                document.documentElement.dataset.theme = cual;
            }

            grupo.querySelectorAll('button').forEach(function (b) {
                b.setAttribute('aria-pressed', b.dataset.tema === cual ? 'true' : 'false');
            });
        }

        poner(guardado());

        grupo.addEventListener('click', function (e) {
            var boton = e.target.closest('button[data-tema]');

            if (!boton) return;

            try { localStorage.setItem(CLAVE, boton.dataset.tema); } catch (err) {}

            poner(boton.dataset.tema);
        });
    })();
</script>
