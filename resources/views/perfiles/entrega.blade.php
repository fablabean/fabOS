@php
    $lab = config('fabos.lab.name');
    $institucion = config('fabos.lab.institution');
    $tz = config('fabos.lab.timezone');
    // La misma plantilla sirve la pantalla y el PDF: lo que se ve es lo que se
    // baja. En el PDF la letra es una que el generador trae con acentos y eñes.
    $paraPdf = $paraPdf ?? false;
    $vacio = '—';
    $dato = fn ($v) => filled($v) ? $v : '—';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Perfiles profesionales · {{ $lab }}</title>
    <style>
        /* Se imprime o se baja en PDF: es lo que se le entrega a compras de la
           Universidad. Todo va en tablas y no en flex ni grid, porque el
           generador de PDF no los entiende y la hoja saldría desarmada. */
        *{box-sizing:border-box}
        @if ($paraPdf)
            @page{margin:1.1cm 1.4cm}
        @endif
        body{
            @if ($paraPdf)
                font-family:DejaVu Sans,sans-serif;font-size:11px;padding:0;
            @else
                font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;font-size:14px;padding:2.5rem;
            @endif
            line-height:1.5;color:#111;margin:0;max-width:900px;background:#fff;
        }
        table{width:100%;border-collapse:collapse}
        .hoja{page-break-after:always}
        .hoja:last-child{page-break-after:auto}
        .cabecera{border-bottom:2px solid #111;margin-bottom:1.2rem}
        .cabecera td{padding:0 0 .8rem;vertical-align:top}
        h1{font-size:1.35rem;margin:0}
        .lab{font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:#555}
        .perfil{font-size:.85rem;color:#444;margin-top:.15rem}
        .sello{text-align:right;font-size:.75rem;color:#555}
        h2{font-size:.72rem;text-transform:uppercase;letter-spacing:.07em;color:#555;
           margin:1.2rem 0 .35rem;border-bottom:1px solid #ddd;padding-bottom:.2rem}
        .datos td{padding:.22rem .6rem .22rem 0;vertical-align:top}
        .datos td.et{color:#555;width:32%;white-space:nowrap}
        .docs{margin-top:.3rem}
        .docs th{text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;
                 color:#555;border-bottom:1px solid #ddd;padding:.2rem .6rem .2rem 0}
        .docs td{padding:.22rem .6rem .22rem 0;border-bottom:1px solid #f0f0f0;vertical-align:top}
        .falta{color:#9a3412}
        .nota{margin-top:1.4rem;padding:.6rem .8rem;background:#f6f6f6;border-left:3px solid #999;
              font-size:.8rem;color:#333}
        .pie{margin-top:1.2rem;font-size:.72rem;color:#666;border-top:1px solid #ddd;padding-top:.5rem}
    </style>
</head>
<body>

@foreach ($perfiles as $perfil)
    <div class="hoja">

        <table class="cabecera">
            <tr>
                <td>
                    <div class="lab">{{ $lab }}@if ($institucion) · {{ $institucion }}@endif</div>
                    <h1>{{ $perfil->nombreParaFirmar() }}</h1>
                    @if ($perfil->specialty)
                        <div class="perfil">{{ $perfil->specialty }}@if ($perfil->area) · {{ $perfil->area->name }}@endif</div>
                    @endif
                </td>
                <td class="sello">
                    Perfil para inscripción como proveedor<br>
                    {{ now($tz)->format('d/m/Y') }}
                    @if ($perfil->vendor_code)
                        <br><strong>Proveedor {{ $perfil->vendor_code }}</strong>
                    @endif
                </td>
            </tr>
        </table>

        <h2>Con quién se firma</h2>
        <table class="datos">
            <tr>
                <td class="et">Tipo de persona</td>
                <td>{{ $dato(\App\Models\ProfessionalProfile::PERSONAS[$perfil->person_kind] ?? null) }}</td>
            </tr>
            <tr><td class="et">Documento</td><td>{{ $dato($perfil->documento()) }}</td></tr>
            @if ($perfil->esJuridica())
                <tr><td class="et">Razón social</td><td>{{ $dato($perfil->legal_name) }}</td></tr>
                <tr><td class="et">Representante legal</td><td>{{ $dato($perfil->representative) }}</td></tr>
            @endif
            <tr><td class="et">Correo</td><td>{{ $dato($perfil->email) }}</td></tr>
            <tr><td class="et">Teléfono</td><td>{{ $dato($perfil->phone) }}</td></tr>
            <tr>
                <td class="et">Dirección</td>
                <td>{{ $dato($perfil->address) }}@if ($perfil->city), {{ $perfil->city }}@endif{{ $perfil->country && $perfil->country !== 'Colombia' ? ' · ' . $perfil->country : '' }}</td>
            </tr>
        </table>

        <h2>Tributario</h2>
        <table class="datos">
            <tr>
                <td class="et">Responsable de IVA</td>
                <td>{{ $perfil->vat_liable === null ? $vacio : ($perfil->vat_liable ? 'Sí' : 'No') }}</td>
            </tr>
            <tr>
                <td class="et">Régimen</td>
                <td>{{ $dato(\App\Models\ProfessionalProfile::REGIMENES[$perfil->tax_regime] ?? null) }}</td>
            </tr>
            <tr><td class="et">Actividad económica (CIIU)</td><td>{{ $dato($perfil->ciiu_code) }}</td></tr>
            <tr><td class="et">Responsabilidades del RUT</td><td>{{ $dato($perfil->tax_responsibilities) }}</td></tr>
        </table>

        <h2>Cómo se le paga</h2>
        <table class="datos">
            <tr><td class="et">Banco</td><td>{{ $dato($perfil->bank_name) }}</td></tr>
            <tr>
                <td class="et">Tipo de cuenta</td>
                <td>{{ $dato(\App\Models\ProfessionalProfile::CUENTAS[$perfil->bank_account_kind] ?? null) }}</td>
            </tr>
            <tr><td class="et">Número de cuenta</td><td>En la certificación bancaria</td></tr>
            @if ($perfil->rate_note)
                <tr><td class="et">Lo que cobra</td><td>{{ $perfil->rate_note }}</td></tr>
            @endif
        </table>

        <h2>Seguridad social</h2>
        <table class="datos">
            <tr><td class="et">EPS</td><td>{{ $dato($perfil->eps_name) }}</td></tr>
            <tr><td class="et">Fondo de pensión</td><td>{{ $dato($perfil->pension_fund) }}</td></tr>
            <tr>
                <td class="et">ARL</td>
                <td>{{ $dato($perfil->arl_name) }}@if ($perfil->arl_risk_level) · clase de riesgo {{ $perfil->arl_risk_level }}@endif</td>
            </tr>
        </table>

        <h2>Autorización de tratamiento de datos</h2>
        <table class="datos">
            <tr><td class="et">Autorizó el</td><td>{{ $perfil->consent_at?->format('d/m/Y') ?? $vacio }}</td></tr>
            <tr>
                <td class="et">Por</td>
                <td>{{ $dato(\App\Models\ProfessionalProfile::CANALES_DE_AUTORIZACION[$perfil->consent_channel] ?? null) }}</td>
            </tr>
            @if ($perfil->consent_purpose)
                <tr><td class="et">Para</td><td>{{ $perfil->consent_purpose }}</td></tr>
            @endif
        </table>

        <h2>Documentos</h2>
        @php
            $tiene = $perfil->documents->filter(fn ($d) => $d->existe());
            $faltan = $perfil->documentosQueFaltan();
        @endphp
        <table class="docs">
            <thead>
                <tr>
                    <th>Tiene</th>
                    <th>Falta</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>
                        @forelse ($tiene->unique('kind') as $documento)
                            {{ $documento->tipoLegible() }}@if ($documento->expires_on) <span style="color:#666">(vence {{ $documento->expires_on->format('d/m/Y') }})</span>@endif<br>
                        @empty
                            {{ $vacio }}
                        @endforelse
                    </td>
                    <td class="falta">
                        @forelse ($faltan as $queFalta)
                            {{ $queFalta }}<br>
                        @empty
                            Nada: está completo
                        @endforelse
                    </td>
                </tr>
            </tbody>
        </table>

        @if ($perfil->notes)
            <h2>Observaciones</h2>
            <div>{{ $perfil->notes }}</div>
        @endif

        <div class="nota">
            <strong>El número de cuenta no se guarda en el sistema:</strong> va dentro de la
            certificación bancaria, que se adjunta aparte. Los documentos de esta hoja se
            solicitan al laboratorio; no se publican en ningún enlace.
        </div>

        <div class="pie">
            Datos personales tratados con autorización de su titular (Ley 1581 de 2012).
            Se entregan a {{ $institucion ?: 'la Universidad' }} con la única finalidad de
            estudiar su inscripción como proveedor.
        </div>

    </div>
@endforeach

</body>
</html>
