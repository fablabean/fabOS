<?php

namespace App\Services\Booking;

/**
 * Resultado de evaluar si alguien puede reservar algo (§10).
 *
 * No es un sí o un no: son tres estados. El tercero —«todavía no»— es el que
 * cambia la experiencia, porque en vez de cerrar la puerta indica qué falta.
 */
final class Eligibility
{
    public const AUTONOMO       = 'autonomo';
    public const CON_ACOMPANANTE = 'con_acompanante';
    public const NO_HABILITADO  = 'no_habilitado';

    /**
     * Por qué hace falta acompañamiento. Son dos cosas distintas y se resuelven
     * distinto: una necesita a alguien presente, la otra un visto bueno.
     */
    public const POR_PRESENCIA  = 'presencia';    // la familia exige compañía
    public const POR_APROBACION = 'aprobacion';   // se excedió la autonomía

    /**
     * @param  array<int,string>  $faltantes  qué le falta a la persona
     */
    private function __construct(
        public readonly string $resultado,
        public readonly string $motivo,
        public readonly array $faltantes = [],
        public readonly ?int $maxMinutos = null,
        public readonly ?string $causa = null,
    ) {}

    public static function autonomo(string $motivo, ?int $maxMinutos = null): self
    {
        return new self(self::AUTONOMO, $motivo, [], $maxMinutos);
    }

    public static function conAcompanante(string $motivo, string $causa, ?int $maxMinutos = null): self
    {
        return new self(self::CON_ACOMPANANTE, $motivo, [], $maxMinutos, $causa);
    }

    /** Necesita a alguien presente, no solo una firma. */
    public function requierePresencia(): bool
    {
        return $this->causa === self::POR_PRESENCIA;
    }

    /** @param array<int,string> $faltantes */
    public static function noHabilitado(string $motivo, array $faltantes = []): self
    {
        return new self(self::NO_HABILITADO, $motivo, $faltantes);
    }

    public function puedeReservar(): bool
    {
        return $this->resultado !== self::NO_HABILITADO;
    }

    public function requiereAcompanante(): bool
    {
        return $this->resultado === self::CON_ACOMPANANTE;
    }

    /** Color para la interfaz: verde, ámbar, rojo. */
    public function color(): string
    {
        return match ($this->resultado) {
            self::AUTONOMO        => 'success',
            self::CON_ACOMPANANTE => 'warning',
            default               => 'danger',
        };
    }
}
