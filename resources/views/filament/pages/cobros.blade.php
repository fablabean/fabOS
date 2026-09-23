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

        {{-- La tienda por su cuenta. Son dos decisiones: cobrar una reserva
             depende de tarifas en duda; cobrar un filamento es un precio que
             ya está puesto. Con un solo interruptor la gente compraba «con
             FabCoins» sin que se le descontara nada. --}}
        <x-filament::section>
            <x-slot name="heading">La tienda por su cuenta</x-slot>
            <x-slot name="description">
                Los precios de la tienda ya están puestos: no dependen de las tarifas de los
                equipos. Se puede cobrar ahí sin encender el cobro de las reservas.
            </x-slot>

            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" wire:model="cobrosTienda" class="mt-1 h-4 w-4 rounded">
                <span>
                    <span class="font-medium">Cobrar en la tienda en {{ config('fabos.currency.name') }}s</span>
                    <span class="block text-sm text-gray-500 dark:text-gray-400">
                        Comprar descuenta el saldo de la persona y anular lo devuelve. Si el cobro
                        general está activo, la tienda cobra de todos modos.
                    </span>
                </span>
            </label>
        </x-filament::section>

        {{-- Prestar una herramienta no es ocupar una máquina. Las tarifas se
             pensaron para máquinas y las herramientas heredan la de su familia
             de riesgo, que se tarifó junto a ellas: un multímetro acababa
             cobrando la tarifa base del laboratorio, más cara que una
             impresora 3D. --}}
        <x-filament::section>
            <x-slot name="heading">El préstamo de herramientas</x-slot>
            <x-slot name="description">
                Prestar un multímetro o un taladro no ocupa una máquina ni gasta nada, y
                cobrarlo solo desanima a pedirlo. Esto lo pone en cero sin tener que tarifar
                una por una las {{ \App\Models\Asset::where('kind', 'herramienta')->where('is_reservable', true)->count() }}
                herramientas que se prestan.
            </x-slot>

            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" wire:model="prestamoGratis" class="mt-1 h-4 w-4 rounded">
                <span>
                    <span class="font-medium">No cobrar por prestar una herramienta</span>
                    <span class="block text-sm text-gray-500 dark:text-gray-400">
                        Ni tiempo, ni montaje, ni mínimo, ni depósito. El material que se gaste
                        se cobra igual: ese sí se consume.
                        <strong>La excepción se dice con una tarifa propia</strong>: si un equipo
                        tiene la suya en <em>Finanzas → Tarifas</em> —no la heredada de su
                        familia—, se cobra aunque esto esté encendido. Es como se deja cobrando
                        lo que sí debe costar: las gafas de realidad virtual, el robot.
                    </span>
                </span>
            </label>

            <label class="flex items-start gap-3 cursor-pointer mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
                <input type="checkbox" wire:model="prestamoSinCertifab" class="mt-1 h-4 w-4 rounded">
                <span>
                    <span class="font-medium">Tampoco exigir certifab para prestarla</span>
                    <span class="block text-sm text-gray-500 dark:text-gray-400">
                        El certifab dice que alguien te vio operar una máquina, y un multímetro
                        no es una máquina. <strong>La excepción va en la ficha del equipo</strong>,
                        campo «Exige certifab»: así se deja pidiéndolo lo que no se le entrega a
                        cualquiera —el robot— sin abrir también las máquinas fijas que comparten
                        familia de riesgo con una herramienta.
                    </span>
                </span>
            </label>
        </x-filament::section>

        {{-- La asesoría tiene precio plano: lo que se paga es el tiempo de
             alguien del equipo, no el de una máquina. Se retiene al pedirla y
             se causa cuando quien atiende valida que la persona vino. --}}
        <x-filament::section>
            <x-slot name="heading">La asesoría</x-slot>
            <x-slot name="description">
                Un precio por asesoría, dure lo que dure. Se retiene al pedirla y se cobra cuando
                quien atiende valida la llegada; si la persona no viene o no la atienden, vuelve.
                Solo mueve saldo con el cobro general activo.
            </x-slot>

            <div class="max-w-xs">
                <label for="precioAsesoria" class="block text-sm font-medium mb-1">
                    Precio en {{ config('fabos.currency.name') }}s
                </label>
                <x-filament::input.wrapper>
                    <x-filament::input id="precioAsesoria" type="number" min="0" step="0.5" wire:model="precioAsesoria" />
                </x-filament::input.wrapper>
                @error('precioAsesoria')
                    <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                @enderror
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Cero: la asesoría es gratis.</p>
            </div>
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
