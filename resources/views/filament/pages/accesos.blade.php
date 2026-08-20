<x-filament-panels::page>

    <form wire:submit="save" class="fi-form grid gap-6">

        <x-filament::section>
            <x-slot name="heading">Código al correo</x-slot>
            <x-slot name="description">
                Puerta principal. Se envía un código de un solo uso a la dirección de la persona.
            </x-slot>

            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" wire:model="otpLogin" class="mt-1 h-4 w-4 rounded">
                <span>
                    <span class="font-medium">Permitir ingreso por código</span>
                    <span class="block text-sm text-gray-500 dark:text-gray-400">
                        No puede apagarse si el carné también está apagado: quedaría el sistema sin entrada.
                    </span>
                </span>
            </label>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Carné digital de la Universidad</x-slot>
            <x-slot name="description">
                Ingreso escaneando el QR de la app institucional. Pensado para las pruebas
                mientras se habilita el correo oficial.
            </x-slot>

            <div class="space-y-4">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" wire:model="carnetLogin" class="mt-1 h-4 w-4 rounded">
                    <span>
                        <span class="font-medium">Permitir ingreso con carné</span>
                        <span class="block text-sm text-gray-500 dark:text-gray-400">
                            Al apagarlo, la página del carné deja de existir de inmediato.
                        </span>
                    </span>
                </label>
            </div>

            <x-slot name="footer">
                <p class="text-sm text-amber-700 dark:text-amber-500">
                    Ten presente que el QR es una URL: una captura de pantalla sirve igual que el
                    carné original mientras el código no rote. Por eso esta puerta es temporal.
                </p>
            </x-slot>
        </x-filament::section>

        <div>
            <x-filament::button type="submit">Guardar</x-filament::button>
        </div>

    </form>

</x-filament-panels::page>
