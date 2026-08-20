<?php

namespace App\Services\Identity;

use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Vincula un carné a una cuenta.
 *
 * Vive en un servicio y no en el controlador porque lo usan tres caminos: la
 * vinculación manual desde «Mi cuenta», el comando de consola, y el ingreso por
 * correo cuando venía un carné pendiente de identificar.
 */
class CarnetLinker
{
    public function __construct(private CarnetClient $client) {}

    /** @return string|null motivo del fallo, o null si quedó vinculado */
    public function vincular(User $user, string $tokenOrUrl): ?string
    {
        $identidad = $this->client->lookup($tokenOrUrl);

        if (! $identidad->valid) {
            return $identidad->failureReason;
        }

        $subject = $identidad->subject();

        if (! $subject) {
            return 'Ese carné no trae datos suficientes para vincularlo.';
        }

        // Un carné pertenece a una sola cuenta. Reasignarlo es una decisión
        // administrativa, no algo que resuelva un inicio de sesión.
        if (User::where('carnet_subject', $subject)->whereKeyNot($user->id)->exists()) {
            return 'Ese carné ya está vinculado a otra cuenta.';
        }

        $user->forceFill([
            'carnet_subject'        => $subject,
            'carnet_linked_at'      => now(),
            'document_number'       => $user->document_number ?: $identidad->documentNumber,
            'identity_verified_at'  => now(),
            'identity_verified_via' => 'carnet_ean',
            // El carné trae el nombre completo; el de la cuenta suele venir del
            // correo ("Mstorres"). Se corrige solo, que además hace que el
            // emparejamiento por nombre funcione en adelante.
            'name'                  => $this->mejorNombre($user->name, $identidad->fullName),
        ])->save();

        Log::info('Carné vinculado', ['user_id' => $user->id]);

        return null;
    }

    /** Se queda con el nombre más completo de los dos. */
    private function mejorNombre(string $actual, ?string $delCarnet): string
    {
        if (! $delCarnet) {
            return $actual;
        }

        $palabras = fn (string $t) => count(preg_split('/\s+/', trim($t)));

        return $palabras($delCarnet) > $palabras($actual)
            ? \Illuminate\Support\Str::title(\Illuminate\Support\Str::lower($delCarnet))
            : $actual;
    }
}
