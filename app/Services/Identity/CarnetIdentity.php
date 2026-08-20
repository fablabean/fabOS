<?php

namespace App\Services\Identity;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Resultado de consultar un carne digital.
 *
 * `valid` significa que el codigo esta vivo y la Universidad lo reconoce.
 * Eso NO alcanza para iniciar sesion: hace falta saber A QUIEN pertenece.
 * En los carnes observados el numero de documento puede venir vacio, asi que
 * la identificacion se resuelve contra una cuenta previamente vinculada.
 */
final class CarnetIdentity
{
    public function __construct(
        public readonly bool $valid,
        public readonly ?string $documentNumber = null,
        public readonly ?string $fullName = null,
        public readonly ?string $email = null,
        public readonly ?string $affiliation = null,
        public readonly array $raw = [],
        public readonly ?string $failureReason = null,
        public readonly ?Carbon $expiresAt = null,
    ) {}

    public static function invalid(string $reason): self
    {
        return new self(valid: false, failureReason: $reason);
    }

    /**
     * Identificador estable del carne: el nombre normalizado sin acentos ni
     * dobles espacios. No es perfecto —dos homonimos colisionarian— y por eso
     * se usa solo para reconocer una cuenta YA vinculada, nunca para crear una.
     */
    public function subject(): ?string
    {
        if ($this->documentNumber) {
            return 'doc:' . preg_replace('/\D/', '', $this->documentNumber);
        }

        if ($this->fullName) {
            return 'name:' . Str::lower(Str::ascii(preg_replace('/\s+/u', ' ', trim($this->fullName))));
        }

        return null;
    }
}
