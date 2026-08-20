<?php

namespace App\Http\Controllers;

use App\Models\Certifab;
use App\Models\Enrollment;
use App\Services\Credentials\OpenBadgeService;
use Illuminate\Http\JsonResponse;

/**
 * Open Badges: las credenciales en formato estándar (§19).
 *
 * Son documentos públicos por definición —la verificación consiste en que
 * cualquiera pueda leerlos en la URL del emisor—, así que no llevan sesión. Lo
 * único delicado, el correo de la persona, va hasheado.
 */
class BadgeController extends Controller
{
    public function __construct(private OpenBadgeService $insignias) {}

    public function emisor(): JsonResponse
    {
        return $this->json($this->insignias->emisor());
    }

    public function clase(string $tipo, string $clave): JsonResponse
    {
        return $this->json(match ($tipo) {
            'certifab' => $this->insignias->claseDeCertifab($this->certifab($clave)),
            'curso'    => $this->insignias->claseDeCertificado($this->certificado($clave)),
            default    => abort(404),
        });
    }

    public function asercion(string $tipo, string $clave): JsonResponse
    {
        return $this->json(match ($tipo) {
            'certifab' => $this->insignias->asercionDeCertifab($this->certifab($clave)),
            'curso'    => $this->insignias->asercionDeCertificado($this->certificado($clave)),
            default    => abort(404),
        });
    }

    private function certifab(string $codigo): Certifab
    {
        return Certifab::with(['user', 'asset.area', 'riskFamily.area'])
            ->where('public_code', strtoupper($codigo))
            ->firstOrFail();
    }

    private function certificado(string $codigo): Enrollment
    {
        return Enrollment::with(['user', 'edition.course.area'])
            ->where('certificate_code', strtoupper($codigo))
            ->where('status', 'aprobado')
            ->firstOrFail();
    }

    /**
     * JSON-LD, que es lo que esperan los lectores del estándar.
     *
     * Sin escapar las barras ni el unicode: una credencial se lee tanto con un
     * validador como con los ojos.
     */
    private function json(array $documento): JsonResponse
    {
        return response()
            ->json($documento, 200, [
                'Content-Type' => 'application/ld+json',
                // Cualquiera puede leerlas desde otro sitio: es el punto.
                'Access-Control-Allow-Origin' => '*',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
