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
            @endphp

            <div class="flex flex-wrap gap-2 mb-4">
                @foreach ($porCamino as $camino => $veces)
                    <span class="rounded-full px-3 py-1 text-sm bg-gray-100 dark:bg-gray-800">
                        {{ $caminos[$camino]['titulo'] ?? 'Ninguno' }}
                        <strong>{{ $veces }}</strong>
                    </span>
                @endforeach
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-gray-500">
                            <th class="py-2 pr-3 font-medium">Cuándo</th>
                            <th class="py-2 pr-3 font-medium">Lo que escribieron</th>
                            <th class="py-2 pr-3 font-medium">Se le sugirió</th>
                            <th class="py-2 font-medium">Quién</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ultimas as $c)
                            <tr class="border-t border-gray-200 dark:border-gray-700 align-top">
                                <td class="py-2 pr-3 whitespace-nowrap text-gray-500">
                                    {{ $c->created_at?->timezone($tz)->format('d/m H:i') }}
                                </td>
                                <td class="py-2 pr-3">{{ $c->texto }}</td>
                                <td class="py-2 pr-3 whitespace-nowrap">
                                    {{ $c->caminoLegible() }}
                                    @if ($c->de_memoria)
                                        <span class="text-gray-400" title="Se contestó de la memoria: no costó una llamada a la API">·&nbsp;repetida</span>
                                    @endif
                                </td>
                                <td class="py-2 text-gray-500">{{ $c->user?->name ?? 'sin cuenta' }}</td>
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
        </x-filament::section>
    @endif
</x-filament-panels::page>
