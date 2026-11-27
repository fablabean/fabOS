<x-filament-panels::page>
    @php
        $datos = $this->datosParaElNavegador();
        $reemplazo = $datos['modelo'];
    @endphp
    <script type="application/json" id="g3dp-datos">@json($datos)</script>

    {{-- Estilos propios: el panel no trae las clases de utilidad que no usa él mismo. --}}
    <style>
        .g3dp{display:grid;grid-template-columns:minmax(0,1.05fr) minmax(0,1fr);gap:1.5rem;align-items:start}
        @media (max-width:64rem){.g3dp{grid-template-columns:minmax(0,1fr)}}
        .g3dp-pasos{display:flex;flex-direction:column;gap:1.25rem;min-width:0}
        .g3dp [hidden]{display:none !important}
        .g3dp-suelta{
            display:flex;flex-direction:column;align-items:center;justify-content:center;gap:.5rem;text-align:center;
            border:2px dashed rgba(127,127,127,.35);border-radius:.75rem;padding:2rem 1rem;cursor:pointer;transition:background .15s,border-color .15s
        }
        .g3dp-suelta:hover,.g3dp-suelta.encima{border-color:var(--primary-500);background:color-mix(in srgb,var(--primary-500) 6%,transparent)}
        .g3dp-suelta svg{width:2.25rem;height:2.25rem;opacity:.6}
        .g3dp-suelta strong{font-size:1rem}
        .g3dp-suelta small{opacity:.7;font-size:.8rem;max-width:34rem}
        .g3dp-nota{font-size:.82rem;opacity:.75;margin:.75rem 0 0;line-height:1.5}
        .g3dp-archivos{list-style:none;margin:.75rem 0 0;padding:0;font-size:.85rem;display:flex;flex-wrap:wrap;gap:.4rem}
        .g3dp-archivos li{border:1px solid rgba(127,127,127,.3);border-radius:999px;padding:.15rem .65rem}
        .g3dp-archivos li.principal{border-color:var(--primary-500);font-weight:600}
        .g3dp-error{border:1px solid rgba(220,38,38,.45);background:rgba(220,38,38,.07);color:rgb(185,28,28);border-radius:.6rem;padding:.75rem 1rem;font-size:.88rem;margin-top:.9rem}
        .dark .g3dp-error{color:rgb(252,165,165)}
        .g3dp-aviso{border:1px solid rgba(217,119,6,.45);background:rgba(217,119,6,.08);border-radius:.6rem;padding:.6rem .9rem;font-size:.85rem;margin-top:.6rem}
        .g3dp-campos{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:1rem}
        .g3dp-campos .ancho{grid-column:1 / -1}
        @media (max-width:40rem){.g3dp-campos{grid-template-columns:minmax(0,1fr)}}
        .g3dp-etiqueta{display:block;font-size:.875rem;font-weight:500;margin-bottom:.35rem}
        .g3dp-ayuda{display:block;font-size:.78rem;opacity:.65;margin-top:.3rem}
        .g3dp-fila{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center}
        .g3dp-casilla{display:flex;gap:.6rem;align-items:flex-start;font-size:.875rem;cursor:pointer}
        .g3dp-casilla input{margin-top:.15rem}
        .g3dp-opcion{
            display:flex;flex-direction:column;gap:.15rem;border:1px solid rgba(127,127,127,.3);border-radius:.6rem;padding:.6rem .8rem;
            cursor:pointer;font-size:.85rem;flex:1 1 9rem
        }
        .g3dp-opcion:has(input:checked){border-color:var(--primary-500);background:color-mix(in srgb,var(--primary-500) 7%,transparent)}
        .g3dp-opcion input{position:absolute;opacity:0;pointer-events:none}
        .g3dp-opcion small{opacity:.7}
        .g3dp-medidas{font-size:1.15rem;font-weight:600;font-variant-numeric:tabular-nums}
        .g3dp-color{width:2.5rem;height:2.25rem;border:1px solid rgba(127,127,127,.35);border-radius:.5rem;padding:0;background:none;cursor:pointer}
        .g3dp-vista{position:sticky;top:5rem}
        .g3dp-lienzo{position:relative;aspect-ratio:1/1;border-radius:.75rem;overflow:hidden;border:1px solid rgba(127,127,127,.25);
            background:radial-gradient(120% 90% at 50% 35%,#f6f6f2 0%,#dcdcd4 100%)}
        .dark .g3dp-lienzo{background:radial-gradient(120% 90% at 50% 35%,#2a2d27 0%,#141611 100%)}
        .g3dp-lienzo canvas{position:absolute;inset:0;width:100%;height:100%;display:block}
        .g3dp-vacio{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;text-align:center;padding:2rem;font-size:.9rem;opacity:.6}
        .g3dp-etiqueta-vista{position:absolute;left:.75rem;bottom:.75rem;font-size:.75rem;background:rgba(255,255,255,.85);color:#222;border-radius:999px;padding:.2rem .65rem}
        .g3dp-progreso{height:.4rem;border-radius:999px;background:rgba(127,127,127,.2);overflow:hidden;margin-top:.75rem}
        .g3dp-progreso span{display:block;height:100%;width:0;background:var(--primary-500);transition:width .2s}
        .g3dp-estado{font-size:.85rem;margin-top:.5rem;opacity:.85}
        .g3dp-cifras{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:.75rem;margin:.75rem 0 0}
        .g3dp-cifras div{border:1px solid rgba(127,127,127,.25);border-radius:.6rem;padding:.6rem .75rem}
        .g3dp-cifras dt{font-size:.72rem;opacity:.65}
        .g3dp-cifras dd{margin:.1rem 0 0;font-weight:600;font-variant-numeric:tabular-nums}
        .g3dp-cifras small{display:block;font-weight:400;opacity:.65;font-size:.72rem}
        .g3dp-listo{display:flex;gap:1rem;align-items:center;margin-top:1rem}
        .g3dp-listo img{width:6rem;height:6rem;object-fit:contain;border-radius:.6rem;background:rgba(127,127,127,.08)}
    </style>

    <div class="g3dp" id="g3dp" wire:ignore>
        <div class="g3dp-pasos">

            {{-- 1. El archivo --}}
            <x-filament::section>
                <x-slot name="heading">1. El archivo</x-slot>
                <x-slot name="description">
                    @if ($reemplazo)
                        El nuevo archivo reemplaza al actual; la ficha, los acabados y la dirección no cambian.
                    @else
                        Se abre y se optimiza en este navegador: el original no se sube al servidor, solo el resultado.
                    @endif
                </x-slot>

                <label class="g3dp-suelta" id="g3dp-suelta" for="g3dp-entrada">
                    {{ svg('heroicon-o-cube-transparent', '', ['aria-hidden' => 'true']) }}
                    <strong>Arrastra aquí el modelo, o haz clic para elegirlo</strong>
                    <small>STL · STEP / STP · IGES · OBJ (con su .mtl y texturas) · FBX · 3MF · GLB / glTF (con su .bin y texturas)</small>
                    <input type="file" id="g3dp-entrada" multiple hidden
                           accept=".stl,.step,.stp,.iges,.igs,.obj,.mtl,.fbx,.3mf,.glb,.gltf,.bin,.png,.jpg,.jpeg,.webp,.tga,.bmp">
                </label>
                <ul class="g3dp-archivos" id="g3dp-archivos" hidden></ul>
                <div class="g3dp-error" id="g3dp-error" role="alert" hidden></div>
                <div id="g3dp-avisos"></div>
                <p class="g3dp-nota">
                    ¿Viene de SolidWorks, Fusion 360, Inventor, SketchUp o Blender? Esos formatos son privados:
                    expórtalo como <strong>STEP</strong> o <strong>STL</strong> (CAD) o como <strong>GLB</strong> / <strong>FBX</strong> (con texturas).
                </p>
            </x-filament::section>

            {{-- 2. Cómo queda --}}
            <x-filament::section id="g3dp-ajustes" hidden>
                <x-slot name="heading">2. Revisa cómo queda</x-slot>
                <x-slot name="description">Comprueba en la vista previa que tenga el tamaño y la posición correctos: así aparecerá en realidad aumentada.</x-slot>

                <div class="g3dp-campos">
                    <div>
                        <span class="g3dp-etiqueta">Mide</span>
                        <span class="g3dp-medidas" id="g3dp-medidas">—</span>
                        <span class="g3dp-ayuda">ancho × fondo × alto</span>
                    </div>
                    <div>
                        <label class="g3dp-etiqueta" for="g3dp-unidades">El archivo está en</label>
                        <x-filament::input.wrapper>
                            <x-filament::input.select id="g3dp-unidades">
                                <option value="mm">milímetros</option>
                                <option value="cm">centímetros</option>
                                <option value="m">metros</option>
                                <option value="in">pulgadas</option>
                            </x-filament::input.select>
                        </x-filament::input.wrapper>
                        <span class="g3dp-ayuda" id="g3dp-unidades-ayuda">Si el tamaño no es el real, cambia las unidades.</span>
                    </div>
                    <div class="ancho">
                        <span class="g3dp-etiqueta">Posición</span>
                        <div class="g3dp-fila">
                            <x-filament::button color="gray" size="sm" icon="heroicon-o-arrow-path" id="g3dp-levantar" type="button">Levantar 90°</x-filament::button>
                            <x-filament::button color="gray" size="sm" icon="heroicon-o-arrow-path-rounded-square" id="g3dp-girar" type="button">Girar 90°</x-filament::button>
                            <x-filament::button color="gray" size="sm" icon="heroicon-o-arrow-uturn-left" id="g3dp-posicion-original" type="button">Como venía</x-filament::button>
                        </div>
                        <span class="g3dp-ayuda">Si aparece acostado o boca abajo, «Levantar» lo rota sobre el eje horizontal. Siempre se apoya solo sobre el suelo.</span>
                    </div>
                    <div class="ancho" id="g3dp-pintura" hidden>
                        <span class="g3dp-etiqueta">Color y acabado</span>
                        <div class="g3dp-fila">
                            <input type="color" class="g3dp-color" id="g3dp-color" value="#0d6e63" aria-label="Color">
                            <x-filament::input.wrapper style="flex:1 1 12rem">
                                <x-filament::input.select id="g3dp-acabado" aria-label="Acabado">
                                    <option value="pla-mate">PLA mate</option>
                                    <option value="pla-brillante">PLA brillante</option>
                                    <option value="pla-seda">PLA seda (brillo metálico)</option>
                                    <option value="resina">Resina</option>
                                    <option value="metal">Metal</option>
                                    <option value="madera">Madera / MDF</option>
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                        <span class="g3dp-ayuda">Este archivo no trae colores (los STL y muchos STEP no los guardan). Elige cómo se verá.</span>
                    </div>
                    <div class="ancho">
                        <span class="g3dp-etiqueta">Calidad</span>
                        <div class="g3dp-fila" id="g3dp-calidades" role="radiogroup" aria-label="Calidad">
                            <label class="g3dp-opcion"><input type="radio" name="g3dp-calidad" value="alta"><strong>Alta</strong><small>hasta 150.000 triángulos · texturas de 2048</small></label>
                            <label class="g3dp-opcion"><input type="radio" name="g3dp-calidad" value="equilibrada" checked><strong>Equilibrada</strong><small>hasta 60.000 · recomendada</small></label>
                            <label class="g3dp-opcion"><input type="radio" name="g3dp-calidad" value="ligera"><strong>Ligera</strong><small>hasta 25.000 · carga muy rápido</small></label>
                        </div>
                        <span class="g3dp-ayuda" id="g3dp-calidad-ayuda"></span>
                    </div>
                </div>
            </x-filament::section>

            {{-- 3. La ficha (solo al subir uno nuevo) --}}
            @unless ($reemplazo)
                <x-filament::section id="g3dp-ficha" hidden>
                    <x-slot name="heading">3. La ficha</x-slot>
                    <x-slot name="description">Lo que verá el público. Los acabados se añaden después, al editar el modelo.</x-slot>
                    <div class="g3dp-campos">
                        <div class="ancho">
                            <label class="g3dp-etiqueta" for="g3dp-nombre">Nombre *</label>
                            <x-filament::input.wrapper><x-filament::input type="text" id="g3dp-nombre" maxlength="120" required /></x-filament::input.wrapper>
                        </div>
                        <div>
                            <label class="g3dp-etiqueta" for="g3dp-categoria">Técnica principal *</label>
                            <x-filament::input.wrapper>
                                <x-filament::input.select id="g3dp-categoria">
                                    @foreach ($datos['categorias'] as $clave => $nombre)
                                        <option value="{{ $clave }}">{{ $nombre }}</option>
                                    @endforeach
                                </x-filament::input.select>
                            </x-filament::input.wrapper>
                        </div>
                        <div>
                            <label class="g3dp-etiqueta" for="g3dp-tecnica">Proceso</label>
                            <x-filament::input.wrapper><x-filament::input type="text" id="g3dp-tecnica" maxlength="120" placeholder="Impresión 3D FDM" /></x-filament::input.wrapper>
                        </div>
                        <div>
                            <label class="g3dp-etiqueta" for="g3dp-maquina">Máquina</label>
                            <x-filament::input.wrapper><x-filament::input type="text" id="g3dp-maquina" maxlength="160" placeholder="Impresora FDM, boquilla de 0,4 mm" /></x-filament::input.wrapper>
                        </div>
                        <div>
                            <label class="g3dp-etiqueta" for="g3dp-material">Material</label>
                            <x-filament::input.wrapper><x-filament::input type="text" id="g3dp-material" maxlength="160" placeholder="PLA" /></x-filament::input.wrapper>
                        </div>
                        <div class="ancho">
                            <label class="g3dp-etiqueta" for="g3dp-resumen">Resumen</label>
                            <x-filament::input.wrapper><textarea id="g3dp-resumen" rows="2" maxlength="300" class="fi-input" style="width:100%;resize:vertical" placeholder="Una o dos frases sobre qué es."></textarea></x-filament::input.wrapper>
                        </div>
                        <div class="ancho">
                            <label class="g3dp-etiqueta" for="g3dp-descripcion">Descripción</label>
                            <x-filament::input.wrapper><textarea id="g3dp-descripcion" rows="3" maxlength="5000" class="fi-input" style="width:100%;resize:vertical" placeholder="Cómo se hizo, quién lo hizo, para qué sirve."></textarea></x-filament::input.wrapper>
                        </div>
                        <label class="g3dp-casilla ancho"><input type="checkbox" id="g3dp-escala"> <span><strong>Verlo a tamaño real en realidad aumentada</strong><br><small style="opacity:.7">Para muebles y piezas de medida conocida: en el teléfono no se podrá agrandar ni encoger.</small></span></label>
                        <label class="g3dp-casilla ancho"><input type="checkbox" id="g3dp-publicar" checked> <span><strong>Publicarlo en la galería al guardar</strong></span></label>
                    </div>
                </x-filament::section>
            @endunless

            {{-- 4. Guardar --}}
            <x-filament::section id="g3dp-guardar-seccion" hidden>
                <x-filament::button size="lg" icon="heroicon-o-sparkles" id="g3dp-guardar" type="button">
                    {{ $reemplazo ? 'Optimizar y reemplazar' : 'Optimizar y guardar' }}
                </x-filament::button>
                <div id="g3dp-trabajando" hidden>
                    <div class="g3dp-progreso"><span id="g3dp-barra"></span></div>
                    <p class="g3dp-estado" id="g3dp-estado" aria-live="polite"></p>
                </div>
                <div id="g3dp-resultado" hidden></div>
            </x-filament::section>
        </div>

        <div class="g3dp-vista">
            <div class="g3dp-lienzo" id="g3dp-lienzo">
                <div class="g3dp-vacio" id="g3dp-vacio">La vista previa aparece aquí al elegir el archivo.<br>Arrastra para girarla.</div>
                <span class="g3dp-etiqueta-vista" id="g3dp-rejilla" hidden></span>
            </div>
        </div>
    </div>

    <script type="module" src="{{ asset('3d/procesar/subir.js') }}?v={{ filemtime(public_path('3d/procesar/subir.js')) }}"></script>
</x-filament-panels::page>
