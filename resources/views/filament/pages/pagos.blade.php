<x-filament-panels::page>

    <style>
        .pag{display:flex;flex-direction:column;gap:1.5rem}
        .pag textarea{width:100%;font-family:inherit;font-size:.95rem;line-height:1.5;padding:.55rem .75rem;border-radius:.5rem;border:1px solid rgb(209 213 219);background:transparent}
        .pag .qr{display:flex;gap:1.5rem;align-items:flex-start;flex-wrap:wrap}
        .pag .qr img{width:14rem;max-width:100%;border:1px solid rgb(229 231 235);border-radius:.5rem;background:#fff;padding:.5rem}
        .pag table{width:100%;font-size:.85rem;border-collapse:collapse}
        .pag th,.pag td{text-align:left;padding:.4rem .5rem;border-bottom:1px solid rgb(229 231 235);vertical-align:top}
    </style>

    <div class="pag">
        <form wire:submit="save" class="pag">
            <x-filament::section>
                <x-slot name="heading">El código QR del banco</x-slot>
                <x-slot name="description">
                    Uno solo para todo el laboratorio. Va adjunto en cada correo de cobro y se ve en la
                    página del proyecto junto al valor a pagar. Súbelo como imagen, tal como lo entrega
                    el banco.
                </x-slot>

                <div class="qr">
                    @if ($this->qrActual())
                        <img src="{{ $this->qrActual() }}" alt="QR de pagos actual">
                    @else
                        <p class="text-sm text-amber-700 dark:text-amber-500">Todavía no hay QR. Sin él no se puede pedir un pago.</p>
                    @endif
                    <div>
                        <label class="block text-sm font-medium" for="qr">{{ $this->qrActual() ? 'Reemplazar el QR' : 'Subir el QR' }}</label>
                        <input id="qr" type="file" wire:model="qr" accept="image/*" class="block text-sm">
                        @error('qr') <p class="text-sm text-red-600">{{ $message }}</p> @enderror
                        <div wire:loading wire:target="qr" class="text-sm text-gray-500">Subiendo…</div>
                    </div>
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Lo que le decimos a quien paga</x-slot>
                <x-slot name="description">Va en el correo y en la página del proyecto, encima del QR.</x-slot>
                <textarea wire:model="instrucciones" rows="3"></textarea>
            </x-filament::section>

            <div>
                <x-filament::button type="submit">Guardar</x-filament::button>
            </div>
        </form>

        <x-filament::section>
            <x-slot name="heading">Pagos que esperan algo</x-slot>
            <x-slot name="description">Primero los comprobantes por validar; después los pagos pedidos sin respuesta. Se validan en cada proyecto, en la pestaña Pagos.</x-slot>

            @php $pendientes = $this->pendientes(); @endphp
            @if ($pendientes->isEmpty())
                <p class="text-sm text-gray-500">Nada pendiente.</p>
            @else
                <table>
                    <thead><tr><th>Proyecto</th><th>Valor</th><th>Estado</th><th>Pagó</th><th>Pedido</th></tr></thead>
                    <tbody>
                    @foreach ($pendientes as $p)
                        <tr>
                            <td>
                                <a href="{{ \App\Filament\Resources\Projects\ProjectResource::getUrl('edit', ['record' => $p->project]) }}" class="underline">
                                    {{ $p->project?->code }} · {{ $p->project?->name }}
                                </a>
                            </td>
                            <td>{{ $p->valorFormateado() }}@if ($p->concept) <span class="text-gray-500">· {{ $p->concept }}</span>@endif</td>
                            <td>{{ \App\Models\ProjectPayment::ESTADOS[$p->status] ?? $p->status }}</td>
                            <td>{{ $p->payer_name ?? '—' }}@if ($p->payer_document) <span class="text-gray-500">· {{ $p->payer_document }}</span>@endif</td>
                            <td>{{ $p->requested_at?->timezone(config('fabos.lab.timezone'))->format('d/m/Y') }} <span class="text-gray-500">{{ $p->requestedBy?->name }}</span></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    </div>

</x-filament-panels::page>
