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

        <x-filament::section>
            <x-slot name="heading">Asistente del bootcamp</x-slot>
            <x-slot name="description">
                Solo entra quien tenga su ID. Cada ID tiene su tiempo al día, desde su primer mensaje; al otro día vuelve a tenerlo.
            </x-slot>
            <div class="flex flex-wrap gap-4 items-end">
                <div class="flex items-center gap-3">
                    <x-filament::badge :color="$asistenteActivo ? 'success' : 'gray'">{{ $asistenteActivo ? 'Prendido' : 'Apagado' }}</x-filament::badge>
                    <x-filament::button size="sm" :color="$asistenteActivo ? 'danger' : 'success'" wire:click="alternarAsistente"
                        :icon="$asistenteActivo ? 'heroicon-o-pause' : 'heroicon-o-play'">
                        {{ $asistenteActivo ? 'Apagar el asistente' : 'Prender el asistente' }}
                    </x-filament::button>
                </div>
                <form wire:submit="guardarMinutos" class="flex items-end gap-2">
                    <label class="grid gap-1 text-sm">
                        <span class="font-medium">Minutos por ID al día</span>
                        <x-filament::input.wrapper class="w-28">
                            <x-filament::input type="number" min="5" max="600" wire:model="minutosAsistente" />
                        </x-filament::input.wrapper>
                    </label>
                    <x-filament::button type="submit" size="sm" color="gray">Guardar</x-filament::button>
                </form>
                <x-filament::button tag="a" href="{{ route('asistente.agentic') }}" target="_blank" size="sm" color="gray" icon="heroicon-o-arrow-top-right-on-square">
                    Abrir el asistente
                </x-filament::button>
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Participantes con ID ({{ $totalParticipantes }})</x-slot>
            <x-slot name="description">
                Quien entra a una presentación que pide nombre recibe un ID de 4 caracteres que sirve en todo el curso.
                Te lo escriben por LinkedIn y aquí ves quién es.
            </x-slot>

            <div class="grid gap-4">
                <form wire:submit="guardarLinkedin" class="flex flex-wrap gap-3 items-end">
                    <label class="grid gap-1 text-sm flex-1 min-w-[18rem]">
                        <span class="font-medium">Tu LinkedIn (lo ven al recibir su ID)</span>
                        <x-filament::input.wrapper>
                            <x-filament::input type="url" wire:model="linkedin" placeholder="https://www.linkedin.com/in/…" />
                        </x-filament::input.wrapper>
                    </label>
                    <x-filament::button type="submit" size="sm" icon="heroicon-o-check">Guardar</x-filament::button>
                </form>

                <x-filament::input.wrapper class="max-w-sm">
                    <x-filament::input type="search" wire:model.live.debounce.400ms="buscar" placeholder="Buscar por ID o nombre" />
                </x-filament::input.wrapper>

                @if ($participantes->isEmpty())
                    <p class="text-sm text-gray-500">{{ $buscar !== '' ? 'Nadie con ese ID o nombre.' : 'Todavía nadie ha recibido su ID.' }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-gray-500 border-b border-gray-200 dark:border-white/10">
                                    <th class="py-2 pr-4">ID</th><th class="py-2 pr-4">Nombre</th><th class="py-2 pr-4">Curso</th><th class="py-2 pr-4">Desde</th><th class="py-2 pr-4">Asistente hoy</th><th></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($participantes as $p)
                                    <tr class="border-b border-gray-100 dark:border-white/5">
                                        <td class="py-2 pr-4 font-mono font-bold tracking-widest">{{ $p->codigo }}</td>
                                        <td class="py-2 pr-4">{{ $p->nombre }}</td>
                                        <td class="py-2 pr-4 text-gray-500">{{ $p->grupo }} · {{ $p->sala }}</td>
                                        <td class="py-2 pr-4 text-gray-500">{{ $p->created_at->setTimezone(config('fabos.lab.timezone'))->format('d/m/Y g:i a') }}</td>
                                        @php($uso = $acceso->hoy($p->codigo))
                                        <td class="py-2 pr-4 text-gray-500 whitespace-nowrap">
                                            @if ($uso['empezo'])
                                                <span @class(['text-danger-600 font-semibold' => $uso['agotado']])>{{ $uso['usados'] }} de {{ $minutosAsistente }} min</span> · {{ $uso['mensajes'] }} mensajes
                                                <button type="button" class="ml-1 underline text-xs" wire:click="devolverHora('{{ $p->codigo }}')">devolver</button>
                                            @else
                                                —
                                            @endif
                                        </td>
                                        <td class="py-2 text-right">
                                            <x-filament::icon-button icon="heroicon-o-trash" color="danger" size="sm" label="Quitar"
                                                wire:click="quitarParticipante({{ $p->id }})"
                                                wire:confirm="¿Quitar a {{ $p->nombre }} ({{ $p->codigo }})?" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
