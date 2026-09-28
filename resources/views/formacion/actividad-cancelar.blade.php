@extends('layouts.publico')
@section('title', 'Cancelar mi inscripción · ' . config('fabos.lab.name'))

@section('styles')
    .hecho{background:var(--surface);border:1px solid var(--rule);border-radius:8px;padding:1.6rem;max-width:40rem}
    .hecho textarea{display:block;width:100%;margin-top:.35rem;padding:.6rem .7rem;font:inherit;box-sizing:border-box;
                    background:var(--ground);color:var(--ink);border:1px solid var(--rule);border-radius:4px}
    .hecho button{margin-top:1rem}
    .error{background:color-mix(in srgb,#9B2C2C 12%,transparent);border-radius:6px;padding:.8rem 1rem;margin-bottom:1rem}
@endsection

@section('content')
<main>
    <section>
        <p class="rotulo">{{ $edicion->nombre() }}</p>

        @if (session('cancelada') || $inscripcion->status === 'retirado')
            <h1>Tu inscripción está cancelada</h1>
            <div class="hecho">
                <p style="margin:0">Gracias por avisar: el cupo queda libre para quien está en lista de espera.</p>
            </div>
        @else
            <h1>Cancelar mi inscripción</h1>

            <div class="hecho">
                <p style="margin-top:0">
                    {{ $inscripcion->user?->name }}, vas a cancelar tu {{ $inscripcion->enEspera() ? 'lugar en la lista de espera' : 'inscripción' }} en
                    <strong>{{ $edicion->nombre() }}</strong>@if ($edicion->fechas()) ({{ $edicion->fechas() }})@endif.
                </p>

                @if ($errors->any())
                    <div class="error">{{ $errors->first() }}</div>
                @endif

                <form method="POST" action="{{ $accion }}">
                    @csrf
                    <label>Si quieres, cuéntanos por qué
                        <textarea name="motivo" rows="3" maxlength="500">{{ old('motivo') }}</textarea>
                    </label>
                    <button type="submit" class="btn">Sí, cancelar</button>
                </form>
            </div>
        @endif

        <p style="margin-top:1.6rem"><a href="{{ route('actividad', $edicion->code) }}">← Volver a la actividad</a></p>
    </section>
</main>
@endsection
