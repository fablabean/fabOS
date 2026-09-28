<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * Lo que cuenta la página de Fab Academy (`/fab-academy`).
 *
 * La página vende un programa de cinco meses y varios miles de dólares: no se
 * vende con párrafos, se vende con fotos del laboratorio, con proyectos de
 * verdad y con las caras de quien acompaña. Ese material cambia cada cohorte y
 * lo tiene Comunicaciones, no quien despliega; por eso se edita en
 * Formación → Página Fab Academy y no en la plantilla.
 *
 * Lo escrito aquí es el punto de partida: lo que se sabe del programa por su
 * sitio oficial (fabacademy.org). Cada campo que se deje vacío en el panel
 * vuelve a estos textos; las fotos, los videos, el equipo y los proyectos no
 * tienen valor por defecto —inventarlos sería peor que no enseñarlos— y su
 * sección no sale hasta que alguien los sube.
 */
class PaginaFabAcademy
{
    public const CLAVE = 'fab_academy.pagina';

    public const CARPETA = 'fab-academy';

    /** @return array<string,mixed> */
    public static function porDefecto(): array
    {
        return [
            'duracion'  => '5 meses',
            'modalidad' => 'Presencial',

            'video_portada' => ['archivo' => null, 'url' => null, 'rotulo' => 'Mira la experiencia de Fab Academy'],

            'pasos' => [
                ['titulo' => 'Aprende', 'texto' => 'Una clase global cada semana con Neil Gershenfeld y la red de instructores.', 'imagen' => null],
                ['titulo' => 'Fabrica', 'texto' => 'Lo aprendido se hace en el laboratorio, con las máquinas y los instructores locales.', 'imagen' => null],
                ['titulo' => 'Documenta', 'texto' => 'Cada semana queda escrita en tu sitio: al final es un portafolio público.', 'imagen' => null],
                ['titulo' => 'Integra', 'texto' => 'Un proyecto final que junta todas las técnicas en un solo objeto que funciona.', 'imagen' => null],
            ],

            'semana' => [
                ['titulo' => 'Clase global', 'texto' => 'Por videoconferencia, con estudiantes de todo el mundo.'],
                ['titulo' => 'Diseño e investigación', 'texto' => 'Se piensa y se diseña lo que toca hacer esa semana.'],
                ['titulo' => 'Trabajo en laboratorio', 'texto' => 'Con las máquinas y los instructores del nodo.'],
                ['titulo' => 'Documentación', 'texto' => 'El proceso, los errores y los archivos, en tu sitio.'],
                ['titulo' => 'Revisión', 'texto' => 'Instructores locales y globales revisan lo entregado.'],
            ],

            'categorias' => [
                ['nombre' => 'Diseño', 'temas' => "Diseño asistido por computador 2D y 3D\nModelado paramétrico\nGestión de proyectos", 'imagen' => null],
                ['nombre' => 'Fabricación', 'temas' => "Corte láser y de vinilo\nImpresión 3D\nEscaneo 3D\nFresado CNC de gran formato\nMoldes y vaciado", 'imagen' => null],
                ['nombre' => 'Electrónica', 'temas' => "Producción de circuitos\nDiseño de electrónica\nSensores (entradas)\nActuadores (salidas)", 'imagen' => null],
                ['nombre' => 'Programación', 'temas' => "Programación embebida\nRedes y comunicaciones\nInterfaces y aplicaciones", 'imagen' => null],
                ['nombre' => 'Integración y proyecto final', 'temas' => "Diseño de máquinas\nPropiedad intelectual y negocio\nDesarrollo del proyecto final", 'imagen' => null],
            ],

            'portafolio' => [
                'texto' => 'Cada estudiante documenta su proceso semana a semana en un sitio propio: qué hizo, cómo lo hizo, qué falló y los archivos para repetirlo. Esa documentación es la que se evalúa, y al terminar queda como un portafolio técnico público que cualquiera puede revisar.',
                'imagen' => null,
                'url' => 'https://fabacademy.org/2026/highlights.html',
            ],

            'proyectos' => [],

            'grupales' => [
                'texto' => 'Algunas tareas se hacen en equipo con el resto de la cohorte del laboratorio: caracterizar las máquinas, probar materiales o medir circuitos. Se aprende de lo que encuentra cada uno.',
                'ejemplos' => "Pruebas de máquinas\nPruebas de materiales\nMediciones electrónicas",
                'imagen' => null,
            ],

            'laboratorio' => [
                'texto' => 'El programa se cursa aquí: el trabajo de cada semana se hace en el laboratorio, con sus máquinas y con los instructores del nodo.',
                'panoramica' => null,
                'tecnologias' => [],
                'video_archivo' => null,
                'video_url' => null,
            ],

            'equipo' => [],

            'dedicacion' => [
                'horas' => '20 a 35 horas por semana',
                'nota' => 'Depende de tu experiencia previa: sin bases en diseño, electrónica o programación, conviene tomarlo como dedicación de tiempo completo.',
                'reparto' => [
                    ['actividad' => 'Clase global', 'horas' => '3 h'],
                    ['actividad' => 'Diseño e investigación', 'horas' => '5–8 h'],
                    ['actividad' => 'Trabajo en laboratorio', 'horas' => '8–15 h'],
                    ['actividad' => 'Documentación', 'horas' => '4–8 h'],
                ],
            ],

            'inversion' => [
                'incluye' => "Clases globales de Fab Academy\nAcompañamiento de los instructores del nodo\nUso del laboratorio y sus máquinas durante el programa\nEvaluación global y diploma al aprobar",
                'no_incluye' => "Viaje, alojamiento y alimentación para la graduación presencial\nMateriales del proyecto final más allá de los del laboratorio",
                'financiacion' => 'Pregúntanos por las opciones de pago y de apoyo. En la preinscripción nos cuentas cómo piensas financiarlo, y con eso buscamos alternativas.',
            ],

            'flujo' => "Preinscripción en " . config('fabos.lab.name') . "\nRegistro oficial en Fab Academy\nRevisión\nAceptación\nInicio del programa",

            'graduacion' => [
                'texto' => 'Quien aprueba la evaluación global se gradúa en la Fab Conference, el encuentro anual de la red Fab Lab. Ir es parte de la experiencia —conoces a la comunidad y a los instructores con quienes trabajaste a distancia— pero no es obligatorio.',
                'nota' => 'El viaje, el alojamiento, la alimentación y los demás gastos de la graduación presencial no están incluidos.',
                'imagen' => null,
            ],

            'obtiene' => "Diploma Fab Academy\nPortafolio técnico público\nTu proyecto final\nExperiencia en fabricación digital\nComunidad global y red de alumni\nRelación con la red Fab Lab",

            'certificacion' => "Impresión 3D\nCorte láser\nFresado CNC\nElectrónica\nEscaneo 3D",

            'comunidad' => [
                'texto' => 'Cursas en Bogotá, pero no aprendes solo con nosotros: cada semana compartes clase con estudiantes, instructores y laboratorios de todo el mundo. Esa red —otros estudiantes, makers, investigadores y posibles aliados— se queda contigo al terminar.',
                'imagen' => null,
            ],

            'faqs' => [
                ['pregunta' => '¿Necesito saber programar?', 'respuesta' => 'No es requisito para entrar. La programación embebida es uno de los temas del programa y se aprende en él; tener bases ayuda y reduce la dedicación.'],
                ['pregunta' => '¿Necesito experiencia previa?', 'respuesta' => 'No es obligatoria, pero sí recomendada: nociones de diseño 2D y 3D, electrónica y diseño web. Sin ellas, cuenta con que el programa te pedirá tiempo completo.'],
                ['pregunta' => '¿Debo saber inglés?', 'respuesta' => 'Sí. Las clases globales son en inglés y la evaluación global también. Fab Academy pide un muy buen nivel, hablado y escrito.'],
                ['pregunta' => '¿Cuánto tiempo debo dedicar?', 'respuesta' => 'Entre 20 y 35 horas por semana durante unos cinco meses, según tu experiencia.'],
                ['pregunta' => '¿Necesito computador propio?', 'respuesta' => 'Sí, conviene tener uno portátil: con él diseñas, programas y documentas cada semana, en el laboratorio y fuera de él.'],
                ['pregunta' => '¿Qué materiales están incluidos?', 'respuesta' => 'Los que usa el laboratorio para las tareas de cada semana. Para el proyecto final pregúntanos: depende de lo que quieras construir.'],
                ['pregunta' => '¿Cómo funciona la evaluación?', 'respuesta' => 'Cada semana tiene una tarea que se documenta. Hay que completar todas, presentar el proyecto final y pasar la evaluación global de Fab Academy.'],
                ['pregunta' => '¿Quién entrega el diploma?', 'respuesta' => 'Fab Academy. El programa se basa en el curso del MIT, pero el diploma no lo otorga el MIT ni tiene vínculo institucional con él.'],
                ['pregunta' => '¿Puedo participar si no pertenezco a la EAN?', 'respuesta' => 'Sí. El programa está abierto a cualquier persona que pueda venir al laboratorio a trabajar cada semana.'],
            ],

            'enlaces' => [
                ['texto' => 'Sitio oficial de Fab Academy', 'url' => 'https://fabacademy.org/'],
                ['texto' => 'Cómo está estructurado el programa', 'url' => 'https://fabacademy.org/about/course.html'],
                ['texto' => 'Registro oficial', 'url' => 'https://fabacademy.org/apply/registration.html'],
                ['texto' => 'Costos oficiales', 'url' => 'https://fabacademy.org/apply/fees.html'],
                ['texto' => 'Proyectos destacados', 'url' => 'https://fabacademy.org/2026/highlights.html'],
                ['texto' => 'Archivo de cohortes anteriores', 'url' => 'https://fabacademy.org/archive/'],
                ['texto' => 'Proyectos finales (repositorio)', 'url' => 'https://gitlab.fabcloud.org/pub/projects/tree/master'],
                ['texto' => 'Egresados', 'url' => 'https://fabacademy.org/students/alumni-list.html'],
                ['texto' => 'Preguntas frecuentes oficiales', 'url' => 'https://fabacademy.org/students/faq.html'],
            ],
        ];
    }

    /**
     * Lo guardado encima de lo de por defecto, campo por campo: un texto
     * borrado en el panel vuelve al de fábrica en vez de dejar un hueco.
     *
     * @return array<string,mixed>
     */
    public static function contenido(): array
    {
        $guardado = Setting::get(self::CLAVE);

        return self::mezclar(self::porDefecto(), is_array($guardado) ? $guardado : []);
    }

    /**
     * Las listas se toman enteras (si alguien quitó una pregunta, se quitó);
     * los grupos con nombre se mezclan clave por clave; un valor vacío deja el
     * de fábrica.
     */
    private static function mezclar(array $base, array $encima): array
    {
        foreach ($encima as $clave => $valor) {
            if (! array_key_exists($clave, $base)) {
                continue;
            }

            if (is_array($base[$clave]) && ! array_is_list($base[$clave]) && is_array($valor)) {
                $base[$clave] = self::mezclar($base[$clave], $valor);
            } elseif (is_array($base[$clave]) && array_is_list($base[$clave])) {
                // Una lista vacía guardada vale: es «no quiero ninguna».
                if (is_array($valor)) {
                    $base[$clave] = array_values($valor);
                }
            } elseif ($valor !== null && $valor !== '') {
                $base[$clave] = $valor;
            }
        }

        return $base;
    }

    /** Las líneas de un campo de texto, sin vacías. @return list<string> */
    public static function lineas(?string $texto): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $texto))));
    }

    public static function url(?string $ruta): ?string
    {
        return filled($ruta) ? Storage::disk('public')->url($ruta) : null;
    }

    /** Un enlace de YouTube o Vimeo, en su forma para incrustar. */
    public static function embed(?string $url): ?string
    {
        return \App\Models\CourseLesson::embedDe($url);
    }
}
