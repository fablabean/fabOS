@php
    $b = \App\Support\BloqueoDeReservas::class;
    $vigentes = $b::vigentes();
    $tz = config('fabos.lab.timezone');
@endphp

<x-filament-panels::page>
    @foreach ($vigentes as $bloqueo)
        @php $ya = $bloqueo->enCurso(); $vivas = $bloqueo->reservasDentro(); @endphp
        <x-filament::section>
            <x-slot name="heading">
                @if ($ya)
                    <span style="color:#dc2626">● Bloqueado ahora</span>
                @else
                    <span style="color:#b45309">● Programado</span>
                @endif
                · {{ ucfirst($bloqueo->cuando()) }}
            </x-slot>
            <x-slot name="description">
                @if ($ya)
                    Las reservas están bloqueadas.
                @elseif ($bloqueo->avisar_desde->lte(now()))
                    El aviso ya sale en el sitio; se reserva normal los demás días.
                @else
                    El aviso empieza a salir el {{ $b::legible($bloqueo->avisar_desde) }}.
                @endif
                @if ($bloqueo->por) · lo programó {{ $bloqueo->por }} @endif
            </x-slot>

            <p class="text-sm" style="white-space:pre-line"><strong>Mensaje:</strong> {{ $b::textoDe($bloqueo) }}</p>

            <p class="text-sm mt-3">
                Hay <strong>{{ $vivas }}</strong> {{ $vivas === 1 ? 'reserva' : 'reservas' }} en pie dentro de este bloqueo.
                El bloqueo no las cancela: si hay que hacerlo, se hace desde
                <a class="text-primary-600 hover:underline" href="{{ \App\Filament\Resources\Reservations\ReservationResource::getUrl('index') }}">Reservas</a>.
            </p>

            <div class="mt-4">
                <x-filament::button :color="$ya ? 'success' : 'gray'" wire:click="quitar({{ $bloqueo->id }})"
                                    wire:confirm="{{ $ya ? '¿Abrir las reservas otra vez?' : '¿Quitar este bloqueo? Se podrá reservar en esas fechas.' }}">
                    {{ $ya ? 'Levantar el bloqueo' : 'Quitar este bloqueo' }}
                </x-filament::button>
            </div>
        </x-filament::section>
    @endforeach

    <x-filament::section>
        <x-slot name="heading">{{ $vigentes->isEmpty() ? 'Bloquear las reservas' : 'Programar otro bloqueo' }}</x-slot>
        <x-slot name="description">El viernes entero, la próxima semana de tal hora a tal otra, o desde ya por una emergencia.</x-slot>

        <form wire:submit="activar" class="space-y-6">
            {{ $this->form }}

            <div>
                <x-filament::button type="submit" color="danger"
                                    wire:confirm="En esas fechas nadie podrá reservar nada —equipos, espacios, herramientas ni asesorías— y en el sitio saldrá el aviso. ¿Bloquear?">
                    Bloquear las reservas
                </x-filament::button>
            </div>
        </form>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Horario de autoservicio</x-slot>
        <x-slot name="description">
            Las horas en que la gente puede reservar por su cuenta, en lo que marques. Sirve cuando hay
            más demanda de la que se puede atender. Lo que se agenda desde el panel no queda limitado.
        </x-slot>

        <form wire:submit="guardarHorario" class="space-y-4">
            <div class="text-sm">
                <p class="mb-2">Limitar a este horario:</p>
                <div class="flex flex-wrap gap-x-6 gap-y-2">
                    @foreach (\App\Support\HorarioDeAutoservicio::TIPOS as $clave => [$etiqueta])
                        <label class="flex items-center gap-2">
                            <input type="checkbox" wire:model="horario.aplica" value="{{ $clave }}" class="rounded">
                            {{ $etiqueta }}
                        </label>
                    @endforeach
                </div>
                <p class="mt-1" style="color:rgb(107 114 128)">Sin ninguna marcada, no se limita nada.</p>
            </div>

            <div class="flex flex-wrap gap-4 text-sm">
                <label>Desde
                    <input type="time" step="900" wire:model="horario.desde"
                           style="margin-left:.4rem;padding:.35rem .5rem;border-radius:6px;border:1px solid rgba(128,128,128,.35);background:transparent">
                </label>
                <label>Hasta
                    <input type="time" step="900" wire:model="horario.hasta"
                           style="margin-left:.4rem;padding:.35rem .5rem;border-radius:6px;border:1px solid rgba(128,128,128,.35);background:transparent">
                </label>
            </div>
            @error('desde') <p class="text-sm" style="color:#dc2626">{{ $message }}</p> @enderror
            @error('hasta') <p class="text-sm" style="color:#dc2626">{{ $message }}</p> @enderror

            <p class="text-sm" style="color:rgb(107 114 128)">
                La reserva tiene que empezar y terminar dentro del horario, el mismo día. En asesorías solo se
                ofrecen las horas que caben. Las reservas que ya existen no se tocan.
            </p>

            <x-filament::button type="submit">Guardar el horario</x-filament::button>
        </form>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Qué hace el bloqueo</x-slot>
        <ul class="text-sm space-y-1" style="list-style:disc;padding-left:1.2rem">
            <li>Nadie puede crear ni reprogramar reservas de equipos, espacios, herramientas ni asesorías que caigan dentro del bloqueo, ni siquiera en parte, tampoco desde el panel. Lo de antes y lo de después se reserva normal.</li>
            <li>Antes de que empiece, en el sitio sale una ventana ámbar con las fechas y el mensaje, que se puede cerrar para seguir reservando los demás días. Al elegir la hora de una reserva también se dice qué días no se puede.</li>
            <li>No se ofrecen horas de asesoría ni de uso acompañado dentro del periodo, y nadie puede registrar su llegada por QR.</li>
            <li>Los proyectos siguen normales.</li>
            <li>Mientras dura, en el sitio sale un aviso en ventana con el mensaje la primera vez que alguien entra, y una franja roja arriba en todas las páginas.</li>
            <li>Las reservas que ya existían siguen igual.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
