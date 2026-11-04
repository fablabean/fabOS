<?php

namespace App\Support;

use App\Models\Space;

/**
 * Qué archivos sirven para la demo de cada zona, y cómo prepararlos.
 *
 * Se reconoce la zona por el nombre del espacio: «Lab. Corte Láser» es láser,
 * «Lab. Impresión 3D Resina» es resina. Lo que no se reconoce acepta lo
 * general —un PDF, imágenes, un comprimido—, que es lo que trae cualquiera.
 */
class FormatosDeDemo
{
    /** Tope por archivo, en KB. */
    public const TAMANO_KB = 51200;

    public const MAXIMO = 5;

    private const ZONAS = [
        'resina' => [
            'extensiones' => ['stl', 'obj', '3mf'],
            'consejo'     => 'El modelo en .stl, .obj o .3mf, a la escala real. Piezas pequeñas: en una hora se ve una impresión corta.',
        ],
        'laser' => [
            'extensiones' => ['dxf', 'svg', 'ai', 'pdf'],
            'consejo'     => 'El dibujo en .dxf (o .svg, .ai, .pdf) en milímetros y a tamaño real. Separa por color: '
                . 'rojo (#FF0000) lo que se corta y azul (#0000FF) lo que se graba en línea; las áreas para grabar en relleno, en negro.',
        ],
        'cnc' => [
            'extensiones' => ['dxf', 'svg', 'stl', 'step', 'stp', 'nc', 'gcode'],
            'consejo'     => 'El dibujo en .dxf o .svg para cortes 2D, o el modelo en .stl/.step para 3D, en milímetros. Si ya tienes la trayectoria, el .nc o .gcode.',
        ],
        '3d' => [
            'extensiones' => ['stl', '3mf', 'obj', 'step', 'stp'],
            'consejo'     => 'El modelo en .stl o .3mf a la escala real. Piezas pequeñas: en una hora se ve una impresión corta, no una de toda la tarde.',
        ],
        'electronica' => [
            'extensiones' => ['ino', 'zip', 'pdf', 'png', 'jpg', 'fzz', 'kicad_pcb'],
            'consejo'     => 'El código (.ino o un .zip con el proyecto) y el esquema del circuito en .pdf o imagen.',
        ],
        'vr' => [
            'extensiones' => ['glb', 'gltf', 'fbx', 'obj', 'zip', 'apk'],
            'consejo'     => 'El modelo o la escena en .glb/.gltf/.fbx, o la aplicación en .apk. Varios archivos, mejor en un .zip.',
        ],
    ];

    private const GENERAL = [
        'extensiones' => ['pdf', 'png', 'jpg', 'jpeg', 'zip', 'stl', 'dxf', 'svg'],
        'consejo'     => 'Lo que se vaya a usar en la demo: el modelo, el dibujo o una referencia en .pdf o imagen.',
    ];

    /** @return array{extensiones: list<string>, consejo: string} */
    public static function para(Space $espacio): array
    {
        $nombre = \Illuminate\Support\Str::of($espacio->name . ' ' . $espacio->slug)->ascii()->lower();

        foreach ([
            'resina'      => ['resina'],
            'laser'       => ['laser'],
            'cnc'         => ['cnc', 'fresad', 'router'],
            '3d'          => ['impresion', '3d'],
            'electronica' => ['electronic'],
            'vr'          => ['vr', 'virtual'],
        ] as $zona => $claves) {
            foreach ($claves as $clave) {
                if ($nombre->contains($clave)) {
                    return self::ZONAS[$zona];
                }
            }
        }

        return self::GENERAL;
    }
}
