<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Segundo factor con aplicación de autenticación (§16).
 *
 * Decisiones:
 *  - El secreto y los códigos de recuperación se guardan CIFRADOS. Un volcado
 *    de la base no debe alcanzar para generar códigos válidos.
 *  - Se acepta una ventana de ±1 intervalo (30 s) por desfase de reloj entre el
 *    teléfono y el servidor. Más ancho debilitaría el factor.
 *  - Cada código de recuperación sirve UNA vez y se consume al usarse.
 */
class TwoFactorService
{
    private const RECUPERACION = 8;

    public function __construct(private Google2FA $totp) {}

    /** Genera un secreto nuevo, sin activarlo todavía. */
    public function generarSecreto(User $user): string
    {
        $secreto = $this->totp->generateSecretKey();

        $user->forceFill([
            'two_factor_secret'        => Crypt::encryptString($secreto),
            'two_factor_recovery_codes' => Crypt::encryptString(json_encode($this->generarCodigos())),
            'two_factor_confirmed_at'  => null,
        ])->save();

        return $secreto;
    }

    /**
     * El secreto que se está dando de alta (guardado pero sin confirmar), o
     * uno nuevo si no hay ninguno.
     *
     * Antes se recordaba en la sesión y, si la sesión no lo tenía, se
     * generaba otro. Con el servidor lento, o abriendo la pantalla dos veces,
     * dos peticiones a la vez generaban dos secretos distintos: la pantalla
     * enseñaba el QR de uno y en la base quedaba el otro, así que ningún
     * código de la app coincidía. Ahora manda la base: mientras no se
     * confirme, se muestra siempre el mismo secreto.
     */
    public function secretoParaDarDeAlta(User $user): string
    {
        $user->refresh();

        if ($user->two_factor_confirmed_at === null && ($secreto = $this->secretoDe($user))) {
            return $secreto;
        }

        return $this->generarSecreto($user);
    }

    /** URI que lee la app de autenticación al escanear el QR. */
    public function uriDeRegistro(User $user, string $secreto): string
    {
        return $this->totp->getQRCodeUrl(
            config('fabos.lab.name'),
            $user->email,
            $secreto,
        );
    }

    /** Confirma la activación: hasta aquí el segundo factor no está en vigor. */
    public function confirmar(User $user, string $codigo): bool
    {
        if (! $this->verificar($user, $codigo)) {
            return false;
        }

        $user->forceFill(['two_factor_confirmed_at' => now()])->save();

        return true;
    }

    public function verificar(User $user, string $codigo): bool
    {
        $secreto = $this->secretoDe($user);

        if (! $secreto) {
            return false;
        }

        // Ventana de 2: acepta el código de hasta un minuto antes o después, por
        // si la hora del teléfono va un poco corrida o la página tardó en enviar.
        return (bool) $this->totp->verifyKey($secreto, preg_replace('/\s+/', '', $codigo), 2);
    }

    /** Consume un código de recuperación. Sirve una sola vez. */
    public function usarCodigoDeRecuperacion(User $user, string $codigo): bool
    {
        $codigos = $this->codigosDe($user);
        $buscado = Str::upper(trim($codigo));

        if (! in_array($buscado, $codigos, true)) {
            return false;
        }

        $user->forceFill([
            'two_factor_recovery_codes' => Crypt::encryptString(
                json_encode(array_values(array_diff($codigos, [$buscado])))
            ),
        ])->save();

        return true;
    }

    /** @return array<int,string> */
    public function codigosDe(User $user): array
    {
        if (! $user->two_factor_recovery_codes) {
            return [];
        }

        return json_decode(Crypt::decryptString($user->two_factor_recovery_codes), true) ?: [];
    }

    public function desactivar(User $user): void
    {
        $user->forceFill([
            'two_factor_secret'         => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at'   => null,
        ])->save();
    }

    private function secretoDe(User $user): ?string
    {
        return $user->two_factor_secret
            ? Crypt::decryptString($user->two_factor_secret)
            : null;
    }

    /** @return array<int,string> */
    private function generarCodigos(): array
    {
        return collect(range(1, self::RECUPERACION))
            ->map(fn () => Str::upper(Str::random(5) . '-' . Str::random(5)))
            ->all();
    }
}
