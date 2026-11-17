{{-- Lo que se le propuso a quien pidió la reserva, desde la bandeja: quién,
     cuándo, el mensaje y las opciones —con su enlace, por si hay que
     reenviárselo por otro lado— y cuál tomó, si ya tomó una. --}}
@php
    $p = $reserva->propuesta;
    $tz = config('fabos.lab.timezone');
    $por = \App\Models\User::find($p['por'] ?? 0)?->name;
    $atiende = \App\Models\User::find($p['atiende'] ?? 0)?->name;
    $tomada = $p['tomada'] ?? null;
@endphp

<div class="propuesta-enviada" style="margin:.6rem 0 0;padding:.6rem .8rem;border-radius:8px;border:1px solid rgba(128,128,128,.25);font-size:.85rem">
    <div class="cab" style="font-size:.75rem;color:rgb(107 114 128);margin-bottom:.3rem">
        Propuesta enviada {{ isset($p['enviada']) ? \Illuminate\Support\Carbon::parse($p['enviada'])->timezone($tz)->locale('es')->isoFormat('D MMM, H:mm') : '' }}
        {{ $por ? 'por ' . $por : '' }} a {{ $reserva->user?->email }}{{ $atiende ? ' · la atendería ' . $atiende : '' }}
    </div>

    @if (filled($p['mensaje'] ?? null))
        <blockquote style="margin:.2rem 0 .5rem;padding-left:.6rem;border-left:3px solid rgba(128,128,128,.35);font-style:italic">{{ $p['mensaje'] }}</blockquote>
    @endif

    @if (! empty($p['opciones']))
        <ul style="margin:.2rem 0 0;padding-left:1.1rem">
            @foreach ($p['opciones'] as $i => $o)
                <li>
                    {{ \App\Services\Booking\OtraOpcion::comoSeDice(\Illuminate\Support\Carbon::parse($o['desde']), \Illuminate\Support\Carbon::parse($o['hasta'])) }}
                    @if ($tomada === $i)
                        <span class="tomada" style="font-weight:600;color:#15803d">· la tomó{{ isset($p['tomada_el']) ? ' el ' . \Illuminate\Support\Carbon::parse($p['tomada_el'])->timezone($tz)->isoFormat('D/MM H:mm') : '' }}</span>
                    @elseif ($tomada === null && $reserva->status === 'solicitada')
                        · <a href="{{ app(\App\Services\Booking\OtraOpcion::class)->enlace($reserva, $i) }}" target="_blank" rel="noopener" style="text-decoration:underline">su enlace</a>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
