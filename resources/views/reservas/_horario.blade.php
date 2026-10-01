{{-- El horario de autoservicio, debajo de la hora de inicio, si limita este tipo. --}}
@if (\App\Support\HorarioDeAutoservicio::activo($tipo))
    <small style="display:block;margin-top:.3rem;color:var(--muted)">
        {{ \App\Support\HorarioDeAutoservicio::aviso($tipo) }}
    </small>
@endif
