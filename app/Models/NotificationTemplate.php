<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La plantilla de un aviso (§15).
 *
 * El texto es de quien atiende a la gente, no del programador: se edita desde
 * el backoffice y no requiere despliegue. Lo que el código decide es cuándo se
 * dispara y qué variables tiene disponibles.
 */
class NotificationTemplate extends Model
{
    protected $fillable = [
        'key', 'name', 'channel', 'subject', 'body',
        'is_essential', 'is_active', 'variables', 'description',
    ];

    protected function casts(): array
    {
        return [
            'is_essential' => 'boolean',
            'is_active'    => 'boolean',
            'variables'    => 'array',
        ];
    }

    public const CANALES = [
        'email'    => 'Correo',
        'whatsapp' => 'WhatsApp',
    ];

    /**
     * Reemplaza las variables del texto.
     *
     * Se usa {llave} y no la sintaxis de Blade a propósito: el texto lo edita
     * gente desde un formulario, y no debe poder ejecutar código por escribir
     * algo entre llaves.
     *
     * @param  array<string,mixed>  $datos
     */
    public function render(string $campo, array $datos): string
    {
        $texto = (string) ($this->{$campo} ?? '');

        foreach ($datos as $llave => $valor) {
            $texto = str_replace('{' . $llave . '}', (string) $valor, $texto);
        }

        // Una variable que nadie llenó se borra en vez de aparecer cruda: es
        // preferible una frase incompleta a un correo que dice «{nombre}».
        return trim(preg_replace('/\{[a-z_]+\}/', '', $texto));
    }
}
