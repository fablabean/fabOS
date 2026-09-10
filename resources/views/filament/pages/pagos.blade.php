<x-filament-panels::page>

    <style>
        .pag table{width:100%;font-size:.85rem;border-collapse:collapse}
        .pag th,.pag td{text-align:left;padding:.4rem .5rem;border-bottom:1px solid rgb(229 231 235);vertical-align:top}
    </style>

    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit">Guardar</x-filament::button>
        </div>
    </form>

    <div class="pag" style="margin-top:1.5rem">
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
