<?php

namespace App\Services\Ia\Inventario;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * El servidor de IA del FabLab, visto desde fabOS.
 *
 * Dos cosas le pedimos:
 *
 *  · `/vision/analizar`: la huella de una foto y, si se pide, una propuesta
 *    de qué es. Con propuesta tarda de 9 a 50 segundos y calienta el
 *    servidor; sin ella, una décima. Por eso la propuesta solo se pide
 *    cuando hace falta.
 *  · `/v1/embeddings` con bge-m3: el vector de un texto, para comparar lo
 *    que vio en la foto con los nombres del inventario.
 *
 * El servidor es compartido y atiende otros proyectos: nada de cargas en
 * bucle. Lo masivo, una por una.
 */
class ServidorDeIaDelFablab
{
    public function configurado(): bool
    {
        return (bool) config('fabos.servidor_fablab.activo') && filled(config('fabos.servidor_fablab.url'));
    }

    /**
     * Si contesta ahora. Se pregunta como mucho una vez por minuto: la
     * pantalla lo consulta en cada vista y no hace falta molestar tanto.
     */
    public function responde(): bool
    {
        if (! $this->configurado()) {
            return false;
        }

        return Cache::remember('servidor-fablab:responde', 60, function () {
            try {
                return $this->cliente(4)->get('/v1/models')->successful();
            } catch (\Throwable) {
                return false;
            }
        });
    }

    /** Tras un fallo, que la próxima vista vuelva a preguntar. */
    public function olvidarEstado(): void
    {
        Cache::forget('servidor-fablab:responde');
    }

    /**
     * La huella de la foto y, si se pide, qué cree que es.
     *
     * @param  list<string>  $categorias  para que la propuesta use una de ellas
     * @return array{huella:list<float>,modelo_huella:?string,segundos:?float,propuesta:?array<string,mixed>}
     */
    public function analizar(string $rutaFoto, array $categorias = [], bool $conPropuesta = true): array
    {
        $r = $this->cliente((int) config('fabos.servidor_fablab.timeout', 120))
            ->attach('foto', file_get_contents($rutaFoto), 'foto.jpg', ['Content-Type' => 'image/jpeg'])
            ->post('/vision/analizar', [
                'propuesta' => $conPropuesta ? 'true' : 'false',
                'categorias' => implode(', ', array_filter($categorias)),
            ]);

        if ($r->failed()) {
            throw new RuntimeException('El servidor de IA del FabLab respondió ' . $r->status() . ': ' . Str::limit($r->body(), 200));
        }

        $huella = $r->json('huella');

        if (! is_array($huella) || count($huella) < 16) {
            throw new RuntimeException('El servidor de IA del FabLab no devolvió la huella de la foto.');
        }

        return [
            'huella' => array_map('floatval', $huella),
            'modelo_huella' => $r->json('modelo_huella'),
            'segundos' => $r->json('segundos'),
            'propuesta' => is_array($r->json('propuesta')) ? $r->json('propuesta') : null,
        ];
    }

    /**
     * Todos los objetos de la foto, cada uno con su caja.
     *
     * `/vision/analizar` describe un solo objeto: con tres botes de resina
     * nombraba el del medio y metía los otros dos en el texto. Esto le pregunta
     * al modelo de visión del servidor (qwen3.5) por cada objeto aparte, con
     * una respuesta de forma fija, y la caja de cada uno en coordenadas de 0 a
     * 1000 para poder recortarlo.
     *
     * @param  list<string>  $categorias
     * @return list<array<string,mixed>>
     */
    public function detectarObjetos(string $rutaFoto, array $categorias = [], array $yaRevisados = []): array
    {
        $texto = ['type' => ['string', 'null']];
        $maximo = max(1, (int) config('fabos.servidor_fablab.max_objetos', 8));

        $esquema = [
            'type' => 'object',
            'properties' => [
                'objetos' => [
                    'type' => 'array',
                    // Sin techo, con una foto llena de cosas se ponía a repetir
                    // el último objeto hasta agotar la respuesta.
                    'maxItems' => $maximo,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'nombre' => ['type' => 'string'],
                            'tipo' => ['type' => 'string', 'enum' => ['activo', 'herramienta', 'insumo', 'componente', 'producto', 'otro']],
                            'categoria' => $texto,
                            'marca' => $texto,
                            'modelo' => $texto,
                            'variante' => $texto,
                            'texto_visible' => $texto,
                            'posicion' => ['type' => 'string'],
                            'caja' => ['type' => 'array', 'items' => ['type' => 'integer']],
                            'se_ve_completo' => ['type' => 'boolean'],
                            'es_inventariable' => ['type' => 'boolean'],
                            'visibilidad' => ['type' => 'string', 'enum' => ['completo', 'parcial', 'casi_oculto']],
                            'confianza' => ['type' => 'string', 'enum' => ['alta', 'media', 'baja']],
                        ],
                        'required' => ['nombre', 'tipo', 'categoria', 'marca', 'modelo', 'variante', 'texto_visible', 'posicion', 'caja', 'se_ve_completo', 'es_inventariable', 'visibilidad', 'confianza'],
                    ],
                ],
            ],
            'required' => ['objetos'],
        ];

        $lista = $categorias ? implode(', ', $categorias) : 'ninguna';

        $instruccion = <<<TXT
        Eres el inventario de un laboratorio de fabricación digital. Lista los objetos físicos
        distintos que se ven en la foto: equipos, herramientas, insumos, productos. Dos productos
        iguales de distinto color o tamaño son objetos distintos.

        Solo cosas que se registran en un inventario: algo concreto que se guarda, se presta o
        se gasta. NO son objetos: manchas, derrames, residuos, gotas, suciedad, polvo, rayones,
        reflejos, sombras, marcas en la mesa, papeles sueltos o basura, ni el mueble, la mesa, la
        repisa, la pared, las ventanas, los edificios o el fondo. Tampoco los cables, cordones de
        poder, caimanes, puntas de prueba, conectores, bornes o bananas (sueltos o en bolsas) que
        están junto a los equipos o encima de ellos, ni las cajas u organizadores donde van: son
        accesorios del equipo, no artículos aparte; tampoco el cargador o adaptador de corriente que
        está junto a ellos o en el piso. Una tapa o cubierta suelta (la cubierta acrílica de una
        impresora de resina puesta al lado) es parte de su máquina, aunque esté separada de ella:
        no la cuentes como otra máquina. El equipo que tiene cables encima SÍ va, como
        cualquier otro: lo que se deja fuera son solo los cables.

        Pon TODOS los artículos que se vean, aunque estén cortados en el borde de la foto o
        tapados en parte por otro: cada bote, caja o equipo es uno. Que esté tapado no es razón
        para dejarlo fuera.

        Como máximo {$maximo} objetos, del más importante (el que está en primer plano y más
        grande) al menos importante. Cada objeto físico va UNA vez: no repitas el mismo bote.
        Un equipo hecho de varias partes unidas por una manguera o un tubo, que funcionan juntas
        (una bomba de vacío con su cámara, un compresor con su tanque), es UN objeto: una sola caja
        que cubra todas sus partes y el nombre del conjunto. Lo mismo con las partes de una máquina
        grande —su consola o panel de control, su pantalla y teclado, su torre de luces, su mesa o
        cama de trabajo, su gabinete—: van en la caja de la máquina, no como objetos aparte. En
        general, lo que va de la mano con otro objeto es parte de él: el soporte y la esponja de un
        soldador, el soldador de su estación de soldadura, el control o el pedal de una máquina, el
        estuche o la base de una herramienta, la boquilla de una pistola. Se cuenta solo el objeto
        principal. Las sillas, toallas y trapos del puesto de trabajo no son objetos del inventario.

        Pero dos botes o equipos iguales, uno al lado del otro o uno encima del otro, son DOS
        objetos y van los dos.

        Para cada objeto:
        - nombre: corto, en español, como se buscaría en un inventario: qué es + marca + lo que
          lo distingue, con la forma «<qué es> <marca> <color o modelo>». Usa SOLO palabras que
          se leen en ESE objeto o que se deducen de su forma; nunca copies este molde ni nombres de
          otros objetos. Sale de lo que dice la etiqueta y de la forma del envase: un aerosol es
          pintura, laca o barniz en aerosol; solo es resina si la etiqueta dice «resin» o «resina».
          Si no sabes traducir algo, déjalo como está en la etiqueta. Una FUENTE DE VOLTAJE con dos salidas
          ajustables (dos pantallas, «master/slave») lleva «dual» en el nombre; los demás equipos no.
          Qué es un equipo lo dice lo que se lee en su panel: «Waveform Generator», «Function
          Generator» o teclas Sine, Square, Burst, Sweep son un generador de señal; «Oscilloscope» o
          teclas Acquire, Trigger, Measure, un osciloscopio; «Power Supply», una fuente de voltaje.
        - tipo: activo (máquina o equipo), herramienta, insumo (se gasta), componente, producto u otro.
        - categoria: una de estas, o null si ninguna encaja: {$lista}.
        - marca, modelo, variante (color, tamaño, presentación): solo si se leen o se ven; si no, null.
        - texto_visible: el texto que se lee en ESE objeto, no en los demás.
        - posicion: izquierda, centro, derecha, arriba, abajo…
        - caja: [x1, y1, x2, y2] del objeto, en coordenadas de 0 a 1000.
        - se_ve_completo: false si sale cortado en el borde de la foto.
        - es_inventariable: true solo si es un equipo, herramienta, insumo o producto concreto.
        - visibilidad: completo, parcial (un poco tapado), o casi_oculto si otro objeto lo tapa
          y solo se ve la tapa o un borde. Inclúyelos igual: pon lo que se alcance a ver y null
          en lo que no se lea.
        No inventes nada que no se vea.
        TXT;

        // «Busca otro»: la persona ya revisó estos; se buscan los que faltan.
        if ($yaRevisados !== []) {
            $lista = implode("\n", array_map(fn ($n) => "- {$n}", $yaRevisados));
            $instruccion .= "\n\nEstos objetos ya se revisaron; NO los vuelvas a poner:\n{$lista}\n"
                . 'Busca en la foto OTROS objetos del inventario que no estén en esa lista, aunque sean chicos o estén al fondo. '
                . 'Si hay más unidades iguales a uno de la lista (otro multímetro igual en el montón), esas SÍ van, cada una con su caja. '
                . 'Si no hay ninguno más, devuelve la lista de objetos vacía.';
        }

        $r = $this->cliente((int) config('fabos.servidor_fablab.timeout_vision', 240))->post('/api/chat', [
            'model' => config('fabos.servidor_fablab.modelo_vision', 'qwen3.5:9b'),
            'stream' => false,
            'think' => false,
            'format' => $esquema,
            // Con tope: si se enreda, falla rápido y se cae al reconocimiento de uno.
            'options' => ['temperature' => 0, 'num_predict' => 4500],
            'messages' => [[
                'role' => 'user',
                'content' => $instruccion,
                'images' => [base64_encode(file_get_contents($rutaFoto))],
            ]],
        ]);

        if ($r->failed()) {
            throw new RuntimeException('El modelo de visión del servidor respondió ' . $r->status() . '.');
        }

        $contenido = (string) $r->json('message.content');
        $datos = json_decode($contenido, true);

        // Cortada a medias: se rescatan los objetos que alcanzaron a cerrarse.
        $objetos = is_array($datos['objetos'] ?? null) ? $datos['objetos'] : self::rescatarObjetos($contenido);

        // En «Busca otro», una lista vacía es una respuesta: no hay más.
        if ($objetos === [] && $yaRevisados !== [] && is_array($datos['objetos'] ?? null)) {
            return [];
        }

        if ($objetos === []) {
            throw new RuntimeException('El modelo de visión no devolvió la lista de objetos.');
        }

        // Y cada objeto una sola vez, aunque lo haya repetido.
        return collect($objetos)
            ->filter(fn ($o) => is_array($o))
            ->unique(fn ($o) => mb_strtolower((string) ($o['nombre'] ?? '')) . '|' . json_encode($o['caja'] ?? null))
            ->take($maximo)
            ->values()
            ->all();
    }

    /**
     * Lee la etiqueta de UN objeto, sobre su recorte en alta resolución.
     *
     * En la foto entera una etiqueta mide unas decenas de píxeles y el color
     * o la referencia no se alcanzan a leer. Recortado, el mismo modelo lee
     * «Blue Low Odor Resin». Se usa solo cuando falta la marca o el color.
     *
     * @return array{nombre:?string,marca:?string,modelo:?string,variante:?string,texto_visible:?string}
     */
    public function leerEtiqueta(string $rutaRecorte, bool $esEquipo = false): array
    {
        // Una máquina con sus partes (la CNC con su consola y su mesa) se
        // nombra como la máquina, no como la parte que más se ve.
        $instruccion = $esEquipo
            ? 'Es UNA sola máquina o equipo de un laboratorio de fabricación digital, con todas sus partes (consola, mesa, '
                . 'pantalla, torre de luces…). Di qué máquina es: nombre corto y en español con la forma «<qué máquina es> <marca> '
                . '<modelo>» (por ejemplo, si es una router CNC, empieza por «Router CNC»), su marca y modelo si se leen, y el texto '
                . 'que se lee tal cual. No la nombres por una de sus partes. Solo lo que se ve o se lee; lo que no, null.'
            : 'Es un solo objeto de un inventario. Lee su etiqueta: marca, modelo, variante (color, '
                . 'tamaño, presentación: «azul, 500 g») y el texto que se lee tal cual. nombre: corto y en español '
                . 'con la forma «<qué es> <marca> <color>», solo con lo que dice su etiqueta y la forma del envase. '
                . 'Solo lo que se lee; lo que no, null.';
        $texto = ['type' => ['string', 'null']];

        $r = $this->cliente((int) config('fabos.servidor_fablab.timeout_vision', 240))->post('/api/chat', [
            'model' => config('fabos.servidor_fablab.modelo_vision', 'qwen3.5:9b'),
            'stream' => false,
            'think' => false,
            'format' => [
                'type' => 'object',
                'properties' => ['nombre' => $texto, 'marca' => $texto, 'modelo' => $texto, 'variante' => $texto, 'texto_visible' => $texto],
                'required' => ['nombre', 'marca', 'modelo', 'variante', 'texto_visible'],
            ],
            'options' => ['temperature' => 0, 'num_predict' => 600],
            'messages' => [[
                'role' => 'user',
                'content' => $instruccion,
                'images' => [base64_encode(file_get_contents($rutaRecorte))],
            ]],
        ]);

        $datos = $r->successful() ? json_decode((string) $r->json('message.content'), true) : null;

        return collect(['nombre', 'marca', 'modelo', 'variante', 'texto_visible'])
            // «null» o «none» escritos como texto también son nada.
            ->mapWithKeys(fn ($c) => [$c => is_string($datos[$c] ?? null)
                && ! in_array(mb_strtolower(trim($datos[$c])), ['', 'null', 'none', 'n/a', 'desconocido'], true)
                ? trim($datos[$c]) : null])
            ->all();
    }

    /**
     * Los objetos completos de un JSON que se cortó antes de terminar.
     *
     * Se recorre la lista objeto por objeto, contando llaves, y se queda con
     * los que cerraron. El que quedó a medias se descarta.
     *
     * @return list<array<string,mixed>>
     */
    public static function rescatarObjetos(string $contenido): array
    {
        $inicio = strpos($contenido, '[');

        if ($inicio === false) {
            return [];
        }

        $objetos = [];
        $nivel = 0;
        $desde = null;
        $enTexto = false;
        $escapado = false;

        for ($i = $inicio + 1, $n = strlen($contenido); $i < $n; $i++) {
            $c = $contenido[$i];

            // Dentro de un texto las llaves no cuentan: «caja {A}» no abre nada.
            if ($enTexto) {
                if ($escapado) {
                    $escapado = false;
                } elseif ($c === '\\') {
                    $escapado = true;
                } elseif ($c === '"') {
                    $enTexto = false;
                }

                continue;
            }

            if ($c === '"') {
                $enTexto = true;
            } elseif ($c === '{') {
                $desde ??= $i;
                $nivel++;
            } elseif ($c === '}' && $nivel > 0) {
                $nivel--;

                if ($nivel === 0 && $desde !== null) {
                    $objeto = json_decode(substr($contenido, $desde, $i - $desde + 1), true);

                    if (is_array($objeto)) {
                        $objetos[] = $objeto;
                    }

                    $desde = null;
                }
            } elseif ($c === ']' && $nivel === 0) {
                break;
            }
        }

        return $objetos;
    }

    /**
     * Los vectores de varios textos, en el mismo orden, ya normalizados.
     *
     * @param  list<string>  $textos
     * @return list<list<float>>
     */
    public function vectores(array $textos): array
    {
        if ($textos === []) {
            return [];
        }

        $r = $this->cliente(60)->post('/v1/embeddings', [
            'model' => config('fabos.servidor_fablab.modelo_texto', 'bge-m3'),
            'input' => array_values($textos),
        ]);

        if ($r->failed()) {
            throw new RuntimeException('El servidor de IA del FabLab no calculó los vectores (' . $r->status() . ').');
        }

        $datos = collect($r->json('data', []))->sortBy('index')->values();

        if ($datos->count() !== count($textos)) {
            throw new RuntimeException('El servidor de IA del FabLab devolvió una cantidad de vectores distinta a la pedida.');
        }

        return $datos->map(fn ($d) => self::normalizar(array_map('floatval', $d['embedding'] ?? [])))->all();
    }

    /** Producto punto: con vectores normalizados, es la similitud. */
    public static function similitud(array $a, array $b): float
    {
        $s = 0.0;
        $n = min(count($a), count($b));

        for ($i = 0; $i < $n; $i++) {
            $s += $a[$i] * $b[$i];
        }

        return $s;
    }

    /** @return list<float> */
    public static function normalizar(array $v): array
    {
        $norma = sqrt(array_sum(array_map(fn ($x) => $x * $x, $v)));

        return $norma > 0 ? array_map(fn ($x) => $x / $norma, $v) : $v;
    }

    private function cliente(int $segundos): PendingRequest
    {
        $cliente = Http::baseUrl(config('fabos.servidor_fablab.url'))
            ->timeout($segundos)
            ->connectTimeout(5)
            ->acceptJson();

        // Dentro del FabLab no hace falta; desde fuera, sí. Nunca sale al navegador.
        if (filled($clave = config('fabos.servidor_fablab.clave'))) {
            $cliente = $cliente->withToken($clave);
        }

        return $cliente;
    }
}
