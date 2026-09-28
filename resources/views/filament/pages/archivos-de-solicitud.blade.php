<x-filament-panels::page>
    <form wire:submit="save" style="display:flex;flex-direction:column;gap:1.5rem">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit">Guardar</x-filament::button>
        </div>
    </form>
</x-filament-panels::page>
