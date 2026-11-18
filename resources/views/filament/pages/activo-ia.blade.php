<x-filament-panels::page>
    <style>
        .aia{--aia-borde:rgba(128,128,128,.22);--aia-suave:rgba(128,128,128,.07);--aia-ambar:rgb(245 158 11);--aia-ambar-suave:rgba(245,158,11,.12);display:flex;flex-direction:column;gap:1.25rem}
        .aia .gris{color:rgb(107 114 128);font-size:.84rem}
        .aia .aviso{border-radius:.6rem;padding:.7rem .9rem;font-size:.86rem;border:1px solid}
        .aia .aviso.ambar{background:rgba(245,158,11,.1);border-color:rgba(245,158,11,.45)}
        .aia .aviso.rojo{background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.45)}
        .aia .aviso.verde{background:rgba(16,185,129,.08);border-color:rgba(16,185,129,.45)}

        /* Dos columnas: la conversación manda, la barra acompaña. */
        .aia .cuerpo{display:grid;gap:1.25rem;grid-template-columns:minmax(0,1fr) 21rem;align-items:start}
        @media (max-width:1180px){.aia .cuerpo{grid-template-columns:minmax(0,1fr)}}
        .aia .lateral{display:flex;flex-direction:column;gap:1.25rem;position:sticky;top:5rem}
        @media (max-width:1180px){.aia .lateral{position:static;display:grid;grid-template-columns:repeat(auto-fit,minmax(17rem,1fr))}}

        /* La conversación */
        .aia .panel{border:1px solid var(--aia-borde);border-radius:.9rem;background:var(--aia-fondo,#fff);display:flex;flex-direction:column;overflow:hidden;box-shadow:0 1px 2px rgba(0,0,0,.04)}
        .dark .aia .panel{--aia-fondo:rgb(24 24 27)}
        .aia .panel-cabeza{display:flex;align-items:center;justify-content:space-between;gap:.8rem;padding:.85rem 1.1rem;border-bottom:1px solid var(--aia-borde)}
        .aia .panel-cabeza .titulo{display:flex;align-items:center;gap:.6rem;font-weight:600}
        .aia .avatar{flex:none;display:inline-flex;align-items:center;justify-content:center;width:2rem;height:2rem;border-radius:999px;background:var(--aia-ambar-suave);color:var(--aia-ambar)}
        .aia .avatar svg{width:1.1rem;height:1.1rem}
        .aia .chat{display:flex;flex-direction:column;gap:1rem;height:max(26rem,calc(100vh - 25rem));overflow-y:auto;padding:1.1rem}
        .aia .msg{display:flex;gap:.65rem;max-width:min(54rem,100%)}
        .aia .msg.persona{align-self:flex-end;flex-direction:row-reverse}
        .aia .msg-cuerpo{display:flex;flex-direction:column;gap:.5rem;min-width:0;flex:1}
        .aia .persona .msg-cuerpo{align-items:flex-end}
        .aia .burbuja{border-radius:1rem;padding:.65rem .95rem;font-size:.92rem;line-height:1.5;white-space:pre-wrap;word-break:break-word;max-width:100%}
        .aia .persona .burbuja{background:var(--aia-ambar-suave);border-top-right-radius:.3rem}
        .aia .ia .burbuja{background:var(--aia-suave);border-top-left-radius:.3rem}
        .aia .hora{font-size:.7rem;color:rgb(107 114 128)}
        .aia .mini{max-width:14rem;max-height:14rem;border-radius:.75rem;border:1px solid var(--aia-borde);object-fit:cover}
        .aia .minis{display:flex;flex-wrap:wrap;gap:.4rem;justify-content:flex-end}
        .aia .minis figure{margin:0;display:flex;flex-direction:column;align-items:center;gap:.15rem}
        .aia .minis img{height:6.5rem;max-width:9rem;object-fit:cover;border-radius:.6rem;border:1px solid var(--aia-borde)}
        .aia .minis figcaption{font-size:.7rem;color:rgb(107 114 128)}
        .aia .cargando{display:flex;align-items:center;gap:.6rem;font-size:.85rem;color:rgb(107 114 128)}
        .aia .puntos{display:inline-flex;gap:.25rem}
        .aia .puntos i{width:.4rem;height:.4rem;border-radius:999px;background:var(--aia-ambar);animation:aia-salto 1s infinite ease-in-out}
        .aia .puntos i:nth-child(2){animation-delay:.15s}.aia .puntos i:nth-child(3){animation-delay:.3s}
        @keyframes aia-salto{0%,80%,100%{opacity:.25;transform:translateY(0)}40%{opacity:1;transform:translateY(-3px)}}

        /* Bienvenida */
        .aia .bienvenida{margin:auto;max-width:46rem;width:100%;text-align:center;display:flex;flex-direction:column;align-items:center;gap:.5rem;padding:1rem 0}
        .aia .bienvenida .avatar{width:3.25rem;height:3.25rem}
        .aia .bienvenida .avatar svg{width:1.7rem;height:1.7rem}
        .aia .bienvenida h3{font-size:1.15rem;font-weight:600;margin-top:.4rem}
        .aia .ideas{display:grid;gap:.75rem;grid-template-columns:repeat(auto-fit,minmax(15rem,1fr));width:100%;margin-top:1rem;text-align:left}
        .aia .idea{display:flex;gap:.7rem;align-items:flex-start;border:1px solid var(--aia-borde);border-radius:.75rem;padding:.8rem .9rem;transition:border-color .15s,background .15s;cursor:pointer;background:transparent}
        .aia .idea:hover{border-color:var(--aia-ambar);background:var(--aia-ambar-suave)}
        .aia .idea svg{flex:none;width:1.25rem;height:1.25rem;color:var(--aia-ambar);margin-top:.1rem}
        .aia .idea b{display:block;font-size:.86rem}
        .aia .idea span{display:block;font-size:.78rem;color:rgb(107 114 128);margin-top:.15rem;line-height:1.35}

        /* Donde se escribe */
        .aia .composer{border-top:1px solid var(--aia-borde);padding:.9rem 1.1rem 1rem;display:flex;flex-direction:column;gap:.5rem}
        .aia .caja-texto{border:1px solid var(--aia-borde);border-radius:.8rem;transition:border-color .15s,box-shadow .15s}
        .aia .caja-texto:focus-within{border-color:var(--aia-ambar);box-shadow:0 0 0 3px rgba(245,158,11,.15)}
        .aia .caja-texto textarea{display:block;width:100%;min-height:3.4rem;max-height:12rem;border:0;background:transparent;padding:.75rem .9rem .3rem;font-size:.92rem;resize:vertical;outline:none;box-shadow:none}
        .aia .herramientas{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.5rem;padding:.4rem .5rem .5rem}
        .aia .fila{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem}
        .aia .fila.entre{justify-content:space-between}
        .aia .icono-boton{display:inline-flex;align-items:center;gap:.35rem;border-radius:.55rem;padding:.38rem .6rem;font-size:.82rem;color:rgb(107 114 128);cursor:pointer;transition:background .15s,color .15s}
        .aia .icono-boton:hover{background:var(--aia-suave);color:inherit}
        .aia .icono-boton svg{width:1.1rem;height:1.1rem}
        .aia .icono-boton.oyendo{color:rgb(239 68 68);background:rgba(239,68,68,.08)}
        .aia .previa{display:inline-flex;align-items:center;gap:.45rem;border:1px solid var(--aia-borde);border-radius:.55rem;padding:.2rem .45rem .2rem .2rem;font-size:.8rem}
        .aia .previa img{height:2.2rem;width:2.2rem;object-fit:cover;border-radius:.35rem}
        .aia .previa button{color:rgb(107 114 128)}
        .aia .previa button:hover{color:rgb(239 68 68)}
        .aia .pie{font-size:.74rem;color:rgb(107 114 128);display:flex;flex-wrap:wrap;gap:.3rem 1rem;justify-content:space-between}
        .aia kbd{font-family:inherit;font-size:.7rem;border:1px solid var(--aia-borde);border-bottom-width:2px;border-radius:.3rem;padding:0 .3rem}

        /* Barra lateral */
        .aia .tarjeta-lateral{border:1px solid var(--aia-borde);border-radius:.9rem;padding:1rem 1.1rem;background:var(--aia-fondo,#fff);display:flex;flex-direction:column;gap:.75rem}
        .dark .aia .tarjeta-lateral{--aia-fondo:rgb(24 24 27)}
        .aia .tarjeta-lateral h3{font-size:.8rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:rgb(107 114 128)}
        .aia .estado{display:flex;align-items:center;gap:.5rem;font-weight:600;font-size:.95rem}
        .aia .punto{width:.6rem;height:.6rem;border-radius:999px;background:rgb(16 185 129);box-shadow:0 0 0 3px rgba(16,185,129,.2)}
        .aia .punto.apagado{background:var(--aia-ambar);box-shadow:0 0 0 3px rgba(245,158,11,.2)}
        .aia .barra{height:.4rem;border-radius:999px;background:var(--aia-suave);overflow:hidden}
        .aia .barra i{display:block;height:100%;background:var(--aia-ambar);border-radius:999px}
        .aia .pasos{display:flex;flex-direction:column;gap:.7rem;counter-reset:paso}
        .aia .paso{display:flex;gap:.65rem;font-size:.84rem;line-height:1.4}
        .aia .paso::before{counter-increment:paso;content:counter(paso);flex:none;display:inline-flex;align-items:center;justify-content:center;width:1.4rem;height:1.4rem;border-radius:999px;background:var(--aia-ambar-suave);color:rgb(217 119 6);font-size:.75rem;font-weight:700}
        .aia .paso b{display:block}
        .aia .cifras{display:grid;gap:.6rem;grid-template-columns:1fr 1fr}
        .aia .cifra{border-radius:.6rem;background:var(--aia-suave);padding:.55rem .7rem}
        .aia .cifra b{display:block;font-size:1.3rem;letter-spacing:-.02em;line-height:1.15}
        .aia .cifra span{font-size:.72rem;color:rgb(107 114 128);line-height:1.25;display:block}

        /* Las tarjetas de propuesta */
        .aia .tarjeta{border:1px solid var(--aia-borde);border-radius:.85rem;padding:1rem 1.1rem;display:flex;flex-direction:column;gap:.85rem;background:var(--aia-fondo,#fff);width:100%;box-shadow:0 1px 3px rgba(0,0,0,.05)}
        .dark .aia .tarjeta{--aia-fondo:rgb(24 24 27)}
        .aia .tarjeta.pendiente{border-left:3px solid var(--aia-ambar)}
        .aia .tarjeta.guardada{border-left:3px solid rgb(16 185 129)}
        .aia .tarjeta.descartada{opacity:.55}
        .aia .tarjeta.repetida{padding:.6rem .8rem;border-style:dashed;box-shadow:none;background:transparent}
        .aia .tarjeta h4{font-weight:600;font-size:.95rem}
        .aia .chips{display:flex;flex-wrap:wrap;gap:.35rem}
        .aia .chip{font-size:.72rem;border-radius:999px;padding:.12rem .55rem;border:1px solid rgba(128,128,128,.35)}
        .aia .chip.alta{border-color:rgba(16,185,129,.6);color:rgb(5 150 105)}
        .aia .chip.media{border-color:rgba(245,158,11,.6);color:rgb(217 119 6)}
        .aia .chip.baja{border-color:rgba(239,68,68,.6);color:rgb(220 38 38)}
        .aia .campos{display:grid;gap:.7rem;grid-template-columns:repeat(auto-fit,minmax(12.5rem,1fr))}
        .aia .campo label{display:block;font-size:.74rem;font-weight:600;margin-bottom:.2rem}
        .aia .campo input,.aia .campo select{width:100%;border-radius:.5rem;border:1px solid rgba(128,128,128,.35);background:transparent;padding:.42rem .6rem;font-size:.88rem}
        .aia .campo input:focus,.aia .campo select:focus{border-color:var(--aia-ambar);outline:none;box-shadow:0 0 0 3px rgba(245,158,11,.15)}
        .aia .campo.revisar input,.aia .campo.revisar select{border-color:var(--aia-ambar);background:rgba(245,158,11,.06)}
        .aia .campo.ancho{grid-column:span 2}
        @media (max-width:640px){.aia .campo.ancho{grid-column:auto}}
        /* La lista que se despliega es del navegador: sin esto, en modo oscuro
           salía con letra blanca sobre su fondo blanco y no se leía nada. */
        .aia select option,.aia select optgroup{background:#fff;color:rgb(17 24 39)}
        .dark .aia{color-scheme:dark}
        .dark .aia select option,.dark .aia select optgroup{background:rgb(39 39 42);color:rgb(244 244 245)}
        .aia select optgroup{font-weight:700;font-style:normal}
        .aia .donde-va{display:flex;gap:.55rem;align-items:flex-start;border-radius:.6rem;padding:.6rem .75rem;font-size:.85rem;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.35)}
        .aia .donde-va svg{width:1.1rem;height:1.1rem;flex:none;color:rgb(5 150 105);margin-top:.1rem}
        .aia .campo small{display:block;font-size:.7rem;color:rgb(107 114 128);margin-top:.15rem}
        .aia .campo.revisar small{color:rgb(217 119 6)}
        .aia ul.lista{list-style:disc;padding-left:1.2rem;font-size:.85rem}
        .aia .dups{display:flex;flex-direction:column;gap:.35rem}
        .aia .dup{display:flex;flex-wrap:wrap;gap:.3rem .7rem;align-items:baseline;font-size:.85rem;border-radius:.5rem;padding:.45rem .6rem;background:var(--aia-suave)}
        .aia .dup.fuerte{background:rgba(239,68,68,.08)}
        .aia .dup a{text-decoration:underline}
        .aia .antes-despues{display:grid;gap:.6rem;grid-template-columns:1fr auto 1fr;align-items:center;font-size:.88rem}
        .aia .antes-despues .flecha{font-size:1.2rem;color:rgb(107 114 128)}
        .aia .caja{border-radius:.5rem;padding:.55rem .7rem;background:var(--aia-suave)}

        /* Reconocimiento por foto */
        .aia .reconocido{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem;border-radius:.7rem;padding:.75rem .9rem;background:rgba(16,185,129,.08);border:1px solid rgba(16,185,129,.35)}
        .aia .reconocido-nombre{display:flex;align-items:center;gap:.5rem;font-weight:600;font-size:1rem}
        .aia .reconocido-nombre a{text-decoration:underline}
        .aia .lo-que-vio{display:grid;gap:.5rem .9rem;grid-template-columns:repeat(auto-fit,minmax(9.5rem,1fr));border-radius:.6rem;background:var(--aia-suave);padding:.7rem .85rem}
        .aia .lo-que-vio span{display:block;font-size:.68rem;text-transform:uppercase;letter-spacing:.05em;color:rgb(107 114 128)}
        .aia .lo-que-vio b{display:block;font-size:.86rem;font-weight:600;word-break:break-word}
        .aia .lo-que-vio b.vacio{font-weight:400;color:rgb(156 163 175);font-style:italic}
        .aia .candidatos{display:flex;flex-direction:column;gap:.4rem}
        .aia .candidato{display:flex;align-items:center;justify-content:space-between;gap:.75rem;border:1px solid var(--aia-borde);border-radius:.6rem;padding:.5rem .7rem;font-size:.86rem}
        .aia .candidato:hover{border-color:rgba(245,158,11,.5)}
        .aia .candidato a{font-weight:600;text-decoration:underline}
        /* El objeto entero, sin recortarlo a un cuadrito: una botella alta mostraba solo la etiqueta. */
        .aia .recorte{height:6rem;width:auto;max-width:6rem;object-fit:contain;border-radius:.5rem;border:1px solid var(--aia-borde);background:rgba(128,128,128,.12);cursor:zoom-in;display:block}
        .aia-lupa{position:fixed;inset:0;z-index:60;background:rgba(0,0,0,.75);display:flex;align-items:center;justify-content:center;padding:1.5rem;cursor:zoom-out}
        .aia-lupa img{max-height:90vh;max-width:90vw;border-radius:.75rem;box-shadow:0 10px 40px rgba(0,0,0,.5)}
        .aia .candidato.probable{border-color:rgba(16,185,129,.55);background:rgba(16,185,129,.06)}
        .aia .buscar-a-mano{display:flex;align-items:center;gap:.5rem;border:1px dashed var(--aia-borde);border-radius:.6rem;padding:.35rem .4rem .35rem .65rem}
        .aia .buscar-a-mano:focus-within{border-color:var(--aia-ambar);border-style:solid}
        .aia .buscar-a-mano svg{width:1rem;height:1rem;color:rgb(107 114 128);flex:none}
        .aia .buscar-a-mano input{flex:1;min-width:0;border:0;background:transparent;font-size:.85rem;outline:none;box-shadow:none;padding:.25rem 0}
        .aia .pastilla{font-size:.74rem;font-weight:600;border-radius:999px;padding:.1rem .5rem;background:var(--aia-suave);white-space:nowrap}
        .aia .pastilla.alta{background:rgba(16,185,129,.15);color:rgb(5 150 105)}
        .aia .pastilla.media{background:var(--aia-ambar-suave);color:rgb(217 119 6)}
        .aia .servicio{display:flex;align-items:flex-start;gap:.55rem;font-size:.85rem}
        .aia .servicio .punto{margin-top:.35rem;flex:none}
        .aia .servicio b{display:block}

        /* Historial */
        .aia table{width:100%;font-size:.84rem;border-collapse:collapse}
        .aia th,.aia td{text-align:left;padding:.55rem .6rem;border-bottom:1px solid rgba(128,128,128,.16);vertical-align:top}
        .aia th{font-size:.68rem;text-transform:uppercase;letter-spacing:.06em;color:rgb(107 114 128)}
        .aia tbody tr:hover{background:var(--aia-suave)}
        .aia .tabla{overflow-x:auto}
        .aia .etiqueta-accion{display:inline-block;font-size:.72rem;border-radius:999px;padding:.08rem .5rem;background:var(--aia-ambar-suave);color:rgb(180 83 9);margin-bottom:.15rem}
        .dark .aia .etiqueta-accion{color:rgb(251 191 36)}
    </style>

    @php
        $estado = $this->estado;
        $permisos = $this->permisos;
        $cat = $this->catalogo;
        $m = $this->medicion;
        $chispa = '<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.6" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904 9 18.75l-.813-2.846a4.5 4.5 0 0 0-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 0 0 3.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 0 0 3.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 0 0-3.09 3.09ZM18.259 8.715 18 9.75l-.259-1.035a3.375 3.375 0 0 0-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 0 0 2.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 0 0 2.456 2.456L21.75 6l-1.035.259a3.375 3.375 0 0 0-2.456 2.456Z"/></svg>';
        $ideas = [
            ['heroicon-o-camera', 'Registrar desde una foto', 'Analiza esta foto. ¿Ya existe en el inventario? Si no, prepara el registro. No lo guardes todavía.'],
            ['heroicon-o-tag', 'Leer una etiqueta', 'Lee la etiqueta y extrae marca, modelo y número de serie. Si algo no se ve bien, dímelo; no lo inventes.'],
            ['heroicon-o-arrows-right-left', 'Trasladar un equipo', 'Busca el equipo con placa EQ-023 y propón trasladarlo al Taller. Muéstrame dónde está ahora.'],
            ['heroicon-o-archive-box', 'Revisar existencias', '¿Qué insumos están por debajo del stock mínimo y cuánto falta para llegar al mínimo?'],
        ];
    @endphp

    <div class="aia">
        <div class="cuerpo">
            {{-- La conversación --}}
            <div class="panel">
                <div class="panel-cabeza">
                    <div class="titulo">
                        <span class="avatar">{!! $chispa !!}</span>
                        <div>
                            Conversación
                            <div class="gris" style="font-weight:400;font-size:.78rem">La IA propone; nada se guarda hasta que pulses «Confirmar».</div>
                        </div>
                    </div>
                    @if ($mensajes)
                        <x-filament::button color="gray" size="sm" wire:click="nuevaConversacion" icon="heroicon-o-plus">
                            Nueva conversación
                        </x-filament::button>
                    @endif
                </div>

                <div class="chat" id="aia-chat"
                     x-data
                     x-init="$nextTick(() => $el.scrollTop = $el.scrollHeight)"
                     x-effect="$wire.mensajes; $nextTick(() => $el.scrollTop = $el.scrollHeight)">
                    @forelse ($mensajes as $i => $msg)
                        <div class="msg {{ $msg['rol'] }}" wire:key="msg-{{ $i }}">
                            @if ($msg['rol'] === 'ia')
                                <span class="avatar">{!! $chispa !!}</span>
                            @endif
                            <div class="msg-cuerpo">
                                @if (! empty($msg['miniaturas']))
                                    <div class="minis">
                                        @foreach ($msg['miniaturas'] as $n => $mini)
                                            <figure>
                                                <img src="{{ $mini }}" alt="Foto {{ $n + 1 }}">
                                                <figcaption>Foto {{ $n + 1 }}</figcaption>
                                            </figure>
                                        @endforeach
                                    </div>
                                @elseif ($msg['miniatura'])
                                    <img class="mini" src="{{ $msg['miniatura'] }}" alt="Foto adjunta">
                                @endif

                                @if ($msg['texto'] !== '')
                                    <div class="burbuja">{{ $msg['texto'] }}</div>
                                @endif

                                @if ($msg['error'])
                                    <div class="aviso rojo">
                                        {{ $msg['error'] }}
                                        @if ($msg['reintentar'] ?? null)
                                            <div style="margin-top:.6rem">
                                                <x-filament::button size="xs" color="gray" icon="heroicon-m-arrow-path"
                                                                    wire:click="reintentar({{ $i }})" wire:loading.attr="disabled" wire:target="reintentar">
                                                    Reintentar con la misma foto
                                                </x-filament::button>
                                            </div>
                                        @endif
                                    </div>
                                @endif

                                {{-- Primero se filtra qué es de verdad un objeto; después, el inventario. --}}
                                @if ($msg['filtro'] ?? false)
                                    @include('filament.pages.activo-ia.filtro', ['i' => $i, 'msg' => $msg])
                                @else
                                @foreach ($msg['propuestas'] as $clave)
                                    @php($p = $propuestas[$clave] ?? null)
                                    @if ($p && ! in_array($p['estado'], ['olvidada', 'por_revisar'], true))
                                        @include($p['clase'] === 'reconocimiento' ? 'filament.pages.activo-ia.reconocimiento' : 'filament.pages.activo-ia.tarjeta', ['clave' => $clave, 'p' => $p])
                                    @endif
                                @endforeach
                                @endif

                                {{-- Si el modelo no vio algo que está en la foto, se agrega a mano. --}}
                                @if (! ($msg['filtro'] ?? false) && collect($msg['propuestas'])->contains(fn ($c) => ($propuestas[$c]['clase'] ?? null) === 'reconocimiento' && ($propuestas[$c]['estado'] ?? null) !== 'olvidada'))
                                    <div>
                                        <x-filament::button size="sm" color="gray" icon="heroicon-m-plus" wire:click="agregarArticulo({{ $i }})">
                                            Falta un artículo de la foto
                                        </x-filament::button>
                                    </div>
                                @endif

                                <span class="hora">{{ $msg['rol'] === 'ia' ? 'Activo IA' : 'Tú' }} · {{ $msg['hora'] }}</span>
                            </div>
                        </div>
                    @empty
                        <div class="bienvenida">
                            <span class="avatar">{!! $chispa !!}</span>
                            <h3>¿Qué hacemos con el inventario?</h3>
                            <p class="gris">Muéstrale un equipo, una etiqueta o una caja de insumos y dile qué necesitas.</p>

                            @unless ($estado['disponible'])
                                <div class="aviso ambar" style="margin-top:.6rem;text-align:left">
                                    <strong>Todavía no está conectado.</strong> {{ $estado['porQue'] }}
                                    El inventario y el historial ya funcionan; solo falta ese paso para que conteste.
                                </div>
                            @endunless

                            <div class="ideas">
                                @foreach ($ideas as [$icono, $titulo, $ejemplo])
                                    <button type="button" class="idea" x-on:click="$wire.set('texto', {{ \Illuminate\Support\Js::from($ejemplo) }}); $nextTick(() => document.getElementById('aia-texto')?.focus())">
                                        <x-filament::icon :icon="$icono" />
                                        <div><b>{{ $titulo }}</b><span>{{ $ejemplo }}</span></div>
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforelse

                    @if ($enCurso)
                        {{-- El reconocimiento corre en la cola: se pregunta cada 3 s si ya está. --}}
                        <div class="msg ia" wire:poll.3s="revisarReconocimiento" style="display:flex">
                            <span class="avatar">{!! $chispa !!}</span>
                            <div class="burbuja cargando">
                                <span class="puntos"><i></i><i></i><i></i></span>
                                {{ $enCurso['tipo'] === 'otros' ? 'Mirando la foto otra vez…' : 'Mirando las fotos y el inventario…' }}
                                con varios objetos o varias fotos puede tardar unos minutos. Puedes seguir revisando lo de arriba mientras tanto; no cierres esta página.
                            </div>
                        </div>
                    @else
                        <div class="msg ia" wire:loading.flex wire:target="enviar,reintentar">
                            <span class="avatar">{!! $chispa !!}</span>
                            <div class="burbuja cargando">
                                <span class="puntos"><i></i><i></i><i></i></span>
                                Enviando la foto…
                            </div>
                        </div>
                    @endif
                </div>

                <form class="composer" wire:submit="enviar"
                      x-data="{
                          oyendo: false,
                          rec: null,
                          puedeDictar: !!(window.SpeechRecognition || window.webkitSpeechRecognition),
                          dictar() {
                              if (this.oyendo) { this.rec?.stop(); return; }
                              const R = window.SpeechRecognition || window.webkitSpeechRecognition;
                              this.rec = new R();
                              this.rec.lang = 'es-CO';
                              this.rec.interimResults = false;
                              this.rec.onresult = (e) => {
                                  const dicho = Array.from(e.results).map(r => r[0].transcript).join(' ');
                                  $wire.set('texto', (($wire.texto || '') + ' ' + dicho).trim());
                              };
                              this.rec.onend = () => this.oyendo = false;
                              this.oyendo = true;
                              this.rec.start();
                          },
                      }">
                    @error('foto') <div class="aviso rojo">{{ $message }}</div> @enderror
                    @error('texto') <div class="aviso rojo">{{ $message }}</div> @enderror

                    <div class="caja-texto">
                        <textarea id="aia-texto" wire:model="texto" rows="2"
                                  placeholder="{{ $estado['disponible'] ? 'Escribe qué necesitas, o adjunta una foto…' : 'Conecta la clave de la API para empezar.' }}"
                                  x-on:keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); $wire.enviar() }"
                                  @disabled(! $estado['disponible'])></textarea>

                        <div class="herramientas">
                            <div class="fila">
                                @if ($foto && method_exists($foto, 'temporaryUrl'))
                                    <span class="previa">
                                        <img src="{{ $foto->temporaryUrl() }}" alt="Foto por enviar">
                                        Foto lista
                                        <button type="button" wire:click="quitarFoto" title="Quitar la foto">
                                            <x-filament::icon icon="heroicon-m-x-mark" style="width:1rem;height:1rem" />
                                        </button>
                                    </span>
                                @endif

                                @foreach ($fotos as $n => $una)
                                    @if (method_exists($una, 'temporaryUrl'))
                                        <span class="previa" wire:key="previa-{{ $n }}">
                                            <img src="{{ $una->temporaryUrl() }}" alt="Foto {{ $n + 1 }} por enviar">
                                            {{ $n + 1 }}
                                            <button type="button" wire:click="quitarFoto({{ $n }})" title="Quitar esta foto">
                                                <x-filament::icon icon="heroicon-m-x-mark" style="width:1rem;height:1rem" />
                                            </button>
                                        </span>
                                    @endif
                                @endforeach

                                @if (! $foto)
                                    {{-- Una o varias: de la misma repisa desde otros ángulos, o de otras. --}}
                                    <label class="icono-boton" title="Adjuntar una o varias fotos (hasta {{ \App\Filament\Pages\ActivoIa::MAX_FOTOS }})">
                                        <x-filament::icon icon="heroicon-o-photo" />
                                        {{ $fotos ? 'Cambiar fotos' : 'Fotos' }}
                                        <input type="file" accept="image/*" multiple wire:model="fotos" style="display:none" @disabled(! $estado['disponible'])>
                                    </label>
                                    @if (! $fotos)
                                        <label class="icono-boton" title="Tomar una foto con la cámara">
                                            <x-filament::icon icon="heroicon-o-camera" />
                                            Cámara
                                            <input type="file" accept="image/*" capture="environment" wire:model="foto" style="display:none" @disabled(! $estado['disponible'])>
                                        </label>
                                    @endif
                                @endif
                                <span class="gris" wire:loading wire:target="foto,fotos">Subiendo…</span>

                                <template x-if="puedeDictar">
                                    <button type="button" class="icono-boton" :class="{ oyendo }" x-on:click="dictar()" @disabled(! $estado['disponible'])>
                                        <x-filament::icon icon="heroicon-o-microphone" />
                                        <span x-text="oyendo ? 'Escuchando… toca para parar' : 'Dictar'"></span>
                                    </button>
                                </template>
                            </div>

                            <x-filament::button type="submit" size="sm" icon="heroicon-m-paper-airplane" icon-position="after"
                                                wire:loading.attr="disabled" wire:target="enviar,foto,fotos" :disabled="! $estado['disponible'] || $enCurso">
                                Enviar
                            </x-filament::button>
                        </div>
                    </div>

                    <div class="pie">
                        <span><kbd>Enter</kbd> envía · <kbd>Shift</kbd> + <kbd>Enter</kbd> nueva línea</span>
                        <span>Lo que cambie datos se confirma siempre con el botón, también lo dictado.</span>
                    </div>
                </form>
            </div>

            {{-- La barra lateral --}}
            <aside class="lateral">
                <div class="tarjeta-lateral">
                    <h3>Estado</h3>
                    <div class="servicio">
                        <span class="punto {{ $estado['fotos'] && $estado['fotos_responde'] ? '' : 'apagado' }}"></span>
                        <div>
                            <b>Fotos · servidor del FabLab</b>
                            <span class="gris" style="font-size:.78rem">
                                @if (! $estado['fotos'])
                                    Apagado (FABLAB_IA_ACTIVO).
                                @elseif ($estado['fotos_responde'])
                                    Conectado. Las fotos no salen del laboratorio.
                                @else
                                    No responde: hay que estar en la red del FabLab.
                                @endif
                            </span>
                        </div>
                    </div>
                    <div class="servicio">
                        <span class="punto {{ $estado['claude'] ? '' : 'apagado' }}"></span>
                        <div>
                            <b>Preguntas escritas · Claude</b>
                            <span class="gris" style="font-size:.78rem">{{ $estado['claude'] ? 'Conectado · ' . $estado['modelo'] : 'Sin conectar: ' . $estado['porQue'] }}</span>
                        </div>
                    </div>
                    @if ($estado['claude'])
                        <div>
                            <div class="fila entre gris" style="font-size:.78rem;margin-bottom:.3rem">
                                <span>Preguntas de hoy</span>
                                <span>{{ $estado['tope'] - $estado['quedan'] }} / {{ $estado['tope'] }}</span>
                            </div>
                            <div class="barra"><i style="width:{{ $estado['tope'] > 0 ? min(100, round(100 * ($estado['tope'] - $estado['quedan']) / $estado['tope'])) : 0 }}%"></i></div>
                        </div>
                    @endif
                </div>

                <div class="tarjeta-lateral">
                    <h3>Cómo funciona</h3>
                    <div class="pasos">
                        <div class="paso"><div><b>Toma la foto</b><span class="gris">De un objeto o de una repisa entera; puedes mandar varias fotos del mismo lugar desde otros ángulos. Que se lean las etiquetas o las placas.</span></div></div>
                        <div class="paso"><div><b>Revisa qué es un objeto</b><span class="gris">Marca cuáles sí lo son y olvida los que no. Si falta alguno, «Busca otro en la foto». Los cables, cargadores y las partes de una máquina no salen aparte.</span></div></div>
                        <div class="paso"><div><b>Elige: ya está o es nuevo</b><span class="gris">Te muestra lo que se le parece en el inventario, del más parecido al menos. Si ya lo tenían, suma; si es nuevo, prepara el registro.</span></div></div>
                        <div class="paso"><div><b>Confirma</b><span class="gris">Solo entonces se guarda, con su foto, y queda en el historial. La próxima vez lo reconoce solo.</span></div></div>
                    </div>
                </div>

                <div class="tarjeta-lateral">
                    <div class="fila entre">
                        <h3>¿Está funcionando?</h3>
                        <span class="gris" style="font-size:.72rem">30 días</span>
                    </div>
                    <div class="cifras">
                        <div class="cifra"><b>{{ $m['consultas'] }}</b><span>consultas · {{ $m['con_foto'] }} con foto</span></div>
                        <div class="cifra"><b>{{ $m['confirmadas'] }}</b><span>de {{ $m['propuestas'] }} propuestas confirmadas</span></div>
                        <div class="cifra"><b>{{ $m['porcentaje_sin_corregir'] !== null ? $m['porcentaje_sin_corregir'] . '%' : '—' }}</b><span>confirmadas sin corregir</span></div>
                        <div class="cifra"><b>{{ $m['duplicados'] }}</b><span>parecidos avisados</span></div>
                    </div>
                    @if ($m['activos'] > 0)
                        <div>
                            <div class="fila entre gris" style="font-size:.78rem;margin-bottom:.3rem">
                                <span>Activos con foto de reconocimiento</span>
                                <span>{{ $m['activos'] - $m['activos_sin_foto'] }} / {{ $m['activos'] }}</span>
                            </div>
                            <div class="barra"><i style="width:{{ round(100 * ($m['activos'] - $m['activos_sin_foto']) / $m['activos']) }}%"></i></div>
                            <p class="gris" style="font-size:.74rem;margin-top:.35rem">Los que nunca aparecen en una foto son candidatos a revisar o dar de baja.</p>
                        </div>
                    @endif
                    @if ($m['mas_corregidos'] !== [])
                        <p class="gris" style="font-size:.78rem">
                            Lo que más se corrige:
                            @foreach ($m['mas_corregidos'] as $campo => $veces)
                                <strong>{{ str_replace(['_id', '_'], ['', ' '], $campo) }}</strong> ({{ $veces }}){{ $loop->last ? '.' : ',' }}
                            @endforeach
                        </p>
                    @endif
                    @if ($m['fallidas'] > 0)
                        <p class="gris" style="font-size:.78rem">{{ $m['fallidas'] }} {{ $m['fallidas'] === 1 ? 'consulta falló' : 'consultas fallaron' }}.</p>
                    @endif
                </div>
            </aside>
        </div>

        {{-- Historial, a lo ancho --}}
        <x-filament::section icon="heroicon-o-clock" collapsible>
            <x-slot name="heading">Historial de cambios</x-slot>
            <x-slot name="description">Lo que se confirmó desde aquí: quién, cuándo y qué cambió.</x-slot>

            @if ($this->historial->isEmpty())
                <p class="gris">Todavía no se ha confirmado ninguna propuesta. Cuando confirmes una, aparece aquí.</p>
            @else
                <div class="tabla">
                    <table>
                        <thead>
                            <tr><th>Cuándo</th><th>Quién</th><th>Acción</th><th>Registro</th><th>Cambio</th></tr>
                        </thead>
                        <tbody>
                            {{-- Qué registros tienen foto, en una sola consulta y no una por fila. --}}
                            @php($conFoto = \App\Models\FotoDeInventario::query()->whereIn('registro_id', $this->historial->pluck('registro_id'))->get(['registro_type', 'registro_id'])->map(fn ($f) => $f->registro_type . ':' . $f->registro_id)->flip())
                            @php($campoDeFicha = ['brand' => 'marca', 'model' => 'modelo', 'asset_tag' => 'placa'])
                            @foreach ($this->historial as $a)
                                <tr wire:key="acc-{{ $a->id }}">
                                    <td style="white-space:nowrap">{{ $a->created_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y H:i') }}</td>
                                    <td>{{ $a->user?->name ?? '—' }}</td>
                                    <td><span class="etiqueta-accion">{{ \App\Models\AccionDeInventarioIa::ACCIONES[$a->accion] ?? $a->accion }}</span></td>
                                    <td>
                                        @if ($url = $this->urlDe($a->registro))
                                            <a href="{{ $url }}" style="text-decoration:underline">{{ $a->registro->name }}</a>
                                        @else
                                            <span class="gris">{{ $a->registro?->name ?? 'registro borrado' }}</span>
                                        @endif
                                    </td>
                                    <td>
                                        @switch($a->accion)
                                            @case('trasladar_activo')
                                                {{ $a->antes['espacio'] ?? 'sin espacio' }}{{ ($a->antes['ubicacion'] ?? null) ? ' · ' . $a->antes['ubicacion'] : '' }}
                                                → {{ $a->despues['espacio'] ?? 'sin espacio' }}{{ ($a->despues['ubicacion'] ?? null) ? ' · ' . $a->despues['ubicacion'] : '' }}
                                                @break
                                            @case('entrada_insumo')
                                                {{ rtrim(rtrim(number_format($a->antes['existencia'] ?? 0, 3, ',', '.'), '0'), ',') }}
                                                → {{ rtrim(rtrim(number_format($a->despues['existencia'] ?? 0, 3, ',', '.'), '0'), ',') }}
                                                {{ $a->despues['unidad'] ?? '' }}
                                                @break
                                            @case('vincular_foto')
                                                Foto n.º {{ $a->despues['fotos'] ?? '?' }} para reconocerlo{{ isset($a->despues['similitud']) ? ' · parecido ' . round($a->despues['similitud'] * 100) . ' %' : '' }}
                                                @break
                                            @case('completar_ficha')
                                                Se completó la ficha: {{ collect($a->despues)->map(fn ($v, $k) => ($campoDeFicha[$k] ?? $k) . ' ' . $v)->join(', ') }}
                                                @break
                                            @default
                                                Registro nuevo{{ isset($conFoto[$a->registro_type . ':' . $a->registro_id]) ? ', con foto' : '' }}
                                        @endswitch
                                        @if ($a->corregidos)
                                            <br><span class="gris">Corregido a mano: {{ implode(', ', $a->corregidos) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
