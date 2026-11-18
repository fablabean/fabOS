<x-filament-panels::page>
    @include('filament.pages.partes.estilos-semana')

    @php
        $hoy = \Illuminate\Support\Carbon::today(config('fabos.lab.timezone'))->toDateString();
        $textoModalidad = [
            'normal' => 'Tu modalidad es <strong>normal</strong>: tu horario es el de siempre, en el laboratorio. Si necesitas un cambio, pídeselo a la coordinación.',
            'hibrida' => 'Tu modalidad es <strong>híbrida</strong>: en cada día puedes marcar qué horas trabajas presencial y cuáles remoto. El horario se queda igual.',
            'flexible' => 'Tu modalidad es <strong>flexible</strong>: en cada día puedes mover tus horas y marcar si son presenciales o remotas.',
        ][$modalidad];
    @endphp

    <div class="sem">
        <div class="tarjeta" style="margin-bottom:1rem">
            <p>{!! $textoModalidad !!} Lo que guardes vale de inmediato: lo ve el equipo en «Semana del equipo» y lo usan las reservas y las asesorías. Siempre tiene que quedar al menos una persona presencial por área.</p>
        </div>

        <div class="nav">
            <h2>Semana del {{ $dias[0]['fecha']->locale('es')->isoFormat('D [de] MMMM') }}</h2>
            <button type="button" wire:click="semanaAnterior">← Anterior</button>
            <button type="button" wire:click="estaSemana">Esta semana</button>
            <button type="button" wire:click="semanaSiguiente">Siguiente →</button>
        </div>

        <div class="leyenda">
            <span><span class="punto" style="background:var(--pres)"></span> Presencial</span>
            <span><span class="punto" style="background:var(--rem)"></span> Remoto</span>
            <span><span class="cambio">Cambiado</span> distinto a tu horario de siempre</span>
            <span>Esta semana: <strong>{{ rtrim(rtrim(number_format($horas, 1, ',', ''), '0'), ',') }} h</strong></span>
        </div>

        <div class="dias">
            @foreach ($dias as $d)
                @php $f = $d['fecha']->toDateString(); @endphp
                <div class="dia @if ($f === $hoy) hoy @endif" wire:key="dia-{{ $f }}">
                    <div class="fila">
                        <h3>{{ ucfirst($d['fecha']->locale('es')->isoFormat('dddd D')) }}</h3>
                        @if ($d['ajustado']) <span class="cambio">Cambiado</span> @endif
                    </div>

                    @if ($d['cierre'])
                        <span class="b ausente">Laboratorio cerrado</span>
                    @elseif ($d['ausencia'])
                        <span class="b ausente">{{ $d['ausencia'] }}</span>
                    @elseif ($d['bloques']->isEmpty())
                        <span class="suave">No trabajas este día.</span>
                    @else
                        @foreach ($d['bloques'] as $b)
                            <span class="b {{ $b['modalidad'] }}">
                                {{ $b['inicio'] }}–{{ $b['fin'] }} · {{ $b['modalidad'] === 'remota' ? 'Remoto' : 'Presencial' }}
                            </span>
                            @if ($b['descanso'])
                                <span class="suave">Descanso {{ $b['descanso'][0] }}–{{ $b['descanso'][1] }}</span>
                            @endif
                        @endforeach
                    @endif

                    @if ($d['editable'] && ! $d['cierre'])
                        <div class="fila" style="margin-top:auto">
                            {{ ($this->editarDiaAction)(['fecha' => $f])->label($d['bloques']->isEmpty() ? 'Agregar horas' : 'Cambiar este día')->size('sm') }}
                            @if ($d['ajustado'])
                                {{ ($this->volverAlHorarioAction)(['fecha' => $f])->label('Volver al de siempre')->link()->size('sm') }}
                            @endif
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="tarjeta" style="margin-top:1.25rem">
            <h3 style="font-weight:700;margin-bottom:.3rem">Tu enlace de reunión</h3>
            <p class="suave" style="margin-bottom:.6rem">Cuando atiendes una asesoría desde casa, se ofrece como virtual y este enlace le llega a quien la pidió. Tu sala fija de Teams o Meet.</p>
            <form wire:submit="guardarEnlace" class="fila">
                <input type="url" wire:model="enlace" placeholder="https://teams.microsoft.com/…" style="flex:1;min-width:16rem">
                <x-filament::button type="submit" size="sm">Guardar</x-filament::button>
            </form>
            @error('enlace') <p style="color:rgb(220 38 38);font-size:.8rem;margin-top:.3rem">{{ $message }}</p> @enderror
        </div>
    </div>
</x-filament-panels::page>
