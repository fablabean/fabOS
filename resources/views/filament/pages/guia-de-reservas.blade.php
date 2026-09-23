<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit">Guardar</x-filament::button>
        </div>
    </form>

    <x-filament::section>
        <x-slot name="heading">La guía con IA</x-slot>
        <x-slot name="description">
            Debajo del banner está la caja «escribe qué necesitas y te decimos por dónde». Usa la misma
            clave, modelo, interruptor y tope diario que el borrador de Preguntas (IA_ACTIVA, IA_MAX_POR_DIA
            en el .env del servidor). No es un chat: una pregunta, una respuesta, uno de los cuatro caminos.
        </x-slot>
        @php
            $guia = app(\App\Services\Ia\GuiaDeReservas::class);
            $fallo = $guia->ultimoFallo();
        @endphp
        <p class="text-sm">
            @if ($guia->disponible())
                Encendida · quedan <strong>{{ $guia->quedanHoy() }}</strong> preguntas hoy.
            @else
                Apagada: la caja no se muestra. Se enciende con IA_ACTIVA y la clave en el servidor.
            @endif
        </p>

        {{-- Por qué dejó de funcionar, si dejó.

             A quien escribe en la caja se le dice «no pudimos leerlo ahora»,
             que es lo que le sirve: nadie de fuera tiene que enterarse de cómo
             pagamos la API. Pero el laboratorio necesita la razón, y hasta
             ahora la única señal era un archivo de log en el servidor: se
             quedó un día entero sin saldo y nos enteramos porque alguien lo
             probó a mano. --}}
        @if ($fallo)
            <div class="mt-3 rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm
                        dark:border-warning-700 dark:bg-warning-950">
                <p class="font-semibold">El último intento falló</p>
                <p class="mt-1">{{ $fallo['motivo'] }}</p>
                <p class="mt-1 text-xs opacity-70">
                    {{ $fallo['cuando']->timezone(config('fabos.lab.timezone'))->format('d/m/Y H:i') }}
                    · mientras tanto, la caja le dice a quien escribe que pruebe otra vez o elija
                    un camino, y el resto de la página funciona igual.
                </p>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
