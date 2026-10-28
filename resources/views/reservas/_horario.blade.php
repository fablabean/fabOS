{{-- El horario de autoservicio, debajo de la hora de inicio, si limita este
     tipo. Y los días bloqueados: se ven al elegir la fecha, no al enviar. --}}
@if (\App\Support\HorarioDeAutoservicio::activo($tipo))
    <small style="display:block;margin-top:.3rem;color:var(--muted)">
        {{ \App\Support\HorarioDeAutoservicio::aviso($tipo) }}
    </small>
@endif
@foreach (\App\Support\BloqueoDeReservas::vigentes()->filter(fn ($b) => $b->enCurso() || $b->avisar_desde->lte(now())) as $b)
    <small style="display:block;margin-top:.3rem;color:#B45309;font-weight:600">
        No se puede reservar {{ $b->cuando() }}.
    </small>
@endforeach
