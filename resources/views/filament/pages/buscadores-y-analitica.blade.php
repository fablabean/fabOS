<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center">
            <x-filament::button type="submit">Guardar</x-filament::button>
            <x-filament::link :href="route('buscadores.sitemap')" target="_blank">sitemap.xml ↗</x-filament::link>
            <x-filament::link :href="route('buscadores.robots')" target="_blank">robots.txt ↗</x-filament::link>
            <x-filament::link :href="route('buscadores.llms')" target="_blank">llms.txt ↗</x-filament::link>
            <x-filament::link :href="\App\Filament\Pages\GuiaBuscadoresYAnalitica::getUrl()">Cómo funciona todo esto →</x-filament::link>
        </div>
    </form>
</x-filament-panels::page>
