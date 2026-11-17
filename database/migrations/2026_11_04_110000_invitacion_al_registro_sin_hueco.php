<?php

use App\Models\NotificationTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * La invitación al registro de Fab Academy, sin el hueco que dejaba el
 * mensaje opcional cuando no se escribía nada: ahora va pegado a las
 * condiciones y trae su propio párrafo solo si existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        $p = NotificationTemplate::where('key', 'curso.registro_oficial')->first();

        if ($p) {
            $p->update(['body' => preg_replace('/\{condiciones\}\R+\{mensaje\}/u', '{condiciones}{mensaje}', $p->body)]);
        }
    }

    public function down(): void
    {
        //
    }
};
