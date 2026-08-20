<?php

namespace App\Services\Credentials;

use App\Models\Certifab;
use App\Models\Enrollment;

/**
 * Certifabs y certificados como Open Badges (§19).
 *
 * Un certifab ya se puede verificar en el sitio del laboratorio, pero eso solo
 * sirve mientras alguien se moleste en visitarlo. Open Badges es el formato con
 * el que una credencial viaja: se guarda en una mochila de insignias, se cuelga
 * en LinkedIn y **la verifica cualquier lector del estándar**, no solo nosotros.
 *
 * Se emite en **verificación alojada** (`HostedBadge`), que es la variante del
 * estándar en la que la prueba es que el documento vive en la URL del emisor.
 * La alternativa —firma criptográfica— exige gestionar un par de claves y una
 * política de rotación; sin eso resuelto, firmar daría una falsa sensación de
 * solidez. Cuando el laboratorio tenga esas claves, se añade sin romper nada:
 * los lectores aceptan las dos formas.
 *
 * Nota sobre el correo: el estándar identifica a la persona por su correo, y
 * publicarlo en claro expondría a cualquiera que comparta su insignia. Se
 * publica **hasheado con sal**, que es exactamente para lo que el estándar
 * define `identity`, `hashed` y `salt`.
 */
class OpenBadgeService
{
    /** Contexto y tipos del estándar (Open Badges 2.0). */
    private const CONTEXTO = 'https://w3id.org/openbadges/v2';

    /** El laboratorio como emisor. */
    public function emisor(): array
    {
        return [
            '@context'    => self::CONTEXTO,
            'type'        => 'Issuer',
            'id'          => route('badges.emisor'),
            'name'        => config('fabos.lab.name'),
            'description' => config('fabos.lab.tagline') . ' de ' . config('fabos.lab.institution')
                . ($this->red() ? '. Parte de la red ' . $this->red() . '.' : ''),
            'url'         => route('publico.home'),
            'email'       => config('mail.from.address'),
            'image'       => asset(config('fabos.lab.logo')),
        ];
    }

    /**
     * La clase de insignia: qué acredita, no quién la tiene.
     *
     * Una por familia de riesgo y nivel: «FDM · nivel kilo» es una credencial
     * distinta de «FDM · nivel byte», y quien la lee necesita esa diferencia.
     */
    public function claseDeCertifab(Certifab $certifab): array
    {
        $alcance = $certifab->asset?->name ?? $certifab->riskFamily?->name ?? 'equipo';
        $area = $certifab->asset?->area?->name ?? $certifab->riskFamily?->area?->name;

        return [
            '@context'    => self::CONTEXTO,
            'type'        => 'BadgeClass',
            'id'          => route('badges.clase', ['tipo' => 'certifab', 'clave' => $certifab->public_code]),
            'name'        => $alcance . ' · nivel ' . $certifab->level,
            'description' => 'Habilitación para operar ' . $alcance
                . ($area ? ' (' . $area . ')' : '')
                . ' en ' . config('fabos.lab.name') . '. Acredita formación práctica y '
                . 'criterio de seguridad verificados por quien responde por el área.',
            'image'       => asset(config('fabos.lab.logo')),
            'criteria'    => ['narrative' => $this->criterios($certifab)],
            'issuer'      => route('badges.emisor'),
            'tags'        => array_values(array_filter([
                'fabricación digital', $area, $certifab->level, 'fabOS',
            ])),
        ];
    }

    public function claseDeCertificado(Enrollment $inscripcion): array
    {
        $curso = $inscripcion->edition?->course;

        return [
            '@context'    => self::CONTEXTO,
            'type'        => 'BadgeClass',
            'id'          => route('badges.clase', ['tipo' => 'curso', 'clave' => $inscripcion->certificate_code]),
            'name'        => $curso?->name ?? 'Curso',
            'description' => $curso?->summary
                ?? 'Curso de fabricación digital de ' . config('fabos.lab.name') . '.',
            'image'       => asset(config('fabos.lab.logo')),
            'criteria'    => [
                'narrative' => 'Asistir y aprobar ' . ($curso?->name ?? 'el curso')
                    . ($curso?->hours ? ' (' . $curso->hours . ' horas)' : '')
                    . ', evaluado por quien lo dicta.',
            ],
            'issuer'      => route('badges.emisor'),
            'tags'        => array_values(array_filter([
                'fabricación digital', $curso?->level, $curso?->area?->name, 'fabOS',
            ])),
        ];
    }

    /** La insignia concreta de una persona: la afirmación. */
    public function asercionDeCertifab(Certifab $certifab): array
    {
        $asercion = [
            '@context'  => self::CONTEXTO,
            'type'      => 'Assertion',
            'id'        => route('badges.asercion', ['tipo' => 'certifab', 'clave' => $certifab->public_code]),
            'badge'     => route('badges.clase', ['tipo' => 'certifab', 'clave' => $certifab->public_code]),
            'recipient' => $this->destinatario($certifab->user?->email),
            'issuedOn'  => $certifab->granted_at?->toAtomString(),
            'verification' => ['type' => 'HostedBadge'],
            'evidence'  => [[
                'type'      => 'Evidence',
                'id'        => route('publico.verificar', $certifab->public_code),
                'name'      => 'Verificación en el laboratorio',
                'narrative' => 'Página pública donde cualquiera puede comprobar que esta '
                    . 'habilitación sigue vigente y quién la otorgó.',
            ]],
        ];

        // Una habilitación revocada o vencida no se calla: el estándar tiene
        // campos para decirlo, y ocultarlo sería falsificar la credencial.
        if ($certifab->expires_at) {
            $asercion['expires'] = $certifab->expires_at->toAtomString();
        }

        if ($certifab->revoked_at) {
            $asercion['revoked'] = true;
            $asercion['revocationReason'] = 'Revocada por el laboratorio el '
                . $certifab->revoked_at->timezone(config('fabos.lab.timezone'))->format('d/m/Y');
        }

        return $asercion;
    }

    public function asercionDeCertificado(Enrollment $inscripcion): array
    {
        return [
            '@context'  => self::CONTEXTO,
            'type'      => 'Assertion',
            'id'        => route('badges.asercion', ['tipo' => 'curso', 'clave' => $inscripcion->certificate_code]),
            'badge'     => route('badges.clase', ['tipo' => 'curso', 'clave' => $inscripcion->certificate_code]),
            'recipient' => $this->destinatario($inscripcion->user?->email),
            'issuedOn'  => $inscripcion->completed_at?->toAtomString(),
            'verification' => ['type' => 'HostedBadge'],
            'evidence'  => [[
                'type' => 'Evidence',
                'id'   => route('publico.verificar', $inscripcion->certificate_code),
                'name' => 'Verificación en el laboratorio',
            ]],
        ];
    }

    /**
     * El correo, hasheado con sal.
     *
     * Publicarlo en claro expondría la dirección de cualquiera que comparta su
     * insignia. Quien quiera comprobar que le pertenece hace el mismo hash con
     * la sal publicada y compara.
     */
    private function destinatario(?string $correo): array
    {
        $sal = config('app.key');

        return [
            'type'     => 'email',
            'hashed'   => true,
            'salt'     => substr(hash('sha256', $sal), 0, 16),
            'identity' => 'sha256$' . hash('sha256', mb_strtolower(trim((string) $correo)) . substr(hash('sha256', $sal), 0, 16)),
        ];
    }

    private function criterios(Certifab $certifab): string
    {
        $base = 'Demostrar uso seguro y autónomo del equipo ante quien responde por el área.';

        return match ($certifab->granted_via) {
            'curso'  => $base . ' Otorgada al aprobar un curso del laboratorio.',
            default  => $base . ' Otorgada tras una asesoría uno a uno.',
        };
    }

    private function red(): ?string
    {
        return config('fabos.lab.network') ?: null;
    }
}
