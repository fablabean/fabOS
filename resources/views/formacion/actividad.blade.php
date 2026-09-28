@extends('layouts.publico')
@section('title', $edicion->nombre() . ' · ' . config('fabos.lab.name'))
@section('description', $curso->summary ?: $curso->tipoLegible() . ' en ' . config('fabos.lab.name') . '.')

@php
    $publico = \Illuminate\Support\Facades\Storage::disk('public');
    $texto = fn (?string $t) => $t ? nl2br(e(trim($t))) : null;
    $lleno = $libres <= 0;

    // Un video de YouTube o Vimeo se ve en la página; cualquier otro, enlazado.
    $incrustar = function (?string $url): ?string {
        if (! $url) {
            return null;
        }
        if (preg_match('~(?:youtube\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([\w-]{11})~', $url, $m)) {
            return 'https://www.youtube-nocookie.com/embed/' . $m[1];
        }
        if (preg_match('~vimeo\.com/(\d+)~', $url, $m)) {
            return 'https://player.vimeo.com/video/' . $m[1];
        }

        return null;
    };
@endphp

@section('styles')
    .portada{background:var(--banner);color:var(--banner-ink);padding:3.4rem 1.4rem 3rem;position:relative;overflow:hidden}
    .portada .in{max-width:70rem;margin:0 auto;position:relative}
    .portada .rotulo{color:var(--banner-muted)}
    .portada h1{font-size:clamp(2rem,5.5vw,3.4rem);margin:.2rem 0 1rem;line-height:1.1}
    .portada p.lead{color:var(--banner-muted);max-width:56ch;font-size:1.1rem}
    .portada.con-imagen::before{content:"";position:absolute;inset:0;background:var(--fondo) center/cover no-repeat;opacity:.28}
    .etiquetas{display:flex;flex-wrap:wrap;gap:.4rem;margin-bottom:.6rem}
    .etiqueta{border:1px solid var(--banner-accent);color:var(--banner-accent);border-radius:999px;
              padding:.15rem .7rem;font-size:.75rem;font-weight:600;letter-spacing:.04em}
    .datos{display:flex;flex-wrap:wrap;gap:1.8rem;margin:1.6rem 0 0;padding:0}
    .datos div{min-width:8rem}
    .datos dt{font-family:ui-monospace,Consolas,monospace;font-size:.64rem;letter-spacing:.16em;
              text-transform:uppercase;color:var(--banner-muted)}
    .datos dd{margin:.2rem 0 0;font-size:1rem;font-weight:600}

    .dos{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:3rem;align-items:start}
    @media (max-width:860px){.dos{grid-template-columns:1fr;gap:1.6rem}}
    .bloque{margin-bottom:2rem}
    .bloque h2{font-size:1.25rem;margin:0 0 .5rem}
    .banner{width:100%;border-radius:8px;display:block;margin-bottom:1.6rem;max-height:26rem;object-fit:cover}
    .galeria{display:grid;grid-template-columns:repeat(auto-fill,minmax(12rem,1fr));gap:.8rem}
    .galeria figure{margin:0}
    .galeria img{width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:6px;display:block}
    .galeria figcaption{font-size:.82rem;color:var(--muted);margin-top:.3rem}
    .video{position:relative;padding-top:56.25%;border-radius:6px;overflow:hidden;margin-bottom:.8rem}
    .video iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
    .archivos{list-style:none;padding:0;margin:0}
    .archivos li{padding:.4rem 0;border-bottom:1px solid var(--rule)}

    .conteo{background:var(--surface);border:1px solid var(--rule);border-radius:8px;padding:1.1rem 1.3rem;margin-bottom:1.2rem}
    .conteo .numero{font-size:2.2rem;font-weight:800;letter-spacing:-.04em;line-height:1}
    .conteo .numero small{font-size:1rem;font-weight:500;color:var(--muted);letter-spacing:0}
    .barra{height:.5rem;border-radius:999px;background:color-mix(in srgb,var(--ink) 10%,transparent);overflow:hidden;margin:.7rem 0 .5rem}
    .barra i{display:block;height:100%;background:var(--accent);border-radius:999px}
    .conteo p{margin:0;font-size:.9rem;color:var(--muted)}

    .ficha{background:var(--surface);border:1px solid var(--rule);border-radius:8px;padding:1.4rem}
    .ficha h2{margin:0 0 .2rem}
    .ficha label,.ficha fieldset{display:block;font-size:.86rem;font-weight:600;margin-top:1rem}
    .ficha fieldset{border:0;padding:0}
    .ficha legend{padding:0;font-weight:600}
    .ficha input:not([type=checkbox]):not([type=radio]),.ficha select,.ficha textarea{
        display:block;width:100%;margin-top:.35rem;padding:.6rem .7rem;font:inherit;
        background:var(--ground);color:var(--ink);border:1px solid var(--rule);border-radius:4px;box-sizing:border-box}
    .ficha input:focus,.ficha select:focus,.ficha textarea:focus{outline:2px solid var(--accent);outline-offset:1px}
    .ficha .help{display:block;font-weight:400;color:var(--muted);font-size:.8rem;margin-top:.3rem}
    .ficha .opcion,.ficha label.casilla{font-weight:400;display:flex;gap:.6rem;align-items:flex-start;margin-top:.4rem}
    .ficha .opcion input,.ficha label.casilla input{margin-top:.3rem;flex:none}
    .ficha .obligatoria::after{content:" *";color:#9B2C2C}
    .ficha button{margin-top:1.2rem;width:100%;padding:.8rem;font-size:1rem}
    .aviso{background:color-mix(in srgb,#0D6E63 12%,transparent);border-radius:6px;padding:.8rem 1rem;margin-bottom:1rem;font-size:.92rem}
    .alerta{background:color-mix(in srgb,#B7791F 16%,transparent);border-radius:6px;padding:.8rem 1rem;margin-bottom:1rem;font-size:.92rem}
    .error{background:color-mix(in srgb,#9B2C2C 12%,transparent);border-radius:6px;padding:.8rem 1rem;margin-bottom:1rem;font-size:.92rem}
    .error ul{margin:0;padding-left:1.1rem}
    .previa{background:#B7791F;color:#fff;text-align:center;padding:.5rem 1rem;font-size:.9rem;font-weight:600}
@endsection

@section('content')
@if ($vistaPrevia)
    <div class="previa">Vista previa: así se verá cuando la publiques. Todavía no la ve nadie más.</div>
@endif

<header class="portada {{ $curso->banner_path ? 'con-imagen' : '' }}"
        @if ($curso->banner_path) style="--fondo:url('{{ $publico->url($curso->banner_path) }}')" @endif>
    <div class="in">
        <div class="etiquetas">
            <span class="etiqueta">{{ $curso->tipoLegible() }}</span>
            <span class="etiqueta">nivel {{ $curso->level }}</span>
            <span class="etiqueta">{{ $edicion->precioLegible() ? 'Con costo' : 'Gratuito' }}</span>
        </div>

        <h1>{{ $curso->name }}@if ($edicion->title)<br><small style="font-size:.55em;color:var(--banner-muted)">{{ $edicion->title }}</small>@endif</h1>

        @if ($curso->summary)
            <p class="lead">{{ $curso->summary }}</p>
        @endif

        <dl class="datos">
            @if ($edicion->fechas())
                <div><dt>Fecha</dt><dd>{{ $edicion->fechas() }}</dd></div>
            @endif
            @if ($edicion->horario())
                <div><dt>Horario</dt><dd>{{ $edicion->horario() }}</dd></div>
            @endif
            @if ($curso->hours)
                <div><dt>Duración</dt><dd>{{ $curso->hours }} {{ $curso->hours == 1 ? 'hora' : 'horas' }}</dd></div>
            @endif
            <div><dt>Dónde</dt><dd>{{ $edicion->lugar() ?? config('fabos.lab.name') }}</dd></div>
            <div><dt>Para</dt><dd>{{ \App\Models\CourseEdition::PUBLICOS[$edicion->audience ?? 'ambos'] }}</dd></div>
            <div><dt>Valor</dt><dd>{{ $edicion->precioLegible() ?? 'Gratuito' }}</dd></div>
        </dl>

        @if ($edicion->recibeInscripciones())
            <p style="margin:1.8rem 0 0">
                <a class="btn claro" href="#inscripcion">{{ $lleno ? 'Anotarme en la lista de espera' : 'Inscribirme' }}</a>
            </p>
        @endif
    </div>
</header>

<main>
    <section class="dos">
        <div>
            @if ($edicion->status === 'cancelada')
                <div class="error"><strong>Esta actividad se canceló.</strong>
                    @if ($ultimo = $edicion->changes()->where('kind', 'cancelada')->first())
                        {{ $ultimo->reason }}
                    @endif
                </div>
            @endif

            @if ($curso->banner_path)
                <img class="banner" src="{{ $publico->url($curso->banner_path) }}" alt="{{ $curso->name }}">
            @endif

            @if ($curso->description)
                <div class="bloque"><h2>De qué se trata</h2><p>{!! $texto($curso->description) !!}</p></div>
            @endif

            @if ($curso->objectives)
                <div class="bloque"><h2>Objetivos</h2><p>{!! $texto($curso->objectives) !!}</p></div>
            @endif

            @if ($curso->requirements)
                <div class="bloque"><h2>Requisitos</h2><p>{!! $texto($curso->requirements) !!}</p></div>
            @endif

            @if ($curso->recommendations)
                <div class="bloque"><h2>Recomendaciones</h2><p>{!! $texto($curso->recommendations) !!}</p></div>
            @endif

            <div class="bloque">
                <h2>Materiales</h2>
                @if ($curso->includes_materials)
                    <p><strong>Incluye materiales.</strong>@if ($curso->materials_included) {!! $texto($curso->materials_included) !!}@endif</p>
                @else
                    <p>No incluye materiales.</p>
                @endif
                @if ($curso->materials_to_bring)
                    <p><strong>Qué debes llevar:</strong> {!! $texto($curso->materials_to_bring) !!}</p>
                @endif
            </div>

            @if ($edicion->is_paid && $edicion->payment_info)
                <div class="bloque"><h2>Cómo se paga</h2><p>{!! $texto($edicion->payment_info) !!}</p></div>
            @endif

            @php
                $galeria = collect($curso->gallery ?? []);
                $imagenes = $galeria->where('tipo', 'imagen')->filter(fn ($g) => filled($g['archivo'] ?? null));
                $videos = $galeria->where('tipo', 'video')->filter(fn ($g) => filled($g['url'] ?? null));
                $documentos = $galeria->where('tipo', 'archivo')->filter(fn ($g) => filled($g['archivo'] ?? null) || filled($g['url'] ?? null));
            @endphp

            @if ($imagenes->isNotEmpty())
                <div class="bloque">
                    <h2>Imágenes</h2>
                    <div class="galeria">
                        @foreach ($imagenes as $g)
                            <figure>
                                <a href="{{ $publico->url($g['archivo']) }}" target="_blank" rel="noopener">
                                    <img src="{{ $publico->url($g['archivo']) }}" alt="{{ $g['titulo'] ?? $curso->name }}" loading="lazy">
                                </a>
                                @if (filled($g['titulo'] ?? null))<figcaption>{{ $g['titulo'] }}</figcaption>@endif
                            </figure>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($videos->isNotEmpty())
                <div class="bloque">
                    <h2>Videos</h2>
                    @foreach ($videos as $g)
                        @if ($embebido = $incrustar($g['url']))
                            <div class="video"><iframe src="{{ $embebido }}" title="{{ $g['titulo'] ?? 'Video' }}" loading="lazy" allowfullscreen></iframe></div>
                        @else
                            <p><a href="{{ $g['url'] }}" target="_blank" rel="noopener">{{ $g['titulo'] ?? $g['url'] }} ↗</a></p>
                        @endif
                    @endforeach
                </div>
            @endif

            @if ($documentos->isNotEmpty())
                <div class="bloque">
                    <h2>Material informativo</h2>
                    <ul class="archivos">
                        @foreach ($documentos as $g)
                            <li><a href="{{ filled($g['archivo'] ?? null) ? $publico->url($g['archivo']) : $g['url'] }}" target="_blank" rel="noopener">{{ $g['titulo'] ?? 'Documento' }} ↗</a></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>

        {{-- ------------------------------------------------ inscripción --}}
        <div id="inscripcion">
            @if ($edicion->status !== 'cancelada')
                <div class="conteo">
                    @if ($lleno)
                        <div class="numero">Cupo lleno</div>
                        <div class="barra"><i style="width:100%"></i></div>
                        <p>
                            @if ($edicion->recibeInscripciones())
                                Puedes anotarte en la lista de espera: si se libera un cupo, te escribimos.
                                @if ($enEspera > 0) Ya hay {{ $enEspera }} {{ $enEspera === 1 ? 'persona' : 'personas' }} esperando. @endif
                            @else
                                Se llenaron los {{ $edicion->capacity }} cupos.
                            @endif
                        </p>
                    @else
                        <div class="numero">{{ $libres }} <small>{{ $libres === 1 ? 'cupo disponible' : 'cupos disponibles' }} de {{ $edicion->capacity }}</small></div>
                        <div class="barra"><i style="width:{{ $edicion->capacity ? min(100, round(100 * ($edicion->capacity - $libres) / $edicion->capacity)) : 0 }}%"></i></div>
                        <p>Los cupos se asignan en orden de inscripción.</p>
                    @endif
                </div>
            @endif

            @if (! $edicion->recibeInscripciones())
                <div class="alerta">
                    @switch($edicion->status)
                        @case('planeada') Esta actividad todavía no abre inscripciones. @break
                        @case('inscripciones_cerradas') Las inscripciones de esta actividad están cerradas. @break
                        @case('cancelada') Esta actividad se canceló: no recibe inscripciones. @break
                        @default Esta actividad ya no recibe inscripciones.
                    @endswitch
                </div>
            @endif

            @if ($edicion->recibeInscripciones() || $vistaPrevia)
                <form method="POST" action="{{ route('actividad.inscribir', $edicion->code) }}" class="ficha" enctype="multipart/form-data">
                    @csrf

                    <h2>{{ $lleno ? 'Lista de espera' : 'Inscríbete' }}</h2>
                    <p class="help" style="color:var(--muted);font-size:.88rem;margin:0">
                        Los campos con <span style="color:#9B2C2C">*</span> son obligatorios.
                        {{ $lleno ? 'Quedas en la lista de espera y te avisamos por correo si se libera un cupo.' : 'Al terminar te decimos si quedaste inscrito, y te llega un correo.' }}
                    </p>

                    @if ($errors->any())
                        <div class="error" style="margin-top:1rem">
                            <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                        </div>
                    @endif

                    <div style="position:absolute;left:-9999px" aria-hidden="true">
                        <label>No llenar este campo <input type="text" name="sitio_web" tabindex="-1" autocomplete="off"></label>
                    </div>

                    <label><span class="obligatoria">Nombre y apellidos</span>
                        <input type="text" name="nombre" required maxlength="160" autocomplete="name" value="{{ old('nombre', auth()->user()?->name) }}">
                    </label>

                    <label><span class="obligatoria">Correo electrónico</span>
                        <input type="email" name="correo" required maxlength="160" autocomplete="email" value="{{ old('correo', auth()->user()?->email) }}">
                        <span class="help">Aquí te llega la confirmación y cualquier novedad de la actividad.</span>
                    </label>

                    <label><span class="obligatoria">Tipo de participante</span>
                        <select name="tipo" required id="tipo-participante">
                            <option value="">Elige uno</option>
                            @foreach ($tipos as $clave => $nombre)
                                <option value="{{ $clave }}" @selected(old('tipo') === $clave)>{{ $nombre }}</option>
                            @endforeach
                        </select>
                        @if (filled(config('fabos.identity.institutional_domain')))
                            <span class="help">Estudiantes, profesores y colaboradores de la EAN se inscriben con su correo institucional.</span>
                        @endif
                    </label>

                    <label><span class="obligatoria">Programa académico, dependencia o área</span>
                        <input type="text" name="programa" required maxlength="160" value="{{ old('programa') }}" placeholder="Ingeniería de Sistemas · Rectoría · Empresa donde trabajas">
                    </label>

                    {{-- Las preguntas de la actividad. Las que son solo para un
                         tipo de participante aparecen al elegirlo. --}}
                    @foreach ($curso->registrationQuestions as $p)
                        @php
                            $campo = 'p' . $p->id;
                            $tiposDe = implode(',', array_filter((array) $p->participant_types));
                        @endphp
                        <div class="pregunta" data-tipos="{{ $tiposDe }}" data-obligatoria="{{ $p->required ? '1' : '0' }}">
                            @switch($p->type)
                                @case('parrafo')
                                    <label><span class="{{ $p->required ? 'obligatoria' : '' }}">{{ $p->label }}</span>
                                        <textarea name="{{ $campo }}" rows="4" maxlength="3000" @required($p->required)>{{ old($campo) }}</textarea>
                                        @if ($p->help)<span class="help">{{ $p->help }}</span>@endif
                                    </label>
                                    @break
                                @case('seleccion')
                                    <label><span class="{{ $p->required ? 'obligatoria' : '' }}">{{ $p->label }}</span>
                                        <select name="{{ $campo }}" @required($p->required)>
                                            <option value="">Elige una</option>
                                            @foreach ($p->opciones() as $o)
                                                <option value="{{ $o }}" @selected(old($campo) === $o)>{{ $o }}</option>
                                            @endforeach
                                        </select>
                                        @if ($p->help)<span class="help">{{ $p->help }}</span>@endif
                                    </label>
                                    @break
                                @case('multiple')
                                    <fieldset><legend class="{{ $p->required ? 'obligatoria' : '' }}">{{ $p->label }}</legend>
                                        @foreach ($p->opciones() as $o)
                                            <label class="opcion"><input type="checkbox" name="{{ $campo }}[]" value="{{ $o }}" @checked(in_array($o, (array) old($campo, []), true))> <span>{{ $o }}</span></label>
                                        @endforeach
                                        @if ($p->help)<span class="help">{{ $p->help }}</span>@endif
                                    </fieldset>
                                    @break
                                @case('aceptacion')
                                    <label class="casilla"><input type="checkbox" name="{{ $campo }}" value="1" @required($p->required) @checked(old($campo))>
                                        <span><span class="{{ $p->required ? 'obligatoria' : '' }}">{{ $p->label }}</span>@if ($p->help)<span class="help">{{ $p->help }}</span>@endif</span>
                                    </label>
                                    @break
                                @case('archivo')
                                    <label><span class="{{ $p->required ? 'obligatoria' : '' }}">{{ $p->label }}</span>
                                        <input type="file" name="{{ $campo }}" @required($p->required)
                                               accept="{{ collect(\App\Models\RegistrationQuestion::EXTENSIONES)->map(fn ($e) => '.' . $e)->implode(',') }}">
                                        <span class="help">{{ $p->help ?: 'Un archivo de hasta ' . intdiv(\App\Services\Projects\SoportesDeSolicitud::TAMANO_MAXIMO, 1024) . ' MB. Si son varios, en un ZIP.' }}</span>
                                    </label>
                                    @break
                                @default
                                    <label><span class="{{ $p->required ? 'obligatoria' : '' }}">{{ $p->label }}</span>
                                        <input type="{{ ['numero' => 'number', 'fecha' => 'date', 'enlace' => 'url'][$p->type] ?? 'text' }}"
                                               name="{{ $campo }}" value="{{ old($campo) }}" @required($p->required) @if ($p->type === 'texto') maxlength="500" @endif>
                                        @if ($p->help)<span class="help">{{ $p->help }}</span>@endif
                                    </label>
                            @endswitch
                        </div>
                    @endforeach

                    <label class="casilla">
                        <input type="checkbox" name="acepta" value="1" required @checked(old('acepta'))>
                        <span><span class="obligatoria">Acepto las condiciones de inscripción y cancelación</span>
                            <span class="help">{{ $curso->condiciones() }}</span>
                        </span>
                    </label>

                    <x-captcha accion="actividad.inscribir"/>

                    @if ($vistaPrevia && ! $edicion->recibeInscripciones())
                        <button type="button" class="btn" disabled>Inscribirme (vista previa)</button>
                    @else
                        <button type="submit" class="btn">{{ $lleno ? 'Anotarme en la lista de espera' : 'Inscribirme' }}</button>
                    @endif
                </form>

                <script>
                    // Todas las preguntas se ven desde el principio. Al elegir el
                    // tipo de participante se esconden las que no le tocan, y
                    // esas no se exigen. Esconderlas antes de elegir dejaba el
                    // formulario como si la actividad no preguntara nada.
                    (function () {
                        var tipo = document.getElementById('tipo-participante');
                        function aplicar() {
                            document.querySelectorAll('.pregunta').forEach(function (bloque) {
                                var tipos = bloque.dataset.tipos ? bloque.dataset.tipos.split(',') : [];
                                var aplica = tipos.length === 0 || !tipo.value || tipos.indexOf(tipo.value) !== -1;
                                bloque.style.display = aplica ? '' : 'none';
                                bloque.querySelectorAll('input,select,textarea').forEach(function (c) {
                                    if (bloque.dataset.obligatoria === '1' && c.type !== 'checkbox' || (c.type === 'checkbox' && bloque.dataset.obligatoria === '1' && !c.name.endsWith('[]'))) {
                                        c.required = aplica;
                                    }
                                    c.disabled = !aplica;
                                });
                            });
                        }
                        tipo.addEventListener('change', aplicar);
                        aplicar();
                    })();
                </script>
            @endif
        </div>
    </section>
</main>
@endsection
