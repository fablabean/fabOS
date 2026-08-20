@extends('layouts.publico')
@section('title', 'Verificación · ' . config('fabos.lab.name'))

@section('styles')
    .tarjeta{
        background:var(--surface);border:1px solid var(--rule);border-radius:8px;
        padding:2rem;max-width:38rem;
    }
    .sello{
        display:inline-flex;align-items:center;gap:.5rem;font-weight:700;
        font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;
        padding:.35rem .7rem;border-radius:4px;margin-bottom:1.2rem;
    }
    .sello.ok{background:color-mix(in srgb,#0D6E63 16%,transparent);color:#0D6E63}
    .sello.no{background:color-mix(in srgb,#9B2C2C 14%,transparent);color:#9B2C2C}
    @media (prefers-color-scheme:dark){
        .sello.ok{color:#5CC9B8}
        .sello.no{color:#E08585}
    }
    .dato{display:flex;gap:1rem;padding:.65rem 0;border-bottom:1px solid var(--rule)}
    .dato b{min-width:9rem;color:var(--muted);font-weight:500;font-size:.88rem}
    .dato span{font-size:.95rem}
    .codigo{
        font-family:ui-monospace,Consolas,monospace;letter-spacing:.12em;
        font-size:1.05rem;color:var(--ink)
    }
    .pie{display:flex;gap:1.4rem;align-items:center;margin-top:1.6rem;flex-wrap:wrap}
    .pie svg{border-radius:4px}
    .pie p{margin:0;font-size:.85rem;color:var(--muted);max-width:22rem}
@endsection

@section('content')
<main>
    <section>
        <p class="rotulo">Verificación</p>

        @if (! $certifab && ! $certificado)
            <h1>No encontramos ese código</h1>
            <div class="tarjeta">
                <span class="sello no">No válido</span>
                <p style="margin:0">
                    El código <span class="codigo">{{ $codigo }}</span> no corresponde a ninguna
                    habilitación ni certificado emitido por el {{ config('fabos.lab.name') }}.
                </p>
                <p class="help" style="margin:1rem 0 0">
                    Revisa que esté bien escrito. Si el documento es reciente y aun así no
                    aparece, escríbenos.
                </p>
            </div>

        @elseif ($certificado)
            {{-- Certificado de un curso aprobado. Acredita formación, no acceso:
                 la habilitación para operar es el certifab, que se verifica con
                 su propio código. --}}
            @php
                $edicion = $certificado->edition;
                $curso = $edicion?->course;
            @endphp

            <h1>Certificado verificado</h1>

            <div class="tarjeta">
                <span class="sello ok">✓ Válido</span>

                <div>
                    <div class="dato">
                        <b>Persona</b>
                        <span>{{ $certificado->user?->name }}</span>
                    </div>
                    <div class="dato">
                        <b>Aprobó</b>
                        <span>
                            {{ $curso?->name }}
                            @if ($curso?->area)
                                <span style="color:var(--muted)">· {{ $curso->area->name }}</span>
                            @endif
                        </span>
                    </div>
                    <div class="dato">
                        <b>Nivel</b>
                        <span>{{ $curso?->level }}@if ($curso?->hours) · {{ $curso->hours }} horas @endif</span>
                    </div>
                    <div class="dato">
                        <b>Terminó</b>
                        <span>
                            {{ $certificado->completed_at?->timezone(config('fabos.lab.timezone'))->format('d/m/Y') }}
                            @if ($edicion?->instructor)
                                · instructor {{ $edicion->instructor->name }}
                            @endif
                        </span>
                    </div>
                    <div class="dato">
                        <b>Cohorte</b>
                        <span class="codigo">{{ $edicion?->code }}</span>
                    </div>
                    <div class="dato" style="border-bottom:0">
                        <b>Código</b>
                        <span class="codigo">{{ $certificado->certificate_code }}</span>
                    </div>
                </div>

                <p style="margin:1.2rem 0 0;font-size:.85rem;color:var(--muted)">
                    Esta credencial también existe como
                    <a href="{{ route('badges.asercion', ['tipo' => $tipoInsignia, 'clave' => $codigo]) }}"
                       target="_blank">Open Badge</a>:
                    un formato estándar que puede leer cualquier verificador, no solo este sitio.
                </p>

                <div class="pie">
                    {!! $qrSvg !!}
                    <p>
                        Este código lleva a esta misma página. Consultado el
                        {{ now(config('fabos.lab.timezone'))->format('d/m/Y \a \l\a\s H:i') }}
                        contra los registros del {{ config('fabos.lab.name') }}.
                    </p>
                </div>
            </div>

        @else
            @php $estado = $certifab->estado(); @endphp

            <h1>Habilitación {{ $estado === 'vigente' ? 'verificada' : 'no vigente' }}</h1>

            <div class="tarjeta">
                <span class="sello {{ $estado === 'vigente' ? 'ok' : 'no' }}">
                    {{ match ($estado) {
                        'vigente'  => '✓ Vigente',
                        'revocado' => '✕ Revocada',
                        'vencido'  => '✕ Vencida',
                    } }}
                </span>

                <div>
                    {{-- Solo lo necesario para verificar: ni correo ni documento. --}}
                    <div class="dato">
                        <b>Persona</b>
                        <span>{{ $certifab->user?->name }}</span>
                    </div>
                    <div class="dato">
                        <b>Habilita</b>
                        <span>
                            {{ $certifab->asset?->name ?? $certifab->riskFamily?->name }}
                            @php $area = $certifab->asset?->area?->name ?? $certifab->riskFamily?->area?->name @endphp
                            @if ($area) <span style="color:var(--muted)">· {{ $area }}</span> @endif
                        </span>
                    </div>
                    <div class="dato">
                        <b>Nivel</b>
                        <span>{{ $certifab->level }}</span>
                    </div>
                    <div class="dato">
                        <b>Otorgada</b>
                        <span>
                            {{ $certifab->granted_at?->timezone(config('fabos.lab.timezone'))->format('d/m/Y') }}
                            @if ($certifab->grantedBy)
                                por {{ $certifab->grantedBy->name }}
                            @endif
                        </span>
                    </div>
                    <div class="dato">
                        <b>Vigencia</b>
                        <span>
                            @if ($estado === 'revocado')
                                Revocada el {{ $certifab->revoked_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y') }}
                            @elseif ($certifab->expires_at)
                                Hasta el {{ $certifab->expires_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y') }}
                            @else
                                Sin vencimiento
                            @endif
                        </span>
                    </div>
                    <div class="dato" style="border-bottom:0">
                        <b>Código</b>
                        <span class="codigo">{{ $certifab->public_code }}</span>
                    </div>
                </div>

                <p style="margin:1.2rem 0 0;font-size:.85rem;color:var(--muted)">
                    Esta credencial también existe como
                    <a href="{{ route('badges.asercion', ['tipo' => $tipoInsignia, 'clave' => $codigo]) }}"
                       target="_blank">Open Badge</a>:
                    un formato estándar que puede leer cualquier verificador, no solo este sitio.
                </p>

                <div class="pie">
                    {!! $qrSvg !!}
                    <p>
                        Este código lleva a esta misma página. Consultado el
                        {{ now(config('fabos.lab.timezone'))->format('d/m/Y \a \l\a\s H:i') }}
                        contra los registros del {{ config('fabos.lab.name') }}.
                    </p>
                </div>
            </div>
        @endif
    </section>
</main>
@endsection
