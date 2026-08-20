<x-filament-panels::page>

    {{-- Los estilos van aquí: Filament trae su CSS precompilado, así que las
         utilidades de rejilla de Tailwind que no use el propio Filament no
         existen en producción. --}}
    <style>
        .cp-aviso { border-left: 4px solid #b45309; background: rgba(180,83,9,.08);
                    padding: 1rem 1.15rem; border-radius: .5rem; }
        .cp-tabla { width: 100%; border-collapse: collapse; }
        .cp-tabla th, .cp-tabla td { padding: .7rem .6rem; text-align: left;
                    border-bottom: 1px solid rgba(128,128,128,.25); }
        .cp-tabla th { font-size: .78rem; text-transform: uppercase;
                    letter-spacing: .05em; opacity: .7; }
        .cp-codigo { font-family: ui-monospace, monospace; font-size: 1.35rem;
                    font-weight: 700; letter-spacing: .12em; }
        .cp-vacio { padding: 2rem 0; text-align: center; opacity: .65; }
        .cp-fila { display: flex; gap: .75rem; align-items: flex-end; flex-wrap: wrap; }
        .cp-campo { display: flex; flex-direction: column; gap: .35rem; }
        .cp-campo input { width: 7rem; padding: .45rem .6rem; border-radius: .5rem;
                    border: 1px solid rgba(128,128,128,.4); background: transparent; }
    </style>

    @if ($this->activa)

        <x-filament::section>
            <x-slot name="heading">Captura activa</x-slot>

            <div class="cp-aviso">
                <p><strong>Mientras esto esté encendido, quien entre aquí puede iniciar
                sesión como cualquier persona del laboratorio.</strong></p>
                <p style="margin-top:.5rem">
                    Se apaga sola el
                    <strong>{{ $this->hasta?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</strong>.
                    Cada vez que se enciende, se apaga o se consulta queda registrado.
                </p>
            </div>

            <div style="margin-top:1rem">
                <x-filament::button wire:click="desactivar" color="danger">
                    Apagar ahora
                </x-filament::button>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Códigos vigentes</x-slot>
            <x-slot name="description">
                Solo los emitidos desde que se encendió la captura, y solo mientras no caduquen.
            </x-slot>

            @php($codigos = $this->codigos)

            @if (empty($codigos))
                <p class="cp-vacio">
                    Todavía no hay ninguno. Pide un código desde
                    <code>/ingresar</code> y aparecerá aquí.
                </p>
            @else
                <div style="overflow-x:auto">
                    <table class="cp-tabla">
                        <thead>
                            <tr><th>Correo</th><th>Código</th><th>Caduca</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($codigos as $c)
                                <tr>
                                    <td>{{ $c['email'] }}</td>
                                    <td class="cp-codigo">{{ $c['codigo'] }}</td>
                                    <td>{{ $c['expira']->timezone(config('app.timezone'))->format('H:i:s') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div style="margin-top:1rem">
                <x-filament::button wire:click="$refresh" color="gray" outlined>
                    Actualizar
                </x-filament::button>
            </div>
        </x-filament::section>

    @else

        <x-filament::section>
            <x-slot name="heading">Captura apagada</x-slot>
            <x-slot name="description">
                Los códigos se guardan cifrados y nadie puede verlos. Esto es lo normal.
            </x-slot>

            <p>
                Enciéndela solo mientras el proveedor de correo no entregue a direcciones
                externas y las pruebas estén paradas por eso. Caduca sola, y como máximo
                dura una semana.
            </p>

            <div class="cp-fila" style="margin-top:1rem">
                <label class="cp-campo">
                    <span style="font-size:.8rem;opacity:.75">Durante cuántas horas</span>
                    <input type="number" wire:model="horas" min="1" max="168">
                </label>
                <x-filament::button wire:click="activar" color="warning">
                    Activar captura
                </x-filament::button>
            </div>
        </x-filament::section>

    @endif

</x-filament-panels::page>
