<x-filament-panels::page>

    <style>
        .ben{display:flex;flex-direction:column;gap:1.5rem}
        .ben input[type=text],.ben textarea{width:100%;font-family:inherit;font-size:.95rem;padding:.55rem .75rem;border-radius:.5rem;border:1px solid rgb(209 213 219);background:transparent}
        .ben textarea{font-family:ui-monospace,Consolas,monospace;font-size:.9rem;line-height:1.5}
        .ben .rejilla{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr))}
        .ben dt{font-size:.8rem;color:rgb(107 114 128)}
        .ben dd{margin:0}
        .ben .grande{font-size:1.6rem;font-weight:600;letter-spacing:-.02em;line-height:1.2}
        .ben table{width:100%;font-size:.85rem;border-collapse:collapse}
        .ben th,.ben td{text-align:left;padding:.35rem .5rem;border-bottom:1px solid rgb(229 231 235)}
        .ben td.n,.ben th.n{text-align:right;font-variant-numeric:tabular-nums}
        .ben .botones{display:flex;gap:.75rem;align-items:center;flex-wrap:wrap}
    </style>

    @php
        $v = $this->vistaPrevia();
        $moneda = config('fabos.currency.code');
    @endphp

    <div class="ben">
        <form wire:submit="save" class="ben">

            <x-filament::section>
                <x-slot name="heading">La regla</x-slot>
                <x-slot name="description">
                    Cada lunes a primera hora, a quien tenga correo de una institución aliada se le
                    completa el saldo hasta el tope. No se acumula: quien ya tiene el tope o más no
                    recibe nada, y lo que no gastó sigue ahí. Los {{ config('fabos.currency.name') }}s
                    no se cambian por dinero.
                </x-slot>

                <label class="flex items-start gap-3 cursor-pointer" style="margin-bottom:1rem">
                    <input type="checkbox" wire:model="activo" class="mt-1 h-4 w-4 rounded">
                    <span>
                        <span class="font-medium">Beneficio semanal activo</span>
                        <span class="block text-sm text-gray-500 dark:text-gray-400">
                            Apagado, no se abona nada, ni el lunes ni con el botón de abajo.
                        </span>
                    </span>
                </label>

                <div class="rejilla">
                    <div>
                        <label class="block text-sm font-medium" for="tope">Tope semanal, en {{ $moneda }}</label>
                        <input id="tope" type="text" wire:model="tope" inputmode="decimal">
                    </div>
                    <div>
                        <label class="block text-sm font-medium" for="equivalencias">A cuánto material equivale</label>
                        <input id="equivalencias" type="text" wire:model="equivalencias">
                        <p class="text-sm text-gray-500 dark:text-gray-400" style="margin-top:.3rem">
                            Se dice, no se calcula. Sale al cerrar una producción, para que quien la cierra
                            sepa hasta dónde llega lo que ya está pagado.
                        </p>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Instituciones aliadas</x-slot>
                <x-slot name="description">
                    Los dominios de correo con derecho al beneficio, uno por línea. Quien tenga
                    una cuenta con correo de alguno de estos dominios entra en la regla.
                </x-slot>

                <textarea wire:model="dominios" rows="5" placeholder="universidadean.edu.co"></textarea>
            </x-filament::section>

            <div class="botones">
                <x-filament::button type="submit">Guardar</x-filament::button>
                <x-filament::button color="gray" wire:click="aplicarAhora"
                    wire:confirm="¿Aplicar el beneficio de esta semana ahora? Se completa el saldo de quien esté por debajo del tope; a quien ya lo recibió esta semana no se le repite.">
                    Aplicar esta semana ahora
                </x-filament::button>
            </div>
        </form>

        <x-filament::section>
            <x-slot name="heading">Lo que pasaría ahora mismo</x-slot>
            <x-slot name="description">
                Semana {{ $v['semana'] }}. Se calcula con los saldos de este momento; el lunes puede
                variar si alguien gasta entre tanto.
            </x-slot>

            <dl class="rejilla" style="margin-bottom:1rem">
                <div><dt>Con derecho</dt><dd class="grande">{{ $v['personas'] }}</dd></div>
                <div><dt>Recibirían</dt><dd class="grande">{{ $v['abonos'] }}</dd></div>
                <div><dt>Ya completos</dt><dd class="grande">{{ $v['completas'] }}</dd></div>
                <div><dt>Se abonaría</dt><dd class="grande">{{ $this->enFabcoins($v['total']) }} <span style="font-size:.9rem;font-weight:400">{{ $moneda }}</span></dd></div>
            </dl>

            @if ($v['filas'] !== [])
                <div style="max-height:24rem;overflow:auto">
                    <table>
                        <thead><tr><th>Persona</th><th>Correo</th><th class="n">Saldo</th><th class="n">Se abonaría</th></tr></thead>
                        <tbody>
                        @foreach ($v['filas'] as $fila)
                            <tr>
                                <td>{{ $fila['persona']->name }}</td>
                                <td style="font-family:ui-monospace,Consolas,monospace;font-size:.8rem">{{ $fila['persona']->email }}</td>
                                <td class="n">{{ $this->enFabcoins($fila['saldo']) }}</td>
                                <td class="n">{{ $fila['abono'] > 0 ? '+' . $this->enFabcoins($fila['abono']) : '—' }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-gray-500">Nadie tiene correo de esos dominios todavía.</p>
            @endif
        </x-filament::section>
    </div>

</x-filament-panels::page>
