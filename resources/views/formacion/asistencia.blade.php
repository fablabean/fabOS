@extends('layouts.publico')
@section('title', 'Asistencia · ' . $edicion->nombre())

@section('styles')
    .hecho{background:var(--surface);border:1px solid var(--rule);border-radius:8px;padding:1.6rem;max-width:32rem}
    .hecho.ok{border-left:4px solid var(--accent)}
    .hecho input{display:block;width:100%;margin-top:.35rem;padding:.7rem;font:inherit;font-size:1.05rem;box-sizing:border-box;
                 background:var(--ground);color:var(--ink);border:1px solid var(--rule);border-radius:4px}
    .hecho button{margin-top:1rem;width:100%;padding:.9rem;font-size:1.05rem}
    .error{background:color-mix(in srgb,#9B2C2C 12%,transparent);border-radius:6px;padding:.8rem 1rem;margin-bottom:1rem}
@endsection

@section('content')
<main>
    <section>
        <p class="rotulo">Registro de asistencia</p>
        <h1 style="font-size:clamp(1.6rem,5vw,2.4rem)">{{ $edicion->nombre() }}</h1>
        <p style="color:var(--muted)">{{ $sesion->nombre() }}</p>

        @if ($r = session('registrada'))
            <div class="hecho ok">
                <p style="margin:0;font-size:1.15rem">
                    <strong>{{ $r['nueva'] ? '¡Listo' : 'Ya estabas registrado' }}{{ $r['nombre'] ? ', ' . \Illuminate\Support\Str::of($r['nombre'])->explode(' ')->first() : '' }}!</strong>
                </p>
                <p style="margin:.4rem 0 0">
                    {{ $r['nueva'] ? 'Tu asistencia a esta sesión quedó registrada.' : 'Tu asistencia a esta sesión ya estaba anotada; no se cuenta dos veces.' }}
                </p>
            </div>
        @elseif (! $abierto)
            <div class="hecho">
                <p style="margin:0">
                    Este QR registra asistencia desde una hora antes de la sesión hasta una hora después de que termina.
                    Si ya estás en la actividad, pídele al equipo que te anote.
                </p>
            </div>
        @else
            <form method="POST" action="{{ route('asistencia.registrar', $sesion->token) }}" class="hecho">
                @csrf

                @if ($errors->any())
                    <div class="error">{{ $errors->first() }}</div>
                @endif

                <label>Correo con que te inscribiste
                    <input type="email" name="correo" required autocomplete="email" inputmode="email"
                           value="{{ old('correo', auth()->user()?->email) }}">
                </label>

                <button type="submit" class="btn">Registrar mi asistencia</button>
            </form>
        @endif
    </section>
</main>
@endsection
