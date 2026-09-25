<?php

use App\Models\Area;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Con quién arranca el turno de proyectos, y quién lleva VR (§11).
 *
 * El turno nació vacío y un turno vacío no reparte nada. Esto lo llena con lo
 * que decidió la coordinación: el turno general para Jhonatan y Michael, y VR
 * para Juan Pablo y Camilo.
 *
 * Va en una migración y no en el sembrador porque no es catálogo: es el estado
 * de este laboratorio en este momento. A partir de aquí se administra desde la
 * ficha de cada persona —«Recibe proyectos» y «Responsable de las áreas»—, que
 * es donde debe vivir, y esta migración no vuelve a opinar.
 *
 * Por correo y no por id, y saltándose a quien no exista: en una instalación
 * limpia estas personas no están, y una migración no puede caerse por eso.
 */
return new class extends Migration
{
    /** El turno general: lo que llega sin área, o de un área sin responsables. */
    private const TURNO_GENERAL = [
        'jhescortes@universidadean.edu.co',
        'mstorres@universidadean.edu.co',
    ];

    /**
     * Y los encargos por área.
     *
     * Quien lleva un área recibe los de su área y sólo esos: no entra por eso
     * en el turno general. Por eso Juan Pablo y Camilo no están arriba.
     */
    private const POR_AREA = [
        'vr' => [
            'jpsalazar@universidadean.edu.co',
            'carodriguezc@universidadean.edu.co',
        ],
    ];

    public function up(): void
    {
        User::whereIn('email', self::TURNO_GENERAL)->update(['recibe_proyectos' => true]);

        foreach (self::POR_AREA as $slug => $correos) {
            $areaId = Area::where('slug', $slug)->value('id');

            if (! $areaId) {
                continue;
            }

            User::whereIn('email', $correos)->each(
                // Sin desprender lo que ya tuviera: responder por un área más
                // no es dejar de responder por las otras.
                fn (User $quien) => $quien->responsibleAreas()->syncWithoutDetaching([$areaId]),
            );
        }
    }

    public function down(): void
    {
        User::whereIn('email', self::TURNO_GENERAL)->update(['recibe_proyectos' => false]);

        foreach (self::POR_AREA as $slug => $correos) {
            $areaId = Area::where('slug', $slug)->value('id');

            if (! $areaId) {
                continue;
            }

            User::whereIn('email', $correos)->each(
                fn (User $quien) => $quien->responsibleAreas()->detach($areaId),
            );
        }
    }
};
