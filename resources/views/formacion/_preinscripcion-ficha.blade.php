{{-- El conteo y el formulario de preinscripción: lo comparten la página de
     cualquier curso por preinscripción y la de Fab Academy. --}}
<style>
    /* ---------- cuantos somos ---------- */
    .conteo{
        background:var(--surface);border:1px solid var(--rule);border-radius:8px;
        padding:1.2rem 1.4rem;margin-bottom:1.2rem;
    }
    .conteo .numero{font-size:2.4rem;font-weight:800;letter-spacing:-.04em;line-height:1}
    .conteo .numero small{font-size:1rem;font-weight:500;color:var(--muted);letter-spacing:0}
    .barra{height:.5rem;border-radius:999px;background:color-mix(in srgb,var(--ink) 10%,transparent);
           overflow:hidden;margin:.8rem 0 .6rem}
    .barra i{display:block;height:100%;background:var(--accent);border-radius:999px}
    .conteo p{margin:0;font-size:.9rem;color:var(--muted)}

    /* ---------- formulario ---------- */
    .ficha{background:var(--surface);border:1px solid var(--rule);border-radius:8px;padding:1.4rem}
    .ficha h2{margin-bottom:.2rem}
    .ficha label{display:block;font-size:.84rem;font-weight:600;margin-top:1rem}
    .ficha input:not([type=checkbox]),.ficha select,.ficha textarea{
        display:block;width:100%;margin-top:.35rem;padding:.6rem .7rem;font:inherit;
        background:var(--ground);color:var(--ink);border:1px solid var(--rule);border-radius:4px;
    }
    .ficha input:focus,.ficha select:focus,.ficha textarea:focus{outline:2px solid var(--accent);outline-offset:1px}
    .ficha .help{display:block;font-weight:400;color:var(--muted);font-size:.8rem;margin-top:.3rem}
    .ficha label.casilla{font-weight:400;display:flex;gap:.6rem;align-items:flex-start}
    .ficha label.casilla input{margin-top:.25rem;flex:none}
    .ficha button{margin-top:1.2rem;width:100%;padding:.8rem;font-size:1rem}
    .aviso{background:color-mix(in srgb,#0D6E63 12%,transparent);border-radius:6px;
           padding:.8rem 1rem;margin-bottom:1rem;font-size:.92rem}
    .error{background:color-mix(in srgb,#9B2C2C 12%,transparent);border-radius:6px;
           padding:.8rem 1rem;margin-bottom:1rem;font-size:.92rem}
    .error ul{margin:0;padding-left:1.1rem}
</style>

            @if ($cohorte)
                <div class="conteo">
                    <div class="numero">
                        {{ $somos }}
                        <small>
                            {{ $somos === 1 ? 'persona preinscrita' : 'personas preinscritas' }}
                            @if ($cohorte->minimum_to_open) de {{ $cohorte->minimum_to_open }} necesarias @endif
                        </small>
                    </div>

                    @if ($cohorte->minimum_to_open)
                        <div class="barra" role="img"
                             aria-label="{{ $somos }} de {{ $cohorte->minimum_to_open }}">
                            <i style="width:{{ min(100, (int) round($somos / $cohorte->minimum_to_open * 100)) }}%"></i>
                        </div>
                        <p>
                            @if ($faltan > 0)
                                {{ $faltan === 1 ? 'Falta una persona' : 'Faltan ' . $faltan . ' personas' }}
                                @if (! empty($registro))
                                    para habilitar el registro oficial a quienes se preinscriban desde ahora.
                                @else
                                    para abrir la cohorte {{ $cohorte->code }}.
                                @endif
                            @else
                                <strong>Ya alcanzamos el mínimo para abrir la cohorte.</strong> Aún puedes preinscribirte para asegurar tu lugar.
                            @endif
                        </p>
                    @endif

                    @if ($cohorte->preenroll_until)
                        <p style="margin-top:.4rem">Preinscripciones hasta el {{ $cohorte->preenroll_until->format('d/m/Y') }}.</p>
                    @endif
                </div>
            @endif

            @if (session('status'))
                <div class="aviso">{{ session('status') }}</div>
            @endif

            @if (! empty($registro))
                {{-- Abierta, con el registro oficial afuera: primero el correo. --}}
                @include('formacion._registro-externo')
            @elseif ($abierta)
                {{-- Ya abrió: lo que toca es inscribirse de verdad. --}}
                <div class="ficha">
                    <h2>La cohorte ya abrió</h2>
                    <p>
                        {{ $abierta->code }} está recibiendo inscripciones
                        @if ($abierta->starts_on) y empieza el {{ $abierta->starts_on->format('d/m/Y') }}@endif.
                        Quedan {{ $abierta->cuposLibres() }} de {{ $abierta->capacity }} cupos.
                    </p>
                    <a class="btn" href="{{ route('formacion') }}">Ir a inscribirme</a>
                </div>
            @elseif (! $cohorte)
                <div class="ficha">
                    <h2>Todavía no hay cohorte anunciada</h2>
                    <p style="margin-bottom:0">
                        Cuando planeemos la próxima, aquí mismo se abre la preinscripción.
                        Vuelve pronto, o pregunta por ella en el laboratorio.
                    </p>
                </div>
            @elseif (! $cohorte->admitePreinscripciones())
                <div class="ficha">
                    <h2>Preinscripción cerrada</h2>
                    <p style="margin-bottom:0">{{ $cohorte->porQueNoAdmitePreinscripciones() }}</p>
                </div>
            @else
                @if ($errors->any())
                    <div class="error">
                        <ul>
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @include('formacion._preinscripcion-formulario')
            @endif
