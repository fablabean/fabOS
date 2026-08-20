<x-filament-panels::page>

    {{-- Estilos propios: el CSS de Filament no trae las utilidades de rejilla
         que usaria una pagina a medida, y sin esto las cifras se apilan. --}}
    <style>
        .cob{display:flex;flex-direction:column;gap:1.5rem}
        .cob .rejilla{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(11rem,1fr))}
        .cob dt{font-size:.8rem;color:rgb(107 114 128)}
        .cob dd{margin:0}
        .cob .grande{font-size:1.6rem;font-weight:600;letter-spacing:-.02em;line-height:1.2}
        .cob .pie{font-size:.75rem;color:rgb(107 114 128)}
    </style>


    @php
        $d = $this->pendientes();
        $moneda = config('fabos.currency.code');
    @endphp

    <div class="cob">

        <form wire:submit="save" class="cob">

        <x-filament::section>
            <x-slot name="heading">Cobrar de verdad</x-slot>
            <x-slot name="description">
                Mientras esté apagado, reservar y cerrar funcionan igual pero no mueven saldo.
                Las cotizaciones se calculan y se muestran; simplemente no se cobran.
            </x-slot>

            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" wire:model="cobrosActivos" class="mt-1 h-4 w-4 rounded">
                <span>
                    <span class="font-medium">Activar el cobro en {{ config('fabos.currency.name') }}s</span>
                    <span class="block text-sm text-gray-500 dark:text-gray-400">
                        Al reservar se retiene el depósito (o el estimado); al cerrar se liquida
                        lo realmente usado y la diferencia vuelve a la cuenta de la persona.
                    </span>
                </span>
            </label>

            @if ($d['tarifas'] > 0)
                <x-slot name="footer">
                    <p class="text-sm text-amber-700 dark:text-amber-500">
                        Hay {{ $d['tarifas'] }} de {{ $d['total'] }} tarifas marcadas como
                        <strong>supuestas</strong>. Se calcularon tomando como ancla una hora de
                        láser CO₂ = 20 {{ $moneda }}. Antes de encender el cobro conviene revisarlas
                        y decidir el ancla real: la proporción entre equipos ya está puesta, solo
                        cambia el multiplicador.
                    </p>
                </x-slot>
            @endif
        </x-filament::section>

            <div>
                <x-filament::button type="submit">Guardar</x-filament::button>
            </div>

        </form>

        <x-filament::section>
            <x-slot name="heading">Estado del libro</x-slot>
        <x-slot name="description">
            Todo saldo sale de sumar asientos. Estos cuatro números deben poder explicarse
            entre sí: lo emitido está o en manos de la gente, o retenido, o ya causado.
        </x-slot>

        <dl class="rejilla">
            <div>
                <dt>Emitido</dt>
                <dd class="grande">{{ $this->enFabcoins($d['emitido']) }}</dd>
                <dd class="pie">{{ $moneda }} entregados en dotaciones, bonificaciones y recargas</dd>
            </div>
            <div>
                <dt>Retenido</dt>
                <dd class="grande">{{ $this->enFabcoins($d['retenido']) }}</dd>
                <dd class="pie">comprometido por reservas que aún no cierran</dd>
            </div>
            <div>
                <dt>Causado</dt>
                <dd class="grande">{{ $this->enFabcoins($d['causado']) }}</dd>
                <dd class="pie">consumo real ya liquidado</dd>
            </div>
            <div>
                <dt>Cuentas abiertas</dt>
                <dd class="grande">{{ $d['personas'] }}</dd>
                <dd class="pie">se abren solas al primer movimiento</dd>
            </div>
        </dl>

        <x-slot name="footer">
            @if ($d['cadena']['intacta'])
                <p class="text-sm text-green-700 dark:text-green-500">
                    La cadena de sellos está intacta: ningún movimiento fue alterado desde que se escribió.
                </p>
            @else
                <p class="text-sm text-red-700 dark:text-red-500">
                    <strong>La cadena está rota en el movimiento #{{ $d['cadena']['rota_en'] }}.</strong>
                    Alguien editó el histórico por fuera del sistema. No conviene seguir operando
                    hasta entender qué pasó.
                </p>
            @endif
        </x-slot>
    </x-filament::section>
    </div>

</x-filament-panels::page>
