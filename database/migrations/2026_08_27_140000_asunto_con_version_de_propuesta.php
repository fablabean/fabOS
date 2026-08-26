<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El asunto de la propuesta nombra su versión (§11, §15).
 *
 * Dos correos seguidos con el mismo asunto parecen el mismo correo, y el
 * segundo se queda sin abrir justo cuando trae el precio corregido.
 *
 * **Solo se cambia si nadie lo tocó a mano.** Las plantillas se editan desde el
 * backoffice a propósito: pisar un texto que alguien corrigió sería exactamente
 * lo contrario de para qué existen.
 */
return new class extends Migration
{
    private const ORIGINAL = 'Tenemos una propuesta para {proyecto} ({codigo})';

    private const CON_VERSION = 'Propuesta {version} para {proyecto} ({codigo})';

    public function up(): void
    {
        DB::table('notification_templates')
            ->where('key', 'proyecto.propuesta')
            ->where('subject', self::ORIGINAL)
            ->update(['subject' => self::CON_VERSION]);
    }

    public function down(): void
    {
        DB::table('notification_templates')
            ->where('key', 'proyecto.propuesta')
            ->where('subject', self::CON_VERSION)
            ->update(['subject' => self::ORIGINAL]);
    }
};
