<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Quien se registró en una presentación en vivo, con su ID de 4 caracteres.
 * El ID es para que el profesor lo reconozca cuando le escriba después.
 */
class ParticipanteEnVivo extends Model
{
    protected $table = 'participantes_en_vivo';

    protected $guarded = ['id'];

    /** Sin 0/O, 1/I/L: el ID se dicta y se copia a mano. */
    public const LETRAS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public static function nuevo(string $grupo, string $sala, string $nombre): self
    {
        // Casi 1 millón de códigos posibles: un choque es raro, pero se evita.
        do {
            $codigo = '';
            for ($j = 0; $j < 4; $j++) {
                $codigo .= self::LETRAS[random_int(0, strlen(self::LETRAS) - 1)];
            }
        } while (self::where('codigo', $codigo)->exists());

        return self::create(['codigo' => $codigo, 'grupo' => $grupo, 'sala' => $sala, 'nombre' => $nombre]);
    }

    /** «k7q2 », «K7Q2» → «K7Q2»; lo que no puede ser un ID, null. */
    public static function limpiarCodigo(?string $codigo): ?string
    {
        $c = strtoupper(trim((string) $codigo));

        return preg_match('/^[' . self::LETRAS . ']{4}$/', $c) ? $c : null;
    }
}
