<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div>
            <x-filament::button type="submit">Guardar</x-filament::button>
        </div>
    </form>

    <x-filament::section>
        <x-slot name="heading">La guía con IA</x-slot>
        <x-slot name="description">
            Debajo del banner está la caja «escribe qué necesitas y te decimos por dónde». Usa la misma
            clave, modelo, interruptor y tope diario que el borrador de Preguntas (IA_ACTIVA, IA_MAX_POR_DIA
            en el .env del servidor). No es un chat: una pregunta, una respuesta, uno de los cuatro caminos.
        </x-slot>
        @php
            $guia = app(\App\Services\Ia\GuiaDeReservas::class);
            $fallo = $guia->ultimoFallo();
        @endphp
        <p class="text-sm">
            @if ($guia->disponible())
                Encendida · quedan <strong>{{ $guia->quedanHoy() }}</strong> preguntas hoy.
            @else
                Apagada: la caja no se muestra. Se enciende con IA_ACTIVA y la clave en el servidor.
            @endif
        </p>

        {{-- Por qué dejó de funcionar, si dejó.

             A quien escribe en la caja se le dice «no pudimos leerlo ahora»,
             que es lo que le sirve: nadie de fuera tiene que enterarse de cómo
             pagamos la API. Pero el laboratorio necesita la razón, y hasta
             ahora la única señal era un archivo de log en el servidor: se
             quedó un día entero sin saldo y nos enteramos porque alguien lo
             probó a mano. --}}
        @php
            $consultas = \App\Models\ConsultaDeGuia::query();
            $cuantas = (clone $consultas)->count();
        @endphp

        @if ($cuantas > 0)
            <p class="text-sm mt-1">
                <strong>{{ number_format($cuantas, 0, ',', '.') }}</strong>
                {{ $cuantas === 1 ? 'consulta' : 'consultas' }} en total ·
                {{ (clone $consultas)->where('created_at', '>=', now()->subDays(30))->count() }}
                en los últimos 30 días.
            </p>
        @endif

        @if ($fallo)
            <div class="mt-3 rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm
                        dark:border-warning-700 dark:bg-warning-950">
                <p class="font-semibold">El último intento falló</p>
                <p class="mt-1">{{ $fallo['motivo'] }}</p>
                <p class="mt-1 text-xs opacity-70">
                    {{ $fallo['cuando']->timezone(config('fabos.lab.timezone'))->format('d/m/Y H:i') }}
                    · mientras tanto, la caja le dice a quien escribe que pruebe otra vez o elija
                    un camino, y el resto de la página funciona igual.
                </p>
            </div>
        @endif
    </x-filament::section>

    {{-- Lo que nos preguntan.

         Es lo más valioso que produce la guía: la gente escribe con sus
         palabras qué quiere hacer —no lo que el catálogo le ofrece— y eso dice
         qué cursos faltan, qué máquina nadie encuentra y qué se pide y no
         tenemos. Guardado junto al camino sugerido, dice además si la guía
         está acertando. --}}
    @if ($cuantas > 0)
        <x-filament::section>
            <x-slot name="heading">Lo que nos preguntan</x-slot>
            <x-slot name="description">
                Lo que escribe quien llega, con sus palabras, y por dónde se le mandó.
                Léelo de vez en cuando: ahí salen los cursos que faltan y las máquinas
                que nadie encuentra.
            </x-slot>

            @php
                $porCamino = \App\Models\ConsultaDeGuia::porCamino();
                $caminos = \App\Services\Ia\GuiaDeReservas::CAMINOS;
                $ultimas = \App\Models\ConsultaDeGuia::with('user')->latest('id')->limit(25)->get();
                $tz = config('fabos.lab.timezone');
                $acierto = \App\Models\ConsultaDeGuia::comoVaAcertando();
            @endphp

            <div class="flex flex-wrap items-center gap-2 mb-4 text-sm">
                @foreach ($porCamino as $camino => $veces)
                    <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 bg-gray-100 dark:bg-white/10">
                        {{ $caminos[$camino]['titulo'] ?? 'Ninguno' }}
                        <strong class="tabular-nums">{{ $veces }}</strong>
                    </span>
                @endforeach
            </div>

            {{-- Cómo va acertando, aparte del recuento por camino: son dos
                 preguntas distintas —qué nos piden, y si le estamos dando la
                 respuesta correcta— y en la misma fila se leían como una. --}}
            @if ($acierto['bien'] + $acierto['mal'] > 0)
                <p class="text-sm mb-4 text-gray-600 dark:text-gray-400">
                    Revisadas:
                    <strong class="text-success-600 dark:text-success-400">{{ $acierto['bien'] }}</strong> bien ·
                    <strong class="text-danger-600 dark:text-danger-400">{{ $acierto['mal'] }}</strong> corregidas.
                    Las corregidas ya le están enseñando: viajan dentro de sus instrucciones como ejemplo.
                </p>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                            <th class="py-2 pr-6 font-medium whitespace-nowrap">Cuándo</th>
                            <th class="py-2 pr-6 font-medium">Lo que escribieron</th>
                            <th class="py-2 pr-6 font-medium">Se le sugirió</th>
                            <th class="py-2 pr-6 font-medium">Quién</th>
                            <th class="py-2 font-medium text-right whitespace-nowrap">¿Acertó?</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ultimas as $c)
                            <tr @class([
                                'align-top border-t border-gray-200 dark:border-white/10',
                                'bg-danger-50/50 dark:bg-danger-500/5' => $c->acerto === false,
                            ])>
                                <td class="py-3 pr-6 whitespace-nowrap text-gray-500 tabular-nums">
                                    {{ $c->created_at?->timezone($tz)->format('d/m H:i') }}
                                </td>
                                <td class="py-3 pr-6">{{ $c->texto }}</td>

                                {{-- Sin `nowrap`: con él esta columna no podía
                                     encogerse, se comía la de al lado y
                                     «repetida» acababa pegado a «sin cuenta».
                                     Y la etiqueta pasa a su propio renglón. --}}
                                <td class="py-3 pr-6">
                                    @if ($c->acerto === false && $c->camino_corregido)
                                        <span class="line-through text-gray-400">{{ $c->caminoLegible() }}</span>
                                        <span class="block font-medium text-success-700 dark:text-success-400">
                                            → {{ $caminos[$c->camino_corregido]['titulo'] ?? 'Ninguno' }}
                                        </span>
                                        @if ($c->nota)
                                            <span class="block text-xs text-gray-500 mt-0.5">{{ $c->nota }}</span>
                                        @endif
                                    @else
                                        {{ $c->caminoLegible() }}
                                    @endif

                                    @if ($c->de_memoria)
                                        <span class="mt-1 inline-block rounded px-1.5 py-0.5 text-xs bg-gray-100 dark:bg-white/10 text-gray-500"
                                              title="Se contestó de la memoria: no costó una llamada a la API">repetida</span>
                                    @endif
                                </td>

                                <td class="py-3 pr-6 text-gray-500">{{ $c->user?->name ?? 'sin cuenta' }}</td>

                                {{-- El veredicto. «Bien» es un clic; «Corregir»
                                     abre el modal, porque un «esto está mal»
                                     sin el «debió ser esto» no le sirve de nada
                                     a la guía: lo que vuelve como ejemplo es el
                                     camino correcto. --}}
                                <td class="py-3 text-right whitespace-nowrap">
                                    @if ($c->acerto === true)
                                        <span class="text-success-600 dark:text-success-400">Acertó</span>
                                    @elseif ($c->acerto === false)
                                        <span class="text-danger-600 dark:text-danger-400" title="Ya es un ejemplo dentro de sus instrucciones">Corregida</span>
                                    @else
                                        <span class="inline-flex gap-1 justify-end">
                                            <x-filament::button size="xs" color="gray"
                                                                wire:click="acerto({{ $c->id }})"
                                                                wire:loading.attr="disabled">
                                                Bien
                                            </x-filament::button>
                                            {{ ($this->corregirAction)(['consulta' => $c->id]) }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="text-sm mt-3 text-gray-500">
                Las 25 últimas. «Repetida» es una pregunta que ya se había hecho igual: se
                contestó de la memoria y no costó una llamada a la API. Quien escribe sin
                haber entrado queda sin identificar, y así se queda.
            </p>
            <p class="text-sm mt-2 text-gray-500">
                Corregir una no cambia lo que ya se le respondió a esa persona: cambia lo que
                la guía contesta de aquí en adelante, y también lo que tenía recordado.
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
