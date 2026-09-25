<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/**
 * El nombre del laboratorio, escrito como lo dice la marca (§3, §19).
 *
 * «Fablab Ean» en el sitio y FABLAB EAN en el logo: la misma marca contada de
 * dos formas en la misma pantalla. El logo manda —es lo que está dibujado y lo
 * que se registra—, así que el texto se alinea con él y no al revés.
 *
 * El nombre ya se administra desde Configuración → Este laboratorio, y lo
 * guardado ahí pisa lo que diga `.env`. Esto sólo lo deja puesto sin que haya
 * que entrar a escribirlo; a partir de aquí se cambia desde esa pantalla, que
 * es donde debe vivir.
 *
 * Cambia también el corto, que es el que sale donde el largo no cabe: dejarlo
 * como estaba devolvería la misma mezcla en el sitio más estrecho.
 */
return new class extends Migration
{
    private const COMO_SE_ESCRIBE = 'FABLAB EAN';

    /** Las grafías que ha tenido, para no pisar un nombre puesto a mano. */
    private const COMO_SE_ESCRIBIA = ['Fablab Ean', 'Ean Fablab', 'FabLab EAN'];

    public function up(): void
    {
        foreach (['lab.name', 'lab.short_name'] as $clave) {
            $actual = trim((string) Setting::get($clave, ''));

            // Vacío es «lo que diga .env», que es una de las grafías viejas.
            // Y si alguien ya escribió otra cosa, esa manda: no es asunto de
            // una migración deshacer una decisión posterior.
            if ($actual === '' || in_array($actual, self::COMO_SE_ESCRIBIA, true)) {
                Setting::put($clave, self::COMO_SE_ESCRIBE, 'laboratorio');
            }
        }
    }

    public function down(): void
    {
        foreach (['lab.name', 'lab.short_name'] as $clave) {
            if (trim((string) Setting::get($clave, '')) === self::COMO_SE_ESCRIBE) {
                Setting::put($clave, 'Fablab Ean', 'laboratorio');
            }
        }
    }
};
