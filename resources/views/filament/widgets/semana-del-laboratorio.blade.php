<x-filament-widgets::widget>
    {{-- Plegable, y se acuerda: quien viene a la tabla a buscar una fila no
         tiene por qué bajar por la semana cada vez. --}}
    <x-filament::section
        heading="Semana del laboratorio"
        description="Qué está ocupado y quién responde por cada franja."
        collapsible
        persist-collapsed
        id="semana-del-laboratorio"
    >
        <div wire:loading.class="opacity-50">
            @include('partials.semana-del-laboratorio', ['s' => $s, 'livewire' => true])
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
