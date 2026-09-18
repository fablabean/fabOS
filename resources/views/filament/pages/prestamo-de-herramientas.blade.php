<x-filament-panels::page>
    @php $h = $this->herramientas; @endphp

    <form wire:submit="save" class="flex flex-col gap-6">
        <x-filament::section>
            <x-slot name="heading">Cuántas caben en una reserva</x-slot>
            <x-slot name="description">
                Desde la lista pública de herramientas se pueden marcar varias y reservarlas juntas,
                a la misma hora. Este es el tope. Sin él, alguien se lleva el taller entero en una
                tarde «por si acaso».
            </x-slot>

            <div class="max-w-xs">
                <label for="maximo" class="block text-sm font-medium mb-1">Herramientas por reserva</label>
                <x-filament::input.wrapper>
                    <x-filament::input id="maximo" type="number" min="1" max="50" wire:model="maximo" />
                </x-filament::input.wrapper>
                @error('maximo')
                    <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                @enderror
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                    Se aplica al reservar herramientas sueltas. Cada una sigue exigiendo su certifab,
                    y si alguna necesita visto bueno el conjunto entero queda como solicitud.
                </p>
            </div>

            <x-slot name="footer">
                <x-filament::button type="submit">Guardar</x-filament::button>
            </x-slot>
        </x-filament::section>
    </form>

    <x-filament::section>
        <x-slot name="heading">Lo que se presta hoy</x-slot>
        <dl class="grid gap-4" style="grid-template-columns:repeat(auto-fit,minmax(11rem,1fr))">
            <div>
                <dt class="text-sm text-gray-500">Herramientas</dt>
                <dd class="text-2xl font-semibold">{{ $h['todas'] }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500">Que se prestan</dt>
                <dd class="text-2xl font-semibold">{{ $h['prestables'] }}</dd>
            </div>
            <div>
                <dt class="text-sm text-gray-500">Portátiles</dt>
                <dd class="text-2xl font-semibold">{{ $h['portatiles'] }}</dd>
                <dd class="text-xs text-gray-500">
                    Se marca en cada herramienta, en Activos: «puede salir» y el espacio donde se usa.
                    Es lo que la lista pública enseña junto al nombre.
                </dd>
            </div>
        </dl>
    </x-filament::section>
</x-filament-panels::page>
