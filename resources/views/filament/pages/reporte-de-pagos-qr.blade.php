<x-filament-panels::page>

    @php
        $pesos = fn ($v) => config('fabos.money.symbol') . number_format((float) $v, 0, ',', '.');
    @endphp

    {{-- Estilos propios: el CSS de Filament viene compilado con un conjunto
         fijo de clases y las de una pagina a medida no se aplicarian. --}}
    <style>
        .rpq{display:flex;flex-direction:column;gap:1.5rem}
        .rpq .filtros{display:flex;flex-wrap:wrap;align-items:flex-end;gap:1rem}
        .rpq .filtros label span{display:block;margin-bottom:.25rem;font-size:.85rem;color:rgb(107 114 128)}
        .rpq .rejilla{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr))}
        .rpq .cifra b{display:block;font-size:1.6rem;letter-spacing:-.02em;line-height:1.1}
        .rpq .cifra span{font-size:.78rem;color:rgb(107 114 128)}
        .rpq .tabla{overflow-x:auto}
        .rpq table{width:100%;font-size:.86rem;border-collapse:collapse}
        .rpq th,.rpq td{text-align:left;padding:.4rem .5rem;border-bottom:1px solid rgba(128,128,128,.2);vertical-align:top}
        .rpq th{font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:rgb(107 114 128)}
        .rpq td.num,.rpq th.num{text-align:right;white-space:nowrap}
        .rpq .gris{color:rgb(107 114 128)}
    </style>

    <div class="rpq">

        <x-filament::section>
            <x-slot name="heading">Mes</x-slot>
            <x-slot name="description">
                Cuenta cada pago en el mes en que llegó el comprobante. Entran los validados y los que
                esperan validación; los pedidos sin comprobante y los devueltos no.
            </x-slot>

            <div class="filtros">
                <label>
                    <span>Mes</span>
                    <input type="month" wire:model.live="mes"
                           class="fi-input block rounded-lg border-gray-300 dark:border-white/10 dark:bg-white/5">
                </label>

                <x-filament::button wire:click="mesAnterior" color="gray">Mes anterior</x-filament::button>

                <x-filament::button wire:click="descargar" icon="heroicon-o-arrow-down-tray" :disabled="$filas->isEmpty()">
                    Descargar para Excel
                </x-filament::button>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Pagos por QR de {{ $nombreMes }}</x-slot>

            <div class="rejilla" style="margin-bottom:1rem">
                <div class="cifra"><b>{{ $filas->count() }}</b><span>pagos</span></div>
                <div class="cifra"><b>{{ $pesos($total) }}</b><span>en total</span></div>
                <div class="cifra"><b>{{ $pesos($validado) }}</b><span>validado</span></div>
                <div class="cifra"><b>{{ $pesos($porValidar) }}</b><span>por validar</span></div>
            </div>

            @if ($filas->isEmpty())
                <p class="text-sm gris">Ningún pago por QR este mes.</p>
            @else
                <div class="tabla">
                    <table>
                        <thead>
                            <tr><th>Fecha</th><th>Nombre</th><th>Documento</th><th>Qué hizo</th><th class="num">Valor</th><th>Estado</th></tr>
                        </thead>
                        <tbody>
                        @foreach ($filas as $f)
                            <tr>
                                <td>{{ $f['fecha']?->format('d/m/Y') }}</td>
                                <td>{{ $f['nombre'] ?: '—' }}</td>
                                <td>{{ $f['documento'] ?: '—' }}</td>
                                <td>
                                    @if ($f['pago']->project)
                                        <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('edit', ['record' => $f['pago']->project]) }}" class="underline">{{ $f['que'] }}</a>
                                    @else
                                        {{ $f['que'] }}
                                    @endif
                                    @if ($f['concepto'] !== '') <span class="gris">· {{ $f['concepto'] }}</span>@endif
                                </td>
                                <td class="num">{{ $pesos($f['valor']) }}</td>
                                <td>{{ $f['estado'] }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>

    </div>

</x-filament-panels::page>
