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
        <x-slot name="heading">Qué hace</x-slot>
        <ul class="text-sm space-y-1" style="list-style:disc;padding-left:1.2rem">
            <li>Nadie puede crear ni reprogramar reservas de equipos, espacios, herramientas ni asesorías que empiecen antes de la reapertura, tampoco desde el panel. Con fecha de reapertura, lo de después sí se puede reservar ya.</li>
            <li>No se ofrecen horas de asesoría ni de uso acompañado dentro del periodo, y nadie puede registrar su llegada por QR.</li>
            <li>Los proyectos siguen normales.</li>
            <li>En el sitio sale un aviso en ventana con el motivo la primera vez que alguien entra, y una franja roja arriba en todas las páginas.</li>
            <li>Las reservas que ya existían siguen igual.</li>
        </ul>
    </x-filament::section>
</x-filament-panels::page>
