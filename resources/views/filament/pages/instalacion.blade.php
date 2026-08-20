<x-filament-panels::page>

    {{-- Estilos propios: el CSS de Filament viene compilado y no trae las
         utilidades de rejilla que usaría una página a medida. --}}
    <style>
        .inst{display:flex;flex-direction:column;gap:1.5rem}
        .inst .rejilla{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr))}
        .inst label{display:block;font-size:.78rem;color:rgb(107 114 128);margin-bottom:.25rem}
        .inst input{
            font:inherit;font-size:.92rem;padding:.5rem .7rem;border-radius:8px;width:100%;
            border:1px solid rgba(128,128,128,.35);background:transparent;color:inherit;
        }
        .inst .nota{font-size:.82rem;color:rgb(107 114 128);margin-top:.8rem}
        .inst .paso{
            display:flex;gap:1rem;align-items:flex-start;padding:.75rem .9rem;
            border:1px solid rgba(128,128,128,.25);border-radius:8px;margin-bottom:.5rem;
        }
        .inst .paso.listo{opacity:.6}
        .inst .marca{
            min-width:1.9rem;height:1.9rem;border-radius:50%;display:flex;
            align-items:center;justify-content:center;font-weight:700;font-size:.82rem;
            border:1px solid rgba(128,128,128,.35);
        }
        .inst .paso.listo .marca{background:#0d6e63;color:#fff;border-color:#0d6e63}
        .inst .paso.falta .marca{color:#b45309;border-color:#b45309}
        .inst .paso b{display:block;font-size:.95rem}
        .inst .paso span{display:block;font-size:.82rem;color:rgb(107 114 128)}
        .inst .barra{height:6px;border-radius:3px;background:rgba(128,128,128,.25);overflow:hidden;margin:.6rem 0 1rem}
        .inst .barra i{display:block;height:100%;background:rgb(var(--primary-500))}
        .inst pre{
            font-size:.75rem;line-height:1.5;padding:.9rem;border-radius:8px;overflow-x:auto;
            background:rgba(128,128,128,.10);max-height:20rem;
        }
        .inst .acciones{display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;margin-top:1rem}
    </style>

    <div class="inst">

        {{-- --------------------------------------------------- identidad --}}
        <x-filament::section>
            <x-slot name="heading">Quién es este laboratorio</x-slot>
            <x-slot name="description">
                Aparece en la portada, en el pie, en los correos y en todo lo que sale impreso.
                Se guarda en la base de datos y manda sobre lo que diga el archivo <code>.env</code>.
            </x-slot>

            <div class="rejilla">
                <div>
                    <label for="i-name">Nombre del laboratorio</label>
                    <input id="i-name" type="text" wire:model="datos.name" placeholder="Fab Lab Ciudad">
                </div>
                <div>
                    <label for="i-short">Nombre corto</label>
                    <input id="i-short" type="text" wire:model="datos.short_name">
                </div>
                <div>
                    <label for="i-inst">Institución</label>
                    <input id="i-inst" type="text" wire:model="datos.institution" placeholder="Universidad de Ejemplo">
                </div>
                <div>
                    <label for="i-city">Ciudad</label>
                    <input id="i-city" type="text" wire:model="datos.city" placeholder="Medellín, Colombia">
                </div>
                <div>
                    <label for="i-tag">Qué es</label>
                    <input id="i-tag" type="text" wire:model="datos.tagline" placeholder="Laboratorio de fabricación digital">
                </div>
                <div>
                    <label for="i-net">Red a la que pertenece</label>
                    <input id="i-net" type="text" wire:model="datos.network" placeholder="Fab Foundation">
                </div>
                <div>
                    <label for="i-logo">Logo (ruta dentro de public/)</label>
                    <input id="i-logo" type="text" wire:model="datos.logo" placeholder="img/fabos-logo.svg">
                </div>
                <div>
                    <label for="i-cname">Nombre de la moneda interna</label>
                    <input id="i-cname" type="text" wire:model="datos.currency_name" placeholder="FabCoin">
                </div>
                <div>
                    <label for="i-ccode">Código de la moneda</label>
                    <input id="i-ccode" type="text" wire:model="datos.currency_code" placeholder="FBC">
                </div>
                <div>
                    <label for="i-sym">Símbolo del dinero real</label>
                    <input id="i-sym" type="text" wire:model="datos.money_symbol" placeholder="$">
                </div>
            </div>

            <div class="acciones">
                <x-filament::button wire:click="guardar">Guardar</x-filament::button>

                @if ($administrado)
                    <x-filament::button wire:click="restablecer" color="gray" outlined>
                        Volver a lo que dice .env
                    </x-filament::button>
                @endif
            </div>

            <p class="nota">
                La zona horaria, los topes de jornada y las claves siguen en <code>.env</code>:
                un cambio descuidado ahí sí desordena la operación, y no debería poder hacerse
                desde una pantalla.
            </p>
        </x-filament::section>

        {{-- ------------------------------------------------- instalación --}}
        <x-filament::section>
            <x-slot name="heading">Qué falta para terminar de instalarlo</x-slot>
            <x-slot name="description">
                En orden: cada paso se apoya en el anterior. Sin áreas no hay equipos, sin equipos
                no hay reservas, y sin horarios el sistema no sabe cuándo está abierto.
            </x-slot>

            <div class="barra"><i style="width:{{ $avance }}%"></i></div>

            @foreach ($pasos as $paso)
                <div class="paso {{ $paso['listo'] ? 'listo' : 'falta' }}">
                    <div class="marca">{{ $paso['listo'] ? '✓' : $paso['paso'] }}</div>
                    <div style="flex:1">
                        <b>
                            {{ $paso['titulo'] }}
                            @if ($paso['cuantos'])
                                <span style="display:inline;color:rgb(107 114 128);font-weight:400">
                                    · {{ $paso['cuantos'] }}
                                </span>
                            @endif
                            @unless ($paso['obligatorio'])
                                <span style="display:inline;color:rgb(107 114 128);font-weight:400">· opcional</span>
                            @endunless
                        </b>
                        <span>{{ $paso['detalle'] }}</span>
                    </div>
                    @if ($paso['url'])
                        <a href="{{ $paso['url'] }}" style="font-size:.85rem;white-space:nowrap">Ir →</a>
                    @endif
                </div>
            @endforeach

            @if ($faltan->isNotEmpty())
                <p class="nota">
                    Faltan <strong>{{ $faltan->count() }}</strong> pasos obligatorios. El sistema
                    funciona igual, pero hasta que estén, media operación no tiene sobre qué apoyarse.
                </p>
            @else
                <p class="nota">
                    Todo lo obligatorio está en su sitio. Lo que sigue es afinar tarifas, insumos y
                    qué habilita cada curso — y eso se decide operando, no antes.
                </p>
            @endif
        </x-filament::section>

        {{-- ------------------------------------------------- producción --}}
        @php
            $graves = $revision->where('nivel', 'grave');
            $avisos = $revision->where('nivel', 'aviso');
        @endphp

        <x-filament::section>
            <x-slot name="heading">Listo para producción</x-slot>
            <x-slot name="description">
                Casi nada de lo que tumba un despliegue es un error de código: es algo que nadie
                configuró y que en local no se nota porque en local no importa.
            </x-slot>

            @if ($graves->isEmpty() && $avisos->isEmpty())
                <p style="margin:0">Todo en orden. Esta instancia se puede abrir a la gente.</p>
            @endif

            @foreach ($revision->whereIn('nivel', ['grave', 'aviso']) as $r)
                <div class="paso {{ $r['nivel'] === 'grave' ? 'falta' : '' }}">
                    <div class="marca" style="{{ $r['nivel'] === 'grave' ? 'color:#dc2626;border-color:#dc2626' : 'color:#b45309;border-color:#b45309' }}">
                        {{ $r['nivel'] === 'grave' ? '✗' : '!' }}
                    </div>
                    <div style="flex:1">
                        <b>{{ $r['titulo'] }}</b>
                        <span>{{ $r['detalle'] }}</span>
                        @if ($r['arreglo'])
                            <span style="margin-top:.3rem"><code>{{ $r['arreglo'] }}</code></span>
                        @endif
                    </div>
                </div>
            @endforeach

            <p class="nota">
                La misma revisión se corre en el servidor con
                <code>php artisan fabos:revisar</code>, que devuelve error si algo bloquea — sirve
                para encadenarla en el despliegue y que se detenga solo. La guía completa está en
                <code>docs/PRODUCCION.md</code>.
            </p>
        </x-filament::section>

        {{-- ----------------------------------------------------- compartir --}}
        <x-filament::section collapsible collapsed>
            <x-slot name="heading">Compartir esta instalación con otro laboratorio</x-slot>
            <x-slot name="description">
                fabOS es software libre (AGPL-3.0): otro Fab Lab puede levantarlo y quedarse con
                todo lo aprendido aquí.
            </x-slot>

            <p style="margin:0 0 .8rem">
                Esto exporta <strong>cómo está configurado</strong> este laboratorio, no sus datos.
                Los equipos, las personas y el histórico son de cada uno; lo que se hereda es la
                forma. Se pega en el <code>.env</code> del laboratorio nuevo y se corre
                <code>php artisan fabos:instalar</code>.
            </p>

            <pre>{{ $exportado }}</pre>

            <div class="acciones">
                <x-filament::button wire:click="exportar" icon="heroicon-o-arrow-down-tray">
                    Descargar configuración
                </x-filament::button>

                <span style="font-size:.82rem;color:rgb(107 114 128)">
                    La guía paso a paso está en <code>docs/DESPLIEGUE.md</code>.
                </span>
            </div>
        </x-filament::section>

    </div>

</x-filament-panels::page>
