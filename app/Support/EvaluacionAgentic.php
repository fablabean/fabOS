<?php

namespace App\Support;

/**
 * La evaluación del Bootcamp AGENTIC: diez preguntas sobre lo que se presentó
 * en los tres días (zona «agentic», módulos dia-1, dia-2 y dia-3), nada más.
 *
 * La clave se queda en el servidor: la página recibe las preguntas sin la
 * respuesta y la calificación se hace aquí. La explicación de cada pregunta
 * dice de qué bloque sale, para que quien se equivocó sepa dónde repasar.
 */
class EvaluacionAgentic
{
    public const GRUPO = 'agentic';

    /**
     * @return list<array{dia:int, bloque:string, pregunta:string, opciones:array<string,string>, correcta:string, explicacion:string}>
     */
    public static function preguntas(): array
    {
        return [
            [
                'dia' => 1, 'bloque' => 'Reconocer muy bien el problema',
                'pregunta' => '¿Cuál de estas frases describe un problema y no una solución?',
                'opciones' => [
                    'a' => '«Quiero un chatbot para responder a los estudiantes».',
                    'b' => '«Necesito una app con IA para la oficina».',
                    'c' => '«Respondo 40 correos iguales por semana».',
                    'd' => '«Hay que automatizar el proceso con un agente».',
                ],
                'correcta' => 'c',
                'explicacion' => 'Un chatbot, una app o un agente son soluciones. «Respondo 40 correos iguales por semana» es un problema: tiene quién, cuánto y qué cuesta, y recién ahí se puede pensar la solución.',
            ],
            [
                'dia' => 1, 'bloque' => 'Cinco preguntas para tu problema',
                'pregunta' => 'Para llegar al problema de verdad, ¿qué propone la quinta pregunta, «¿Por qué pasa?»?',
                'opciones' => [
                    'a' => 'Pedirle a la IA tres soluciones y quedarse con la más sencilla.',
                    'b' => 'Preguntar «¿por qué?» tres veces seguidas: la tercera respuesta suele ser el problema de verdad.',
                    'c' => 'Hacer una encuesta a todas las personas que lo sufren.',
                    'd' => 'Construir una primera versión y ver qué falla.',
                ],
                'correcta' => 'b',
                'explicacion' => 'Preguntar «¿por qué?» tres veces seguidas: la tercera respuesta suele ser el problema de verdad. Las otras cuatro preguntas eran quién lo sufre, cuándo pasa, cuánto cuesta hoy y cómo se resuelve hoy.',
            ],
            [
                'dia' => 1, 'bloque' => 'Un objetivo principal, y solo uno',
                'pregunta' => 'Al cortar el alcance, tu lista de «Imprescindible» quedó con cinco cosas. ¿Qué significa?',
                'opciones' => [
                    'a' => 'Que todavía es muy grande: la versión del jueves cabe en una frase.',
                    'b' => 'Que está bien, siempre que la IA pueda hacerlas todas.',
                    'c' => 'Que hay que pasar dos de ellas a «Deseable» y prometerlas igual.',
                    'd' => 'Que el objetivo está completo y se puede empezar a programar.',
                ],
                'correcta' => 'a',
                'explicacion' => 'Si tu imprescindible tiene más de tres cosas, todavía es muy grande. Lo imprescindible es lo mínimo que tiene que funcionar para decir «sirve»; lo deseable se escribe, pero no se promete.',
            ],
            [
                'dia' => 1, 'bloque' => 'Del chat al editor: tu PROYECTO.md',
                'pregunta' => 'En el pedido para crear PROYECTO.md, ¿qué debe hacer la IA si le falta información para una sección?',
                'opciones' => [
                    'a' => 'Completarla con lo más probable para un proyecto así.',
                    'b' => 'Dejar la sección por fuera del archivo.',
                    'c' => 'Escribir «POR DEFINIR», sin inventar.',
                    'd' => 'Buscar la información en internet.',
                ],
                'correcta' => 'c',
                'explicacion' => 'El pedido dice: «Llénalo solo con lo que te cuento, sin inventar: si falta algo, escribe POR DEFINIR». Y la IA te muestra el archivo antes de crearlo: si inventó algo, se lo dices.',
            ],
            [
                'dia' => 2, 'bloque' => 'El ciclo de trabajo con IA',
                'pregunta' => '¿Cuál es el orden del ciclo de trabajo con IA?',
                'opciones' => [
                    'a' => 'Pedir → Probar → Revisar → Guardar.',
                    'b' => 'Planear → Pedir → Revisar → Probar y guardar.',
                    'c' => 'Pedir → Guardar → Revisar → Planear.',
                    'd' => 'Planear → Probar → Pedir → Revisar.',
                ],
                'correcta' => 'b',
                'explicacion' => 'Planear (pasos pequeños, tú decides el orden), Pedir (un paso a la vez, con tu ejemplo), Revisar (nada se aplica sin que lo leas) y Probar y guardar (si funciona, guárdalo y sigue).',
            ],
            [
                'dia' => 2, 'bloque' => 'Retos en paralelo',
                'pregunta' => 'La IA del editor ya lleva tres intentos y lo tuyo sigue sin funcionar. ¿Qué recomienda el bootcamp?',
                'opciones' => [
                    'a' => 'Pedirle que lo intente otra vez con más detalle.',
                    'b' => 'Cambiar de IA y repetir el mismo pedido.',
                    'c' => 'Aceptar los cambios y corregirlos a mano después.',
                    'd' => 'Parar, volver al último punto guardado y pedir un paso más pequeño.',
                ],
                'correcta' => 'd',
                'explicacion' => 'Si la IA se enreda o lleva tres intentos sin funcionar: para, vuelve a tu último punto guardado y pide un paso más pequeño. Por eso el ciclo guarda cada avance que funciona.',
            ],
            [
                'dia' => 2, 'bloque' => 'La IA, adentro del programa',
                'pregunta' => 'En el muro de tweets, ¿qué conviene preguntarse antes de meter IA en un programa?',
                'opciones' => [
                    'a' => 'Si alcanza una regla: es gratis, instantánea y siempre responde igual.',
                    'b' => 'Qué modelo de IA es el más grande disponible.',
                    'c' => 'Si la IA puede responder sin que nadie revise.',
                    'd' => 'Cuántos tweets por persona va a aguantar el servidor.',
                ],
                'correcta' => 'a',
                'explicacion' => 'Antes de meter IA, pregúntate si alcanza una regla: es gratis, instantánea y siempre responde igual. La IA entra cuando hace falta entender, como el tono de un mensaje.',
            ],
            [
                'dia' => 3, 'bloque' => 'La API: se paga con créditos',
                'pregunta' => '¿En qué se diferencia pagar la API de pagar la licencia del chat?',
                'opciones' => [
                    'a' => 'Son lo mismo: la licencia del chat incluye la API sin límite.',
                    'b' => 'La API es gratis y la licencia del chat cuesta un valor fijo.',
                    'c' => 'La licencia es un valor fijo al mes por persona; la API se paga con créditos según los tokens que use tu programa.',
                    'd' => 'La API se paga por número de programas, y la licencia por número de archivos.',
                ],
                'correcta' => 'c',
                'explicacion' => 'La licencia del chat es un valor fijo al mes, para ti en la pantalla. La API se paga con créditos prepago: cada llamada se descuenta por los tokens de entrada y salida. Con un modelo pequeño, mil correos cuestan centavos.',
            ],
            [
                'dia' => 3, 'bloque' => 'Cuida el saldo',
                'pregunta' => '¿Dónde va tu llave de API, y qué haces si se escapa?',
                'opciones' => [
                    'a' => 'En el archivo .env, fuera de Git y fuera del chat; si se escapa, la borras y creas otra.',
                    'b' => 'Escrita dentro del código, para que el programa siempre la encuentre.',
                    'c' => 'En PROYECTO.md, para que la IA del editor la pueda usar.',
                    'd' => 'En el chat de la IA, que la guarda de forma segura.',
                ],
                'correcta' => 'a',
                'explicacion' => 'La llave es dinero: va en el .env, fuera de Git y fuera del chat. Si se escapa, bórrala y crea otra. Y pon un tope de gasto mensual: un programa en bucle puede hacer miles de llamadas.',
            ],
            [
                'dia' => 3, 'bloque' => 'Construimos un agente y MCP',
                'pregunta' => 'En la máquina del tiempo (historia.py), ¿quién llama de verdad a Wikipedia, iTunes y Gemini, y qué cambia con MCP?',
                'opciones' => [
                    'a' => 'El modelo llama a cada API por su cuenta; MCP sirve para que gaste menos tokens.',
                    'b' => 'El modelo solo pide; quien llama a cada API es el código que tú aprobaste. Con MCP la conexión ya viene hecha y se enchufa, como un USB.',
                    'c' => 'Tú llamas cada API a mano desde el navegador; MCP lo hace un poco más rápido.',
                    'd' => 'Wikipedia llama al modelo; MCP reemplaza la llave de API.',
                ],
                'correcta' => 'b',
                'explicacion' => 'El modelo decide y pide; las herramientas son funciones de tu programa, y quien llama a cada API es el código que aprobaste. En historia.py programamos tres conexiones una por una; con MCP cada servicio hace su conector una vez y cualquier IA lo enchufa.',
            ],
        ];
    }

    /** Las preguntas como las ve la página: sin la clave ni la explicación. */
    public static function paraLaPagina(): array
    {
        return array_map(fn ($p) => [
            'dia' => $p['dia'],
            'pregunta' => $p['pregunta'],
            'opciones' => $p['opciones'],
        ], self::preguntas());
    }

    /**
     * Califica. Lo que no se respondió cuenta como error.
     *
     * @param  array<int|string,string|null>  $respuestas  índice de la pregunta => letra
     * @return array{aciertos:int, total:int, detalle:list<array{correcta:string, elegida:?string, bien:bool, bloque:string, dia:int, explicacion:string}>}
     */
    public static function calificar(array $respuestas): array
    {
        $detalle = [];
        foreach (self::preguntas() as $i => $p) {
            $elegida = $respuestas[$i] ?? null;
            $elegida = is_string($elegida) && array_key_exists($elegida, $p['opciones']) ? $elegida : null;
            $detalle[] = [
                'correcta' => $p['correcta'],
                'elegida' => $elegida,
                'bien' => $elegida === $p['correcta'],
                'bloque' => $p['bloque'],
                'dia' => $p['dia'],
                'explicacion' => $p['explicacion'],
            ];
        }

        return [
            'aciertos' => count(array_filter($detalle, fn ($d) => $d['bien'])),
            'total' => count($detalle),
            'detalle' => $detalle,
        ];
    }
}
