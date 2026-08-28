<x-filament-panels::page>

    {{-- Estilos propios: una matriz de cuarenta filas por cuatro columnas no
         sale de las utilidades que trae Filament compiladas. --}}
    <style>
        .rya table { width:100%; border-collapse:collapse; font-size:.88rem; }
        .rya th, .rya td { padding:.4rem .5rem; text-align:left; }
        .rya thead th { font-size:.75rem; text-transform:uppercase; letter-spacing:.06em;
                        color:rgb(107 114 128); border-bottom:1px solid rgb(229 231 235); }
        .rya th.rol, .rya td.marca { text-align:center; width:8rem; }
        .rya tbody tr { border-bottom:1px solid rgb(243 244 246); }
        .rya tbody tr:hover { background:rgb(249 250 251); }
        .rya .grupo td { padding-top:1.1rem; font-size:.75rem; text-transform:uppercase;
                         letter-spacing:.08em; color:rgb(107 114 128); font-weight:600; }
        .rya .grupo:hover { background:none; }
        .rya .todos { font-size:.7rem; color:rgb(107 114 128); text-transform:none;
                      letter-spacing:0; }
        .rya .todos button { background:none; border:none; cursor:pointer; padding:0 .2rem;
                             color:rgb(217 119 6); font-size:.7rem; }
        .rya .seccion small { display:block; color:rgb(156 163 175); font-size:.72rem; }
        .rya input[type=checkbox] { width:1.05rem; height:1.05rem; cursor:pointer; }
        .dark .rya thead th { border-color:rgb(55 65 81); }
        .dark .rya tbody tr { border-color:rgb(31 41 55); }
        .dark .rya tbody tr:hover { background:rgb(31 41 55); }
    </style>

    <div class="rya">
        <x-filament::section>
            <x-slot name="heading">Qué ve cada rol</x-slot>
            <x-slot name="description">
                Cada casilla es una sección del menú lateral. Lo que aquí se apaga no aparece
                en el menú <em>y tampoco se abre escribiendo la dirección a mano</em>: no es
                esconder, es cerrar.
            </x-slot>

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
                                <td class="marca todos">
                                    <button type="button"
                                            wire:click="todoElGrupo('{{ $rol }}', @js($grupo), true)">todo</button>·<button
                                            type="button"
                                            wire:click="todoElGrupo('{{ $rol }}', @js($grupo), false)">nada</button>
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
                                    <td class="marca">
                                        <input type="checkbox"
                                               wire:model="matriz.{{ $rol }}.{{ $seccion['clave'] }}"
                                               aria-label="{{ $nombre }} ve {{ $seccion['nombre'] }}">
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>

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
