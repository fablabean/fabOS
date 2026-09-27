<x-filament-panels::page>
    <style>
        .drv-migas { display:flex; flex-wrap:wrap; gap:.4rem; align-items:center; font-size:.9rem; margin-bottom:1rem; }
        .drv-migas button { background:none; border:0; padding:0; color:rgb(217 119 6); cursor:pointer; text-decoration:underline; font:inherit; }
        .drv-rejilla { display:grid; gap:.8rem; grid-template-columns:repeat(auto-fill, minmax(170px, 1fr)); }
        .drv-item { display:flex; flex-direction:column; border:1px solid rgba(128,128,128,.25); border-radius:.6rem;
                    overflow:hidden; text-decoration:none; color:inherit; background:rgba(128,128,128,.04); cursor:pointer; }
        .drv-item:hover { border-color:rgb(245 158 11); }
        .drv-mini { position:relative; aspect-ratio:4/3; background:rgba(128,128,128,.12); display:flex;
                    align-items:center; justify-content:center; overflow:hidden; }
        .drv-mini img { width:100%; height:100%; object-fit:cover; display:block; }
        .drv-mini .ico { width:2.4rem; height:2.4rem; opacity:.55; }
        .drv-mini .play { position:absolute; width:2.2rem; height:2.2rem; border-radius:999px; background:rgba(0,0,0,.55);
                          color:#fff; display:flex; align-items:center; justify-content:center; font-size:.9rem; }
        .drv-nombre { font-size:.8rem; padding:.45rem .55rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
        .drv-vacio { padding:2rem 0; text-align:center; opacity:.7; }
        .drv-pasos { margin:.5rem 0 0; padding-left:1.2rem; line-height:1.7; font-size:.92rem; }
        .drv-error { border-left:4px solid rgb(220 38 38); background:rgba(220,38,38,.07); padding:.9rem 1.1rem; border-radius:.5rem; }
    </style>

    @php
        $drive = app(\App\Services\Contenido\DriveDelLaboratorio::class);
    @endphp

    @if (! $drive->configurada())
        <x-filament::section>
            <x-slot name="heading">Falta conectar la carpeta</x-slot>
            <p style="margin:0">Una vez, y lo hace quien administra la cuenta de Google del laboratorio:</p>
            <ol class="drv-pasos">
                <li>En <a href="https://console.cloud.google.com/" target="_blank" style="text-decoration:underline">Google Cloud</a>, en un proyecto del laboratorio, activa la <strong>API de Google Drive</strong>.</li>
                <li>En «IAM y administración → Cuentas de servicio», crea una cuenta (por ejemplo, <em>fabos-drive</em>), y en su pestaña «Claves» agrega una clave <strong>JSON</strong>. Se descarga un archivo.</li>
                <li>En Drive, crea la carpeta del laboratorio y <strong>compártela con el correo de esa cuenta</strong> (termina en <em>iam.gserviceaccount.com</em>), como lector.</li>
                <li>Aquí, pulsa <strong>Configurar</strong>: pega el enlace de la carpeta y sube el archivo JSON.</li>
            </ol>
        </x-filament::section>
    @else
        @php($contenido = $this->contenido())

        <nav class="drv-migas" aria-label="Carpetas">
            <button type="button" wire:click="volverA(-1)">Carpeta del laboratorio</button>
            @foreach ($ruta as $i => $paso)
                <span aria-hidden="true">›</span>
                @if ($loop->last)
                    <strong>{{ $paso['nombre'] }}</strong>
                @else
                    <button type="button" wire:click="volverA({{ $i }})">{{ $paso['nombre'] }}</button>
                @endif
            @endforeach
        </nav>

        @if ($contenido['error'])
            <div class="drv-error">{{ $contenido['error'] }}</div>
        @elseif (empty($contenido['archivos']))
            <div class="drv-vacio">Esta carpeta está vacía. Pulsa «Subir a Drive» para agregar fotos y videos.</div>
        @else
            <div class="drv-rejilla" wire:loading.class="opacity-50">
                @foreach ($contenido['archivos'] as $f)
                    @if ($f['esCarpeta'])
                        <button type="button" class="drv-item" wire:click="entrar('{{ $f['id'] }}')" style="text-align:left;padding:0">
                            <span class="drv-mini">
                                <x-filament::icon icon="heroicon-o-folder" class="ico" />
                            </span>
                            <span class="drv-nombre" title="{{ $f['nombre'] }}">{{ $f['nombre'] }}</span>
                        </button>
                    @else
                        <a class="drv-item" href="{{ $f['enlace'] }}" target="_blank" rel="noopener">
                            <span class="drv-mini">
                                @if ($f['miniatura'])
                                    {{-- La sirve Google: no pasa por el servidor. --}}
                                    <img src="{{ $f['miniatura'] }}" alt="{{ $f['nombre'] }}" loading="lazy" referrerpolicy="no-referrer">
                                @else
                                    <x-filament::icon icon="heroicon-o-document" class="ico" />
                                @endif
                                @if ($f['esVideo'])
                                    <span class="play" aria-hidden="true">▶</span>
                                @endif
                            </span>
                            <span class="drv-nombre" title="{{ $f['nombre'] }}">{{ $f['nombre'] }}</span>
                        </a>
                    @endif
                @endforeach
            </div>
            <p style="font-size:.8rem;opacity:.65;margin-top:.8rem">
                {{ count($contenido['archivos']) }} elementos. El listado se guarda unos minutos; si acabas de subir algo, pulsa «Actualizar».
            </p>
        @endif
    @endif
</x-filament-panels::page>
