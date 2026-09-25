<?php

use App\Models\Area;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Quien lleva el turno general responde también por las áreas (§11).
 *
 * Estaba implícito y ahora queda escrito. Jhonatan y Michael ya recibían todo
 * lo que no es de VR —el turno general es el cajón de lo que no tiene área y
 * de las áreas sin responsable—, pero en su ficha sólo figuraba «Estampado y
 * bordado», que era un dato viejo y hacía parecer que el resto no lo cubría
 * nadie.
 *
 * VR no: la lleva su propio equipo, y añadirlos ahí repartiría los proyectos
 * de VR entre cuatro en vez de entre dos.
 *
 * Ojo con el efecto de tenerlo explícito: a partir de aquí, cada área tiene
 * dueño, y la lista del área gana sobre el turno general. Quien entre luego al
 * turno recibirá lo que llegue sin área, pero no lo de un área concreta hasta
 * que se le marquen también a él. Se hace desde la ficha de la persona.
 */
return new class extends Migration
{
    private const QUIENES = [
        'jhescortes@universidadean.edu.co',
        'mstorres@universidadean.edu.co',
    ];

    /** La que tiene equipo propio y no entra en el reparto general. */
    private const CON_EQUIPO_PROPIO = ['vr'];

    public function up(): void
    {
        $areas = Area::whereNotIn('slug', self::CON_EQUIPO_PROPIO)->pluck('id')->all();

        User::whereIn('email', self::QUIENES)->each(
            // Sin desprender: si alguien ya respondía por algo más, sigue.
            fn (User $quien) => $quien->responsibleAreas()->syncWithoutDetaching($areas),
        );
    }

    public function down(): void
    {
        $areas = Area::whereNotIn('slug', self::CON_EQUIPO_PROPIO)->pluck('id')->all();

        User::whereIn('email', self::QUIENES)->each(
            fn (User $quien) => $quien->responsibleAreas()->detach($areas),
        );
    }
};
