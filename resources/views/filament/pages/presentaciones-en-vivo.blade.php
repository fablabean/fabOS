<x-filament-panels::page>
    <div wire:poll.5s class="grid gap-6">
        @foreach ($salas as $s)
            <x-filament::section>
                <x-slot name="heading">{{ $s['titulo'] }}</x-slot>
                <x-slot name="description">
                    {{ $s['vivos'] }} {{ $s['vivos'] === 1 ? 'persona conectada' : 'personas conectadas' }}
                    · {{ $s['agenda']['inicio'] ? 'empezó a las ' . \Illuminate\Support\Carbon::createFromTimestampMs($s['agenda']['inicio'])->setTimezone(config('fabos.lab.timezone'))->format('g:i a') . ' · ' . count($s['agenda']['listos']) . ' bloques cerrados' : 'sin empezar' }}
                </x-slot>

                <div class="grid gap-4">
                    @if ($s['profe'])
                        <div class="flex flex-wrap gap-3 items-center">
                            <x-filament::button tag="a" :href="$s['profe']" target="_blank" icon="heroicon-o-play">
                                Abrir como profesor
                            </x-filament::button>
                            <x-filament::button tag="a" :href="$s['publico']" target="_blank" color="gray" icon="heroicon-o-users">
                                Enlace para participantes
                            </x-filament::button>
                            <span class="text-sm text-gray-500">Comparte solo el enlace de participantes: <code class="text-xs">{{ $s['publico'] }}</code></span>
                        </div>
                    @else
                        <p class="text-sm text-danger-600">No encontré el módulo de esta presentación en las zonas.</p>
                    @endif

                    <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($s['votos'] as $pregunta => $cuentas)
                            <div class="rounded-lg border border-gray-200 dark:border-white/10 p-3 text-sm">
                                <div class="font-semibold mb-1">{{ $pregunta }}</div>
                                <div class="text-gray-500">{{ implode(' · ', $cuentas) }} <span class="text-xs">(total {{ array_sum($cuentas) }})</span></div>
                            </div>
                        @endforeach
                    </div>

                    <div class="flex flex-wrap gap-3">
                        <x-filament::button color="warning" size="sm" icon="heroicon-o-arrow-path"
                            wire:click="reiniciarVotos('{{ $s['sala'] }}')"
                            wire:confirm="¿Poner todos los votos en cero?">
                            Reiniciar votos
                        </x-filament::button>
                        <x-filament::button color="gray" size="sm" icon="heroicon-o-clock"
                            wire:click="reiniciarAgenda('{{ $s['sala'] }}')"
                            wire:confirm="¿Reiniciar la agenda (inicio y bloques cerrados)?">
                            Reiniciar agenda
                        </x-filament::button>
                    </div>
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
