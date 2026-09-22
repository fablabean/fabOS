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
            El mismo archivo en los tres sitios, para que la marca no se abra en versiones.
        </x-slot>

        @php $logo = \App\Support\Settings::logo(); @endphp

        <ul class="text-sm space-y-1">
            <li>· La barra de arriba, en todas las páginas del sitio y del panel.</li>
            <li>· La propuesta en PDF que se le manda a quien encarga un trabajo.</li>
            <li>· El acuerdo de servicio y el acuerdo de alianza.</li>
        </ul>

        <p class="text-sm mt-3">
            @if ($logo)
                Ahora mismo se usa el logo subido.
                <strong>Los PDF ya generados no cambian</strong>: llevan el que había el día
                que se hicieron, que es lo correcto para un documento que alguien firmó.
            @else
                Ahora mismo se usa el que viene con el sistema
                (<code>{{ config('fabos.lab.logo') }}</code>).
            @endif
        </p>
    </x-filament::section>
</x-filament-panels::page>
