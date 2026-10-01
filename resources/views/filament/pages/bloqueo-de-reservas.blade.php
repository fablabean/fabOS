@php
    $b = \App\Support\BloqueoDeReservas::class;
    $activo = $b::activo();
    $estado = $b::estado();
@endphp

<x-filament-panels::page>
    @if ($activo)
        <x-filament::section>
            <x-slot name="heading">
                <span style="color:#dc2626">● Las reservas están bloqueadas</span>
            </x-slot>
            <x-slot name="description">
                Desde el {{ \Illuminate\Support\Carbon::parse($estado['desde'])->timezone(config('fabos.lab.timezone'))->locale('es')->isoFormat('D [de] MMMM, H:mm') }}
                @if ($estado['por']) · lo activó {{ $estado['por'] }} @endif
            </x-slot>

            <p class="text-sm"><strong>Motivo:</strong> {{ $b::motivo() }}</p>
            <p class="text-sm mt-1">
                <strong>Se reabre:</strong> {{ $b::hastaLegible() ?? 'cuando se levante aquí' }}
            </p>

            @php $vivas = $this->reservasPorDelante(); @endphp
            <p class="text-sm mt-3">
                Siguen en pie <strong>{{ $vivas }}</strong> {{ $vivas === 1 ? 'reserva' : 'reservas' }}
                {{ $b::hasta() ? 'de aquí a la reapertura' : 'de aquí en adelante' }}.
                El bloqueo no las cancela: si hay que hacerlo, se hace desde
                <a class="text-primary-600 hover:underline" href="{{ \App\Filament\Resources\Reservations\ReservationResource::getUrl('index') }}">Reservas</a>.
            </p>

            <div class="mt-4">
                <x-filament::button color="success" wire:click="levantar"
                                    wire:confirm="¿Abrir las reservas otra vez?">
                    Levantar el bloqueo
                </x-filament::button>
            </div>
        </x-filament::section>
    @endif

    <form wire:submit="activar" class="space-y-6">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit" color="danger"
                                wire:confirm="Nadie podrá reservar nada —equipos, espacios, herramientas ni asesorías— y todo el que entre al sitio verá el aviso. ¿Bloquear?">
                {{ $activo ? 'Actualizar el bloqueo' : 'Bloquear todas las reservas' }}
            </x-filament::button>
        </div>
    </form>

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
            <li>Nadie puede crear ni reprogramar reservas de equipos, espacios, herramientas ni asesorías que empiecen antes de la reapertura, tampoco desde el panel. Con fecha de reapertura, lo de después sí se puede reservar ya.</li>
            <li>No se ofrecen horas de asesoría ni de uso acompañado dentro del periodo, y nadie puede registrar su llegada por QR.</li>
            <li>Los proyectos siguen normales.</li>
            <li>En el sitio sale un aviso en ventana con el motivo la primera vez que alguien entra, y una franja roja arriba en todas las páginas.</li>
            <li>Las reservas que ya existían siguen igual.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
