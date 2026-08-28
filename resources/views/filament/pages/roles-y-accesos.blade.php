<x-filament-panels::page>

    {{-- Estilos propios: una matriz de cuarenta secciones por cuatro roles y
         cuatro acciones no sale de las utilidades que trae Filament. --}}
    <style>
        .rya .marco { overflow-x:auto; }
        .rya table { width:100%; border-collapse:collapse; font-size:.88rem; }
        .rya th, .rya td { padding:.4rem .5rem; text-align:left; }
        .rya thead th { font-size:.75rem; text-transform:uppercase; letter-spacing:.06em;
                        color:rgb(107 114 128); border-bottom:1px solid rgb(229 231 235); }
        .rya th.rol { text-align:center; }
        .rya td.celda { text-align:center; white-space:nowrap; }
        .rya tbody tr { border-bottom:1px solid rgb(243 244 246); }
        .rya tbody tr:hover { background:rgb(249 250 251); }
        .rya .grupo td { padding-top:1.1rem; font-size:.75rem; text-transform:uppercase;
                         letter-spacing:.08em; color:rgb(107 114 128); font-weight:600; }
        .rya .grupo:hover { background:none; }
        .rya .atajos { font-size:.7rem; color:rgb(107 114 128); text-transform:none;
                       letter-spacing:0; text-align:center; }
        .rya .atajos button { background:none; border:none; cursor:pointer; padding:0 .15rem;
                              color:rgb(217 119 6); font-size:.7rem; }
        .rya .seccion small { display:block; color:rgb(156 163 175); font-size:.72rem; }

        /* Las cuatro acciones dentro de la celda del rol. Cada una con su
           letra: cuatro casillas desnudas no dicen cual es cual, y acertar
           por posicion es como se marca la equivocada. */
        .rya .acciones { display:inline-flex; gap:.5rem; }
        .rya .acciones label { display:inline-flex; flex-direction:column; align-items:center;
                               gap:.1rem; font-size:.62rem; color:rgb(156 163 175);
                               text-transform:uppercase; letter-spacing:.04em; cursor:pointer; }
        .rya .acciones input { width:1rem; height:1rem; cursor:pointer; margin:0; }
        .rya .acciones .ver label { color:rgb(107 114 128); }
        .rya .leyenda { font-size:.8rem; color:rgb(107 114 128); margin:0 0 .8rem; }
        .dark .rya thead th { border-color:rgb(55 65 81); }
        .dark .rya tbody tr { border-color:rgb(31 41 55); }
        .dark .rya tbody tr:hover { background:rgb(31 41 55); }
    </style>

    <div class="rya">
        <x-filament::section>
            <x-slot name="heading">Qué ve y qué toca cada rol</x-slot>
            <x-slot name="description">
                Cada sección del menú, con cuatro casillas por rol:
                <strong>V</strong>er, <strong>C</strong>rear, <strong>E</strong>ditar,
                <strong>B</strong>orrar. Lo que aquí se apaga no aparece en el menú
                <em>y tampoco se abre escribiendo la dirección a mano</em>: no es esconder,
                es cerrar.
            </x-slot>

            <p class="leyenda">
                Sin <strong>ver</strong> no hay nada más: apagarlo apaga el resto. Donde solo
                aparece <strong>V</strong> es porque no hay nada que crear o borrar ahí —una
                página, o algo que se escribe desde otro sitio, como un movimiento del libro o
                el contenido que llega del teléfono—.
            </p>

            <div class="marco">
                <table>
                    <thead>
                        <tr>
                            <th>Sección</th>
                            @foreach ($this->roles() as $clave => $nombre)
                                <th class="rol">{{ $nombre }}</th>
                            @endforeach
                        </tr>
                    </thead>

                    <tbody>
                        @foreach ($this->grupos() as $grupo => $secciones)
                            <tr class="grupo">
                                <td>{{ $grupo }}</td>
                                @foreach ($this->roles() as $rol => $nombre)
                                    <td class="atajos">
                                        <button type="button"
                                                wire:click="todoElGrupo('{{ $rol }}', @js($grupo), 'todo')">todo</button>·<button
                                                type="button"
                                                wire:click="todoElGrupo('{{ $rol }}', @js($grupo), 'ver')">ver</button>·<button
                                                type="button"
                                                wire:click="todoElGrupo('{{ $rol }}', @js($grupo), 'nada')">nada</button>
                                    </td>
                                @endforeach
                            </tr>

                            @foreach ($secciones as $seccion)
                                <tr>
                                    <td class="seccion">
                                        {{ $seccion['nombre'] }}
                                        <small>{{ $seccion['clave'] }}</small>
                                    </td>

                                    @foreach ($this->roles() as $rol => $nombre)
                                        <td class="celda">
                                            <span class="acciones">
                                                @foreach ($this->accionesDe($seccion) as $accion => $etiqueta)
                                                    <span class="{{ $accion === 'ver' ? 'ver' : '' }}">
                                                        <label title="{{ $nombre }}: {{ $etiqueta }} {{ $seccion['nombre'] }}">
                                                            <input type="checkbox"
                                                                   wire:model="matriz.{{ $rol }}.{{ $seccion['clave'] }}.{{ $accion }}"
                                                                   @if ($accion === 'ver') wire:change="alCambiarVer('{{ $rol }}', '{{ $seccion['clave'] }}')" @endif>
                                                            {{ mb_substr($etiqueta, 0, 1) }}
                                                        </label>
                                                    </span>
                                                @endforeach
                                            </span>
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>

            <x-slot name="footer">
                <div class="flex items-center gap-3">
                    <x-filament::button wire:click="save">Guardar accesos</x-filament::button>
                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        El superadmin no está en la tabla: lo ve todo siempre. Una casilla que
                        pudiera quitarle el acceso a esta misma pantalla es la forma de cerrar
                        la puerta por dentro.
                    </span>
                </div>
            </x-slot>
        </x-filament::section>
    </div>

</x-filament-panels::page>
