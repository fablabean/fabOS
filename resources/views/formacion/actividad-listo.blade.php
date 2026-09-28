@extends('layouts.publico')
@section('title', ($inscripcion->enEspera() ? 'En lista de espera' : 'Inscripción confirmada') . ' · ' . config('fabos.lab.name'))

@section('styles')
    .hecho{background:var(--surface);border:1px solid var(--rule);border-left:4px solid var(--accent);
           border-radius:8px;padding:1.6rem;max-width:44rem}
    .hecho.espera{border-left-color:#B7791F}
    .hecho dl{display:grid;grid-template-columns:max-content 1fr;gap:.3rem 1rem;margin:1rem 0}
    .hecho dt{color:var(--muted)}
    .hecho dd{margin:0;font-weight:600}
@endsection

@section('content')
<main>
    <section>
        <p class="rotulo">{{ $edicion->course?->tipoLegible() }} · {{ $edicion->nombre() }}</p>

        @if ($inscripcion->enEspera())
            <h1>Quedaste en lista de espera</h1>

            <div class="hecho espera">
                <p style="margin-top:0">
                    <strong>Todavía no tienes cupo.</strong>
                    @if ($grupo)
                        Los cupos de <strong>{{ $grupo }}</strong> están llenos y quedaste en su lista de espera
                        ({{ $posicion }}.º en la lista de la actividad).
                    @else
                        Los cupos de esta actividad están llenos y quedaste
                        de <strong>{{ $posicion }}.º</strong> en la lista de espera.
                    @endif
                </p>
                <p>
                    Si alguien cancela y se libera un cupo, te escribimos a <strong>{{ $inscripcion->user?->email }}</strong>.
                    No vengas a la actividad hasta recibir ese correo: sin cupo asignado no podemos recibirte.
                </p>
                <p style="margin-bottom:0">
                    Tus datos quedan guardados aunque no alcances cupo: con la lista de espera decidimos si
                    abrimos otro grupo.
                </p>
            </div>
        @else
            <h1>¡Quedaste inscrito!</h1>

            <div class="hecho">
                <p style="margin-top:0">
                    Tienes cupo en <strong>{{ $edicion->nombre() }}</strong>. Te enviamos la confirmación a
                    <strong>{{ $inscripcion->user?->email }}</strong>.
                </p>

                <dl>
                    @if ($grupo)<dt>Grupo</dt><dd>{{ $grupo }}</dd>@endif
                    @if ($edicion->fechas())<dt>Fecha</dt><dd>{{ $edicion->fechas() }}</dd>@endif
                    @if ($edicion->horario())<dt>Horario</dt><dd>{{ $edicion->horario() }}</dd>@endif
                    <dt>Dónde</dt><dd>{{ $edicion->lugar() ?? config('fabos.lab.name') }}</dd>
                    @if ($edicion->precioLegible())<dt>Valor</dt><dd>{{ $edicion->precioLegible() }}</dd>@endif
                </dl>

                @if ($edicion->is_paid && $edicion->payment_info)
                    <p><strong>Cómo se paga:</strong> {!! nl2br(e($edicion->payment_info)) !!}</p>
                @endif

                @if ($edicion->course?->materials_to_bring)
                    <p><strong>Qué debes llevar:</strong> {!! nl2br(e($edicion->course->materials_to_bring)) !!}</p>
                @endif

                <p style="margin-bottom:0">
                    Si al final no puedes ir, cancela desde el enlace del correo: ese cupo le sirve a
                    alguien que está en lista de espera.
                </p>
            </div>
        @endif

        <p style="margin-top:1.6rem"><a href="{{ route('actividad', $edicion->code) }}">← Volver a la actividad</a></p>
    </section>
</main>
@endsection
