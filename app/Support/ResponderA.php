<?php

namespace App\Support;

use App\Models\Project;
use App\Models\Reservation;
use Illuminate\Database\Eloquent\Model;

/**
 * A quién le llega la respuesta de un aviso.
 *
 * Los avisos salen de una dirección que nadie lee, pero la gente pulsa
 * «Responder» igual. Que esa respuesta caiga en el vacío es perder la
 * conversación: le llega a quien tiene el asunto asignado —el responsable del
 * proyecto, quien supervisa la reserva— y siempre también al buzón del
 * laboratorio, para que nada dependa de que una sola persona lo lea. Sin nadie
 * asignado, solo al buzón.
 */
class ResponderA
{
    /**
     * @param  array<string,string>  $explicito  correo => nombre que ya decidió quien envía
     * @return array<string,string> correo => nombre
     */
    public static function para(?Model $referencia, ?string $destinatario = null, array $explicito = []): array
    {
        $lista = array_change_key_case($explicito);

        if (! $lista && ($quien = self::asignado($referencia)) && filled($quien->email)) {
            $lista[mb_strtolower($quien->email)] = $quien->name;
        }

        if (filled($buzon = config('fabos.correo.responder_a'))) {
            $lista[mb_strtolower($buzon)] ??= config('fabos.lab.name');
        }

        // Responderse a uno mismo no lleva a ninguna parte.
        unset($lista[mb_strtolower((string) $destinatario)]);

        return $lista ?: ($buzon ? [$buzon => config('fabos.lab.name')] : []);
    }

    /** Quien del equipo tiene el asunto. */
    public static function asignado(?Model $referencia): ?\App\Models\User
    {
        return match (true) {
            $referencia instanceof Project     => $referencia->lead,
            $referencia instanceof Reservation => $referencia->supervisor ?: $referencia->project?->lead,
            $referencia !== null && method_exists($referencia, 'project')
                && ($p = $referencia->project) instanceof Project => $p->lead,
            default => null,
        };
    }
}
