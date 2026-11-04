<?php

use App\Models\NotificationTemplate;
use Illuminate\Database\Migrations\Migration;

/**
 * El correo de «recibimos tu solicitud» dice qué día se revisa: cada
 * solicitud toma su turno en la agenda de quien la recibe.
 */
return new class extends Migration
{
    public function up(): void
    {
        $p = NotificationTemplate::where('key', 'proyecto.recibido')->first();

        if (! $p || str_contains($p->body, '{revision}')) {
            return;
        }

        $cuerpo = preg_replace('/(Código: \{codigo\}\R+)/u', '$1{revision}', $p->body, 1, $hechos);

        $p->update([
            'body'      => $hechos ? $cuerpo : $p->body,
            'variables' => array_values(array_unique(array_merge((array) $p->variables, ['revision']))),
        ]);
    }

    public function down(): void
    {
        //
    }
};
