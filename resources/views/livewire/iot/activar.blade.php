@php
    $moneda = config('fabos.currency.name');
    $reloj = fn (int $s) => sprintf('%d:%02d', intdiv($s, 60), $s % 60);
@endphp

{{-- Se refresca solo: la fila cambia sin que nadie toque la página. --}}
<div class="bloque iot" wire:poll.5s>
    <style>
        .iot{border:1px solid var(--rule);border-radius:14px;padding:1.4rem;background:var(--surface)}
        .iot h2{margin:0 0 .4rem}
        .iot .sub{color:var(--muted);margin:0 0 1rem}
        .iot .ahora{display:flex;flex-wrap:wrap;gap:.6rem 1.5rem;align-items:center;justify-content:space-between;
                    padding:1rem 1.2rem;border-radius:10px;margin-bottom:1rem;background:color-mix(in srgb,var(--accent) 12%,transparent)}
        .iot .ahora.libre{background:color-mix(in srgb,var(--muted) 12%,transparent)}
        .iot .ahora b{font-size:1.25rem}
        .iot .cuenta{font-size:2rem;font-weight:800;font-variant-numeric:tabular-nums}
        .iot .fila{list-style:none;margin:0 0 1.2rem;padding:0;display:flex;flex-direction:column;gap:.35rem}
        .iot .fila li{display:flex;justify-content:space-between;gap:1rem;padding:.5rem .8rem;border-radius:8px;border:1px solid var(--rule)}
        .iot .fila li.mio{border-color:var(--accent);font-weight:700}
        .iot .aviso{border-radius:10px;padding:.8rem 1rem;margin:0 0 1rem;font-weight:600;border-left:4px solid var(--accent);
                    background:color-mix(in srgb,var(--accent) 12%,transparent)}
        .iot .aviso.malo{border-left-color:#B42318;background:color-mix(in srgb,#B42318 12%,transparent)}
        .iot .rejilla{display:grid;gap:.8rem;grid-template-columns:repeat(auto-fit,minmax(13rem,1fr))}
        .iot label{display:block;font-size:.85rem;color:var(--ink-soft);margin-bottom:.25rem}
        .iot input[type=text]{width:100%;font:inherit;padding:.65rem .75rem;border-radius:8px;border:1px solid var(--rule);
                              background:var(--ground);color:var(--ink)}
        .iot .error{color:#B42318;font-size:.85rem;margin-top:.25rem}
        .iot .nota{font-size:.85rem;color:var(--muted);margin:.6rem 0 0}
        .iot button.btn{border:0;cursor:pointer;font:inherit;font-weight:700}
        .iot button.btn.secundario{border:1px solid var(--accent)}
        .iot .caja{border-top:1px solid var(--rule);margin-top:1.2rem;padding-top:1.2rem}
        .iot .invitar{display:flex;flex-wrap:wrap;gap:1rem;align-items:center}
        .iot .invitar code{word-break:break-all}
    </style>

    @if (! $dispositivo)
        <p class="sub">Este dispositivo ya no está disponible.</p>
    @else
        <h2>{{ $titulo ?: $dispositivo->nombre }}</h2>
        @if ($texto)<p class="sub">{{ $texto }}</p>@endif

        {{-- Quién juega y quién sigue: lo ve todo el mundo. --}}
        @if ($actual)
            @php $quedan = max(0, (int) now()->diffInSeconds($actual->termina_at)); @endphp
            <div class="ahora" wire:key="turno-{{ $actual->id }}">
                <div>Ahora juega<br><b>{{ $actual->nombre }}</b></div>
                {{-- La cuenta baja sola entre refrescos, para que no vaya a saltos. --}}
                <div class="cuenta" x-data="{ s: {{ $quedan }}, t: null }"
                     x-init="t = setInterval(() => { if (s > 0) s-- }, 1000)"
                     x-text="Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0')">{{ $reloj($quedan) }}</div>
            </div>
        @else
            <div class="ahora libre"><div><b>Libre</b><br>Nadie está jugando: el siguiente empieza de inmediato.</div></div>
        @endif

        @if ($fila->isNotEmpty())
            <ol class="fila">
                @foreach ($fila as $n => $t)
                    <li class="{{ $t->id === $miTurnoId ? 'mio' : '' }}">
                        <span>{{ $n + 1 }}. {{ $t->nombre }}{{ $t->id === $miTurnoId ? ' (tú)' : '' }} · {{ $t->minutos }} min</span>
                        <span>en {{ max(1, (int) ceil(now()->diffInSeconds($t->empieza_at) / 60)) }} min</span>
                    </li>
                @endforeach
            </ol>
        @endif

        @if ($aviso)
            <div class="aviso {{ $avisoBueno ? '' : 'malo' }}">
                {{ $aviso }}
            </div>
        @endif

        @if (! $dispositivo->activo || ! $dispositivo->conectado())
            <p class="nota">{{ $dispositivo->nombre }} no está conectado en este momento. Avísale a alguien del laboratorio.</p>

        @elseif (! $persona && $correoDelCodigo)
            {{-- Ya tenía cuenta: el código se escribe aquí, sin salir de la página. --}}
            <form wire:submit="verificar">
                <label for="iot-codigo">El código que te llegó a {{ $correoDelCodigo }}</label>
                <div style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:center">
                    <input id="iot-codigo" type="text" wire:model="codigo" inputmode="numeric" autocomplete="one-time-code"
                           maxlength="12" required autofocus
                           style="max-width:12rem;font-size:1.4rem;letter-spacing:.25em;text-align:center">
                    <button class="btn" type="submit" wire:loading.attr="disabled">Entrar y jugar</button>
                </div>
                @error('codigo') <div class="error">{{ $message }}</div> @enderror
                <p class="nota">
                    Puede tardar un minuto; revisa también el correo no deseado.
                    <button type="button" wire:click="otroCorreo" style="background:none;border:0;padding:0;font:inherit;color:var(--accent);text-decoration:underline;cursor:pointer">Usar otro correo</button>
                </p>
            </form>

        @elseif (! $persona)
            {{-- Sin sesión: registrarse enciende. --}}
            <form wire:submit="registrar">
                <div class="rejilla">
                    <div>
                        <label for="iot-nombre">Tu nombre completo</label>
                        <input id="iot-nombre" type="text" wire:model="nombre" autocomplete="name" required>
                        @error('nombre') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label for="iot-correo">Tu correo</label>
                        <input id="iot-correo" type="text" wire:model="correo" autocomplete="email" inputmode="email" required
                               placeholder="{{ $dominio ? 'usuario o usuario@' . $dominio : 'tu@correo.com' }}">
                        @error('correo') <div class="error">{{ $message }}</div> @enderror
                    </div>
                    <div>
                        <label for="iot-invita">¿Quién te invitó? (opcional)</label>
                        <input id="iot-invita" type="text" wire:model="invita" inputmode="email"
                               placeholder="{{ $dominio ? 'su usuario o su correo' : 'su correo' }}">
                        @error('invita') <div class="error">{{ $message }}</div> @enderror
                    </div>
                </div>

                @if ($dominio)
                    <p class="nota">Con el usuario basta: «ehansen» se entiende como ehansen{{ '@' . $dominio }}. Si es otro correo, escríbelo completo.</p>
                @endif

                <p style="margin:1rem 0 0;display:flex;flex-wrap:wrap;gap:.6rem">
                    <button class="btn" type="submit" wire:loading.attr="disabled">
                        Registrarme y jugar {{ $dispositivo->minutos_turno }} minutos
                    </button>
                    {{-- Con el mismo correo de arriba: le llega el código y lo escribe aquí. --}}
                    <button class="btn secundario" type="button" wire:click="ingresar" wire:loading.attr="disabled">
                        Ya tengo cuenta
                    </button>
                </p>
                <p class="nota">
                    Al registrarte recibes 1 {{ $moneda }} de regalo y no necesitas código.
                    Si ya tienes cuenta, escribe tu correo y pulsa «Ya tengo cuenta»: te llega un código y lo pones aquí mismo.
                </p>
            </form>

        @else
            {{-- Con sesión: su turno, y sus FabCoins para seguir. --}}
            @if ($gratis)
                <p style="margin:0">
                    <button class="btn" type="button" wire:click="activar" wire:loading.attr="disabled">
                        Activar mi turno de {{ $dispositivo->minutos_turno }} minutos
                    </button>
                </p>
                <p class="nota">Es una sola vez por persona.</p>
            @else
                <p class="nota" style="margin-top:0">Ya usaste tu turno, {{ \App\Services\Iot\Turnos::nombreCorto($persona->name) }}. Para seguir, invita a alguien o usa tus {{ $moneda }}s.</p>
            @endif

            <div class="caja">
                <strong>Tienes {{ $saldo }} {{ $moneda }}{{ $saldo === 1 ? '' : 's' }}</strong>
· cada uno vale {{ $dispositivo->minutos_por_fabcoin }} {{ $dispositivo->minutos_por_fabcoin === 1 ? 'minuto' : 'minutos' }} de juego.

                @if ($saldo > 0)
                    <div style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;margin-top:.7rem" x-data="{ n: $wire.entangle('fabcoins') }">
                        <button type="button" class="btn secundario" @click="n = Math.max(1, n - 1)" aria-label="Menos">−</button>
                        <span style="min-width:9rem;text-align:center"><b x-text="n"></b> {{ $moneda }} = <b x-text="n * {{ $dispositivo->minutos_por_fabcoin }}"></b> min</span>
                        <button type="button" class="btn secundario" @click="n = Math.min({{ $saldo }}, n + 1)" aria-label="Más">+</button>
                        <button type="button" class="btn secundario" @click="n = {{ $saldo }}">Todos</button>
                        <button type="button" class="btn" wire:click="pagar" wire:loading.attr="disabled">Reclamar tiempo</button>
                    </div>
                    <p class="nota">O déjalos en tu billetera: sirven para todo lo demás del laboratorio.</p>
                @endif
            </div>

            <div class="caja">
                <strong>Invita y gana 1 {{ $moneda }} por cada persona que se registre</strong>
                <div class="invitar" style="margin-top:.7rem">
                    {!! $qr !!}
                    <div style="flex:1;min-width:14rem">
                        <p style="margin:0 0 .4rem">Que escanee este código, o que al registrarse escriba que la invitaste tú:</p>
                        <p style="margin:0;font-size:1.15rem"><b>{{ \App\Models\User::nickDe($persona->email) ?? $persona->email }}</b></p>
                        <p class="nota"><code>{{ $enlace }}</code></p>
                    </div>
                </div>
            </div>
        @endif
    @endif
</div>
