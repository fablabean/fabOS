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
                $ultimas = $this->lasConsultas();
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

            {{-- El interruptor, a mano y no con una librería: son veinte
                 líneas y traerse una dependencia para un botón que se pinta
                 con dos divs es cargar con sus actualizaciones para siempre.

                 Los colores salen de las variables del panel, así que sigue
                 al tema claro y oscuro solo; los valores de respaldo son por
                 si un día se renombran. Y el CSS va aquí y no en clases de
                 Tailwind porque el panel usa la hoja ya compilada de
                 Filament: una clase escrita a mano puede no existir en ella
                 y no hacer nada, que es lo que pasó con el espaciado. --}}
            <style>
                .acerto-sw{
                    position:relative;display:inline-block;width:4.4rem;height:1.55rem;
                    padding:0;border:0;border-radius:999px;cursor:pointer;
                    transition:background-color .16s ease, opacity .16s ease;
                }
                .acerto-sw .bolita{
                    position:absolute;top:.175rem;width:1.2rem;height:1.2rem;border-radius:50%;
                    background:#fff;box-shadow:0 1px 3px rgba(0,0,0,.28);
                    transition:left .16s ease;
                }
                .acerto-sw .texto{
                    position:absolute;top:0;line-height:1.55rem;color:#fff;
                    font-size:.62rem;font-weight:800;letter-spacing:.08em;
                }
                /* Acertó: la bolita a la derecha, como en un interruptor que
                   está puesto. */
                .acerto-sw[data-estado="si"],
                .acerto-sw[data-estado="sin"]{background:var(--success-500,#10b981)}
                .acerto-sw[data-estado="si"] .bolita,
                .acerto-sw[data-estado="sin"] .bolita{left:calc(100% - 1.375rem)}
                .acerto-sw[data-estado="si"] .texto,
                .acerto-sw[data-estado="sin"] .texto{left:.7rem}
                /* Sin revisar: la misma posición, apagada. Nadie la ha mirado
                   todavía y eso no es lo mismo que darla por buena. */
                .acerto-sw[data-estado="sin"]{opacity:.45}
                .acerto-sw[data-estado="sin"]:hover{opacity:.75}
                /* No acertó. */
                .acerto-sw[data-estado="no"]{background:var(--danger-500,#ef4444)}
                .acerto-sw[data-estado="no"] .bolita{left:.175rem}
                .acerto-sw[data-estado="no"] .texto{right:.7rem}

                /* Los dos lados se pulsan, y cada uno lleva a su estado.

                   Con un solo punto de pulsación había que adivinar qué hacía:
                   desde «sin revisar» sólo se podía abrir la corrección, así
                   que decir «sí acertó» no tenía por dónde. Un interruptor
                   donde cada lado es el estado al que va se lee sin
                   instrucciones: se pulsa el lado que uno quiere. */
                .acerto-sw .mitad{
                    position:absolute;top:0;height:100%;width:50%;
                    padding:0;margin:0;border:0;background:transparent;cursor:pointer;
                }
                .acerto-sw .mitad.izq{left:0;border-radius:999px 0 0 999px}
                .acerto-sw .mitad.der{right:0;border-radius:0 999px 999px 0}
                .acerto-sw .mitad:focus-visible{outline:2px solid var(--primary-500,#0d6e63);outline-offset:2px}
            </style>

            {{-- Buscar y filtrar. Se guardan todas desde el primer día y sólo
                 se veían las 25 últimas, que es lo contrario de para qué
                 sirve esto: lo que dice qué curso falta o qué máquina nadie
                 encuentra no está en lo de esta semana, está en el montón. --}}
            <div style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;margin-bottom:1rem">
                <input type="search" wire:model.live.debounce.400ms="busca"
                       placeholder="Buscar en lo que escribieron…"
                       class="fi-input"
                       style="flex:1 1 18rem;min-width:0;padding:.45rem .7rem;font-size:.875rem;border-radius:.5rem;
                              border:1px solid var(--gray-300);background:transparent;color:inherit">

                <select wire:model.live="veredicto"
                        style="padding:.45rem .7rem;font-size:.875rem;border-radius:.5rem;
                               border:1px solid var(--gray-300);background:transparent;color:inherit">
                    <option value="">Todas</option>
                    <option value="sin">Sin revisar</option>
                    <option value="mal">Corregidas</option>
                    <option value="bien">Acertadas</option>
                </select>

                <span class="text-gray-500" style="font-size:.8rem">
                    {{ number_format($ultimas->total(), 0, ',', '.') }}
                    {{ $ultimas->total() === 1 ? 'consulta' : 'consultas' }}
                </span>
            </div>

            {{-- El espaciado va en `style` y no en clases de Tailwind.

                 El panel usa el CSS ya compilado de Filament, que solo trae
                 las utilidades que Filament usa: una clase escrita aquí puede
                 no existir en la hoja y entonces no hace nada. Es lo que pasó
                 con el relleno de las columnas —la fecha quedó pegada al
                 texto— y no se ve leyendo el Blade, porque la clase está ahí.
                 Lo escrito en línea no depende de eso. --}}
            <div class="overflow-x-auto">
                <table class="w-full text-sm" style="border-collapse:collapse">
                    <thead>
                        <tr class="text-left text-xs uppercase text-gray-500">
                            <th style="padding:.4rem 1.25rem .4rem 0;white-space:nowrap">Cuándo</th>
                            <th style="padding:.4rem 1.25rem .4rem 0">Lo que escribieron</th>
                            <th style="padding:.4rem 1.25rem .4rem 0">Se le sugirió</th>
                            <th style="padding:.4rem 1.25rem .4rem 0">Quién</th>
                            <th style="padding:.4rem 0;text-align:right;white-space:nowrap">¿Acertó?</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($ultimas as $c)
                            <tr class="border-t border-gray-200 dark:border-white/10" style="vertical-align:top">
                                <td class="text-gray-500" style="padding:.7rem 1.25rem .7rem 0;white-space:nowrap;font-variant-numeric:tabular-nums">
                                    {{ $c->created_at?->timezone($tz)->format('d/m H:i') }}
                                </td>
                                <td style="padding:.7rem 1.25rem .7rem 0">{{ $c->texto }}</td>

                                {{-- Sin `nowrap`: con él esta columna no podía
                                     encogerse y se comía la de al lado. --}}
                                <td style="padding:.7rem 1.25rem .7rem 0">
                                    @if ($c->acerto === false && $c->camino_corregido)
                                        <span class="text-gray-400" style="text-decoration:line-through">{{ $c->caminoLegible() }}</span>
                                        <span class="text-success-700 dark:text-success-400" style="display:block;font-weight:600">
                                            → {{ $caminos[$c->camino_corregido]['titulo'] ?? 'Ninguno' }}
                                        </span>
                                        @if ($c->nota)
                                            <span class="text-gray-500" style="display:block;font-size:.75rem;margin-top:.15rem">{{ $c->nota }}</span>
                                        @endif
                                    @else
                                        {{ $c->caminoLegible() }}
                                    @endif

                                    @if ($c->de_memoria)
                                        <span class="text-gray-500"
                                              style="display:inline-block;margin-top:.3rem;font-size:.72rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase"
                                              title="Se contestó de la memoria: no costó una llamada a la API">repetida</span>
                                    @endif
                                </td>

                                <td class="text-gray-500" style="padding:.7rem 1.25rem .7rem 0">{{ $c->user?->name ?? 'sin cuenta' }}</td>

                                {{-- El veredicto, en un interruptor de dos
                                     posiciones.

                                     Reposa en «acertó» porque casi siempre
                                     acierta: revisar es buscar las pocas que
                                     fallan, no confirmar las muchas que están
                                     bien. Con dos botones había que pulsar
                                     doscientas veces «bien» para que la lista
                                     dijera algo, y nadie lo iba a hacer.

                                     Sin tocar sigue valiendo `null` —«nadie la
                                     ha mirado»—, que es distinto de un
                                     «acertó» dicho por alguien, y por eso la
                                     posición se ve apagada hasta que se
                                     confirma. El filtro de arriba se apoya en
                                     esa diferencia. --}}
                                <td style="padding:.55rem 0;text-align:right;white-space:nowrap">
                                    @php
                                        $mal = $c->acerto === false;
                                        $estado = $c->acerto === null ? 'sin' : ($mal ? 'no' : 'si');
                                    @endphp

                                    {{-- Cada lado lleva a su estado: el
                                         izquierdo a «no acertó», el derecho a
                                         «acertó». Con un solo punto de
                                         pulsación había que adivinar qué hacía
                                         —y desde «sin revisar» no había manera
                                         de decir que sí acertó, porque el
                                         único clic abría la corrección—.

                                         El lado del «no» abre el modal en vez
                                         de cambiar el estado a secas: un «esto
                                         está mal» sin el «debió ser esto» no
                                         le sirve de nada a la guía, y lo que
                                         vuelve como ejemplo es el camino
                                         correcto. El del «sí» es directo. --}}
                                    <span class="acerto-sw" data-estado="{{ $estado }}">
                                        <span class="texto">{{ $mal ? 'NO' : 'SÍ' }}</span>
                                        <span class="bolita"></span>

                                        <button type="button" class="mitad izq"
                                                wire:click="mountAction('corregir', { consulta: {{ $c->id }} })"
                                                wire:loading.attr="disabled"
                                                aria-label="No acertó: decir con qué debió contestar"
                                                title="No acertó: decir con qué debió contestar"></button>

                                        <button type="button" class="mitad der"
                                                wire:click="acerto({{ $c->id }})"
                                                wire:loading.attr="disabled"
                                                aria-label="Acertó"
                                                title="{{ $mal ? 'Retirar la corrección: dejar de enseñársela' : 'Acertó' }}"></button>
                                    </span>

                                    @if ($c->acerto === null)
                                        <span class="text-gray-400" style="display:block;font-size:.66rem;margin-top:.2rem">sin revisar</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($ultimas->hasPages())
                <div style="margin-top:1rem">{{ $ultimas->links() }}</div>
            @endif

            <p class="text-sm mt-3 text-gray-500">
                En «¿Acertó?» se pulsa el lado al que quieres llevarlo: el <strong>derecho</strong>
                si la respuesta estuvo bien, el <strong>izquierdo</strong> si no —y ahí se dice con
                qué debió contestar—. Mientras nadie lo toque, la fila queda «sin revisar».
            </p>
            <p class="text-sm mt-2 text-gray-500">
                «Repetida» es una pregunta que ya se había hecho igual: se contestó de la
                memoria y no costó una llamada a la API. Quien escribe sin haber entrado
                queda sin identificar, y así se queda.
            </p>
            <p class="text-sm mt-2 text-gray-500">
                Corregir una no cambia lo que ya se le respondió a esa persona: cambia lo que
                la guía contesta de aquí en adelante, y también lo que tenía recordado.
            </p>
        </x-filament::section>
    @endif
</x-filament-panels::page>
