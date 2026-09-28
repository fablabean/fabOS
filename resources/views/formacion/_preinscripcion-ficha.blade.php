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
                                para abrir la cohorte {{ $cohorte->code }}.
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

            @if ($abierta)
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

                <form method="POST" action="{{ route('preinscripcion.store', $curso) }}" class="ficha">
                    @csrf

                    <h2>Preinscríbete</h2>
                    <p class="help" style="color:var(--muted);font-size:.88rem;margin:0">
                        @if ($yaEstoy)
                            Ya estás en la lista. Si vuelves a enviar el formulario, corrige lo anterior en vez de duplicarlo.
                        @else
                            Dos minutos. Sin pago y sin compromiso.
                        @endif
                    </p>

                    {{-- Trampa para robots: nadie la ve, nadie debería llenarla. --}}
                    <div style="position:absolute;left:-9999px" aria-hidden="true">
                        <label>No llenar este campo
                            <input type="text" name="sitio_web" tabindex="-1" autocomplete="off">
                        </label>
                    </div>

                    <label>
                        Nombre completo
                        <input type="text" name="nombre" required maxlength="160" autocomplete="name"
                               value="{{ old('nombre', auth()->user()?->name) }}">
                    </label>

                    <label>
                        Correo
                        <input type="email" name="correo" required maxlength="160" autocomplete="email"
                               value="{{ old('correo', auth()->user()?->email) }}">
                        <span class="help">Aquí te avisamos si la cohorte se abre.</span>
                    </label>

                    {{-- Con indicativo: a un nodo único en el país le escribe gente
                         de fuera, y un número sin país no se puede marcar. --}}
                    @include('partials.telefono', ['valor' => auth()->user()?->phone])

                    <label>
                        Ciudad
                        <input type="text" name="ciudad" required maxlength="120"
                               value="{{ old('ciudad') }}" placeholder="Bogotá">
                        <span class="help">El trabajo de cada semana es presencial, en el laboratorio.</span>
                    </label>

                    <label>
                        A qué te dedicas
                        <input type="text" name="ocupacion" required maxlength="160"
                               value="{{ old('ocupacion') }}" placeholder="Diseñadora industrial, docente, ingeniero…">
                    </label>

                    <label>
                        Empresa o universidad
                        <input type="text" name="institucion" maxlength="160" value="{{ old('institucion') }}">
                        <span class="help">Si vienes de parte de una, o si sería la que lo paga.</span>
                    </label>

                    <label>
                        Por qué quieres hacerlo
                        <textarea name="motivacion" rows="4" required maxlength="2000">{{ old('motivacion') }}</textarea>
                        <span class="help">Qué quieres aprender o qué te gustaría construir. No hace falta que sea largo.</span>
                    </label>

                    <label>
                        Cómo piensas financiarlo
                        <select name="financiacion" required>
                            <option value="">Elige una</option>
                            @foreach (\App\Models\Preenrollment::FINANCIACION as $clave => $texto)
                                <option value="{{ $clave }}" @selected(old('financiacion') === $clave)>{{ $texto }}</option>
                            @endforeach
                        </select>
                        <span class="help">No te compromete a nada: nos dice si la cohorte es viable y qué apoyos buscar.</span>
                    </label>

                    <label>
                        Portafolio o LinkedIn
                        <input type="url" name="portafolio" maxlength="255" value="{{ old('portafolio') }}"
                               placeholder="https://">
                        <span class="help">Si tienes. No es requisito.</span>
                    </label>

                    {{-- Ley 1581 de 2012. Con el para qué escrito: una
                         autorización que no dice para qué no autoriza gran cosa. --}}
                    <label class="casilla">
                        <input type="checkbox" name="autoriza" value="1" required {{ old('autoriza') ? 'checked' : '' }}>
                        <span>
                            Autorizo a {{ config('fabos.lab.name') }} a guardar estos datos y a escribirme
                            sobre la apertura de esta cohorte. Puedo pedir que los borren cuando quiera.
                        </span>
                    </label>

                    <x-captcha accion="preinscripcion.store"/>

                    <button type="submit" class="btn">Preinscribirme</button>
                </form>
            @endif
