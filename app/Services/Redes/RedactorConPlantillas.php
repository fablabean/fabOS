<?php

namespace App\Services\Redes;

/**
 * El borrador escrito con plantillas, mientras llega la IA propia (§21).
 *
 * No pretende ser el texto final: da el arranque con los datos ya puestos
 * —la fecha, el horario, el enlace—, que es justo lo que se escribe mal cuando
 * se copia a mano de otra pantalla.
 */
class RedactorConPlantillas implements Redactor
{
    public function borrador(string $tipo, array $datos): string
    {
        $d = fn (string $clave) => trim((string) ($datos[$clave] ?? ''));
        $lab = config('fabos.lab.name');

        $texto = match ($tipo) {
            'anuncio' => match ($d('tipo')) {
                'evento' => 'Te invitamos 🎉',
                'taller' => 'Nuevo taller 🛠️',
                default  => 'Abrimos inscripciones 🛠️',
            } . "\n\n«{$d('nombre')}»"
                . ($d('fecha') ? "\n📅 {$d('fecha')}" : '')
                . ($d('horario') ? "\n🕘 {$d('horario')}" : '')
                . ($d('lugar') ? "\n📍 {$d('lugar')}" : '')
                . ($d('resumen') ? "\n\n{$d('resumen')}" : '')
                . "\n\nInscríbete aquí 👉 {$d('enlace')}",

            'cierre' => "⏳ Últimos días para inscribirte en «{$d('nombre')}».\n\n"
                . 'Las inscripciones cierran el ' . $d('cierre') . '.'
                . ($d('cupos') !== '' ? ' Quedan ' . $d('cupos') . ' cupos.' : '')
                . "\n\n👉 {$d('enlace')}",

            'proyecto' => "Así quedó «{$d('nombre')}» ✨\n\n"
                . ($d('resumen') ?: 'Un proyecto hecho en el ' . $lab . '.')
                . "\n\nTe contamos cómo lo hicimos 👉 {$d('enlace')}",

            'alianza' => "Buscamos aliados para «{$d('nombre')}» 🤝\n\n"
                . ($d('resumen') ?: 'Un proyecto abierto a quien quiera sumarse.')
                . "\n\nConoce el proyecto y súmate 👉 {$d('enlace')}",

            'material' => "Así se ve un día en el {$lab} 📸\n\n"
                . ($d('tema') ? "Esta vez, {$d('tema')}. " : '')
                . '¿Qué te gustaría fabricar a ti?',

            'habilitaciones' => "¡{$d('cuantas')} nuevas habilitaciones este mes! 🎉\n\n"
                . 'Cada certificado es alguien que ya puede usar las máquinas del laboratorio por su cuenta. '
                . '¿Quieres ser el próximo? Pregúntanos cómo.',

            'fecha' => "Hoy es el {$d('nombre')} 🗓️\n\n"
                . '[Cuéntalo desde el laboratorio: ' . ($d('idea') ?: 'qué tiene que ver con lo que hacemos') . ']',

            'hueco' => "Detrás de cámaras en el {$lab} 🎬\n\n"
                . 'Una máquina, un proceso, una persona del equipo: cuéntale a la comunidad qué pasa esta semana.',

            default => $d('nombre'),
        };

        return trim($texto);
    }

    public function nombre(): string
    {
        return 'plantillas';
    }
}
