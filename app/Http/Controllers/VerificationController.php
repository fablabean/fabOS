<?php

namespace App\Http\Controllers;

use App\Models\Certifab;
use App\Models\Enrollment;
use App\Services\Qr\QrRenderer;

/**
 * Verificación pública de una habilitación o un certificado (§9).
 *
 * Sin sesión: el valor está en que cualquiera pueda comprobarlo sin depender
 * de que la EAN conteste un correo. Se muestra lo justo para verificar —quién,
 * qué acredita, desde cuándo y si sigue vigente— y nada más: ni correo, ni
 * documento, ni el resto del historial de la persona.
 *
 * Una sola dirección sirve para los dos códigos. Quien recibe un certificado no
 * tiene por qué saber si lo que tiene en la mano es un certifab o el diploma de
 * un curso: pega el código y el sistema resuelve cuál es.
 */
class VerificationController extends Controller
{
    public function __construct(private QrRenderer $qr) {}

    public function show(string $codigo)
    {
        $codigo = strtoupper($codigo);

        $certifab = Certifab::with(['user', 'asset.area', 'riskFamily.area', 'grantedBy'])
            ->where('public_code', $codigo)
            ->first();

        $certificado = $certifab
            ? null
            : Enrollment::with(['user', 'edition.course.area', 'edition.instructor'])
                ->where('certificate_code', $codigo)
                ->where('status', 'aprobado')
                ->first();

        return view('publico.verificar', [
            'certifab'    => $certifab,
            'certificado' => $certificado,
            'codigo'      => $codigo,
            // Para enlazar la misma credencial en formato Open Badge.
            'tipoInsignia' => $certifab ? 'certifab' : 'curso',
            'qrSvg'       => ($certifab || $certificado)
                ? $this->qr->svg(route('publico.verificar', $codigo), 150)
                : null,
        ]);
    }
}
