<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Los roles los decide el laboratorio, no el código (§5).
 *
 * Hasta hoy los roles eran cinco constantes, y un voluntario —un estudiante
 * que atiende el laboratorio sin ser practicante— no tenía cómo entrar al
 * panel salvo haciéndolo practicante, con lo que eso abre. Ahora un rol es una
 * fila: se crea y se borra desde *Roles y accesos*, aparece como columna en
 * la matriz y entra al panel a lo que se le marque.
 *
 * Los cinco de siempre siguen existiendo y no se borran: el código sigue
 * preguntando por «superadmin» y «administrador» en sitios donde tiene
 * sentido —el segundo factor obligatorio, validar personas—.
 *
 * Dos columnas sobre la tabla de Spatie: cómo se llama para la gente, y si
 * es «del equipo». Lo segundo es lo que hoy decide `ROLES_BACKOFFICE`: quién
 * puede acompañar una reserva, recibir un traspaso, ver el inventario.
 * Comunicaciones no lo es, a propósito; un voluntario, sí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('label', 60)->nullable()->after('name');
            $table->boolean('del_equipo')->default(true)->after('label');
        });

        $etiquetas = [
            'practicante'    => 'Practicante',
            'consultor'      => 'Consultor',
            'administrador'  => 'Administrador',
            'comunicaciones' => 'Comunicaciones',
            'superadmin'     => 'Superadmin',
        ];

        foreach ($etiquetas as $nombre => $etiqueta) {
            DB::table('roles')->where('name', $nombre)->update([
                'label'      => $etiqueta,
                'del_equipo' => $nombre !== 'comunicaciones',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['label', 'del_equipo']);
        });
    }
};
