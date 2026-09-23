@extends('layouts.publico')
@section('title', $alianza->name . ' · Alianzas · ' . config('fabos.lab.name'))
@section('description', \Illuminate\Support\Str::limit($alianza->alliance_pitch ?: $alianza->summary, 150))

@section('styles')
    .dos{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:3rem;align-items:start}
    @media (max-width:860px){.dos{grid-template-columns:1fr;gap:1.6rem}}
    .partes{list-style:none;padding:0;margin:1rem 0 0;display:grid;gap:.5rem}
    .partes li{background:var(--surface);border:1px solid var(--rule);border-radius:6px;padding:.7rem 1rem}
    .partes b{display:block}
    .partes span{font-size:.86rem;color:var(--muted)}
    .busca{background:color-mix(in srgb,var(--accent) 10%,transparent);border-radius:8px;padding:1rem 1.2rem;margin:1.4rem 0 0}
    .busca p{margin:0}
    .ficha{background:var(--surface);border:1px solid var(--rule);border-radius:8px;padding:1.4rem}
    .ficha h2{margin-bottom:.2rem}
    .ficha label{display:block;font-size:.84rem;font-weight:600;margin-top:1rem}
    .ficha input:not([type=checkbox]),.ficha select,.ficha textarea{
        display:block;width:100%;margin-top:.35rem;padding:.6rem .7rem;font:inherit;
        background:var(--ground);color:var(--ink);border:1px solid var(--rule);border-radius:4px;
    }
    .ficha .help{display:block;font-weight:400;color:var(--muted);font-size:.8rem;margin-top:.3rem}
    .ficha label.casilla{font-weight:400;display:flex;gap:.6rem;align-items:flex-start}
    .ficha label.casilla input{margin-top:.25rem;flex:none}
    .ficha button{margin-top:1.2rem;width:100%;padding:.8rem;font-size:1rem}
    .error{background:color-mix(in srgb,#9B2C2C 12%,transparent);border-radius:6px;padding:.8rem 1rem;margin-bottom:1rem;font-size:.92rem}
    .error ul{margin:0;padding-left:1.1rem}
    /* La portada del proyecto, debajo del resumen: una alianza se entiende
       antes viendo lo que se construye que leyendo de qué va. */
    .portada{display:block;width:100%;max-height:26rem;object-fit:cover;
             border-radius:8px;margin-top:1.4rem}
@endsection

@section('content')
<main>
    <section>
        <p class="rotulo"><a href="{{ route('alianzas.index') }}" style="text-decoration:none">Alianzas</a> · {{ $alianza->code }}</p>
        <h1>{{ $alianza->name }}</h1>
        @if ($alianza->summary)
            <p class="lead">{{ $alianza->summary }}</p>
        @endif

        @if ($alianza->reference_image_path)
            <img class="portada" src="{{ route('proyectos.imagen', $alianza) }}"
                 alt="{{ $alianza->name }}" loading="eager">
        @endif
    </section>

    <section class="dos" style="padding-top:0">
        <div>
            <h2>Quiénes están</h2>
            {{-- Quién es parte y qué tipo de cosa pone. Nunca cuánto: el dinero
                 de una alianza es de sus partes, no del sitio. --}}
            <ul class="partes">
                @foreach ($partes as $p)
                    <li>
                        <b>{{ $p->esElLaboratorio() ? $p->name : ($p->organization ?: $p->name) }}</b>
                        <span>{{ $p->papel() }} · pone {{ mb_strtolower(\App\Models\ProjectPartner::APORTES[$p->contribution_kind] ?? $p->contribution_kind) }}@if ($p->contribution_note): {{ $p->contribution_note }}@endif</span>
                    </li>
                @endforeach
            </ul>

            @if ($alianza->alliance_pitch)
                <div class="busca">
                    <p class="rotulo" style="margin-bottom:.3rem">Qué busca la alianza</p>
                    <p>{{ $alianza->alliance_pitch }}</p>
                </div>
            @endif

            @if ($alianza->lead)
                <p style="color:var(--muted);font-size:.9rem;margin-top:1.4rem">
                    Por el laboratorio responde {{ $alianza->lead->name }}.
                </p>
            @endif
        </div>

        {{-- Mostrarla y abrirla son dos decisiones: una alianza puede
             enseñarse sin recibir propuestas. Sin esto, no querer lo segundo
             dejaba el proyecto invisible. --}}
        @if ($alianza->admiteAliados())
        <div id="unirme">
            @if ($errors->any())
                <div class="error">
                    <ul>
                        @foreach ($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('alianzas.unirme', $alianza) }}" class="ficha">
                @csrf

                <h2>Quiero unirme</h2>
                <p class="help" style="margin:0">
                    Nos dices quién eres y qué pondrías. Quedas como propuesto, te escribimos, y si
                    cuadra entras en el acuerdo con las demás partes.
                </p>

                <div style="position:absolute;left:-9999px" aria-hidden="true">
                    <label>No llenar este campo <input type="text" name="sitio_web" tabindex="-1" autocomplete="off"></label>
                </div>

                <label>Nombre <input type="text" name="nombre" required maxlength="160" value="{{ old('nombre', auth()->user()?->name) }}"></label>
                <label>Empresa u organización <input type="text" name="organizacion" maxlength="160" value="{{ old('organizacion') }}"><span class="help">Si vienes en nombre de una.</span></label>
                <label>Correo <input type="email" name="correo" required maxlength="160" value="{{ old('correo', auth()->user()?->email) }}"></label>
                @include('partials.telefono', ['valor' => auth()->user()?->phone])

                <label>
                    Cómo entrarías
                    <select name="papel" required>
                        <option value="aliado" @selected(old('papel', 'aliado') === 'aliado')>Como aliado: pongo trabajo, equipos o conocimiento</option>
                        <option value="inversor" @selected(old('papel') === 'inversor')>Como inversor: pongo capital</option>
                    </select>
                </label>

                <label>
                    Qué pondrías
                    <select name="tipo" required>
                        @foreach (\App\Models\ProjectPartner::APORTES as $clave => $texto)
                            <option value="{{ $clave }}" @selected(old('tipo') === $clave)>{{ $texto }}</option>
                        @endforeach
                    </select>
                </label>

                <label>
                    Valorado en pesos <span style="font-weight:400">(opcional)</span>
                    <input type="number" name="valor" min="0" step="1000" value="{{ old('valor') }}" placeholder="12000000">
                    <span class="help">Lo que vale lo que pones, aunque no sea dinero. Ayuda a repartir la participación.</span>
                </label>

                <label>
                    En qué consiste
                    <textarea name="aporte" rows="4" required maxlength="1000">{{ old('aporte') }}</textarea>
                    <span class="help">«200 horas de diseño electrónico», «acceso a nuestro taller de soldadura», «el capital del primer lote».</span>
                </label>

                <label class="casilla">
                    <input type="checkbox" name="autoriza" value="1" required {{ old('autoriza') ? 'checked' : '' }}>
                    <span>Autorizo a {{ config('fabos.lab.name') }} a guardar estos datos y a escribirme sobre esta alianza. Puedo pedir que los borren cuando quiera.</span>
                </label>

                <x-captcha accion="alianzas.unirme"/>

                <button type="submit" class="btn">Pedir entrar</button>
            </form>
        </div>
        @else
            <div class="ficha">
                <h2>Esta alianza no está recibiendo propuestas</h2>
                <p class="help" style="margin:0">
                    Está aquí para que se vea lo que se está construyendo. Si te interesa,
                    escríbenos y lo miramos: <a href="{{ route('proyectos.solicitar') }}">cuéntanos tu idea</a>.
                </p>
            </div>
        @endif
    </section>
</main>
@endsection
