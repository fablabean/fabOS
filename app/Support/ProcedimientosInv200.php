<?php

namespace App\Support;

/**
 * Los procedimientos del FABLAB en la caracterización INV-200, como dicen sus
 * documentos aprobados (Isolución), para leerlos punto por punto en la
 * presentación de la auditoría.
 *
 * El texto es el del documento; lo que va entre ** ** es el punto clave que un
 * auditor verifica, y se resalta. Cada actividad nombra los registros de FabOS
 * que la evidencian (claves de la presentación). Una actividad sin registros
 * sale marcada en la versión interna: es lo que hay que preparar.
 *
 * Los PDF originales viven en storage/app/private/calidad/procedimientos/{código}.pdf,
 * fuera del repositorio.
 */
class ProcedimientosInv200
{
    public const CARPETA = 'calidad/procedimientos';

    /** @return array<string, array{titulo: string, version: string, fecha: string, objetivo: string, pasos: list<array{num: string, titulo: string, texto: string, registros: list<string>}>}> */
    public static function todos(): array
    {
        return [
            'INV-114' => [
                'titulo' => 'Servicios de Impresión a Terceros',
                'version' => '0', 'fecha' => '14/Jul/2023',
                'objetivo' => 'Determinar las actividades sobre el servicio de impresión a clientes externos de la Universidad Ean.',
                'pasos' => [
                    ['num' => '6.1.1', 'titulo' => 'Formulación de la oferta de servicio',
                        'texto' => 'Una vez se consolida el interés del cliente externo en tomar los servicios de impresión, desde la Unidad de Desarrollo **se estima el costo del servicio**, dicho costo es utilizado como referencia por parte de Gerencia Comercial, quien es la encargada de **enviar la propuesta al cliente**.',
                        'registros' => ['solicitar', 'proyectos_con_propuesta']],
                    ['num' => '6.1.2', 'titulo' => 'Actividades para la impresión',
                        'texto' => "El coordinador de la Unidad de Desarrollo recibe la solicitud de impresión **una vez se haya concretado la relación contractual con el cliente** e informa al profesional especializado por medio de correo electrónico para el inicio con los trabajos de impresión.\n\nSi el cliente proporciona un modelo 3D, el profesional **revisa y evalúa el modelo** para asegurarse de que cumple con los requisitos técnicos y de fabricación. **Previa autorización dada por el cliente** se realizará cualquier modificación necesaria para adaptarlo al proceso de fabricación ofertado. En caso de que el cliente no proporcione un modelo 3D y pague por el servicio de modelado, el profesional con apoyo del auxiliar crea un modelo desde cero utilizando software CAD según las especificaciones y requisitos del cliente.\n\nEl profesional y/o el auxiliar deberán **preparar y ajustar la impresora 3D**: calibrar la máquina, cargar los materiales adecuados y configurar los parámetros de impresión. Cargan el archivo de diseño y comienzan el proceso de impresión; ambos **supervisan el progreso**.\n\nUna vez completada la impresión, retiran la pieza y realizan el **postprocesamiento necesario** (eliminar soportes, lijar o pulir) **e inspecciones de calidad**.",
                        'registros' => ['proyectos_aceptados', 'proyectos_externos']],
                    ['num' => '6.1.3', 'titulo' => 'Recepción de producto por parte del cliente',
                        'texto' => 'Con el producto ya terminado **el cliente recibe y aprueba a satisfacción** el mismo según las características definidas en la propuesta de servicio. El personal encargado confirma vía correo al área comercial o área interna encargada del contacto con el cliente, con el fin de **recibir aceptación por escrito** por parte del cliente; una vez recibido el visto bueno, el área encargada deberá hacer las gestiones internas para **generar la factura** correspondiente.',
                        'registros' => ['proyectos_externos_fin']],
                ],
            ],

            'INV-115' => [
                'titulo' => 'Préstamo de Espacios Físicos, Equipos y/o Herramientas de Laboratorios Unidad de Desarrollo',
                'version' => '0', 'fecha' => '25/Jul/2023',
                'objetivo' => 'Determinar las actividades, un marco normativo y operativo para la solicitud, autorización y control eficiente del préstamo de espacios físicos, equipos y/o herramientas pertenecientes a los laboratorios de la Unidad de Desarrollo.',
                'pasos' => [
                    ['num' => '2', 'titulo' => 'Alcance · equipos que salen del laboratorio',
                        'texto' => 'En el caso de herramientas o equipos portátiles, por norma general **no se permitirá sacarlos del laboratorio** al que pertenecen, salvo en situaciones excepcionales debidamente justificadas. Dichas solicitudes deberán enviarse por correo y estarán sujetas a la **aprobación del responsable del laboratorio**, además de requerir la **firma del Formato de Préstamos**, con el fin de garantizar la trazabilidad y responsabilidad del recurso.',
                        'registros' => ['actas_prestamo']],
                    ['num' => '6.1.1', 'titulo' => 'Realizar solicitud',
                        'texto' => 'El interesado deberá realizar un **agendamiento a través del código QR** dispuesto en los laboratorios. Este código QR redirigirá a un enlace donde se realizará el agendamiento para el préstamo del espacio del laboratorio y el tipo de servicio al que se desea acceder. Es importante realizar el agendamiento con **al menos una semana de anticipación**.',
                        'registros' => ['reservas_espacios', 'reservar', 'etiquetas']],
                    ['num' => '6.1.2', 'titulo' => 'Programación de la reunión',
                        'texto' => "Al llegar la solicitud **el espacio queda apartado automáticamente por el sistema**; el profesional especializado o el auxiliar preparan el espacio para la persona o grupo que utilizará el laboratorio con las herramientas que se requieran.\n\nSi es un agendamiento para impartir una clase, los estudiantes deben estar **acompañados obligatoria y permanentemente por un docente**.",
                        'registros' => ['reservas_espacios']],
                    ['num' => '6.1.3', 'titulo' => 'Reunión',
                        'texto' => "El profesional especializado o el auxiliar recibirá a las personas que han solicitado el laboratorio y pondrá a su disposición el espacio. Existen herramientas específicas —sierra de banco, taladro de árbol, cortadora láser, caladora, esmeriladora o pulidora, e impresoras 3D— que **no serán prestadas: solo podrán ser operadas por el equipo del FABLAB**.\n\nDurante la actividad, **el personal encargado del laboratorio estará presente** para brindar asesoría y garantizar una revisión constante del uso adecuado de las herramientas y equipos.",
                        'registros' => ['reservas_herramientas']],
                    ['num' => '6.1.4', 'titulo' => 'Cerrar solicitud',
                        'texto' => "El cierre de la solicitud se realiza al terminar la actividad y **verificar que el espacio, herramientas y/o equipos se entregan en las mismas condiciones** que se recibieron. El técnico o profesional encargado verifica y asegura la correcta entrega. Se debe **dejar el espacio limpio y ordenado**. Al finalizar la reserva, el usuario devuelve los equipos y herramientas al lugar predeterminado, bajo la supervisión del personal especializado.\n\nNota: para las herramientas y/o equipos utilizados **dentro del laboratorio no se diligencia** el formato INV-115-f1 Préstamo de Equipos.",
                        'registros' => ['reservas_espacios', 'reservas_herramientas']],
                ],
            ],

            'INV-116' => [
                'titulo' => 'Préstamo de Servicios de Prototipado Rápido',
                'version' => '0', 'fecha' => '12/Ago/2023',
                'objetivo' => 'Determinar las actividades para facilitar y brindar servicios de prototipado rápido, incluyendo acceso a los laboratorios de corte láser e impresión 3D, a la comunidad Eanista para respaldar proyectos de investigación, emprendimiento y consultoría.',
                'pasos' => [
                    ['num' => '6.1.1', 'titulo' => 'Impresión 3D · realizar solicitud de agendamiento',
                        'texto' => 'El interesado deberá **escanear el código QR** dispuesto en el laboratorio para agendar la asesoría o préstamo de servicio de prototipado rápido, especificando **la fecha y el tipo de servicio**. También podrá **enviar el archivo del proyecto** o el enlace de la biblioteca 3D donde se encuentre el modelo. En los casos sin envío previo, si el modelo tiene errores o requerimientos especiales, deberá agendar nuevamente.',
                        'registros' => ['reservas_prototipado', 'reservar']],
                    ['num' => '6.1.2', 'titulo' => 'Impresión 3D · verificar y analizar los documentos y la solicitud',
                        'texto' => 'El Profesional Especializado, junto con el Asistente Técnico, **verifican y analizan los documentos y la solicitud**. Con base en los criterios de evaluación, el equipo determina si la Unidad de Desarrollo cubrirá los costos de impresión: si el peso de la impresión **supera los 60 gramos, el usuario proporciona el material**; si no, la Unidad de Desarrollo se hace cargo del costo y del material.',
                        'registros' => ['reservas_prototipado']],
                    ['num' => '6.1.3', 'titulo' => 'Impresión 3D · asesoría y/o préstamo de servicio',
                        'texto' => 'Tras revisar el modelo, el equipo **ofrece recomendaciones y sugerencias específicas**; si la solicitud cumple los requisitos, procede a realizar la impresión. Los elementos terminados se **almacenan únicamente por 7 días hábiles** (10 días hábiles para proyectos de más de 72 horas de impresión); después se desechan los no recogidos.',
                        'registros' => ['asesorias', 'reservas_prototipado']],
                    ['num' => '6.1.4', 'titulo' => 'Impresión 3D · cerrar solicitud',
                        'texto' => 'Al finalizar la orden de trabajo y asegurar que **el cliente ha recibido la pieza de manera satisfactoria**, se **cierra la solicitud**. Si la pieza no cumple con las necesidades del cliente, se realiza un **reproceso**.',
                        'registros' => ['reservas_prototipado']],
                    ['num' => '6.2.1', 'titulo' => 'Cortadora láser · realizar solicitud de agendamiento',
                        'texto' => 'El interesado realiza el **agendamiento a través de los códigos QR** de los laboratorios y envía el archivo del proyecto en **formatos adecuados para corte** (AI, SVG, DXF, PDF, HPGL, PLT o RD) **y grabado** (PNG, JPEG, BMP, TIFF, TGA o GIF), diferenciando por capas lo de corte y lo de grabado. La reserva se genera con **al menos una semana de antelación**.',
                        'registros' => ['reservas_prototipado', 'reservar']],
                    ['num' => '6.2.2', 'titulo' => 'Cortadora láser · verificar y analizar los documentos y la solicitud',
                        'texto' => 'El Profesional Especializado y el Asistente Técnico revisan la documentación para **verificar la cantidad de material** necesario. Es responsabilidad del usuario **proveer el material** (MDF o acrílico), cantidad que se le notifica tras revisar los archivos.',
                        'registros' => ['reservas_prototipado']],
                    ['num' => '6.2.3', 'titulo' => 'Cortadora láser · asesoría y/o préstamo de servicio',
                        'texto' => 'El cliente llega puntualmente con el material; **si llega más de 20 minutos tarde, perderá la reserva**. La **manipulación de la cortadora láser está a cargo exclusivamente del equipo** especializado de la Unidad de Desarrollo, que brinda la asesoría y realiza el corte o grabado. Los elementos cortados están disponibles para su recogida durante **5 días hábiles**.',
                        'registros' => ['asesorias', 'reservas_prototipado']],
                    ['num' => '6.2.4', 'titulo' => 'Cortadora láser · cerrar solicitud',
                        'texto' => 'Al concluir el trabajo y asegurar que **el cliente ha recibido las piezas de manera satisfactoria**, se **cierra la solicitud**. Si las piezas no cumplen con las necesidades del cliente, se realiza un **reproceso**.',
                        'registros' => ['reservas_prototipado']],
                ],
            ],

            'INV-118' => [
                'titulo' => 'Mantenimiento Equipos de Laboratorios Unidad de Desarrollo',
                'version' => '0', 'fecha' => '11/Sep/2024',
                'objetivo' => 'Establecer un procedimiento claro y efectivo para garantizar el óptimo funcionamiento de los equipos del FabLab que apoyan a las diversas áreas de interés de la universidad, mediante la implementación de mantenimientos preventivos regulares y la definición de acciones específicas a seguir en caso de averías.',
                'pasos' => [
                    ['num' => '5', 'titulo' => 'Documentos relacionados',
                        'texto' => 'Hoja de vida de equipos · Revisión rutinaria · Instrucciones y/o manuales del fabricante (si aplica) · **Cronograma de mantenimiento** · Administración y registro contable de activos · **Acta de baja de activos** · **Informe de mantenimiento**.',
                        'registros' => ['pauta', 'actas_baja']],
                    ['num' => '6.1', 'titulo' => 'Generalidades · hoja de vida',
                        'texto' => "Los equipos de los laboratorios cuentan con su **«Hoja de vida equipos y herramientas»** con: nombre del equipo, código Ean, marca, serial, modelo, accesorios y piezas, mantenimiento preventivo y/o correctivo, y observaciones.\n\nNota: los equipos del Anexo 2, catalogados como **Equipos de Apoyo**, **no cuentan con Hoja de Vida**: no afectan la operación en caso de falla, ya que se dispone de suficientes unidades.",
                        'registros' => ['fijos', 'de_apoyo']],
                    ['num' => '6.2', 'titulo' => 'Revisiones rutinarias',
                        'texto' => "6.2.1 Inspección visual: el profesional y/o auxiliar abre el documento «Revisión Rutinaria», **selecciona la categoría del equipo según el esquema de color** y registra las novedades encontradas, completando los campos de su esquema (Anexo 1).\n\n6.2.1.1 Frecuencia: según la categoría de cada equipo en el Anexo 1: **A (mensual), B (bimestral) y C (semestral)**.\n\n6.2.1.2 a 6.2.1.4: **lista de revisión del esquema verde, amarillo o rojo** (limpieza exterior, partes móviles, daños visibles, cableado y conexiones, prueba funcional, accesorios; y en amarillo y rojo, estructura, refrigeración, correas, lubricación, componentes mecánicos, ejes, sistemas de control, seguridad y aspiración).",
                        'registros' => ['con_color', 'con_plan']],
                    ['num' => '6.3', 'titulo' => 'Diagnóstico',
                        'texto' => "Si todo está en buen estado: limpieza y/o lubricación y la máquina sigue en operación normal.\n\nSi se encuentran problemas menores: **se programa el mantenimiento preventivo en el cronograma** y se deja una **breve anotación de las anomalías** observadas.\n\nSi se detectan problemas importantes o de seguridad: **se detiene el uso de la máquina de inmediato**, se programa el mantenimiento correctivo y se **informa inmediatamente al coordinador**.",
                        'registros' => ['preventivas', 'correctivas']],
                    ['num' => '6.4', 'titulo' => 'Mantenimiento preventivo (básico)',
                        'texto' => "6.4.1 Identificar afectaciones y/o problemas con una inspección exhaustiva, siguiendo las normas del fabricante si están disponibles. 6.4.2 a 6.4.8: preparación de herramientas y materiales, limpieza, lubricación, ajuste de componentes, verificación de conexiones eléctricas y **prueba de funcionamiento**.\n\n6.4.9 Documentación: se **registran todas las acciones en el Informe de Mantenimiento, anexado a la hoja de vida del equipo**, con las piezas ajustadas y recomendaciones. Si el equipo continúa fallando, **se reprograma como mantenimiento correctivo**.",
                        'registros' => ['preventivas']],
                    ['num' => '6.5', 'titulo' => 'Mantenimiento preventivo específico',
                        'texto' => '6.5.1 **Revisión del cronograma de mantenimiento** preventivo. 6.5.2 Inspección detallada de componentes críticos. 6.5.3 Reemplazo y actualización de componentes (incluido software o firmware). 6.5.4 Pruebas de funcionamiento. 6.5.5 Documentación: se **completa un informe del mantenimiento preventivo** con los ajustes realizados.',
                        'registros' => ['pauta', 'preventivas']],
                    ['num' => '6.6', 'titulo' => 'Mantenimiento correctivo',
                        'texto' => "6.6.1 Preparación de recursos; si se requiere asistencia externa o piezas, se informa al coordinador y se **documenta el problema y se reprograma** hasta la llegada del repuesto. 6.6.2 Evaluación y **diagnóstico de la causa raíz**. 6.6.3 Ejecución según especificaciones del fabricante. 6.6.4 **Verificación y pruebas**.\n\n6.6.5 Documentación: **informe detallado del mantenimiento correctivo** y **actualización del formato INV-113-f1 «Hoja de vida equipos y herramientas»** anexando el informe.",
                        'registros' => ['correctivas']],
                    ['num' => '6.7', 'titulo' => 'Acciones en casos excepcionales',
                        'texto' => 'Cuando el problema excede las capacidades del personal o involucra equipos de categoría roja, el coordinador **contacta al proveedor** para la visita de un técnico especializado, asegura los recursos y el acceso, supervisa la reparación y, al **recibir el informe del proveedor**, el profesional y/o auxiliar lo **anexa a la hoja de vida** del equipo.',
                        'registros' => ['correctivas']],
                ],
            ],

            'INV-113' => [
                'titulo' => 'Elaboración de Escenarios de Realidad Virtual',
                'version' => '0', 'fecha' => '14/Jul/2023',
                'objetivo' => 'Identificar y definir las acciones específicas que se deben llevar a cabo para ofrecer el servicio de creación de entornos virtuales inmersivos (escenarios de Realidad Virtual) para la comunidad eanista.',
                'pasos' => [
                    ['num' => '6.1.1', 'titulo' => 'Servicio generado para convocatoria interna',
                        'texto' => 'El laboratorio de realidad virtual es **notificado por correo electrónico del ganador de la convocatoria** que realiza la Dirección de Investigaciones. Se planea una **reunión con el ganador**, el coordinador de la Unidad de Desarrollo y los encargados del laboratorio, en la cual se **elabora el plan de trabajo** acorde al tiempo de la convocatoria, con un **acta digital** enviada por correo a los asistentes, que establece la fecha de inicio, las condiciones y las actividades de cada parte.',
                        'registros' => ['proyectos_vr', 'solicitar']],
                    ['num' => '6.1.2', 'titulo' => 'Recepción de producto por parte del ganador de la convocatoria',
                        'texto' => 'Se convoca una reunión donde se presenta al ganador el desarrollo y se le hace **entrega formal de la experiencia de realidad virtual**.',
                        'registros' => ['proyectos_vr', 'reservas_vr']],
                    ['num' => '6.1.3', 'titulo' => 'Cerrar solicitud',
                        'texto' => 'Desde el laboratorio se envía un **correo electrónico a las personas que figuran en el acta** de la primera reunión para realizar la entrega final del proyecto, **dando cierre a la solicitud**.',
                        'registros' => ['proyectos_vr']],
                ],
            ],
        ];
    }

    /** El texto con sus puntos clave resaltados, ya escapado. */
    public static function html(string $texto): string
    {
        return collect(preg_split("/\n\n+/", trim($texto)))
            ->map(fn ($p) => '<p>' . preg_replace('/\*\*(.+?)\*\*/u', '<mark>$1</mark>', e($p)) . '</p>')
            ->implode('');
    }

    public static function pdf(string $codigo): ?string
    {
        $ruta = self::CARPETA . '/' . $codigo . '.pdf';

        return array_key_exists($codigo, self::todos()) && \Illuminate\Support\Facades\Storage::disk('local')->exists($ruta) ? $ruta : null;
    }
}
