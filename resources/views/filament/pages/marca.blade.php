<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit">Guardar</x-filament::button>
        </div>
    </form>

    <x-filament::section>
        <x-slot name="heading">Dónde sale</x-slot>
        <x-slot name="description">
            Cada versión donde le sienta la forma. Con una sola subida, esa vale para todo.
        </x-slot>

        @php
            $logo = \App\Support\Settings::logo();
            $largo = \App\Support\Settings::logoLargo();
        @endphp

        <ul class="text-sm space-y-1">
            <li><strong>Larga</strong> · la barra del sitio y del panel en pantalla de trabajo.</li>
            <li><strong>Larga</strong> · la cabecera de la propuesta en PDF, el acuerdo de
                servicio y el de alianza: una cabecera es ancha y baja, que es su forma.</li>
            <li><strong>Compacta</strong> · la barra en el móvil, por debajo de 640 píxeles.</li>
            <li><strong>Icono</strong> · la pestaña del navegador, en el sitio y en el panel.
                Sin icono propio se usa la compacta, y sin compacta la larga.</li>
            <li><strong>Para fondo oscuro</strong> · las mismas dos, cuando quien mira tiene el
                sistema en modo oscuro. Con un color fijo en la barra manda ese color y no el
                sistema, porque el color es el mismo para todo el mundo.</li>
        </ul>

        <p class="text-sm mt-3">
            En los PDF sale siempre la versión clara: el papel es blanco.
        </p>

        <p class="text-sm mt-3">
            @if ($largo || $logo)
                <strong>Los PDF ya generados no cambian</strong>: llevan el logo que había el
                día que se hicieron, que es lo correcto para un documento que alguien firmó.
            @else
                Ahora mismo se usa el que viene con el sistema
                (<code>{{ config('fabos.lab.logo') }}</code>).
            @endif
        </p>
    </x-filament::section>
</x-filament-panels::page>
