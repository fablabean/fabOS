<x-filament-panels::page>
    <p class="text-sm">
        Se publica en
        <a href="{{ route('fab-academy') }}" target="_blank" rel="noopener"
           class="font-medium text-primary-600 dark:text-primary-400 hover:underline">{{ route('fab-academy') }}</a>.
        Las fechas, el valor y el conteo de preinscritos salen de la cohorte (Formación → Ediciones);
        aquí va lo que cuenta el programa.
    </p>

    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex gap-3">
            <x-filament::button type="submit">Guardar</x-filament::button>
            <x-filament::button color="gray" wire:click="restablecer"
                                wire:confirm="¿Volver a los textos de fábrica? Se pierden las fotos, los proyectos y el equipo que se hayan cargado aquí.">
                Restablecer
            </x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
