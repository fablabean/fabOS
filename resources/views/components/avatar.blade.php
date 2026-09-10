@props(['usuario', 'tamano' => '2.2rem'])
{{-- El círculo de la persona: su foto o, a falta de ella, sus iniciales. --}}
@if ($usuario->fotoUrl())
    <img class="avatar" src="{{ $usuario->fotoUrl() }}" alt="{{ $usuario->name }}" style="width:{{ $tamano }};height:{{ $tamano }}">
@else
    <span class="avatar" aria-hidden="true" style="width:{{ $tamano }};height:{{ $tamano }}">{{ $usuario->iniciales() }}</span>
@endif
