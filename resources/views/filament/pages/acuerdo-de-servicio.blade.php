<x-filament-panels::page>

    <style>
        .acu{display:flex;flex-direction:column;gap:1.5rem}
        .acu textarea{width:100%;font-family:inherit;font-size:.9rem;line-height:1.5;padding:.6rem .75rem;border-radius:.5rem;border:1px solid rgb(209 213 219);background:transparent}
        .acu .vars{display:grid;gap:.25rem .9rem;grid-template-columns:repeat(auto-fit,minmax(16rem,1fr));font-size:.85rem}
        .acu .vars code{font-weight:600}
        .acu .vars span{color:rgb(107 114 128)}
        .acu .botones{display:flex;gap:.75rem;align-items:center}
    </style>

    <div class="acu">
        <form wire:submit="save" class="acu">

            <x-filament::section>
                <x-slot name="heading">Las cláusulas</x-slot>
                <x-slot name="description">
                    Es el texto que sale en cada acuerdo. Lo que va entre llaves se rellena solo con lo
                    del proyecto; se puede corregir todo antes de generar cada uno. Separa las cláusulas
                    con una línea en blanco; si empiezan por «3. Plazo.», el número y el título salen en negrita.
                </x-slot>

                <textarea wire:model="clausulas" rows="22"></textarea>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Forma de pago</x-slot>
                <x-slot name="description">
                    Va dentro de la cláusula de valor, en {forma_pago}. También admite las llaves de abajo.
                </x-slot>

                <textarea wire:model="formaDePago" rows="3"></textarea>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Lo que se puede escribir entre llaves</x-slot>

                <div class="vars">
                    @foreach ($this->variables() as $clave => $que)
                        <div><code>{{ '{' . $clave . '}' }}</code> <span>{{ $que }}</span></div>
                    @endforeach
                </div>
            </x-filament::section>

            <div class="botones">
                <x-filament::button type="submit">Guardar la base</x-filament::button>
                <x-filament::button color="gray" wire:click="restablecer" wire:confirm="¿Volver al texto que trae el sistema? Se pierde lo escrito aquí.">
                    Restablecer
                </x-filament::button>
            </div>
        </form>
    </div>

</x-filament-panels::page>
