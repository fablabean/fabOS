<x-filament-panels::page>
    @php($sinPlan = $this->sinPlan())

    @if ($sinPlan > 0)
        <x-filament::section>
            <p style="margin:0">
                <strong>{{ $sinPlan }} {{ $sinPlan === 1 ? 'activo fijo' : 'activos fijos' }}</strong>
                todavía sin plan preventivo. Asígnalos a una franja abajo.
            </p>
        </x-filament::section>
    @endif

    <form wire:submit="save" style="display:flex;flex-direction:column;gap:1.5rem">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit">Guardar la pauta</x-filament::button>
        </div>
    </form>

    <p style="font-size:.85rem;opacity:.7;margin:0">
        Cada franja es un plan en Mantenimiento → Planes, donde se ve el historial de revisiones de cada
        equipo. Una franja sin equipos se apaga pero conserva su historial.
    </p>
</x-filament-panels::page>
