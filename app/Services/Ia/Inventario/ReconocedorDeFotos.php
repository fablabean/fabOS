<?php

namespace App\Services\Ia\Inventario;

use App\Models\Asset;
use App\Models\FotoDeInventario;
use App\Models\Supply;
use App\Models\User;
use App\Models\VectorDeInventario;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * «¿Qué es esto, y ya lo tenemos?», respondido con una foto.
 *
 * Tres pasos, del más seguro al más dudoso:
 *
 *  1. **Por foto.** La huella se compara con las de las fotos ya
 *     confirmadas. 0,85 o más es el mismo objeto; entre 0,70 y 0,85,
 *     posiblemente.
 *  2. **Por nombre.** Si no hay foto parecida —al principio no hay ninguna—,
 *     lo que el servidor cree que es («Impresora 3D Creality K1») se compara
 *     con los nombres del inventario, y se ofrecen los 3 más parecidos.
 *  3. **Es nuevo.** Si nada encaja, la propuesta sirve de borrador para
 *     registrarlo.
 *
 * Nunca se enlaza solo: en una prueba, «Cortadora láser» quedó primera pero
 * con poco margen sobre la fresadora. Se sugiere, y una persona elige.
 */
class ReconocedorDeFotos
{
    /** Las huellas esperan aquí mientras la persona decide. */
    private const HORAS_DE_ESPERA = 8;

    /** Desde aquí un vecino cuenta para decidir dónde va algo nuevo. */
    private const PARECIDO_PARA_UBICAR = 0.6;

    /** @var list<array{tipo:string,id:int,similitud:float}> los más cercanos de la última búsqueda */
    private array $vecinos = [];

    public function __construct(
        private ServidorDeIaDelFablab $servidor,
        private BuscadorDeInventario $buscador,
        private CatalogoDeInventario $catalogo,
    ) {}

    public function disponible(): bool
    {
        return $this->servidor->configurado();
    }

    /**
     * Una tarjeta por cada objeto de la foto.
     *
     * Con un objeto, la foto entera es su ejemplo. Con varios, cada uno se
     * recorta por su caja y el recorte lleva su propia huella: así la próxima
     * foto de la resina rosa se reconoce aunque esta vez salga sola, o junto a
     * otras distintas. Pedir solo la huella tarda una décima y no calienta el
     * servidor.
     *
     * Si el modelo que separa los objetos falla, se cae al reconocimiento de
     * un solo objeto de siempre: mejor una tarjeta que ninguna.
     *
     * @param  array{ruta:string}  $foto  la foto ya preparada y guardada
     * @return list<array<string,mixed>> las tarjetas de reconocimiento
     */
    public function reconocer(array $foto): array
    {
        $ruta = Storage::disk('local')->path($foto['ruta']);
        $inicio = microtime(true);

        $objetos = $this->objetosDe($ruta, Storage::disk('local')->path(FotoParaLaIa::rutaGrande($foto['ruta'])));

        if ($objetos === null) {
            $analisis = $this->servidor->analizar($ruta, $this->categorias());

            return [$this->tarjeta($foto['ruta'], $analisis, $this->limpiarPropuesta($analisis['propuesta'] ?? null))];
        }

        if (count($objetos) === 1) {
            $analisis = $this->servidor->analizar($ruta, conPropuesta: false);
            $objetos[0] = $this->nombrarLaMaquinaUnida($objetos[0], $ruta);
            $analisis['segundos'] = microtime(true) - $inicio;

            return [$this->tarjeta($foto['ruta'], $analisis, $this->comoPropuesta($objetos[0]), $objetos[0])];
        }

        return $this->marcarLasQuePuedenSerUnaPieza($this->marcarLasDelMismoRegistro($this->sugerirLosDeAtras(
            $this->tarjetasDe($foto, $objetos, 0, $inicio),
        )));
    }

    /**
     * «Busca otro en la foto»: la persona ya revisó unos objetos —los que sí y
     * los que no— y quiere que se busque lo que falta. Se le pide al modelo
     * que no repita los revisados, y de lo que devuelve se quita lo que cae
     * donde ya había algo. Las tarjetas nuevas siguen la numeración.
     *
     * @param  array{ruta:string}  $foto
     * @param  list<array{nombre:?string,caja:?array}>  $revisados
     * @return list<array<string,mixed>>
     */
    public function buscarOtros(array $foto, array $revisados, int $yaHay): array
    {
        $ruta = Storage::disk('local')->path($foto['ruta']);
        $grande = Storage::disk('local')->path(FotoParaLaIa::rutaGrande($foto['ruta']));
        $fuente = is_file($grande) ? $grande : $ruta;
        $inicio = microtime(true);

        // Lo que cae donde ya había algo revisado —o algo ya encontrado— se
        // quita; por el nombre no, porque otra unidad igual (otro multímetro
        // rojo del montón) sí va.
        //
        // En un montón las cajas del modelo son imprecisas y se pisan: el
        // mismo multímetro volvía con otra caja corrida. Lo nuevo que cae en
        // más de un tercio sobre algo ya visto se toma por repetido.
        $cajas = array_values(array_filter(array_column($revisados, 'caja')));
        $sinLoVisto = function (array $objetos) use (&$cajas): array {
            $quedan = [];
            foreach ($objetos as $o) {
                if (! collect($cajas)->contains(fn ($c) => self::seSuperponen($c, $o['caja'])
                    || self::dentroDe($o['caja'], $c) >= self::YA_VISTO_EN_EL_MONTON)) {
                    $quedan[] = $o;
                    $cajas[] = $o['caja'];
                }
            }

            return $quedan;
        };

        // 1) La foto entera, con la lista de lo ya revisado.
        $nuevos = $sinLoVisto($this->depurarObjetos($this->servidor->detectarObjetos(
            $ruta, $this->categorias(), array_values(array_filter(array_column($revisados, 'nombre'))),
        )));

        /*
         * 2) De cerca. Con la foto entera el modelo no separa objetos iguales
         * amontonados: en la repisa de soldadura decía «no hay más» y había
         * más multímetros en el montón. Primero la zona de lo que se repite
         * (dos o más del mismo tipo); si ahí no hay nada, la foto en cuatro
         * partes con traslape.
         */
        if ($nuevos === []) {
            foreach ($this->zonasDondeBuscar($revisados) as $zona) {
                try {
                    $nuevos = array_merge($nuevos, $sinLoVisto($this->detectarEnZona($fuente, $zona)));
                } catch (\Throwable $e) {
                    Log::info('Activo IA: no se pudo mirar de cerca una zona', ['error' => $e->getMessage()]);
                }

                if ($nuevos !== [] && ($zona['montón'] ?? false)) {
                    break;
                }
            }
        }

        if ($nuevos === []) {
            return [];
        }

        $tarjetas = $this->tarjetasDe($foto, array_map(fn ($o) => collect($o)->except('montón')->all(), $nuevos), $yaHay, $inicio, soloAccesoriosSeQuedan: false);

        return $this->marcarLasDelMismoRegistro($this->conElNombreDeSusVecinos($tarjetas, $revisados));
    }

    /** Lo nuevo de «Busca otro» que cae en esta parte sobre algo ya visto, es eso mismo. */
    private const YA_VISTO_EN_EL_MONTON = 0.35;

    /**
     * Lo que «Busca otro» trae con un nombre genérico —«caja roja»— y se
     * parece mucho a uno ya revisado (un multímetro del mismo montón) toma
     * ese nombre, y se busca así en el inventario.
     */
    private function conElNombreDeSusVecinos(array $tarjetas, array $revisados): array
    {
        // Genérico de verdad: «caja roja», «objeto metálico gris»; no «caja de
        // herramientas» ni «equipo de soldadura», que sí dicen qué son.
        $generico = fn ($n) => (bool) preg_match('/^(cajas?|objetos?|aparatos?|equipos?|dispositivos?|cosas?|bultos?|elementos?)( [a-z]+){0,2}$/', self::comparable((string) $n))
            && ! preg_match('/\b(de|para|con)\b/', self::comparable((string) $n));

        foreach ($tarjetas as $i => $t) {
            $hb = $this->huellaDe($t['clave'])['huella'] ?? [];

            if (! $generico($t['propuesta']['nombre'] ?? '') || $hb === []) {
                continue;
            }

            $vecino = collect($revisados)
                ->filter(fn ($r) => filled($r['clave'] ?? null) && filled($r['nombre'] ?? null) && ! $generico($r['nombre']))
                ->map(fn ($r) => $r + ['parecido' => ServidorDeIaDelFablab::similitud($hb, $this->huellaDe($r['clave'])['huella'] ?? [])])
                ->sortByDesc('parecido')
                ->first();

            if (! $vecino || $vecino['parecido'] < self::RECORTES_EN_SU_SITIO) {
                continue;
            }

            $tarjetas[$i]['propuesta']['nombre'] = $vecino['nombre'];
            $tarjetas[$i]['por_texto'] = $this->porTexto($tarjetas[$i]['propuesta'], collect($t['por_foto'])->pluck('clave')->all());
            [$tarjetas[$i]['por_foto'], $tarjetas[$i]['por_texto']] = self::unSoloMasProbable($tarjetas[$i]['por_foto'], $tarjetas[$i]['por_texto']);
            $tarjetas[$i]['resumen'] = $this->resumen($tarjetas[$i]);
        }

        return $tarjetas;
    }

    /**
     * Dónde mirar de cerca en «Busca otro»: primero donde se amontona lo que
     * se repite (dos o más del mismo tipo, con un margen), después la foto en
     * cuatro partes con traslape.
     *
     * @return list<array{0:int,1:int,2:int,3:int,montón?:bool}>
     */
    private function zonasDondeBuscar(array $revisados): array
    {
        $tipo = fn ($r) => strtok(self::comparable((string) ($r['nombre'] ?? '')), ' ') ?: null;
        $montones = collect($revisados)->filter(fn ($r) => isset($r['caja']) && $tipo($r) !== null)->groupBy($tipo)->filter(fn ($g) => $g->count() >= 2)
            ->map(function ($g) {
                $c = $g->map(fn ($r) => array_map('intval', array_values($r['caja'])));
                $margen = 80;

                return [max(0, $c->min(0) - $margen), max(0, $c->min(1) - $margen), min(1000, $c->max(2) + $margen), min(1000, $c->max(3) + $margen), 'montón' => true];
            })
            ->values()->all();

        $cuadrantes = [[0, 0, 600, 600], [400, 0, 1000, 600], [0, 400, 600, 1000], [400, 400, 1000, 1000]];

        return [...$montones, ...$cuadrantes];
    }

    /**
     * Detecta los objetos de una zona de la foto (en milésimas) y los devuelve
     * con sus cajas en la foto entera.
     */
    private function detectarEnZona(string $fuente, array $zona): array
    {
        $imagen = @imagecreatefromjpeg($fuente);
        if (! $imagen) {
            return [];
        }

        [$ancho, $alto] = [imagesx($imagen), imagesy($imagen)];
        [$x1, $y1, $x2, $y2] = [(int) $zona[0], (int) $zona[1], (int) $zona[2], (int) $zona[3]];
        $recorte = imagecrop($imagen, [
            'x' => (int) ($x1 / 1000 * $ancho), 'y' => (int) ($y1 / 1000 * $alto),
            'width' => max(1, (int) (($x2 - $x1) / 1000 * $ancho)), 'height' => max(1, (int) (($y2 - $y1) / 1000 * $alto)),
        ]);

        if (! $recorte) {
            return [];
        }

        $lado = max(imagesx($recorte), imagesy($recorte));
        if ($lado > FotoParaLaIa::LADO_MAXIMO) {
            $recorte = imagescale($recorte, (int) round(imagesx($recorte) * FotoParaLaIa::LADO_MAXIMO / $lado));
        }

        $temporal = tempnam(sys_get_temp_dir(), 'zona') . '.jpg';
        imagejpeg($recorte, $temporal, 88);

        try {
            $objetos = $this->depurarObjetos($this->servidor->detectarObjetos($temporal, $this->categorias()));
        } finally {
            @unlink($temporal);
        }

        return array_map(function ($o) use ($x1, $y1, $x2, $y2) {
            [$a1, $b1, $a2, $b2] = array_map('intval', array_values($o['caja']));
            $o['caja'] = [
                (int) round($x1 + $a1 * ($x2 - $x1) / 1000), (int) round($y1 + $b1 * ($y2 - $y1) / 1000),
                (int) round($x1 + $a2 * ($x2 - $x1) / 1000), (int) round($y1 + $b2 * ($y2 - $y1) / 1000),
            ];

            return $o;
        }, array_values(array_filter($objetos, fn ($o) => self::seInventaria($o))));
    }

    /**
     * Las tarjetas de una lista de objetos: cada uno se recorta, se le relee la
     * etiqueta si hace falta y se compara con el inventario.
     *
     * @param  array{ruta:string}  $foto
     * @return list<array<string,mixed>>
     */
    private function tarjetasDe(array $foto, array $objetos, int $desde, float $inicio, bool $soloAccesoriosSeQuedan = true): array
    {
        $ruta = Storage::disk('local')->path($foto['ruta']);

        // Se recorta de la copia grande, si la hay: ahí se leen las etiquetas.
        $grande = Storage::disk('local')->path(FotoParaLaIa::rutaGrande($foto['ruta']));
        $fuente = is_file($grande) ? $grande : $ruta;

        $recortados = [];
        $lecturas = 0;

        foreach ($objetos as $objeto) {
            $recorte = $this->recortar($fuente, $objeto['caja']);

            if (! $recorte) {
                continue;
            }

            // Una máquina unida con sus partes se nombra mirándola entera.
            if ($objeto['unido'] ?? false) {
                $objeto = $this->nombrarLaMaquinaUnida($objeto, Storage::disk('local')->path($recorte['ruta']));
                $lecturas++;
            } elseif ($this->leHaceFaltaLeer($objeto) && $lecturas++ < self::MAX_LECTURAS) {
                // Sin marca o sin color, se le vuelve a leer la etiqueta de cerca.
                // Con tope, para no encadenar diez lecturas en una foto.
                try {
                    $leido = $this->servidor->leerEtiqueta(Storage::disk('local')->path($recorte['ruta']));
                    $objeto = array_merge($objeto, array_filter($leido, fn ($v) => $v !== null));
                } catch (\Throwable $e) {
                    Log::info('Activo IA: no se pudo releer una etiqueta', ['error' => $e->getMessage()]);
                }
            }

            $recortados[] = ['objeto' => $objeto, 'recorte' => $recorte];
        }

        // Y otra vez el filtro de accesorios, ya con la etiqueta releída de
        // cerca: lo que de lejos parecía un multímetro, de cerca resultó ser
        // «cargador EASY rojo», y se colaba como objeto. Si la foto es solo
        // de eso, se deja.
        $sinAccesorios = array_values(array_filter($recortados, fn ($r) => ! self::esCable($r['objeto'])));
        $recortados = $sinAccesorios !== [] || ! $soloAccesoriosSeQuedan ? $sinAccesorios : $recortados;

        // Una tarjeta por artículo, aunque sean iguales: cada bote es uno.
        $tarjetas = [];

        foreach ($recortados as $i => ['objeto' => $objeto, 'recorte' => $recorte]) {
            $analisis = $this->servidor->analizar(Storage::disk('local')->path($recorte['ruta']), conPropuesta: false);
            $analisis['segundos'] = microtime(true) - $inicio;

            /*
             * El que está casi tapado también sale —es un artículo que está
             * ahí—, pero de él solo se ve la tapa: su recorte no se guarda como
             * foto para reconocerlo, ni se compara con las fotos guardadas. Un
             * recorte de solo tapas haría «iguales» a todos los botes Creality.
             */
            $tapado = ($objeto['visibilidad'] ?? null) === 'casi_oculto';

            $tarjetas[] = $this->tarjeta($recorte['ruta'], $analisis, $this->comoPropuesta($objeto), $objeto + [
                'miniatura' => $recorte['miniatura'],
                'indice' => $desde + $i + 1,
                'total' => $desde + count($recortados),
                'tapado' => $tapado,
            ], sinReferencia: $tapado);
        }

        return $tarjetas;
    }

    /**
     * Lo que está en fila detrás de otro suele ser otra unidad del mismo.
     *
     * En las repisas del laboratorio los botes se guardan en fila: el de
     * atrás, del que se ve un pedazo, casi siempre es otro igual al de
     * adelante. Casi siempre, no siempre: por eso se sugiere —se le pone el
     * producto del de adelante como primera opción— y la persona confirma.
     *
     * «Detrás» se mide como se ve en una repisa: los dos se superponen de lado
     * y la base del de atrás queda más arriba. Lo que se le alcanza a leer al
     * de atrás no manda: la etiqueta asoma a pedazos y a veces es la del
     * vecino. Solo una marca distinta, leída con claridad, lo descarta.
     *
     * @param  list<array<string,mixed>>  $tarjetas
     * @return list<array<string,mixed>>
     */
    private function sugerirLosDeAtras(array $tarjetas): array
    {
        $familia = fn ($t) => in_array($t['propuesta']['tipo'] ?? null, ['insumo', 'componente', 'producto'], true) ? 'insumo' : 'activo';

        foreach ($tarjetas as $j => $atras) {
            $cj = $atras['objeto']['caja'] ?? null;

            // Solo los parcialmente tapados: del que solo asoma la tapa no se
            // adivina nada, ni siquiera que sea igual al de adelante. Y solo
            // insumos: dos botes de resina en fila suelen ser la misma; dos
            // osciloscopios apilados son dos equipos, cada uno con su ficha.
            if (! $cj || ($atras['objeto']['visibilidad'] ?? 'completo') !== 'parcial' || $familia($atras) !== 'insumo') {
                continue;
            }

            $mejor = null;
            $mejorSolape = 0.0;

            foreach ($tarjetas as $i => $adelante) {
                $ci = $adelante['objeto']['caja'] ?? null;

                if ($i === $j || ! $ci || $familia($adelante) !== $familia($atras)) {
                    continue;
                }

                // De lado se superponen al menos un tercio del de atrás…
                $solape = max(0, min($cj[2], $ci[2]) - max($cj[0], $ci[0])) / max(1, $cj[2] - $cj[0]);
                // …y su base queda más arriba: está más al fondo de la repisa.
                $masAlFondo = $cj[3] < $ci[3] - 15 && $cj[1] < $ci[1];

                if ($solape >= 0.3 && $masAlFondo && $solape > $mejorSolape) {
                    $mejor = $i;
                    $mejorSolape = $solape;
                }
            }

            if ($mejor === null) {
                continue;
            }

            $adelante = $tarjetas[$mejor];
            $marcaAtras = self::comparable((string) ($atras['propuesta']['marca'] ?? ''));
            $marcaAdelante = self::comparable((string) ($adelante['propuesta']['marca'] ?? ''));

            if ($marcaAtras !== '' && $marcaAdelante !== '' && $marcaAtras !== $marcaAdelante) {
                continue;
            }

            $tarjetas[$j]['objeto']['detras_de'] = $adelante['objeto']['indice'] ?? null;

            // Lo que se le reconoció al de adelante —solo si de verdad se le
            // reconoció: por foto, o como «más probable»; un 55 % suelto no—.
            $producto = match (true) {
                $adelante['mismo'] => $adelante['por_foto'][0] ?? null,
                (bool) ($adelante['por_foto'][0]['probable'] ?? false) => $adelante['por_foto'][0],
                (bool) ($adelante['por_texto'][0]['probable'] ?? false) => $adelante['por_texto'][0],
                default => null,
            };

            if (! $producto) {
                continue;
            }

            // Entra con el porcentaje del de adelante y en su lugar: es «el más
            // probable» solo si nada se parece más. Nunca dos con estrella.
            $sugerido = ['razones' => ['está detrás del objeto ' . ($adelante['objeto']['indice'] ?? '?')], 'probable' => false] + $producto;

            // El color que se le leyó al de atrás no cuenta —la etiqueta asoma
            // a pedazos o es la del vecino—: se le quita lo que sumó o restó.
            $sinColor = function (array $c): array {
                $razones = $c['razones'] ?? [];
                $ajuste = (in_array('otro color', $razones, true) ? self::PESO_COLOR : 0.0) - (in_array('mismo color', $razones, true) ? self::PESO_COLOR : 0.0);

                return ['similitud' => $c['similitud'] + $ajuste, 'razones' => array_values(array_diff($razones, ['otro color', 'mismo color'])), 'otro_color' => false, 'probable' => false] + $c;
            };

            $porTexto = collect($atras['por_texto'])->reject(fn ($c) => $c['clave'] === $producto['clave'])->map($sinColor)->push($sugerido)
                ->sortByDesc('similitud')->values();
            $porFoto = collect($atras['por_foto'])->reject(fn ($c) => $c['clave'] === $producto['clave'])->map($sinColor)->sortByDesc('similitud')->values();

            $esElMejor = $porTexto->first()['clave'] === $producto['clave']
                && (float) ($porFoto->first()['similitud'] ?? 0) <= $sugerido['similitud'];

            $tarjetas[$j]['por_texto'] = $porTexto->map(fn ($c) => ['probable' => $esElMejor && $c['clave'] === $producto['clave']] + $c)->all();
            $tarjetas[$j]['por_foto'] = $porFoto->all();
            [$tarjetas[$j]['por_foto'], $tarjetas[$j]['por_texto']] = self::unSoloMasProbable($tarjetas[$j]['por_foto'], $tarjetas[$j]['por_texto']);
            $tarjetas[$j]['mismo'] = false;
            $tarjetas[$j]['resumen'] = 'Está detrás del objeto ' . ($adelante['objeto']['indice'] ?? '?') . ($esElMejor
                ? ' y parece otra unidad del mismo producto: «' . $producto['nombre'] . '». Suele ser así cuando se guardan en fila; si es otro, elígelo abajo.'
                : '; puede ser otra unidad de «' . $producto['nombre'] . '», pero hay opciones que se parecen más. Revisa la etiqueta.');
        }

        return $tarjetas;
    }

    /** Hasta qué separación (en milésimas de la foto) una parte con nombre de parte va con su máquina. */
    private const PARTE_CERCA_DE_SU_MAQUINA = 200;

    /**
     * Lo que por su nombre es una parte de una máquina —la consola o el panel
     * de control, la torre de luces, la mesa de trabajo, el gabinete— va con
     * la máquina más cercana. En la foto de la CNC grande salían ocho objetos:
     * su consola, su torre de luces, su mesa y su gabinete, cada uno aparte.
     *
     * Esto sí se junta solo (la persona puede deshacerlo con «No, va aparte»):
     * el nombre ya dice que es una parte.
     *
     * @param  list<array<string,mixed>>  $tarjetas
     * @return list<array<string,mixed>>
     */
    private function colgarLasPartesDeLaMaquina(array $tarjetas, \Closure $esEquipo): array
    {
        $nombre = fn ($t) => self::comparable((string) ($t['propuesta']['nombre'] ?? ''));
        $esParteDeNombre = fn ($t) => (bool) preg_match('/^(consolas?|panel(es)?|tableros?|pantallas?|teclados?|mouse|raton(es)?|torres? de (luces|senal)'
            . '|balizas?|semaforos?|linternas? de senal|luz de senal|luces de senal|senalizador(es)?|indicador(es)? luminosos?|torres?'
            . '|mesas? de trabajo|camas?|bancadas?|gabinetes?|armarios?'
            . '|cajas? de control|controlador(es)?|portico|cabezal(es)?'
            // Lo que va de la mano con otro objeto —regla general, no solo del
            // soldador—: su soporte, su base, su esponja, su estuche, su control,
            // su pedal, su boquilla, sus mangueras.
            . '|soportes?|bases?|esponjas?|estuches?|fundas?|limpiador(es)? de puntas?|controles? remotos?|mandos?'
            . '|pedal(es)?|boquillas?|mangueras?|ductos?|accesorios?'
            // Y el colector de polvo de la CNC: sus bolsas y sus filtros.
            . '|bolsas?|filtros?|colector(es)?|extractor(es)?)\b/', $nombre($t))
            || (bool) preg_match('/\b(con panel|panel de control|consola de control|tablero de control)\b/', $nombre($t));
        $esParte = $esParteDeNombre;

        /*
         * El mango de una estación —el soldador, el cautín, la pistola de aire
         * caliente— va con la estación si hay una en la foto. Sin estación, un
         * cautín es un equipo propio (en el inventario hay «Cautín 1» a «3»).
         */
        $estaciones = collect($tarjetas)->filter(fn ($t) => isset($t['objeto']['caja']) && str_starts_with($nombre($t), 'estacion'));
        $esMango = fn ($t) => $estaciones->isNotEmpty()
            && (bool) preg_match('/^(soldador(es)?|cautin(es)?|lapiz de soldar|pistola de (aire caliente|calor)|mangos?)\b/', $nombre($t));

        $hueco = function (array $a, array $b): int {
            [$a, $b] = [array_map('intval', array_values($a)), array_map('intval', array_values($b))];

            return max(max(0, max($a[0], $b[0]) - min($a[2], $b[2])), max(0, max($a[1], $b[1]) - min($a[3], $b[3])));
        };

        $maquinas = collect($tarjetas)->filter(fn ($t) => $esEquipo($t) && ! $esParte($t) && ! $esMango($t) && isset($t['objeto']['caja']));

        // Si solo se vieron partes —la consola y la mesa de la CNC, sin la
        // máquina—, la más grande hace de máquina y las demás van con ella.
        if ($maquinas->isEmpty()) {
            $mayor = collect($tarjetas)->filter(fn ($t) => $esEquipo($t) && $esParte($t) && isset($t['objeto']['caja']))
                ->sortByDesc(fn ($t) => self::area($t['objeto']['caja']))->first();

            if (! $mayor) {
                return $tarjetas;
            }

            $maquinas = collect([$mayor]);
            $esParte = fn ($t) => $t['clave'] !== $mayor['clave'] && $esParteDeNombre($t);
        }

        $colgar = function (int $p, array $principal) use (&$tarjetas): void {
            $tarjetas[$p]['objeto']['pieza_de'] = $principal['objeto']['indice'] ?? null;
            $tarjetas[$p]['objeto']['pieza_de_clave'] = $principal['clave'];
            $tarjetas[$p]['objeto']['pieza_segura'] = true;
        };

        /*
         * Del mismo tipo, mucho más chico y pegado a otro: es una parte suya.
         * Salían dos «estaciones de soldadura»: la Weller y, pegada a ella,
         * una cajita de su mango. Dos osciloscopios apilados no entran aquí:
         * son del mismo tamaño.
         */
        $tipo = fn ($t) => strtok($nombre($t), ' ') ?: null; // sin nombre no hay tipo
        foreach ($tarjetas as $p => $t) {
            if (! $esEquipo($t) || ! isset($t['objeto']['caja'])) {
                continue;
            }

            $mayor = collect($tarjetas)->first(fn ($o) => $o['clave'] !== $t['clave'] && $esEquipo($o) && isset($o['objeto']['caja'])
                && $tipo($t) !== null && $tipo($o) === $tipo($t) && self::area($t['objeto']['caja']) < self::area($o['objeto']['caja']) / 4
                && $hueco($t['objeto']['caja'], $o['objeto']['caja']) <= self::PIEZAS_PEGADAS);

            if ($mayor) {
                $colgar($p, $mayor);
                $maquinas = $maquinas->reject(fn ($m) => $m['clave'] === $t['clave']);
                $estaciones = $estaciones->reject(fn ($e) => $e['clave'] === $t['clave']);
            }
        }

        // Los mangos, con su estación: la principal, la más grande.
        $estacion = $estaciones->sortByDesc(fn ($e) => self::area($e['objeto']['caja']))->first();
        foreach ($tarjetas as $p => $t) {
            if ($estacion && $esMango($t) && isset($t['objeto']['caja'])) {
                $colgar($p, $estacion);
            }
        }

        // Los accesorios pegados a un mango ya colgado —el soporte del
        // soldador— van con la misma estación.
        foreach ($tarjetas as $p => $t) {
            if (! $esParte($t) || isset($tarjetas[$p]['objeto']['pieza_de_clave']) || ! isset($t['objeto']['caja'])) {
                continue;
            }

            $mango = collect($tarjetas)->first(fn ($o) => $esMango($o) && isset($o['objeto']['caja'])
                && $hueco($t['objeto']['caja'], $o['objeto']['caja']) <= self::PIEZAS_PEGADAS);

            if ($mango) {
                $colgar($p, collect($tarjetas)->firstWhere('clave', $mango['objeto']['pieza_de_clave']));
            }
        }

        /*
         * Lo que lleva en el nombre el modelo de una máquina de la foto —la
         * «bolsa SS2-1325A» del colector de la CNC— es parte de ella, si es de
         * otro tipo (dos «osciloscopio DS2202A» son dos osciloscopios).
         */
        foreach ($tarjetas as $p => $t) {
            if (isset($tarjetas[$p]['objeto']['pieza_de_clave']) || ! isset($t['objeto']['caja'])) {
                continue;
            }

            $suyos = self::modelosEn($t['propuesta']['nombre'] ?? '');
            $duena = $suyos === [] ? null : $maquinas->first(fn ($m) => $m['clave'] !== $t['clave'] && $tipo($m) !== $tipo($t)
                && array_intersect($suyos, self::modelosEn(implode(' ', [$m['propuesta']['nombre'] ?? '', $m['propuesta']['texto_visible'] ?? '']))) !== []);

            if ($duena) {
                $colgar($p, $duena);
                $maquinas = $maquinas->reject(fn ($m) => $m['clave'] === $t['clave']);
            }
        }

        // Lo demás, con la máquina más cercana.
        foreach ($tarjetas as $p => $t) {
            if (! $esParte($t) || isset($tarjetas[$p]['objeto']['pieza_de_clave']) || ! isset($t['objeto']['caja']) || ($t['propuesta']['tipo'] ?? null) === 'insumo') {
                continue;
            }

            $cerca = $maquinas->sortBy(fn ($m) => $hueco($t['objeto']['caja'], $m['objeto']['caja']))->first();

            // Si en la foto hay una sola máquina, sus partes van con ella
            // aunque su caja quede lejos: de la CNC el modelo solo encerró el
            // letrero «SS2-1325A», al otro lado de la consola.
            if ($maquinas->count() === 1 || $hueco($t['objeto']['caja'], $cerca['objeto']['caja']) <= self::PARTE_CERCA_DE_SU_MAQUINA) {
                $colgar($p, $cerca);
            }
        }

        // Y lo que queda pegado a una parte ya colgada —la torre de luces
        // encima de la consola— va con la misma máquina.
        foreach ($tarjetas as $p => $t) {
            if (! $esParte($t) || isset($t['objeto']['pieza_de_clave']) || ! isset($t['objeto']['caja'])) {
                continue;
            }

            $vecina = collect($tarjetas)->first(fn ($o) => isset($o['objeto']['pieza_de_clave'], $o['objeto']['caja'])
                && $hueco($t['objeto']['caja'], $o['objeto']['caja']) <= self::PIEZAS_PEGADAS);

            if ($vecina) {
                $colgar($p, collect($tarjetas)->firstWhere('clave', $vecina['objeto']['pieza_de_clave']));
            }
        }

        return $tarjetas;
    }

    /** Hasta qué separación (en milésimas de la foto) dos partes se tienen por pegadas. */
    private const PIEZAS_PEGADAS = 80;

    /**
     * Dos equipos que pueden ser una sola pieza: la bomba de vacío y su cámara,
     * unidas por la manguera, salían como dos objetos, y en el inventario son
     * uno («Bomba de Vacío con Compresor»). Si son equipos de la misma marca
     * leída, o el inventario sugiere para los dos el mismo registro, y están
     * pegados uno al otro, al menor se le avisa «puede ser una sola pieza con
     * el objeto N». No se juntan solos: puede pasar o no, y lo decide la persona.
     *
     * @param  list<array<string,mixed>>  $tarjetas
     * @return list<array<string,mixed>>
     */
    private function marcarLasQuePuedenSerUnaPieza(array $tarjetas): array
    {
        $esEquipo = fn ($t) => in_array($t['propuesta']['tipo'] ?? null, ['activo', 'herramienta'], true) && ! ($t['objeto']['tapado'] ?? false);
        $marca = fn ($t) => self::comparable((string) ($t['propuesta']['marca'] ?? ''));
        $registro = fn ($t) => match (true) {
            (bool) $t['mismo'] => $t['por_foto'][0]['clave'] ?? null,
            (bool) ($t['por_foto'][0]['probable'] ?? false) => $t['por_foto'][0]['clave'],
            (bool) ($t['por_texto'][0]['probable'] ?? false) => $t['por_texto'][0]['clave'],
            default => null,
        };

        $pegados = function (array $a, array $b): bool {
            [$a, $b] = [array_map('intval', array_values($a)), array_map('intval', array_values($b))];
            $hueco = max(0, max($a[0], $b[0]) - min($a[2], $b[2]));
            $alto = max(0, min($a[3], $b[3]) - max($a[1], $b[1])) / max(1, min($a[3] - $a[1], $b[3] - $b[1]));

            return $hueco <= self::PIEZAS_PEGADAS && $alto >= 0.3;
        };

        $tarjetas = $this->colgarLasPartesDeLaMaquina($tarjetas, $esEquipo);

        foreach ($tarjetas as $i => $a) {
            foreach ($tarjetas as $j => $b) {
                if ($j <= $i || ! $esEquipo($a) || ! $esEquipo($b) || ! isset($a['objeto']['caja'], $b['objeto']['caja'])
                    || isset($tarjetas[$i]['objeto']['pieza_de']) || isset($tarjetas[$j]['objeto']['pieza_de'])) {
                    continue;
                }

                // Dos del mismo tipo —dos osciloscopios apilados— son dos
                // unidades, no partes. Y lo que el inventario tiene como
                // familia numerada (Osciloscopio 1 a 5) es un equipo completo.
                $tipo = fn ($t) => strtok(self::comparable((string) ($t['propuesta']['nombre'] ?? '')), ' ') ?: null;
                $deFamilia = fn ($t) => collect($t['por_texto'] ?? [])->contains('familia', true);

                if ($tipo($a) === $tipo($b) || $deFamilia($a) || $deFamilia($b)) {
                    continue;
                }

                $mismaMarca = $marca($a) !== '' && $marca($a) === $marca($b);
                $mismoRegistro = $registro($a) !== null && $registro($a) === $registro($b);

                if (! ($mismaMarca || $mismoRegistro) || ! $pegados($a['objeto']['caja'], $b['objeto']['caja'])) {
                    continue;
                }

                // El principal es el más grande; el otro es la parte.
                [$principal, $parte] = self::area($a['objeto']['caja']) >= self::area($b['objeto']['caja']) ? [$i, $j] : [$j, $i];
                $tarjetas[$parte]['objeto']['pieza_de'] = $tarjetas[$principal]['objeto']['indice'] ?? null;
                $tarjetas[$parte]['objeto']['pieza_de_clave'] = $tarjetas[$principal]['clave'];
            }
        }

        return $tarjetas;
    }

    /**
     * Las tarjetas que son el mismo producto del inventario se marcan, no se
     * juntan.
     *
     * Dos botes azules en la foto son dos artículos, y se ven los dos: la
     * segunda tarjeta dice «Igual que el objeto 2», para que se entienda que
     * es otra unidad del mismo producto y no un error de la IA.
     *
     * @param  list<array<string,mixed>>  $tarjetas
     * @return list<array<string,mixed>>
     */
    private function marcarLasDelMismoRegistro(array $tarjetas): array
    {
        $grupos = [];

        foreach ($tarjetas as $i => $t) {
            $p = $t['propuesta'] ?? [];
            $colores = implode(',', self::colores(implode(' ', [$p['variante'] ?? '', $p['nombre'] ?? ''])));

            /*
             * Por foto, basta con que sean el mismo registro. Por nombre, no:
             * cuatro resinas distintas pueden tener todas como «más probable»
             * la «Resina estándar» genérica, y juntarlas borraría la diferencia
             * entre la rosa y la marrón. Ahí además tiene que leerse el mismo
             * color.
             */
            $registro = match (true) {
                $t['mismo'] => 'foto|' . ($t['por_foto'][0]['clave'] ?? $t['clave']),
                ($t['por_texto'][0]['probable'] ?? false) && $colores !== '' => 'texto|' . $t['por_texto'][0]['clave'] . '|' . $colores,
                default => null,
            };

            if ($registro !== null) {
                $grupos[$registro][] = $i;
            }
        }

        /*
         * Uno al lado del otro no es lo mismo que uno detrás del otro.
         *
         * Dos botes en la misma botella —la Water Washable negra y la roja— se
         * parecen en la foto igual que dos del mismo producto. Cuando varios
         * salen como el mismo registro, a cada uno que no esté detrás de otro
         * se le pide que su propia etiqueta lo confirme: el color o la marca
         * del registro. Al que no se le leyó nada se le avisa en vez de
         * afirmarlo, sin importar en qué orden salió en la foto.
         */
        foreach ($grupos as $indices) {
            if (count($indices) < 2) {
                continue;
            }

            $confirmados = [];
            $dudosos = [];

            foreach ($indices as $i) {
                $t = $tarjetas[$i];

                // El de atrás ya trae su sugerencia: ahí sí se presume que es otro igual.
                if ($t['objeto']['detras_de'] ?? null) {
                    continue;
                }

                $this->etiquetaLoConfirma($t) ? $confirmados[] = $i : $dudosos[] = $i;
            }

            // El primero confirmado es la referencia; los demás confirmados son «el mismo».
            foreach (array_slice($confirmados, 1) as $i) {
                $tarjetas[$i]['objeto']['igual_a'] = $tarjetas[$confirmados[0]]['objeto']['indice'] ?? null;
            }

            foreach ($dudosos as $i) {
                $referencia = $confirmados[0] ?? collect($indices)->first(fn ($k) => $k !== $i);
                $otro = $tarjetas[$referencia]['objeto']['indice'] ?? null;

                $tarjetas[$i]['objeto']['parecido_a'] = $otro;

                if ($tarjetas[$i]['mismo']) {
                    $tarjetas[$i]['mismo'] = false;
                    $tarjetas[$i]['por_foto'][0]['razones'] = array_merge($tarjetas[$i]['por_foto'][0]['razones'] ?? [], ['se parece al objeto ' . $otro]);
                }

                $tarjetas[$i]['resumen'] = 'Se parece al objeto ' . $otro . ', pero no está detrás de él: puede ser otro producto en el mismo envase. '
                    . 'No le leí el color ni la marca; revisa su etiqueta y elige abajo.';
            }
        }

        return $tarjetas;
    }

    /** Si lo que se le leyó a este objeto confirma el registro con que se lo emparejó. */
    private function etiquetaLoConfirma(array $t): bool
    {
        $p = $t['propuesta'] ?? [];
        $registro = $t['mismo'] ? ($t['por_foto'][0]['nombre'] ?? '') : ($t['por_texto'][0]['nombre'] ?? '');
        $marca = array_values(array_diff(self::palabras((string) ($p['marca'] ?? '')), self::NO_SON_MARCA));

        return array_intersect(self::colores(implode(' ', [$p['variante'] ?? '', $p['nombre'] ?? ''])), self::colores($registro)) !== []
            || ($marca !== [] && array_diff($marca, self::palabras($registro)) === []);
    }

    /** Lecturas de etiqueta de cerca por foto: cada una son unos segundos del servidor. */
    private const MAX_LECTURAS = 5;

    /** Si al objeto le falta lo que distingue a uno de otro: la marca o el color. */
    private function leHaceFaltaLeer(array $o): bool
    {
        $variante = self::comparable((string) ($o['variante'] ?? ''));

        return blank($o['marca'] ?? null)
            || blank($o['variante'] ?? null)
            || str_contains($variante, 'no legible')
            || str_contains(self::comparable((string) ($o['nombre'] ?? '')), 'no legible');
    }

    /**
     * Los colores que nombra un texto, en una forma común para los dos idiomas
     * en que vienen las etiquetas: «Blue» y «azul» son el mismo color.
     *
     * @return list<string>
     */
    public static function colores(string $texto): array
    {
        static $sinonimos = [
            'negro' => ['negro', 'negra', 'black', 'smoky black'],
            'blanco' => ['blanco', 'blanca', 'white'],
            'gris' => ['gris', 'grey', 'gray', 'plateado', 'plateada', 'silver'],
            'azul' => ['azul', 'blue'],
            'amarillo' => ['amarillo', 'amarilla', 'yellow'],
            'rojo' => ['rojo', 'roja', 'red'],
            'verde' => ['verde', 'green'],
            'naranja' => ['naranja', 'orange'],
            'rosa' => ['rosa', 'rosado', 'rosada', 'pink'],
            'marron' => ['marron', 'cafe', 'brown'],
            'transparente' => ['transparente', 'clear', 'transparent', 'high transparency'],
            'morado' => ['morado', 'morada', 'purple', 'violeta'],
            'piel' => ['piel', 'flesh', 'skin'],
        ];

        $t = ' ' . preg_replace('/[^a-z0-9]+/', ' ', self::comparable($texto)) . ' ';
        $encontrados = [];

        foreach ($sinonimos as $color => $palabras) {
            foreach ($palabras as $p) {
                if (str_contains($t, ' ' . $p . ' ')) {
                    $encontrados[] = $color;

                    break;
                }
            }
        }

        sort($encontrados);

        return $encontrados;
    }

    /** Dos textos nombran colores distintos (y ninguno en común). */
    public static function colorDistinto(string $a, string $b): bool
    {
        $ca = self::colores($a);
        $cb = self::colores($b);

        return $ca !== [] && $cb !== [] && array_intersect($ca, $cb) === [];
    }

    /**
     * Los objetos de la foto, o null si no se pudo separarlos.
     *
     * @return list<array<string,mixed>>|null
     */
    private function objetosDe(string $ruta, ?string $grande = null): ?array
    {
        if (! config('fabos.servidor_fablab.separar_objetos')) {
            return null;
        }

        $objetos = null;

        try {
            $objetos = $this->depurarObjetos($this->servidor->detectarObjetos($ruta, $this->categorias()));
        } catch (\Throwable $e) {
            Log::warning('Activo IA: no se pudieron separar los objetos de la foto', ['error' => $e->getMessage()]);
        }

        /*
         * Con muchos objetos, por partes.
         *
         * En una repisa llena el modelo pierde el hilo: en una foto de
         * pegantes listó seis veces el mismo «PL285» y dejó sin ver la mitad
         * de la repisa (B-7000, Novarchem, Topex, Epasun). Mirando la mitad
         * izquierda y la derecha por separado vio todo, una vez cada cosa. Se
         * hace solo cuando hace falta, porque cuesta dos lecturas más.
         */
        /*
         * Una segunda opinión cuando vio muy poco.
         *
         * Con la lista de categorías del inventario en las instrucciones, en
         * la repisa de pegantes el modelo a veces ve 2 objetos; sin la lista,
         * con la misma foto, ve 10. Si vio pocos y cubren poco de la foto, se
         * pregunta otra vez sin la lista y se queda la lectura que vea más.
         * Una foto de cerca de un solo producto no entra aquí: ese ocupa casi
         * toda la foto.
         */
        if ($objetos !== null && $this->vioPoco($objetos)) {
            try {
                $otra = $this->depurarObjetos($this->servidor->detectarObjetos($ruta, []));

                if (count($otra) > count($objetos)) {
                    $objetos = $otra;
                }
            } catch (\Throwable $e) {
                Log::warning('Activo IA: no se pudo pedir una segunda lectura de la foto', ['error' => $e->getMessage()]);
            }
        }

        if ($objetos === null || $this->convienePorPartes($objetos)) {
            $porPartes = $this->detectarPorPartes($grande && is_file($grande) ? $grande : $ruta);

            /*
             * Las mitades suman a la foto entera, no la reemplazan: en la foto
             * de las impresoras de resina la lectura por mitades perdió la
             * Elegoo Saturn de la izquierda, que la foto entera sí había visto.
             * Lo de la foto entera que no está en ninguna mitad se queda.
             */
            //
            // Pero no lo que el modelo repitió al trabarse —seis «PL285» en
            // fila—: de eso solo se rescata lo grande, como una máquina.
            if ($porPartes) {
                $repetidos = collect($objetos ?? [])->countBy(fn ($o) => self::comparable((string) $o['nombre']))->filter(fn ($n) => $n >= 3)->keys()->all();
                $sueltos = collect($objetos ?? [])
                    ->reject(fn ($o) => collect($porPartes)->contains(fn ($p) => self::seSuperponen($p['caja'], $o['caja'])
                        || self::dentroDe($o['caja'], $p['caja']) >= 0.5 || self::dentroDe($p['caja'], $o['caja']) >= 0.5))
                    ->filter(fn ($o) => ! in_array(self::comparable((string) $o['nombre']), $repetidos, true) || self::area($o['caja']) >= self::CAJA_DE_MAQUINA);

                $objetos = collect($porPartes)->merge($sueltos)
                    ->filter(fn ($o) => self::seInventaria($o)) // el tamaño, ya sobre la foto entera
                    ->sortBy(fn ($o) => $o['caja'][0])
                    ->values()
                    ->all();
            }
        }

        if ($objetos) {
            $objetos = $this->separarLosGrandes($objetos, $grande && is_file($grande) ? $grande : $ruta);
            $objetos = $this->sinLoSueltoSobreLaMaquina($objetos);

            // Los accesorios (el cargador, los cables), también al final: la
            // lectura por mitades o la de cerca los podía volver a traer.
            $equipos = array_values(array_filter($objetos, fn ($o) => ! self::esCable($o)));
            $objetos = $equipos !== [] ? $equipos : $objetos;

            $objetos = $this->unirLasPartesDeLaMaquina($objetos);
        }

        return $objetos ? array_slice($objetos, 0, (int) config('fabos.servidor_fablab.max_objetos', 16)) : null;
    }

    /**
     * Un envase sin nada legible —una botella, un frasco, un atomizador— que
     * está encima o pegado a una máquina no se cuenta: no se sabe qué es, y en
     * la foto de una máquina es parte del puesto. La CNC tenía que quedar como
     * un solo objeto, y salía con la botella spray de encima de su consola. Si
     * el envase tiene etiqueta o marca legible, sí cuenta.
     */
    private function sinLoSueltoSobreLaMaquina(array $objetos): array
    {
        $maquinas = collect($objetos)->filter(fn ($o) => in_array($o['tipo'] ?? null, ['activo', 'herramienta'], true));

        if ($maquinas->isEmpty()) {
            return $objetos;
        }

        $envaseSinNada = fn ($o) => preg_match('/^(botellas?|frascos?|envases?|atomizador(es)?|spray|recipientes?|vasos?|tarros?)\b/', self::comparable((string) ($o['nombre'] ?? '')))
            && blank($o['marca'] ?? null) && blank($o['texto_visible'] ?? null);

        $pegado = function (array $a, array $b): bool {
            [$a, $b] = [array_map('intval', array_values($a)), array_map('intval', array_values($b))];

            return max(0, max($a[0], $b[0]) - min($a[2], $b[2])) <= self::PIEZAS_PEGADAS
                && max(0, max($a[1], $b[1]) - min($a[3], $b[3])) <= self::PIEZAS_PEGADAS;
        };

        // El material que está sobre la máquina —la lámina de madera en la
        // mesa de la CNC— es trabajo en proceso, no algo del inventario.
        $materialEncima = fn ($o) => preg_match('/^(madera|laminas?|planchas?|tableros? de|panel(es)? de madera|contrachapad|mdf|acrilico|carton|hojas? de)/', self::comparable((string) ($o['nombre'] ?? '')))
            && $maquinas->contains(fn ($m) => $m !== $o && self::dentroDe($o['caja'], $m['caja']) >= 0.6);

        return array_values(array_filter($objetos, fn ($o) => ! $materialEncima($o) && ! ($envaseSinNada($o)
            && $maquinas->contains(fn ($m) => $m !== $o && $pegado($o['caja'], $m['caja'])))));
    }

    /**
     * El nombre de una máquina unida con sus partes, leído sobre la máquina
     * entera: «Router CNC SS2-1325A», no «Consola de control de máquina CNC».
     * Si la lectura falla o no dice nada, se queda el nombre que traía.
     */
    private function nombrarLaMaquinaUnida(array $objeto, string $imagen): array
    {
        if (! ($objeto['unido'] ?? false)) {
            return $objeto;
        }

        try {
            $leido = array_filter($this->servidor->leerEtiqueta($imagen, esEquipo: true), fn ($v) => $v !== null);
        } catch (\Throwable $e) {
            Log::info('Activo IA: no se pudo nombrar la máquina entera', ['error' => $e->getMessage()]);

            return $objeto;
        }

        return array_merge($objeto, $leido);
    }

    /**
     * Las partes de una máquina se juntan con ella antes de recortar: la caja
     * pasa a cubrir la máquina entera, y el objeto lleva la lista de lo que
     * incluye. De la CNC solo quedaba el recorte de la consola —se guardaba
     * esa foto y se llamaba «Consola de control»—, cuando el objeto era toda
     * la máquina. Las reglas son las de siempre: el nombre de la parte, la
     * máquina más cercana, el mango con su estación.
     */
    private function unirLasPartesDeLaMaquina(array $objetos): array
    {
        $comoTarjetas = array_map(fn ($o, $i) => [
            'clave' => 'o' . $i,
            'propuesta' => ['nombre' => $o['nombre'] ?? null, 'tipo' => $o['tipo'] ?? null, 'texto_visible' => $o['texto_visible'] ?? null],
            'objeto' => ['caja' => $o['caja'], 'indice' => $i + 1, 'tapado' => ($o['visibilidad'] ?? null) === 'casi_oculto'],
            'mismo' => false, 'por_foto' => [], 'por_texto' => [],
        ], $objetos, array_keys($objetos));

        $esEquipo = fn ($t) => in_array($t['propuesta']['tipo'] ?? null, ['activo', 'herramienta'], true) && ! ($t['objeto']['tapado'] ?? false);
        $colgadas = $this->colgarLasPartesDeLaMaquina($comoTarjetas, $esEquipo);

        $de = [];
        foreach ($colgadas as $i => $t) {
            if ($t['objeto']['pieza_segura'] ?? false) {
                $de[$i] = (int) substr($t['objeto']['pieza_de_clave'], 1);
            }
        }

        if ($de === []) {
            return $objetos;
        }

        foreach ($de as $parte => $principal) {
            [$a, $b] = [array_map('intval', array_values($objetos[$principal]['caja'])), array_map('intval', array_values($objetos[$parte]['caja']))];
            $objetos[$principal]['caja'] = [min($a[0], $b[0]), min($a[1], $b[1]), max($a[2], $b[2]), max($a[3], $b[3])];
            $objetos[$principal]['incluye'][] = $objetos[$parte]['nombre'];
            $objetos[$principal]['unido'] = true;
            $objetos[$principal]['tipo'] = 'activo';
        }

        return array_values(array_diff_key($objetos, $de));
    }

    /** Una caja de este tamaño o más (un 10 % de la foto) es una máquina, no un bote. */
    private const CAJA_DE_MAQUINA = 100000;

    /** Una caja desde este tamaño (en milésimas al cuadrado) puede llevar varios equipos dentro. */
    private const CAJA_GRANDE = 120000;

    /** Cuántas cajas grandes se miran de cerca, como mucho: cada una es una lectura más. */
    private const MAX_CAJAS_DE_CERCA = 3;

    /**
     * Las cajas grandes, otra vez y de cerca.
     *
     * Dos fuentes Siglent, una encima de la otra, salían en una sola caja: de
     * lejos el modelo ve un bloque. Recortada esa zona, ve las dos. Solo se
     * cambia la caja si de cerca salen dos o más objetos de buen tamaño (no
     * una etiqueta o un cable), y se queda como estaba si la lectura falla.
     */
    private function separarLosGrandes(array $objetos, string $fuente): array
    {
        // Solo equipos: es lo que se apila. Un bote grande es un bote.
        $grandes = collect($objetos)
            ->filter(fn ($o) => self::area($o['caja']) >= self::CAJA_GRANDE && in_array($o['tipo'] ?? null, ['activo', 'herramienta'], true))
            ->sortByDesc(fn ($o) => self::area($o['caja']))
            ->take(self::MAX_CAJAS_DE_CERCA)
            ->keys();

        if ($grandes->isEmpty() || ! ($imagen = @imagecreatefromjpeg($fuente))) {
            return $objetos;
        }

        /*
         * Un equipo que queda casi entero dentro de una caja grande que se va
         * a mirar de cerca sobra: de cerca se vuelve a ver. Dos osciloscopios
         * apilados venían en una caja para los dos y otra para el de arriba;
         * al mirar las dos de cerca, el de arriba salía dos veces.
         */
        $esEquipo = fn ($o) => in_array($o['tipo'] ?? null, ['activo', 'herramienta'], true);
        $objetos = collect($objetos)
            ->reject(fn ($o, $i) => $esEquipo($o) && $grandes->contains(fn ($g) => $g !== $i
                && self::area($objetos[$g]['caja']) > self::area($o['caja'])
                && self::dentroDe($o['caja'], $objetos[$g]['caja']) >= 0.7))
            ->all();
        $grandes = $grandes->filter(fn ($g) => isset($objetos[$g]))->values();

        [$ancho, $alto] = [imagesx($imagen), imagesy($imagen)];
        $resultado = [];

        foreach ($objetos as $i => $o) {
            if (! $grandes->contains($i)) {
                $resultado[] = $o;

                continue;
            }

            [$x1, $y1, $x2, $y2] = array_map('intval', array_values($o['caja']));
            $recorte = imagecrop($imagen, [
                'x' => (int) ($x1 / 1000 * $ancho), 'y' => (int) ($y1 / 1000 * $alto),
                'width' => max(1, (int) (($x2 - $x1) / 1000 * $ancho)), 'height' => max(1, (int) (($y2 - $y1) / 1000 * $alto)),
            ]);

            $adentro = [];

            if ($recorte) {
                $lado = max(imagesx($recorte), imagesy($recorte));
                if ($lado > FotoParaLaIa::LADO_MAXIMO) {
                    $recorte = imagescale($recorte, (int) round(imagesx($recorte) * FotoParaLaIa::LADO_MAXIMO / $lado));
                }

                $temporal = tempnam(sys_get_temp_dir(), 'cerca') . '.jpg';
                imagejpeg($recorte, $temporal, 88);

                try {
                    $adentro = $this->depurarObjetos($this->servidor->detectarObjetos($temporal, $this->categorias()));
                } catch (\Throwable $e) {
                    Log::info('Activo IA: no se pudo mirar de cerca una caja grande', ['error' => $e->getMessage()]);
                } finally {
                    @unlink($temporal);
                }
            }

            // De buen tamaño: al menos una sexta parte del recorte cada uno.
            $adentro = array_values(array_filter($adentro, fn ($a) => self::area($a['caja']) >= 1000000 / 6));

            if (count($adentro) < 2) {
                $resultado[] = $o;

                continue;
            }

            foreach ($adentro as $a) {
                [$a1, $b1, $a2, $b2] = array_map('intval', array_values($a['caja']));
                $a['caja'] = [
                    (int) round($x1 + $a1 * ($x2 - $x1) / 1000), (int) round($y1 + $b1 * ($y2 - $y1) / 1000),
                    (int) round($x1 + $a2 * ($x2 - $x1) / 1000), (int) round($y1 + $b2 * ($y2 - $y1) / 1000),
                ];
                $resultado[] = $a;
            }
        }

        return $resultado;
    }

    /** Lo que se puede usar de una detección: con nombre, caja y que se inventaríe. */
    private function depurarObjetos(array $objetos): array
    {
        return collect($objetos)
            // «null» o «none» escritos como nombre son nada: el modelo a veces los pone así.
            ->filter(fn ($o) => filled($o['nombre'] ?? null) && ! in_array(mb_strtolower(trim((string) $o['nombre'])), ['null', 'none', 'n/a', 'desconocido'], true) && self::cajaValida($o['caja'] ?? null))
            ->filter(fn ($o) => self::seInventaria($o))
            ->values()
            ->pipe(function ($objetos) {
                // Los cables que acompañan a los equipos no son artículos
                // aparte: en el estante de las fuentes salían cables de poder,
                // caimanes y un USB como si fueran cosas para inventariar. Si
                // la foto es solo de cables, sí se dejan.
                $equipos = $objetos->reject(fn ($o) => self::esCable($o));

                return $equipos->isNotEmpty() ? $equipos->values() : $objetos;
            })
            ->all();
    }

    /**
     * Un cable, un cordón, unos caimanes, conectores o un cargador: lo que va
     * con un equipo. También la bolsa o la caja donde se guardan.
     */
    public static function esCable(array $objeto): bool
    {
        $accesorio = 'cables?|cordon(es)?|caiman(es)?|conectores?|terminales?|bornes?|bananas?|plugs?|puntas?( de prueba)?';

        // También las partes sueltas de una máquina: la cubierta roja de una
        // Elegoo, quitada y puesta al lado, salía como otra máquina.
        $parte = 'tapas?|tapaderas?|cubiertas?|campanas?|carcasas?|bandejas?|plataformas?';

        return (bool) preg_match(
            '/^((' . $accesorio . ')|pinzas?( de)? caiman(es)?|extension(es)?|adaptador(es)?( de corriente)?|cargador(es)?|enchufes?|clavijas?|multitomas?'
            . '|transformador(es)?|' . $parte
            . '|(bolsas?|cajas?|organizador(es)?)( \w+)? de (' . $accesorio . '))\b/',
            self::comparable((string) ($objeto['nombre'] ?? '')),
        );
    }

    /** Si la detección de la foto entera parece haberse perdido. */
    private function convienePorPartes(array $objetos): bool
    {
        $repetido = collect($objetos)->countBy(fn ($o) => self::comparable((string) $o['nombre']))->max() ?? 0;

        return count($objetos) >= self::OBJETOS_PARA_MIRAR_POR_PARTES || $repetido >= 3;
    }

    /** Pocos objetos (3 o menos) que entre todos cubren menos de un tercio de la foto. */
    private function vioPoco(array $objetos): bool
    {
        $cubierto = collect($objetos)->sum(fn ($o) => self::area(array_map('intval', array_values($o['caja']))));

        return count($objetos) <= 3 && $cubierto < 350000;
    }

    /** Desde cuántos objetos en una foto conviene mirarla por mitades. */
    private const OBJETOS_PARA_MIRAR_POR_PARTES = 8;

    /**
     * Detecta mirando la foto por mitades, con traslape, y junta lo que se
     * vio dos veces en la franja común.
     *
     * Mitades de izquierda a derecha si la foto es ancha; de arriba abajo si
     * es alta. Las cajas vuelven a las coordenadas de la foto entera.
     *
     * @return list<array<string,mixed>>|null
     */
    private function detectarPorPartes(string $fuente): ?array
    {
        $imagen = @imagecreatefromjpeg($fuente);

        if (! $imagen) {
            return null;
        }

        [$ancho, $alto] = [imagesx($imagen), imagesy($imagen)];
        $vertical = $alto > $ancho * 1.15;
        $todos = [];

        foreach ([[0.0, 0.6], [0.4, 1.0]] as [$desde, $hasta]) {
            $parte = $vertical
                ? imagecrop($imagen, ['x' => 0, 'y' => (int) ($desde * $alto), 'width' => $ancho, 'height' => (int) (($hasta - $desde) * $alto)])
                : imagecrop($imagen, ['x' => (int) ($desde * $ancho), 'y' => 0, 'width' => (int) (($hasta - $desde) * $ancho), 'height' => $alto]);

            if (! $parte) {
                continue;
            }

            $lado = max(imagesx($parte), imagesy($parte));
            if ($lado > FotoParaLaIa::LADO_MAXIMO) {
                $parte = imagescale($parte, (int) round(imagesx($parte) * FotoParaLaIa::LADO_MAXIMO / $lado));
            }

            $temporal = tempnam(sys_get_temp_dir(), 'parte') . '.jpg';
            imagejpeg($parte, $temporal, 88);

            /*
             * Si una mitad no se pudo leer, no sirve lo que dio la otra: en la
             * repisa de pegantes el servidor falló en una mitad y la otra, con
             * 2 objetos, reemplazó la lectura entera, que tenía 10. Mejor la
             * foto entera que media foto.
             */
            try {
                $objetos = $this->depurarObjetos($this->servidor->detectarObjetos($temporal, $this->categorias()));
            } catch (\Throwable $e) {
                Log::warning('Activo IA: una parte de la foto no se pudo leer; se usa la foto entera', ['error' => $e->getMessage()]);

                return null;
            } finally {
                @unlink($temporal);
            }

            // De la parte a la foto entera.
            foreach ($objetos as $o) {
                [$x1, $y1, $x2, $y2] = array_map('intval', array_values($o['caja']));
                $o['caja'] = $vertical
                    ? [$x1, (int) round($desde * 1000 + $y1 * ($hasta - $desde)), $x2, (int) round($desde * 1000 + $y2 * ($hasta - $desde))]
                    : [(int) round($desde * 1000 + $x1 * ($hasta - $desde)), $y1, (int) round($desde * 1000 + $x2 * ($hasta - $desde)), $y2];
                $o['mitad'] = $desde;
                $todos[] = $o;
            }
        }

        if ($todos === []) {
            return null;
        }

        // Los que se vieron en las dos mitades: se queda el que más dice.
        $informa = fn ($o) => match ($o['visibilidad'] ?? 'parcial') { 'completo' => 2, 'parcial' => 1, default => 0 }
            + (filled($o['marca'] ?? null) ? 1 : 0)
            + (str_contains(self::comparable((string) $o['nombre']), 'sin nombre') ? -1 : 0)
            + self::area($o['caja']) / 100000;

        $quedan = [];

        foreach (collect($todos)->sortByDesc($informa) as $o) {
            // El que cae en el borde entre las dos mitades sale cortado en cada
            // una y sus cajas apenas se pisan: si además se llaman igual, es él.
            // Y cada mitad le puede poner otro nombre —«Pegamento Bon para
            // papel» y «pegamento blanco»— aunque lea la misma etiqueta. Eso
            // solo vale entre mitades: en una misma, dos iguales son dos.
            //
            // Solo entre mitades distintas: cuatro fuentes iguales apiladas se
            // tocan y se llaman igual, y se fundían en una sola.
            $repetido = collect($quedan)->contains(fn ($q) => self::seSuperponen($q['caja'], $o['caja'])
                || ($q['mitad'] !== $o['mitad'] && self::seTocan($q['caja'], $o['caja']) && (
                    self::comparable((string) $q['nombre']) === self::comparable((string) $o['nombre'])
                    || self::etiquetasCoinciden($q, $o) !== null
                )));

            if (! $repetido) {
                $quedan[] = $o;
            }
        }

        // En el orden en que se ven, de izquierda a derecha (o de arriba abajo).
        usort($quedan, fn ($a, $b) => $vertical ? $a['caja'][1] <=> $b['caja'][1] : $a['caja'][0] <=> $b['caja'][0]);

        return array_map(fn ($o) => collect($o)->except('mitad')->all(), $quedan);
    }

    private static function area(array $c): int
    {
        return max(0, $c[2] - $c[0]) * max(0, $c[3] - $c[1]);
    }

    private static function seTocan(array $a, array $b): bool
    {
        return min($a[2], $b[2]) > max($a[0], $b[0]) && min($a[3], $b[3]) > max($a[1], $b[1]);
    }

    /** Qué parte de la caja A queda dentro de la caja B (0 a 1). */
    private static function dentroDe(array $a, array $b): float
    {
        [$a, $b] = [array_map('intval', array_values($a)), array_map('intval', array_values($b))];
        $interseccion = max(0, min($a[2], $b[2]) - max($a[0], $b[0])) * max(0, min($a[3], $b[3]) - max($a[1], $b[1]));

        return $interseccion / max(1, self::area($a));
    }

    /** Dos cajas del mismo objeto: se pisan mucho, o una queda casi entera dentro de la otra. */
    private static function seSuperponen(array $a, array $b): bool
    {
        $interseccion = max(0, min($a[2], $b[2]) - max($a[0], $b[0])) * max(0, min($a[3], $b[3]) - max($a[1], $b[1]));
        $union = self::area($a) + self::area($b) - $interseccion;

        return $union > 0 && ($interseccion / $union >= 0.4 || $interseccion / max(1, min(self::area($a), self::area($b))) >= 0.7);
    }

    /**
     * Si es algo que se registra en un inventario.
     *
     * El modelo marcaba como objeto una mancha de resina derramada en la
     * repisa —y la emparejaba con «Resina estándar»—. Se le pide que no lo
     * haga, pero no basta con pedirlo: lo que él mismo dice que no se
     * inventaría, lo que se llama mancha, derrame o residuo, y lo que ocupa
     * una porción ínfima de la foto, se descarta aquí.
     */
    public static function seInventaria(array $objeto): bool
    {
        if (($objeto['es_inventariable'] ?? true) === false) {
            return false;
        }

        $nombre = self::comparable((string) ($objeto['nombre'] ?? ''));

        if (preg_match('/\b(mancha|manchas|derrame|derramad[oa]s?|residuos?|gotas?|goteo|suciedad|sucio|polvo|rayon(es)?|rayado|reflejos?|sombras?|basura|desperdicio|charco|salpicadura|huella|marca de)\b/', $nombre)) {
            return false;
        }

        // Lo del puesto, no del inventario: la silla frente a la CNC, la
        // toalla o el trapo encima de la consola.
        if (preg_match('/^(sillas?|sillon(es)?|taburetes?|bancos? para sentarse|toallas?|trapos?|panos?|franelas?|cojin(es)?)\b/', $nombre)) {
            return false;
        }

        // Menos del 0,4 % de la foto: un punto, no un objeto que se pueda guardar.
        [$x1, $y1, $x2, $y2] = array_map('intval', array_values($objeto['caja']));

        return ($x2 - $x1) * ($y2 - $y1) >= 4000;
    }

    /** Una caja que se puede recortar: cuatro números de 0 a 1000, con área. */
    private static function cajaValida(mixed $caja): bool
    {
        if (! is_array($caja) || count($caja) !== 4) {
            return false;
        }

        [$x1, $y1, $x2, $y2] = array_map('intval', array_values($caja));

        return $x2 - $x1 >= 20 && $y2 - $y1 >= 20 && min($x1, $y1) >= 0 && max($x2, $y2) <= 1000;
    }

    /**
     * Recorta un objeto de la foto, con un margen para no cortarle los bordes.
     *
     * @param  array<int>  $caja  [x1, y1, x2, y2] de 0 a 1000
     * @return array{ruta:string,miniatura:string}|null
     */
    private function recortar(string $ruta, array $caja): ?array
    {
        $imagen = @imagecreatefromjpeg($ruta);

        if (! $imagen) {
            return null;
        }

        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        [$x1, $y1, $x2, $y2] = array_map('intval', array_values($caja));

        /*
         * Con margen generoso: el modelo acierta dónde está el objeto, pero no
         * sus bordes, y suele quedarse corto de lado. Una botella recortada
         * justo por su caja salía sin un costado y sin la base: solo la
         * etiqueta. Se agrega un tercio del ancho de la caja a cada lado y un
         * décimo de su alto arriba y abajo, para que salga el objeto entero.
         */
        $bx = ($x2 - $x1) / 1000;
        $by = ($y2 - $y1) / 1000;
        $mx = max(0.03, $bx / 3);
        $my = max(0.03, $by / 10);

        $x = max(0, (int) round(($x1 / 1000 - $mx) * $ancho));
        $y = max(0, (int) round(($y1 / 1000 - $my) * $alto));
        $w = min($ancho - $x, (int) round(($x2 / 1000 + $mx) * $ancho) - $x);
        $h = min($alto - $y, (int) round(($y2 / 1000 + $my) * $alto) - $y);

        $recorte = imagecrop($imagen, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);

        if (! $recorte) {
            return null;
        }

        ob_start();
        imagejpeg($recorte, null, 88);
        $jpeg = (string) ob_get_clean();

        $destino = 'activo-ia/' . now()->format('Y-m') . '/' . Str::uuid() . '.jpg';
        Storage::disk('local')->put($destino, $jpeg);

        // Alta: en la tarjeta se ve el objeto entero y se puede agrandar.
        $miniatura = imagescale($recorte, max(1, (int) round(480 * imagesx($recorte) / max(1, imagesy($recorte)))), 480);
        ob_start();
        imagejpeg($miniatura ?: $recorte, null, 70);

        return ['ruta' => $destino, 'miniatura' => FotoParaLaIa::guardarMiniatura($destino, (string) ob_get_clean())];
    }

    /** Lo que vio el modelo de visión, con la forma de la propuesta de siempre. */
    private function comoPropuesta(array $o): array
    {
        return $this->limpiarPropuesta([
            'nombre' => $o['nombre'] ?? null,
            'tipo' => $o['tipo'] ?? null,
            'categoria' => $o['categoria'] ?? null,
            'descripcion' => $o['variante'] ?? null,
            'marca' => $o['marca'] ?? null,
            'modelo' => $o['modelo'] ?? null,
            'texto_visible' => $o['texto_visible'] ?? null,
            'confianza' => $o['confianza'] ?? null,
        ]) + ['variante' => $o['variante'] ?? null];
    }

    /**
     * Arma la tarjeta: busca por foto, por nombre si hace falta, y guarda la
     * huella esperando la decisión.
     *
     * @param  array{huella:list<float>,modelo_huella:?string,segundos:?float}  $analisis
     * @param  array<string,mixed>|null  $propuesta
     * @param  array<string,mixed>  $objeto  posición, miniatura, índice… si hay varios
     * @return array<string,mixed>
     */
    private function tarjeta(string $rutaFoto, array $analisis, ?array $propuesta, array $objeto = [], bool $sinReferencia = false): array
    {
        // De un objeto casi tapado la huella es la de su tapa: no sirve para comparar.
        $porFoto = $sinReferencia ? [] : $this->porFoto($analisis['huella']);

        /*
         * El color manda sobre la foto.
         *
         * Las resinas Creality vienen todas en la misma botella de aluminio:
         * la huella ve la botella, no la etiqueta, y un bote azul salía como
         * «es el mismo» que la foto guardada del amarillo. Si lo que se lee
         * dice un color y el registro otro, no es el mismo, por mucho que se
         * parezca la foto.
         */
        $suColor = implode(' ', [$propuesta['variante'] ?? '', $propuesta['nombre'] ?? '']);
        //
        // Y el porcentaje lo dice: baja, no solo se manda al final; un 99 %
        // de otro color quedaba debajo de un 90 %, y eso no se entiende.
        $porFoto = collect($porFoto)
            ->map(fn ($c) => self::colorDistinto($suColor, $c['nombre'])
                ? ['razones' => array_merge($c['razones'] ?? [], ['otro color']), 'otro_color' => true, 'similitud' => $c['similitud'] - self::PESO_COLOR] + $c
                : $c)
            ->values()
            ->all();

        $porFoto = $sinReferencia ? $porFoto : $this->conLaEtiqueta($porFoto, $propuesta);
        $porFoto = collect($porFoto)->sortByDesc('similitud')->values()->all();

        $mismo = $porFoto !== [] && empty($porFoto[0]['otro_color'])
            && $porFoto[0]['similitud'] >= (float) config('fabos.servidor_fablab.umbral_mismo');

        // Si la foto ya lo reconoce, no hace falta adivinar por el nombre.
        $porTexto = [];
        $avisoTexto = null;
        $lugar = null;
        $this->vecinos = [];

        // Casi tapado (solo la tapa): no se adivina cuál es, ni por nombre ni por foto.
        if (! $mismo && ! $sinReferencia && $propuesta && blank($propuesta['error'] ?? null)) {
            try {
                $porTexto = $this->porTexto($propuesta, collect($porFoto)->pluck('clave')->all());
                $lugar = $this->lugarSugerido();
            } catch (\Throwable $e) {
                Log::warning('Activo IA: no se pudo comparar por nombre', ['error' => $e->getMessage()]);
                $avisoTexto = 'No se pudo comparar con los nombres del inventario ahora.';
            }
        }

        /*
         * El más probable se elige entre los dos lados. Un registro cuya foto
         * se parece un 80 % no puede quedar debajo de uno que solo se parece
         * un 65 % por el nombre: el aceite Binner estaba inventariado con
         * otro nombre, y la foto sí lo reconocía.
         */
        $deFoto = $porFoto[0] ?? null;
        $deTexto = $porTexto[0] ?? null;

        if (! $mismo && $deFoto && empty($deFoto['otro_color']) && ($deFoto['etiqueta_de_acuerdo'] ?? $deFoto['similitud'] >= self::FOTO_CASI_IGUAL) && array_intersect(['la etiqueta no coincide', 'otro modelo'], $deFoto['razones'] ?? []) === []
            && $deFoto['similitud'] >= (float) config('fabos.servidor_fablab.umbral_posible')
            && (! ($deTexto['probable'] ?? false) || $deFoto['similitud'] > $deTexto['similitud'] || $deFoto['clave'] === $deTexto['clave'])) {
            $porFoto[0]['probable'] = true;

            if ($deTexto && $deTexto['clave'] !== $deFoto['clave']) {
                $porTexto[0]['probable'] = false;
            }
        }

        [$porFoto, $porTexto] = self::unSoloMasProbable($porFoto, $porTexto);

        $clave = 'r' . Str::lower(Str::random(10));

        Cache::put($this->claveDeHuella($clave), [
            'ruta' => $rutaFoto,
            'huella' => $analisis['huella'],
            'modelo_huella' => $analisis['modelo_huella'],
            // Y su recorte no se guarda como ejemplo, aunque se confirme cuál es.
            'sin_referencia' => $sinReferencia,
        ], now()->addHours(self::HORAS_DE_ESPERA));

        $tarjeta = [
            'clave' => $clave,
            'clase' => 'reconocimiento',
            'propuesta' => $propuesta,
            'segundos' => $analisis['segundos'],
            'por_foto' => $porFoto,
            'mismo' => $mismo,
            'por_texto' => $porTexto,
            'aviso_texto' => $avisoTexto,
            'lugar' => $lugar,
            'objeto' => array_filter([
                'indice' => $objeto['indice'] ?? null,
                'total' => $objeto['total'] ?? null,
                'posicion' => $objeto['posicion'] ?? null,
                'tapado' => ($objeto['tapado'] ?? false) ?: null,
                'caja' => $objeto['caja'] ?? null,
                'visibilidad' => $objeto['visibilidad'] ?? null,
                'miniatura' => $objeto['miniatura'] ?? null,
                'cortado' => isset($objeto['se_ve_completo']) ? ! $objeto['se_ve_completo'] : null,
                // Lo que se juntó con la máquina: su consola, su mesa, su torre de luces.
                'incluye' => array_values(array_unique($objeto['incluye'] ?? [])) ?: null,
            ], fn ($v) => $v !== null),
        ];

        $tarjeta['resumen'] = $sinReferencia
            ? 'Solo se ve una parte, como la tapa: no adivino cuál es. Si te importa, búscalo abajo o tómale una foto aparte.'
            : $this->resumen($tarjeta);

        return $tarjeta;
    }

    /** Lo que se le dice a la persona, en una o dos frases. */
    public function resumen(array $r): string
    {
        $p = $r['propuesta'];

        if ($r['mismo']) {
            $c = $r['por_foto'][0];

            return 'Lo reconozco: es «' . $c['nombre'] . '» (' . $this->porcentaje($c['similitud']) . ' de parecido con su foto). Confírmalo y guardo esta foto como otro ángulo.';
        }

        $que = $p && filled($p['nombre'] ?? null)
            ? 'Parece «' . $p['nombre'] . '»' . ($p['tipo'] ? ' (' . $p['tipo'] . ')' : '') . '.'
            : 'No pude decir qué es.';

        if (($r['por_foto'][0]['probable'] ?? false)) {
            $c = $r['por_foto'][0];

            return $que . ' Creo que es «' . $c['nombre'] . '», que ya está en el inventario (' . $this->porcentaje($c['similitud']) . ' de parecido con su foto). Si es ese, pulsa «Es este» y guardo la foto para reconocerlo la próxima vez.';
        }

        $probable = $r['por_texto'][0] ?? null;

        if ($probable && ($probable['probable'] ?? false)) {
            return $que . ' Creo que es «' . $probable['nombre'] . '», que ya está en el inventario (' . implode(', ', $probable['razones']) . '). Si es ese, pulsa «Es este» y guardo la foto para reconocerlo la próxima vez.';
        }

        $familia = collect($r['por_texto'])->where('familia', true);

        if ($familia->count() >= 2) {
            return $que . ' Es uno de estos ' . $familia->count() . ' que ya están en el inventario («' . $familia->first()['nombre'] . '» y los demás), pero por el nombre no se distinguen'
                . ' y su foto no lo reconoció. Elige cuál es por el número o la placa que tiene pegada: guardo su foto y la próxima vez lo reconozco solo.';
        }

        $opciones = count($r['por_foto']) + count($r['por_texto']);

        return $que . ' ' . ($opciones > 0
            ? 'Mira si es alguno de los que ya están en el inventario; si no, márcalo como nuevo.'
            : 'No encontré nada parecido en el inventario: puedes registrarlo como nuevo.');
    }

    /** Desde aquí, la mitad de los objetos en común: es la misma escena. */
    private const COMPARTIDOS_PARA_MISMA_ESCENA = 0.5;

    /** Dos recortes sin producto reconocido son el mismo objeto desde aquí. */
    private const RECORTES_DEL_MISMO_OBJETO = 0.9;

    /** Dos fotos enteras así de parecidas son la misma escena. */
    private const FOTOS_DE_LA_MISMA_ESCENA = 0.92;

    /**
     * Varias fotos en un mensaje: ¿la misma repisa desde otro ángulo, u otra?
     *
     * Comparar las fotos enteras no sirve: dos repisas distintas de resinas se
     * parecen entre sí tanto como dos ángulos de la misma (se midió: 0,80 a
     * 0,86 en las dos situaciones). Lo que distingue es qué objetos comparten.
     * Se cruzan los objetos de cada foto con los de las anteriores; si la mitad
     * o más son los mismos, es la misma escena desde otro ángulo, y los que se
     * repiten no se cuentan dos veces: quedan colgados del objeto de la foto
     * anterior como un ángulo más de él.
     *
     * Dos objetos son el mismo si se reconocieron como el mismo registro, o si
     * sus recortes se parecen mucho y no dicen colores distintos.
     *
     * @param  list<list<array<string,mixed>>>  $porFoto  las tarjetas de cada foto, en orden
     * @return array{tarjetas:list<list<array<string,mixed>>>,relaciones:list<array{foto:int,con:?int,misma:bool,comunes:int}>}
     */
    public function relacionarFotos(array $porFoto, array $huellasEnteras = []): array
    {
        $relaciones = [];
        $escena = [0 => 0]; // de qué repisa es cada foto: la primera foto en que se vio

        // Solo cuentan los que se pueden identificar: las tapas que asoman al
        // fondo no son ni iguales ni distintas, y diluían la cuenta.
        $identificables = fn (array $ts) => count(array_filter($ts, fn ($t) => ! ($t['objeto']['tapado'] ?? false)));

        foreach ($porFoto as $k => $tarjetas) {
            if ($k === 0 || $tarjetas === []) {
                continue;
            }

            // Con cuál de las fotos anteriores comparte más.
            $mejor = null;

            foreach (range(0, $k - 1) as $j) {
                $pares = $this->emparejar($porFoto[$j], $tarjetas, $porFoto);
                $proporcion = count($pares) / max(1, min($identificables($porFoto[$j]), $identificables($tarjetas)));
                $enteras = isset($huellasEnteras[$j], $huellasEnteras[$k])
                    ? ServidorDeIaDelFablab::similitud($huellasEnteras[$j], $huellasEnteras[$k])
                    : 0.0;

                if (! $mejor || count($pares) > count($mejor['pares']) || (count($pares) === count($mejor['pares']) && $enteras > $mejor['enteras'])) {
                    $mejor = ['j' => $j, 'pares' => $pares, 'proporcion' => $proporcion, 'enteras' => $enteras];
                }
            }

            /*
             * La misma escena si comparten la mitad de lo identificable, o si
             * las fotos enteras son casi iguales. Lo segundo solo como apoyo:
             * dos vistas de la misma repisa dieron 0,965, y repisas distintas
             * de 0,69 a 0,86.
             */
            $misma = $mejor && (
                ($mejor['pares'] !== [] && $mejor['proporcion'] >= self::COMPARTIDOS_PARA_MISMA_ESCENA)
                || $mejor['enteras'] >= self::FOTOS_DE_LA_MISMA_ESCENA
            );

            /*
             * Otra parte de la misma repisa: una repisa larga en tres fotos
             * comparte solo lo de los bordes. Basta con dos objetos en común
             * si además conservan su orden de izquierda a derecha —en dos
             * repisas distintas que tengan el mismo producto, el orden sale
             * por casualidad—.
             */
            $parte = ! $misma && $mejor
                && count($mejor['pares']) >= 2
                && $mejor['proporcion'] >= self::COMPARTIDOS_PARA_OTRA_PARTE
                && $this->conservanElOrden($mejor['pares'], $porFoto[$mejor['j']], $tarjetas);

            /*
             * Varias fotos en un mismo mensaje pueden ser el mismo lugar —los
             * mismos objetos desde otro ángulo o más de cerca— o no; se tiene
             * presente, sin darlo por hecho. Un objeto en común, reconocido por
             * su etiqueta, su registro o su foto, basta para buscar lo demás en
             * esa foto también: la foto de cerca de dos osciloscopios comparte
             * uno solo con la de la repisa entera, y salía como «de otras
             * cosas». Lo que no tiene pruebas solo se marca «¿es el mismo?».
             */
            $comparte = ! $misma && ! $parte && $mejor && $mejor['pares'] !== [];

            $relaciones[] = [
                'foto' => $k + 1,
                'con' => $mejor ? $mejor['j'] + 1 : null,
                'misma' => $misma,
                'parte' => $parte,
                'comparte' => $comparte,
                'comunes' => $mejor ? count($mejor['pares']) : 0,
            ];

            if (! $misma && ! $parte && ! $comparte) {
                $escena[$k] = $k;

                continue;
            }

            $escena[$k] = $escena[$mejor['j']] ?? $mejor['j'];
            $this->colgar($porFoto, $mejor['j'], $k, $mejor['pares']);

            $porSitio = $this->porPosicion($porFoto[$mejor['j']], $porFoto[$k], $mejor['pares']);
            $this->colgar($porFoto, $mejor['j'], $k, $porSitio);
            $relaciones[count($relaciones) - 1]['comunes'] += count($porSitio);

            /*
             * Lo que no compartió con esa foto puede estar en otra de la misma
             * repisa: en tres fotos de izquierda a derecha, la tercera ve cosas
             * de la primera que la segunda no. Si no se buscan ahí, salen dos
             * veces y al confirmarlas quedan duplicados.
             */
            foreach (range(0, $k - 1) as $j) {
                if ($j === $mejor['j'] || ($escena[$j] ?? $j) !== $escena[$k]) {
                    continue;
                }

                $yaVistos = collect($porFoto[$k])->pluck('repetida_de.clave')->filter()->all();
                $libres = array_filter($porFoto[$k], fn ($t) => ! isset($t['repetida_de']));
                $deAquella = array_filter($porFoto[$j], fn ($t) => ! in_array($t['repetida_de']['clave'] ?? $t['clave'], $yaVistos, true));
                $pares = $this->emparejar($deAquella, $libres, $porFoto);

                $this->colgar($porFoto, $j, $k, $pares);
                $relaciones[count($relaciones) - 1]['comunes'] += count($pares);
            }
        }

        return ['tarjetas' => $this->marcarLosQuePuedenSerElMismo($porFoto), 'relaciones' => $relaciones];
    }

    /**
     * Lo que quedó sin pareja en una foto de la misma repisa pero se parece a
     * algo de otra de sus fotos: no se da por repetido —una botella marrón sin
     * etiqueta puede ser otra—, pero se avisa, para que con un clic se cuente
     * una sola vez y no termine registrado dos veces.
     */
    private function marcarLosQuePuedenSerElMismo(array $porFoto): array
    {
        $color = fn ($t) => implode(' ', [$t['propuesta']['variante'] ?? '', $t['propuesta']['nombre'] ?? '']);

        foreach ($porFoto as $k => $tarjetas) {
            if ($k === 0) {
                continue;
            }

            $tomados = collect($tarjetas)->pluck('repetida_de.clave')->filter()->all();

            foreach ($tarjetas as $b => $tb) {
                if (isset($tb['repetida_de']) || ($tb['objeto']['tapado'] ?? false)) {
                    continue;
                }

                $hb = $this->huellaDe($tb['clave'])['huella'] ?? [];
                $mejor = null;

                // En cualquier foto anterior del mensaje, no solo en las que ya
                // se dieron por el mismo lugar: pueden serlo. Es un aviso, no
                // se junta nada sin que la persona lo confirme.
                foreach (range(0, $k - 1) as $j) {
                    if ($hb === []) {
                        continue;
                    }

                    foreach ($porFoto[$j] as $ta) {
                        $raiz = $ta['repetida_de'] ?? ['foto' => $j + 1, 'objeto' => $ta['objeto']['indice'] ?? 1, 'clave' => $ta['clave']];
                        $ha = $this->huellaDe($ta['clave'])['huella'] ?? [];

                        if (in_array($raiz['clave'], $tomados, true) || $ha === [] || ($ta['objeto']['tapado'] ?? false)
                            || self::colorDistinto($color($ta), $color($tb))
                            || self::etiquetasSeContradicen($ta['propuesta'] ?? [], $tb['propuesta'] ?? [])) {
                            continue;
                        }

                        $s = ServidorDeIaDelFablab::similitud($ha, $hb);

                        if ($s >= self::RECORTES_EN_SU_SITIO && (! $mejor || $s > $mejor['parecido'])) {
                            $mejor = $raiz + ['parecido' => round($s, 3)];
                        }
                    }
                }

                if ($mejor) {
                    $porFoto[$k][$b]['quizas_de'] = $mejor;
                    $tomados[] = $mejor['clave'];
                }
            }
        }

        return $porFoto;
    }

    /** Qué tan lejos —en milésimas de la foto— puede caer de donde se esperaba. */
    private const CERCA_EN_LA_OTRA_FOTO = 90;

    /** Con el sitio a favor, basta este parecido entre los recortes. */
    private const RECORTES_EN_SU_SITIO = 0.8;

    /**
     * Dos fotos de la misma repisa: lo que ya se emparejó dice cómo se movió
     * la cámara —cuánto se corrió y cuánto se acercó—. Una botella sin
     * etiqueta que cae justo donde debería caer la de la otra foto, y que se
     * le parece, es la misma: la sin etiqueta de líquido marrón no se puede
     * reconocer por el texto, y de otra distancia el recorte cambia.
     *
     * @param  list<array{0:int,1:int}>  $pares  lo ya emparejado
     * @return list<array{0:int,1:int}>  los pares nuevos
     */
    private function porPosicion(array $a, array $b, array $pares): array
    {
        $centro = fn ($t) => isset($t['objeto']['caja'])
            ? [($t['objeto']['caja'][0] + $t['objeto']['caja'][2]) / 2, ($t['objeto']['caja'][1] + $t['objeto']['caja'][3]) / 2]
            : null;

        $anclas = collect($pares)
            ->map(fn ($p) => [$centro($a[$p[0]]), $centro($b[$p[1]])])
            ->filter(fn ($p) => $p[0] !== null && $p[1] !== null)
            ->values();

        if ($anclas->count() < 2) {
            return [];
        }

        // Una recta por eje: dónde cae en B lo que está en tal sitio de A.
        $recta = function (int $eje) use ($anclas): array {
            $xs = $anclas->map(fn ($p) => $p[0][$eje]);
            $ys = $anclas->map(fn ($p) => $p[1][$eje]);
            [$mx, $my] = [$xs->avg(), $ys->avg()];
            $varianza = $xs->sum(fn ($x) => ($x - $mx) ** 2);
            $escala = $varianza < 100 * $xs->count()
                ? 1.0
                : $xs->zip($ys)->sum(fn ($p) => ($p[0] - $mx) * ($p[1] - $my)) / $varianza;
            $escala = max(0.3, min(3.0, $escala));

            return [$escala, $my - $escala * $mx];
        };

        [$rx, $ry] = [$recta(0), $recta(1)];
        $donde = fn (array $c) => [$rx[0] * $c[0] + $rx[1], $ry[0] * $c[1] + $ry[1]];

        // Si lo ya emparejado no cuadra con un solo movimiento, no se adivina.
        $desfase = $anclas->max(fn ($p) => hypot($donde($p[0])[0] - $p[1][0], $donde($p[0])[1] - $p[1][1]));
        if ($desfase > 2 * self::CERCA_EN_LA_OTRA_FOTO) {
            return [];
        }

        $usadosA = array_column($pares, 0);
        $usadosB = array_column($pares, 1);
        $candidatos = [];

        foreach ($a as $i => $ta) {
            foreach ($b as $j => $tb) {
                if (in_array($i, $usadosA, true) || in_array($j, $usadosB, true) || isset($tb['repetida_de'])
                    || ($ta['objeto']['tapado'] ?? false) || ($tb['objeto']['tapado'] ?? false)) {
                    continue;
                }

                [$ca, $cb] = [$centro($ta), $centro($tb)];
                if ($ca === null || $cb === null) {
                    continue;
                }

                $esperado = $donde($ca);
                $distancia = hypot($esperado[0] - $cb[0], $esperado[1] - $cb[1]);
                if ($distancia > self::CERCA_EN_LA_OTRA_FOTO) {
                    continue;
                }

                $color = fn ($t) => implode(' ', [$t['propuesta']['variante'] ?? '', $t['propuesta']['nombre'] ?? '']);
                if (self::colorDistinto($color($ta), $color($tb))
                    || self::etiquetasSeContradicen($ta['propuesta'] ?? [], $tb['propuesta'] ?? [])) {
                    continue;
                }

                $ha = $this->huellaDe($ta['clave'])['huella'] ?? [];
                $hb = $this->huellaDe($tb['clave'])['huella'] ?? [];
                $s = $ha && $hb ? ServidorDeIaDelFablab::similitud($ha, $hb) : 0.0;

                if ($s >= self::RECORTES_EN_SU_SITIO) {
                    $candidatos[] = [$i, $j, $s - $distancia / 1000];
                }
            }
        }

        usort($candidatos, fn ($x, $y) => $y[2] <=> $x[2]);
        $nuevos = [];

        foreach ($candidatos as [$i, $j]) {
            if (in_array($i, $usadosA, true) || in_array($j, $usadosB, true)) {
                continue;
            }

            $usadosA[] = $i;
            $usadosB[] = $j;
            $nuevos[] = [$i, $j];
        }

        return $nuevos;
    }

    /**
     * Los repetidos quedan como otro ángulo del objeto de la foto anterior
     * —del original, si aquel ya era a su vez un repetido de otra foto—.
     *
     * @param  list<array{0:int,1:int}>  $pares  [índice en la foto $j, índice en la foto $k]
     */
    private function colgar(array &$porFoto, int $j, int $k, array $pares): void
    {
        foreach ($pares as [$a, $b]) {
            $anterior = $porFoto[$j][$a];
            $raiz = $anterior['repetida_de'] ?? [
                'foto' => $j + 1,
                'objeto' => $anterior['objeto']['indice'] ?? 1,
                'clave' => $anterior['clave'],
            ];

            $porFoto[$k][$b]['repetida_de'] = $raiz;

            foreach ($porFoto as $fi => $ts) {
                foreach ($ts as $ti => $t) {
                    if ($t['clave'] === $raiz['clave']) {
                        $porFoto[$fi][$ti]['otros_angulos'][] = $porFoto[$k][$b]['clave'];
                    }
                }
            }
        }
    }

    /** Desde aquí, con el orden conservado, es otra parte de la misma repisa. */
    private const COMPARTIDOS_PARA_OTRA_PARTE = 0.2;

    /**
     * Si los objetos en común están en el mismo orden de izquierda a derecha
     * en las dos fotos (al menos tres de cada cuatro).
     *
     * @param  list<array{0:int,1:int}>  $pares
     */
    private function conservanElOrden(array $pares, array $a, array $b): bool
    {
        $centro = fn ($t) => isset($t['objeto']['caja']) ? ($t['objeto']['caja'][0] + $t['objeto']['caja'][2]) / 2 : null;

        $puntos = collect($pares)
            ->map(fn ($p) => [$centro($a[$p[0]]), $centro($b[$p[1]])])
            ->filter(fn ($p) => $p[0] !== null && $p[1] !== null)
            ->sortBy(fn ($p) => $p[0])
            ->pluck(1)
            ->values()
            ->all();

        if (count($puntos) < 2) {
            return false;
        }

        /*
         * Como una panorámica: lo compartido queda en el borde derecho de una
         * foto y en el izquierdo de la otra (o al revés). Sirve cuando lo que
         * comparten son productos idénticos —cinco Carpincol iguales—, donde
         * no se puede saber cuál es cuál y el orden no dice nada.
         */
        $enA = collect($pares)->map(fn ($p) => $centro($a[$p[0]]))->filter(fn ($x) => $x !== null);
        $enB = collect($pares)->map(fn ($p) => $centro($b[$p[1]]))->filter(fn ($x) => $x !== null);
        $mitad = fn ($xs, bool $derecha) => $xs->filter(fn ($x) => $derecha ? $x >= 500 : $x <= 500)->count() / max(1, $xs->count()) >= 0.75;

        if (($mitad($enA, true) && $mitad($enB, false)) || ($mitad($enA, false) && $mitad($enB, true))) {
            return true;
        }

        // La subsecuencia creciente más larga: cuántos siguen en orden.
        $largos = [];
        foreach ($puntos as $i => $x) {
            $largos[$i] = 1;
            for ($j = 0; $j < $i; $j++) {
                if ($puntos[$j] < $x) {
                    $largos[$i] = max($largos[$i], $largos[$j] + 1);
                }
            }
        }

        return max($largos) / count($puntos) >= 0.75;
    }

    /**
     * Qué objetos de una foto son los mismos que los de otra, uno con uno.
     *
     * @return list<array{0:int,1:int}> [índice en A, índice en B]
     */
    private function emparejar(array $a, array $b, array $todas = []): array
    {
        $candidatos = [];
        $color = fn ($t) => implode(' ', [$t['propuesta']['variante'] ?? '', $t['propuesta']['nombre'] ?? '']);

        // El original del que ya colgaba: un blanco no es otro ángulo del azul
        // aunque se parezca a uno sin color que se había tomado por el azul.
        $original = fn (array $t) => isset($t['repetida_de'])
            ? collect($todas)->flatten(1)->firstWhere('clave', $t['repetida_de']['clave'])
            : null;

        foreach ($a as $i => $ta) {
            $raiz = $original($ta);

            foreach ($b as $j => $tb) {
                if ($raiz && (self::colorDistinto($color($raiz), $color($tb))
                    || self::etiquetasSeContradicen($raiz['propuesta'] ?? [], $tb['propuesta'] ?? []))) {
                    continue;
                }

                $parecido = $this->mismoObjeto($ta, $tb);

                if ($parecido !== null) {
                    $candidatos[] = [$i, $j, $parecido];
                }
            }
        }

        // Los más seguros primero; cada objeto se empareja una sola vez.
        usort($candidatos, fn ($x, $y) => $y[2] <=> $x[2]);
        $usadosA = [];
        $usadosB = [];
        $pares = [];

        foreach ($candidatos as [$i, $j]) {
            if (isset($usadosA[$i]) || isset($usadosB[$j])) {
                continue;
            }

            $usadosA[$i] = $usadosB[$j] = true;
            $pares[] = [$i, $j];
        }

        return $pares;
    }

    /** Qué tan seguro es que dos tarjetas sean el mismo objeto físico, o null si no lo son. */
    private function mismoObjeto(array $a, array $b): ?float
    {
        // De lo que solo asoma la tapa no se dice que sea el mismo que nada.
        if (($a['objeto']['tapado'] ?? false) || ($b['objeto']['tapado'] ?? false)) {
            return null;
        }

        $color = fn ($t) => implode(' ', [$t['propuesta']['variante'] ?? '', $t['propuesta']['nombre'] ?? '']);

        if (self::colorDistinto($color($a), $color($b))) {
            return null;
        }

        // Si las etiquetas dicen productos distintos, no son el mismo por mucho
        // que se parezcan los envases: un Carpincol y un PL285 en la misma
        // botella blanca de Afix salían emparejados por la foto.
        if (self::etiquetasSeContradicen($a['propuesta'] ?? [], $b['propuesta'] ?? [])) {
            return null;
        }

        // Lo reconocido por foto, o el «más probable» de cualquiera de las dos listas.
        $registro = fn ($t) => match (true) {
            (bool) $t['mismo'] => $t['por_foto'][0]['clave'] ?? null,
            (bool) ($t['por_foto'][0]['probable'] ?? false) => $t['por_foto'][0]['clave'],
            (bool) ($t['por_texto'][0]['probable'] ?? false) => $t['por_texto'][0]['clave'],
            default => null,
        };

        [$ra, $rb] = [$registro($a), $registro($b)];

        // Que los dos se parezcan al mismo registro no basta si sus etiquetas
        // dicen cosas distintas: un Carpincol y un PL285 salían «el mismo».
        if ($ra && $rb) {
            if ($ra !== $rb) {
                return null;
            }

            // Reconocidos los dos por la foto, es seguro. Si alguno solo se
            // adivinó por el texto —«Afix PLE ANTE PARA T»—, vale menos que
            // una etiqueta que de verdad coincide, y no le quita el par a esa.
            return $a['mismo'] && $b['mismo'] ? 2.0 : 1.2;
        }

        /*
         * Lo que dice la etiqueta. En una repisa de cosas que no están en el
         * inventario no hay registro con qué emparejar, y la foto tomada de más
         * cerca hace que el mismo bote se vea a otra escala: el recorte ya no
         * se parece lo bastante. Pero «B-7000 GSE» o «Novarchem» se leen igual
         * en las dos fotos.
         */
        $porEtiqueta = self::etiquetasCoinciden($a['propuesta'] ?? [], $b['propuesta'] ?? []);

        if ($porEtiqueta !== null) {
            return 1.0 + $porEtiqueta;
        }

        $ha = $this->huellaDe($a['clave'])['huella'] ?? [];
        $hb = $this->huellaDe($b['clave'])['huella'] ?? [];

        if ($ha === [] || $hb === [] || ($this->huellaDe($a['clave'])['sin_referencia'] ?? false)) {
            return null;
        }

        $s = ServidorDeIaDelFablab::similitud($ha, $hb);

        return $s >= self::RECORTES_DEL_MISMO_OBJETO ? $s : null;
    }

    /** Palabras que describen pero no distinguen un producto de otro. */
    private const NO_DISTINGUEN = ['pegamento', 'pegante', 'bote', 'botella', 'botellas', 'tubo', 'adhesivo', 'adhesiva',
        'resina', 'liquido', 'liquida', 'sin', 'etiqueta', 'clara', 'claro', 'marca', 'oculta', 'oculto', 'parcialmente',
        'visible', 'tapado', 'tapada', 'vacia', 'vacio', 'codigo', 'barras', 'contenido', 'desconocido', 'producto',
        'pintura', 'aerosol', 'frasco', 'envase', 'pequeno', 'pequena', 'grande', 'trasera', 'trasero', 'izquierda', 'derecha',
        // La línea del producto: la misma marca la pone en todos sus botes.
        'alta', 'exigencia', 'profesional', 'professional', 'secado', 'rapido', 'ultra', 'extra', 'fuerte', 'calzado',
        'industrial', 'multiusos', 'uso', 'general', 'contacto', 'instantaneo', 'formula', 'nueva', 'nuevo',
        // Para qué sirve, no qué es: un pegante y un aceite «para madera» no son lo mismo.
        'para', 'con', 'por', 'del', 'las', 'los', 'una', 'the', 'and', 'for', 'with', 'madera', 'maderas', 'papel', 'plastico',
        'vidrio', 'metal', 'agua', 'todo', 'tipo'];

    /**
     * Si dos etiquetas dicen lo mismo: comparten palabras que distinguen —una
     * marca poco común, un número de modelo— y no dicen cosas distintas.
     * Devuelve qué tanto coinciden (0 a 1), o null si no alcanza.
     */
    /** Las palabras de una etiqueta que distinguen un producto de otro. */
    private static function distintivas(array $p): array
    {
        return collect(self::palabras(implode(' ', array_filter([
            $p['nombre'] ?? null, $p['marca'] ?? null, $p['modelo'] ?? null, $p['texto_visible'] ?? null,
        ]))))
            ->reject(fn ($w) => in_array($w, self::NO_DISTINGUEN, true) || self::colores($w) !== [])
            ->filter(fn ($w) => strlen($w) >= 3 || preg_match('/\d/', $w))
            ->values()
            ->all();
    }

    /**
     * Si dos etiquetas dicen productos distintos: las dos tienen palabras
     * fuertes (un número de modelo, una palabra de cinco letras o más) y no
     * comparten ninguna, sin contar la marca.
     */
    public static function etiquetasSeContradicen(array $a, array $b): bool
    {
        $marcas = array_merge(self::palabras((string) ($a['marca'] ?? '')), self::palabras((string) ($b['marca'] ?? '')));
        $fuertes = fn (array $p) => array_values(array_filter(
            array_diff(self::distintivas($p), $marcas),
            fn ($w) => preg_match('/\d/', $w) || strlen($w) >= 5,
        ));

        [$fa, $fb] = [$fuertes($a), $fuertes($b)];

        if ($fa === [] || $fb === []) {
            return false;
        }

        if (array_intersect($fa, $fb) === []) {
            return true;
        }

        /*
         * Aunque compartan algo, un número de modelo que solo tiene una y un
         * nombre que solo tiene la otra son dos productos: «Afix PL285» y
         * «Afix Carpincol» comparten la marca y el «alta exigencia» del bote.
         * Solo cuenta lo del nombre: el texto de atrás trae «madera» o «precio».
         */
        $delNombre = fn (array $p) => array_values(array_diff(
            self::distintivas(['nombre' => $p['nombre'] ?? null, 'modelo' => $p['modelo'] ?? null]), $marcas,
        ));
        [$na, $nb] = [$delNombre($a), $delNombre($b)];

        $modelo = fn (array $de, array $todo) => collect(array_diff($de, $todo))
            ->contains(fn ($w) => preg_match('/\d/', $w) && preg_match('/[a-z]/', $w));
        $nombre = fn (array $de, array $todo) => collect(array_diff($de, $todo))
            ->contains(fn ($w) => ! preg_match('/\d/', $w) && strlen($w) >= 6);

        return ($modelo($nb, $fa) && $nombre($na, $fb)) || ($modelo($na, $fb) && $nombre($nb, $fa));
    }

    public static function etiquetasCoinciden(array $a, array $b): ?float
    {
        [$pa, $pb] = [self::distintivas($a), self::distintivas($b)];

        if ($pa === [] || $pb === []) {
            return null;
        }

        // Una letra de diferencia en palabras largas es la misma: «topex» y «topes».
        $esta = fn (string $w, array $en) => in_array($w, $en, true)
            || (strlen($w) >= 5 && collect($en)->contains(fn ($x) => strlen($x) >= 5 && levenshtein($w, $x) <= 1));

        $comunes = array_values(array_filter($pa, fn ($w) => $esta($w, $pb)));

        // Al menos una que de verdad distinga: con un número, o de cinco letras o más.
        $fuerte = collect($comunes)->contains(fn ($w) => preg_match('/\d/', $w) || strlen($w) >= 5);

        // Sobre la etiqueta más corta: de cerca se lee todo el texto de atrás
        // —«madera, alfombra, cuero, goma…»— y eso no la hace menos la misma.
        $parecido = count(array_unique($comunes)) / min(count(array_unique($pa)), count(array_unique($pb)));

        return $fuerte && $parecido >= 0.5 ? min(1.0, $parecido) : null;
    }

    /**
     * Una tarjeta para un artículo que el modelo no vio, en la misma foto que
     * otra. No lleva recorte ni huella propia: se marca sin referencia, así
     * que al confirmar cuál es no deja foto de ejemplo.
     */
    public function tarjetaManual(string $claveHermana): ?string
    {
        $hermana = $this->huellaDe($claveHermana);

        if (! $hermana) {
            return null;
        }

        $clave = 'm' . Str::lower(Str::random(10));

        Cache::put($this->claveDeHuella($clave), [
            'ruta' => $hermana['ruta'],
            'huella' => [],
            'modelo_huella' => null,
            'sin_referencia' => true,
        ], now()->addHours(self::HORAS_DE_ESPERA));

        return $clave;
    }

    /**
     * Lo que se guardó para esta tarjeta: la ruta y la huella.
     *
     * @return array{ruta:string,huella:list<float>,modelo_huella:?string}|null
     */
    public function huellaDe(string $clave): ?array
    {
        return Cache::get($this->claveDeHuella($clave));
    }

    /**
     * Guarda la foto como ejemplo de este registro: la próxima la reconoce.
     */
    public function guardarFoto(string $clave, Asset|Supply $registro, ?User $quien): ?FotoDeInventario
    {
        $guardada = $this->huellaDe($clave);

        // Lo que estaba casi tapado no deja foto: sería un ejemplo engañoso.
        if (! $guardada || ($guardada['sin_referencia'] ?? false)) {
            return null;
        }

        return FotoDeInventario::create([
            'registro_type' => $registro::class,
            'registro_id' => $registro->getKey(),
            'ruta' => $guardada['ruta'],
            'huella' => $guardada['huella'],
            'modelo_huella' => $guardada['modelo_huella'],
            'user_id' => $quien?->id,
        ]);
    }

    /**
     * Las fotos confirmadas más parecidas, una por registro.
     *
     * @param  list<float>  $huella
     * @return list<array<string,mixed>>
     */
    private function porFoto(array $huella): array
    {
        $umbral = (float) config('fabos.servidor_fablab.umbral_posible');
        $mejores = [];

        // Las huellas guardadas se leen una vez por análisis, no por tarjeta.
        $this->fotosEnMemoria ??= FotoDeInventario::query()->get(['id', 'registro_type', 'registro_id', 'huella']);

        foreach ($this->fotosEnMemoria as $foto) {
            $s = ServidorDeIaDelFablab::similitud($huella, $foto->huella ?? []);
            $clave = $foto->registro_type . ':' . $foto->registro_id;

            if ($s >= $umbral && $s > ($mejores[$clave]['similitud'] ?? -1)) {
                $mejores[$clave] = ['tipo' => $foto->registro_type, 'id' => $foto->registro_id, 'similitud' => $s];
            }
        }

        return $this->conFicha(collect($mejores)->sortByDesc('similitud')->take(3)->values()->all());
    }

    /**
     * La garantía de la tarjeta: a lo más un «más probable», y nunca uno con
     * menos porcentaje que otra opción de la misma tarjeta. Lo pidió dos veces
     * —un 65 % con estrella sobre un 80 %, un 57 % sobre un 99 %—: venga de
     * donde venga la estrella, si no es el de mayor porcentaje, se le quita.
     *
     * @return array{0:list<array<string,mixed>>,1:list<array<string,mixed>>}
     */
    public static function unSoloMasProbable(array $porFoto, array $porTexto): array
    {
        $mejor = (float) collect($porFoto)->merge($porTexto)->max('similitud');
        $yaHay = false;

        $poner = function (array $lista) use ($mejor, &$yaHay) {
            return array_map(function ($c) use ($mejor, &$yaHay) {
                $vale = ($c['probable'] ?? false) && ! $yaHay && (float) $c['similitud'] >= $mejor - 0.001;
                $yaHay = $yaHay || $vale;

                return ['probable' => $vale] + $c;
            }, $lista);
        };

        return [$poner($porFoto), $poner($porTexto)];
    }

    /** Desde aquí, lo que dice la etiqueta se parece al nombre de la ficha. */
    private const ETIQUETA_DE_LA_FICHA = 0.58;

    /** Desde aquí la foto es prácticamente la misma que una guardada. */
    private const FOTO_CASI_IGUAL = 0.95;

    /** Lo que se le pide al texto cuando la marca leída no está en la ficha. */
    private const ETIQUETA_SIN_SU_MARCA = 0.66;

    /** Cuánto resta, en la foto, que la ficha diga otro modelo. */
    private const OTRO_MODELO_EN_LA_FOTO = 0.15;

    /** Cuánto suma que el modelo leído esté en la ficha. */
    private const MISMO_MODELO = 0.05;

    /**
     * El parecido por foto, contrastado con la etiqueta.
     *
     * Los botes de pegante de una repisa se parecen todos entre sí (72 a 85 %
     * por foto): el B-7000, el Epasun y la laca salían «más probable: Colbon».
     * Medido con las fotos reales, en los aciertos lo que dice la etiqueta se
     * parece al nombre de la ficha un 59 % o más; en esos errores, entre 32 y
     * 49 %. A cada parecido por foto se le resta lo que le falte al texto para
     * llegar a ese punto, y suma si el modelo leído está en la ficha: el
     * Carpincol R-60 es el «MR-60», no el otro Carpincol.
     *
     * @param  list<array<string,mixed>>  $porFoto
     * @return list<array<string,mixed>>
     */
    private function conLaEtiqueta(array $porFoto, ?array $propuesta): array
    {
        $texto = collect([$propuesta['nombre'] ?? null, $propuesta['marca'] ?? null, $propuesta['modelo'] ?? null,
            $propuesta['variante'] ?? null, $propuesta['texto_visible'] ?? null])->filter()->join(' · ');

        // Si la foto es prácticamente la misma que una guardada, no hay nada
        // que dudar: no se pregunta al servidor por el texto.
        if ($porFoto === [] || trim($texto) === '' || collect($porFoto)->max('similitud') >= self::FOTO_CASI_IGUAL) {
            return $porFoto;
        }

        try {
            $this->vectoresAlDia();
            [$vector] = $this->servidor->vectores([$texto]);
        } catch (\Throwable $e) {
            Log::info('Activo IA: no se pudo contrastar la foto con la etiqueta', ['error' => $e->getMessage()]);

            return $porFoto;
        }

        $clase = fn (array $c) => $c['tipo'] === 'activo' ? Asset::class : Supply::class;
        $vectores = VectorDeInventario::query()
            ->where(fn ($q) => collect($porFoto)->each(fn ($c) => $q->orWhere(fn ($w) => $w->where('registro_type', $clase($c))->where('registro_id', $c['id']))))
            ->get()
            ->keyBy(fn ($v) => $v->registro_type . ':' . $v->registro_id);
        $textos = $this->registrosConVector();
        $pegado = fn (?string $m) => preg_replace('/[^a-z0-9]/', '', self::comparable((string) $m));

        return collect($porFoto)
            ->map(function ($c) use ($vector, $vectores, $clase, $textos, $propuesta, $pegado) {
                $v = $vectores[$clase($c) . ':' . $c['id']] ?? null;

                if (! $v) {
                    return $c;
                }

                $deLaFicha = $textos[$clase($c) . ':' . $c['id']]['texto'] ?? '';
                $parecidoDelTexto = ServidorDeIaDelFablab::similitud($vector, $v->vector ?? []);

                // Si se leyó la marca y la ficha no la tiene, se le pide más
                // al texto: un «Bon» o un «Topex» no es el «Afix Carpincol».
                $marcaAusente = filled($propuesta['marca'] ?? null) && ! self::seLee((string) $propuesta['marca'], $deLaFicha);
                $exigido = $marcaAusente ? self::ETIQUETA_SIN_SU_MARCA : self::ETIQUETA_DE_LA_FICHA;

                $otroProducto = self::modelosDistintos($propuesta, $deLaFicha) || (! $marcaAusente && filled($propuesta['marca'] ?? null) && self::otroProductoDeLaMarca($propuesta, $deLaFicha));
                $resta = max(0.0, $exigido - $parecidoDelTexto) + ($otroProducto ? self::OTRO_MODELO_EN_LA_FOTO : 0.0);
                $modelo = $pegado($propuesta['modelo'] ?? null);
                $suma = preg_match('/\d/', $modelo) && strlen($modelo) >= 3 && str_contains($pegado($deLaFicha), $modelo) ? self::MISMO_MODELO : 0.0;

                $razones = $c['razones'] ?? [];
                if ($otroProducto) {
                    $razones[] = 'otro modelo';
                } elseif ($resta >= 0.08) {
                    $razones[] = 'la etiqueta no coincide';
                }
                if ($suma > 0) {
                    $razones[] = 'mismo modelo';
                }

                return ['similitud' => min(0.99, $c['similitud'] - $resta + $suma), 'razones' => $razones, 'parecido_de_la_foto' => $c['similitud'], 'etiqueta_de_acuerdo' => $parecidoDelTexto >= $exigido && ! $otroProducto] + $c;
            })
            ->filter(fn ($c) => $c['similitud'] >= (float) config('fabos.servidor_fablab.umbral_posible'))
            ->sortByDesc('similitud')
            ->values()
            ->all();
    }

    /** Lo que suma cada pista además del parecido de significado. */
    private const PESO_MARCA = 0.35;

    private const PESO_MARCA_PARECIDA = 0.25;

    private const PESO_AREA = 0.10;

    private const PESO_COLOR = 0.20;

    private const PESO_PALABRAS = 0.15;

    /**
     * Los 3 registros que más se parecen a lo que vio, por nombre.
     *
     * El parecido de significado (bge-m3) solo no basta: «XTOOL Laser
     * Engraver» quedaba más cerca de «MotorTool» —por el «tool»— que de
     * «Máquina de Fibra xTool F1 Ultra», que era la buena. Por eso se suman
     * pistas que se comprueban letra por letra:
     *
     *  · la **marca** aparece como palabra entera en el registro («xtool» sí
     *    cuenta en «xTool F1 Ultra»; no en «MotorTool»);
     *  · es de la **misma área** que la categoría que propuso;
     *  · coinciden **palabras** con lo que se lee en la foto (un «K1», un «F1»).
     *
     * Cada sugerencia lleva sus razones, para que se vea por qué salió.
     *
     * @param  array<string,mixed>  $propuesta
     * @param  list<string>  $yaSugeridos
     * @return list<array<string,mixed>>
     */
    private function porTexto(array $propuesta, array $yaSugeridos): array
    {
        $palabrasVistas = self::palabras(collect([
            $propuesta['nombre'] ?? null, $propuesta['marca'] ?? null,
            $propuesta['modelo'] ?? null, $propuesta['texto_visible'] ?? null,
        ])->filter()->join(' '));

        if ($palabrasVistas === []) {
            return [];
        }

        $this->vectoresAlDia();

        // Sin repetir palabras: «XTOOL Laser Engraver XTOOL XTOOL» pesaba la marca tres veces.
        [$vector] = $this->servidor->vectores([implode(' ', $palabrasVistas)]);

        /*
         * Para las pistas exactas solo cuenta lo que se LEYÓ, no el nombre que
         * le puso el modelo: llamó «Resina fotosensible Polarís» a una laca en
         * aerosol, y «resina fotosensible» sumaba con las resinas de verdad.
         */
        $palabrasVistas = self::palabras(collect([
            $propuesta['marca'] ?? null, $propuesta['modelo'] ?? null, $propuesta['texto_visible'] ?? null,
        ])->filter()->join(' '));

        // Y una marca no es «PRO» ni «Plus»: con esas, «PRO Acrylic Varnish»
        // salía como «misma marca» que la «Resin Pro 2» de Anycubic.
        $marca = array_values(array_diff(self::palabras((string) ($propuesta['marca'] ?? '')), self::NO_SON_MARCA));
        $categoria = self::comparable((string) ($propuesta['categoria'] ?? ''));

        $registros = $this->registrosConVector();

        return $this->conFicha(
            $this->todosLosVectores()
                ->map(function ($v) use ($vector, $registros, $marca, $categoria, $palabrasVistas, $propuesta) {
                    $registro = $registros[$v->registro_type . ':' . $v->registro_id] ?? null;

                    if (! $registro) {
                        return null;
                    }

                    $sentido = ServidorDeIaDelFablab::similitud($vector, $v->vector ?? []);
                    $suyas = self::palabras($registro['texto']);
                    $razones = [];
                    $extra = 0.0;

                    if ($marca !== [] && array_diff($marca, $suyas) === []) {
                        $extra += self::PESO_MARCA;
                        $razones[] = 'misma marca';
                    } elseif ($marca !== [] && self::marcaParecida($marca, $suyas)) {
                        $extra += self::PESO_MARCA_PARECIDA;
                        $razones[] = 'marca parecida';
                    }

                    // La misma marca con otro modelo es otro equipo: un Rigol
                    // DS2202A salía al 99 % como el «Osciloscopio 5», que es un
                    // Rigol DS1104, solo por la marca.
                    if (self::otroModelo($propuesta['modelo'] ?? null, $registro['modelo'] ?? null)
                        || self::modelosDistintos($propuesta, $registro['texto'])) {
                        $extra -= self::PESO_MARCA;
                        $razones[] = 'otro modelo';
                    } elseif (in_array('misma marca', $razones, true) && self::otroProductoDeLaMarca($propuesta, $registro['texto'])) {
                        $extra -= self::PESO_MARCA;
                        $razones[] = 'otro producto de la marca';
                    }

                    if ($categoria !== '' && $categoria === self::comparable((string) $registro['area'])) {
                        $extra += self::PESO_AREA;
                        $razones[] = 'misma área';
                    }

                    // Palabras distintivas en común, sin contar la marca, que ya sumó.
                    $comunes = array_values(array_diff(array_intersect($palabrasVistas, $suyas), $marca));
                    if ($comunes !== []) {
                        $extra += self::PESO_PALABRAS * min(1, count($comunes) / 2);
                        $razones[] = 'dice «' . implode(' ', array_slice($comunes, 0, 3)) . '»';
                    }

                    // El color, de la variante y el nombre: no del texto entero,
                    // que en las Creality trae impresa la lista de todos los colores.
                    $color = implode(' ', [$propuesta['variante'] ?? '', $propuesta['nombre'] ?? '']);

                    if (self::colorDistinto($color, $registro['texto'])) {
                        $extra -= self::PESO_COLOR;
                        $razones[] = 'otro color';
                    } elseif (self::colores($color) !== [] && array_intersect(self::colores($color), self::colores($registro['texto'])) !== []) {
                        $extra += self::PESO_COLOR;
                        $razones[] = 'mismo color';
                    }

                    return [
                        'tipo' => $v->registro_type,
                        'id' => $v->registro_id,
                        'similitud' => $sentido + $extra,
                        'razones' => $razones,
                    ];
                })
                ->filter()
                ->reject(fn ($c) => in_array(($c['tipo'] === Asset::class ? 'activo' : 'insumo') . ':' . $c['id'], $yaSugeridos, true))
                // Un insumo se busca entre los insumos, y un equipo entre los
                // activos: una resina no es el «Soplador de aire (Resina)».
                // Solo si no hay ninguno de su clase se miran los demás.
                ->pipe(function ($todos) use ($propuesta) {
                    $clase = match ($propuesta['tipo'] ?? null) {
                        'insumo', 'componente', 'producto' => Supply::class,
                        'activo', 'herramienta' => Asset::class,
                        default => null,
                    };

                    $suyos = $clase ? $todos->where('tipo', $clase) : $todos;

                    return $suyos->isNotEmpty() ? $suyos : $todos;
                })
                ->sortByDesc('similitud')
                // Los vecinos más cercanos dicen dónde suele ir algo así.
                ->tap(function ($ordenados) {
                    $this->vecinos = $ordenados->take(10)->values()->all();
                })
                ->pipe(fn ($ordenados) => $this->conSuFamilia($ordenados, $propuesta))
                ->values()
                // El primero es «el más probable» si comparte la marca, o si
                // comparte palabras y se despega de los demás.
                ->pipe(function ($top) {
                    if ($top->isEmpty()) {
                        return $top;
                    }

                    $primero = $top->first();
                    $margen = $primero['similitud'] - (float) ($top->get(1)['similitud'] ?? 0);

                    // De una familia de equipos iguales no se adivina cuál es,
                    // salvo que uno se despegue por lo que dice su ficha.
                    if ($primero['familia'] ?? false) {
                        $loDistingue = collect($primero['razones'])->contains(fn ($r) => $r === 'misma marca' || (str_starts_with($r, 'dice') && preg_match('/\d/', $r)));
                        $primero['probable'] = $loDistingue && array_intersect(['otro modelo', 'otro producto de la marca'], $primero['razones']) === [] && $margen >= 0.1;

                        return $top->put(0, $primero);
                    }
                    $comparte = collect($primero['razones'])->contains(fn ($r) => str_starts_with($r, 'dice'));

                    // Compartir una palabra no basta: «móvil» juntaba un soporte de
                    // celular con la «Lijadora de Banda móvil». Hace falta además
                    // un parecido de verdad y despegarse de los otros.
                    $primero['probable'] = (in_array('misma marca', $primero['razones'], true) || in_array('marca parecida', $primero['razones'], true))
                        || ($comparte && $margen >= 0.08 && $primero['similitud'] >= 0.65);
                    $primero['probable'] = $primero['probable'] && array_intersect(['otro modelo', 'otro producto de la marca'], $primero['razones']) === [];

                    return $top->put(0, $primero);
                })
                // Con las pistas sumadas puede pasar de 1: se escala respecto
                // al mejor, para que tres «99 %» no parezcan empatados.
                ->pipe(function ($top) {
                    $mejor = (float) $top->max('similitud');

                    return $mejor > 0.99
                        ? $top->map(fn ($c) => ['similitud' => $c['similitud'] * 0.99 / $mejor] + $c)
                        : $top;
                })
                ->all()
        );
    }

    /** Hasta cuántos de una misma familia se muestran en una tarjeta. */
    private const MAX_DE_LA_FAMILIA = 12;

    /** @var array<string,array<int,string>> los nombres de cada clase, para no pedirlos en cada tarjeta */
    private array $nombres = [];

    /**
     * Los tres más parecidos; pero si son de una familia numerada —«Fuente
     * voltaje 1» a «Fuente voltaje 6»—, la familia entera, en orden.
     *
     * Equipos iguales, sin placa ni foto en el inventario, no se distinguen
     * por el nombre: salían tres al azar de las seis fuentes, y a veces no la
     * que era. Así se ven todas y se elige por el número que tiene pegado.
     * Si el equipo tiene dos salidas, primero las «dual».
     */
    private function conSuFamilia(\Illuminate\Support\Collection $ordenados, array $propuesta): \Illuminate\Support\Collection
    {
        $top = $ordenados->take(3);

        // Si el mejor ya dice el modelo que se leyó —su ficha tiene «DS2202A»—,
        // no hace falta la familia: ese es el que se distingue.
        $dicePalabraConNumero = fn ($c) => collect($c['razones'] ?? [])->contains(fn ($r) => str_starts_with($r, 'dice') && preg_match('/\d/', $r));
        if ($top->isNotEmpty() && $dicePalabraConNumero($top->first()) && ! $dicePalabraConNumero($top->get(1) ?? [])) {
            return $top;
        }

        $nombre = function (array $c): string {
            $this->nombres[$c['tipo']] ??= $c['tipo']::query()->pluck('name', 'id')->all();

            return (string) ($this->nombres[$c['tipo']][$c['id']] ?? '');
        };
        $familia = fn (array $c) => preg_match('/^(.*\D)\s*\d+$/u', trim($nombre($c)), $m) ? $c['tipo'] . '|' . self::comparable(trim($m[1])) : null;

        $deSuFamilia = fn (string $f) => $ordenados->filter(fn ($c) => $familia($c) === $f);
        $familias = $top->map($familia)->filter()->unique()
            ->filter(fn ($f) => $deSuFamilia($f)->count() >= 2)
            ->values();

        if ($familias->isEmpty()) {
            return $top;
        }

        // Dos salidas ajustables: «dual», «master/slave», «CH1 CH2», SPD3303.
        $leido = self::comparable(implode(' ', array_filter([$propuesta['nombre'] ?? null, $propuesta['modelo'] ?? null, $propuesta['texto_visible'] ?? null])));
        $dosSalidas = (bool) preg_match('/\b(dual|doble|dos canales|2 canales|master|slave|ch2|spd3303)/u', $leido);

        // Las familias de una misma cosa —«Fuente voltaje» y «Fuente voltaje
        // dual»— van juntas, por si la que sale primero no es.
        $hermanas = $ordenados->map($familia)->filter()->unique()
            ->filter(fn ($f) => $familias->contains(fn ($g) => $f !== $g
                && (str_starts_with(explode('|', $f)[1], explode('|', $g)[1]) || str_starts_with(explode('|', $g)[1], explode('|', $f)[1]))));

        // Todos de mayor a menor parecido: un 99 % nunca debajo de un 57 %. Las
        // «dual», arriba o abajo según tenga dos salidas, por un empujón chico.
        $empujon = fn (string $f) => str_contains($f, 'dual') ? ($dosSalidas ? 0.05 : -0.05) : 0.0;

        return $familias->merge($hermanas)->unique()
            ->flatMap(fn ($f) => $deSuFamilia($f)->map(fn ($c) => ['similitud' => $c['similitud'] + $empujon($f), 'familia' => true] + $c))
            ->sortByDesc('similitud')
            ->take(self::MAX_DE_LA_FAMILIA)
            ->values();
    }

    /**
     * Dónde va algo nuevo: donde están guardados los que más se le parecen.
     *
     * Las resinas del laboratorio viven en el Armario de Resina 3D; los MDF,
     * en el Rack de Corte Láser. Quien registra una resina nueva no debería
     * tener que acordarse: se mira a sus vecinos más parecidos y se propone
     * lo que comparte la mayoría —la ubicación, el área, la categoría, la
     * unidad—, pesando más a los que más se parecen. Si no hay mayoría clara,
     * el campo queda vacío: mejor preguntar que mandarlo al estante equivocado.
     *
     * @return array{clase:string,campos:array<string,array{valor:mixed,etiqueta:string,como:list<string>,cuantos:int,de:int}>}|null
     */
    private function lugarSugerido(): ?array
    {
        $vecinos = collect($this->vecinos)->filter(fn ($v) => $v['similitud'] >= self::PARECIDO_PARA_UBICAR);

        if ($vecinos->isEmpty()) {
            return null;
        }

        $clase = $vecinos->first()['tipo'];
        $vecinos = $vecinos->where('tipo', $clase)->values();
        $ids = $vecinos->pluck('id')->all();

        $registros = $clase === Supply::class
            ? Supply::whereIn('id', $ids)->get(['id', 'name', 'location_id', 'category_id', 'area_id', 'unit'])->keyBy('id')
            : Asset::whereIn('id', $ids)->get(['id', 'name', 'location_id', 'space_id', 'area_id', 'kind'])->keyBy('id');

        $columnas = $clase === Supply::class
            ? ['ubicacion_id' => 'location_id', 'categoria_id' => 'category_id', 'area_id' => 'area_id', 'unidad' => 'unit']
            : ['espacio_id' => 'space_id', 'ubicacion_id' => 'location_id', 'area_id' => 'area_id', 'tipo_de_activo' => 'kind'];

        $etiquetas = [
            'ubicacion_id' => fn ($v) => $this->catalogo->ubicaciones()[$v] ?? null,
            'espacio_id' => fn ($v) => $this->catalogo->espacios()[$v] ?? null,
            'area_id' => fn ($v) => $this->catalogo->areas()[$v] ?? null,
            'categoria_id' => fn ($v) => $this->catalogo->categorias()[$v] ?? null,
            'unidad' => fn ($v) => $v,
            'tipo_de_activo' => fn ($v) => Asset::TIPOS[$v] ?? null,
        ];

        $campos = [];

        foreach ($columnas as $campo => $columna) {
            $votos = [];
            $descartados = [];
            $peso = 0.0;

            foreach ($vecinos as $v) {
                $valor = $registros[$v['id']]->{$columna} ?? null;

                if ($valor === null || $valor === '') {
                    continue;
                }

                // Un estante de vencidos no se propone solo para algo nuevo: se
                // puede elegir a mano, pero no es el lugar por defecto. Se aparta
                // y sirve para saber en qué mueble está (más abajo).
                if ($campo === 'ubicacion_id' && $this->catalogo->esDeDescarte((int) $valor)) {
                    $descartados[(string) $valor]['valor'] = (int) $valor;
                    $descartados[(string) $valor]['peso'] = ($descartados[(string) $valor]['peso'] ?? 0) + $v['similitud'];
                    $descartados[(string) $valor]['como'][] = $registros[$v['id']]->name;

                    continue;
                }

                $valor = is_string($valor) ? trim($valor) : $valor;
                $votos[(string) $valor]['peso'] = ($votos[(string) $valor]['peso'] ?? 0) + $v['similitud'];
                $votos[(string) $valor]['valor'] = $valor;
                $votos[(string) $valor]['como'][] = $registros[$v['id']]->name;
                $peso += $v['similitud'];
            }

            // Todos los parecidos están en el estante de vencidos (pasa con las
            // resinas): se propone el mueble que lo contiene, y la persona
            // elige el rack.
            if ($votos === [] && $campo === 'ubicacion_id' && $descartados !== []) {
                $pesoDescartados = array_sum(array_column($descartados, 'peso'));

                if ($comun = $this->muebleComun($descartados, $pesoDescartados)) {
                    $votos = [(string) $comun['valor'] => $comun];
                    $peso = $pesoDescartados;
                }
            }

            if ($votos === []) {
                continue;
            }

            $ganador = collect($votos)->sortByDesc('peso')->first();

            /*
             * Sin mayoría en el estante exacto, se busca el mueble común: cada
             * MDF está en un «Espacio» distinto del Rack de Corte Láser, pero
             * todos están en ese Rack. Se elige el más profundo que tenga
             * mayoría, para no quedarse en «Lab. Corte Láser» a secas.
             */
            if ($campo === 'ubicacion_id' && $ganador['peso'] < $peso / 2) {
                $ganador = $this->muebleComun($votos, $peso) ?? $ganador;
            }

            // Mayoría de verdad: más de la mitad del peso de los que opinan.
            if ($ganador['peso'] < $peso / 2 || ! ($etiqueta = $etiquetas[$campo]($ganador['valor']))) {
                continue;
            }

            $campos[$campo] = [
                'valor' => is_numeric($ganador['valor']) && str_ends_with($campo, '_id') ? (int) $ganador['valor'] : $ganador['valor'],
                'etiqueta' => $etiqueta,
                'como' => array_values(array_unique($ganador['como'])),
                'cuantos' => count($ganador['como']),
                'de' => $vecinos->count(),
            ];
        }

        return $campos === [] ? null : ['clase' => $clase === Supply::class ? 'insumo' : 'activo', 'campos' => $campos];
    }

    /**
     * El mueble más específico que contiene a la mayoría de los vecinos.
     *
     * @param  array<string,array{peso:float,valor:mixed,como:list<string>}>  $votos  por ubicación exacta
     * @return array{peso:float,valor:int,como:list<string>}|null
     */
    private function muebleComun(array $votos, float $peso): ?array
    {
        $porMueble = [];

        foreach ($votos as $voto) {
            foreach ($this->catalogo->ancestros((int) $voto['valor']) as $profundidad => $mueble) {
                if ($this->catalogo->esDeDescarte($mueble)) {
                    continue;
                }

                $porMueble[$mueble]['valor'] = $mueble;
                $porMueble[$mueble]['peso'] = ($porMueble[$mueble]['peso'] ?? 0) + $voto['peso'];
                $porMueble[$mueble]['como'] = array_merge($porMueble[$mueble]['como'] ?? [], $voto['como']);
                // Cuántos niveles tiene por encima: más es más específico.
                $porMueble[$mueble]['nivel'] = count($this->catalogo->ancestros($mueble));
            }
        }

        return collect($porMueble)
            ->filter(fn ($m) => $m['peso'] >= $peso / 2)
            ->sortByDesc(fn ($m) => [$m['nivel'], $m['peso']])
            ->first();
    }

    /**
     * El texto y el área de cada registro, para comprobar las pistas.
     *
     * @return array<string,array{texto:string,area:?string}>
     */
    /*
     * Lo que se lee del inventario, una sola vez por análisis. Cada tarjeta
     * cargaba de nuevo todos los activos, insumos y vectores —con tres fotos
     * llenas, cientos de lecturas completas de la base en una sola petición—.
     * El reconocedor vive lo que dura un análisis, y en ese rato el
     * inventario no cambia.
     */
    private ?array $registrosEnMemoria = null;

    private ?\Illuminate\Support\Collection $vectoresEnMemoria = null;

    private bool $vectoresPuestosAlDia = false;

    private ?\Illuminate\Support\Collection $fotosEnMemoria = null;

    private function vectoresAlDia(): void
    {
        if (! $this->vectoresPuestosAlDia) {
            $this->ponerAlDiaLosVectores();
            $this->vectoresPuestosAlDia = true;
        }
    }

    private function todosLosVectores(): \Illuminate\Support\Collection
    {
        return $this->vectoresEnMemoria ??= VectorDeInventario::query()->get(['registro_type', 'registro_id', 'vector']);
    }

    private function registrosConVector(): array
    {
        return $this->registrosEnMemoria ??= $this->leerLosRegistros();
    }

    private function leerLosRegistros(): array
    {
        $todos = [];

        foreach (Asset::query()->with('area:id,name')->get(['id', 'name', 'brand', 'model', 'serial', 'asset_tag', 'area_id']) as $a) {
            $todos[Asset::class . ':' . $a->id] = [
                'texto' => collect([$a->name, $a->brand, $a->model, $a->serial, $a->asset_tag])->filter()->join(' '),
                'area' => $a->area?->name,
                'modelo' => $a->model,
            ];
        }

        foreach (Supply::query()->with(['area:id,name', 'category:id,name'])->get(['id', 'name', 'sku', 'description', 'area_id', 'category_id']) as $s) {
            $todos[Supply::class . ':' . $s->id] = [
                'texto' => collect([$s->name, $s->sku, Str::limit((string) $s->description, 200, '')])->filter()->join(' '),
                'area' => $s->category?->name ?? $s->area?->name,
            ];
        }

        return $todos;
    }

    /**
     * La marca casi igual: una o dos letras de diferencia en una palabra larga.
     *
     * El modelo leyó «NOVASO» en una etiqueta que en el inventario está como
     * «NOVA30»: la O y el 0, la S y el 3 se confunden en una foto. En marcas
     * cortas no se perdona nada —«HP» y «LG» no son parecidas, son otras—.
     *
     * Lo que se perdona son las letras que una foto confunde; las demás
     * cuentan como diferencia de verdad: «Binner» no es «Pinter» (la palabra
     * mal escrita en «Creality 3D Pinter»), aunque solo cambien dos letras.
     */
    private static function marcaParecida(array $marca, array $suyas): bool
    {
        $confundibles = fn (string $w) => strtr($w, ['0' => 'o', '5' => 's', '3' => 's', '1' => 'l', 'i' => 'l', '8' => 'b', '2' => 'z', '6' => 'g']);

        foreach ($marca as $m) {
            if (strlen($m) < 5) {
                return false;
            }

            $encontrada = collect($suyas)->contains(fn ($s) => abs(strlen($s) - strlen($m)) <= 1
                && levenshtein($confundibles($m), $confundibles($s)) <= 1);

            if (! $encontrada) {
                return false;
            }
        }

        return true;
    }

    /** Sin tildes, en minúscula, con los espacios en su sitio. */
    public static function comparable(string $texto): string
    {
        return Str::of($texto)->ascii()->lower()->squish()->value();
    }

    /**
     * Las palabras que distinguen, sin repetir: de dos letras o más y sin
     * las que no dicen nada («de», «para», «máquina»…).
     *
     * @return list<string>
     */
    /** Palabras de etiqueta que se leen como marca y no lo son. */
    private const NO_SON_MARCA = ['pro', 'plus', 'max', 'mini', 'basic', 'standard', 'premium', 'ultra', 'lite', 'light',
        'super', 'extra', 'new', 'nuevo', 'original', 'classic', 'series', 'edition', 'professional', 'profesional', 'hd', 'xl'];

    public static function palabras(string $texto): array
    {
        static $vacias = ['de', 'del', 'la', 'el', 'los', 'las', 'y', 'con', 'para', 'por', 'en', 'un', 'una',
            'the', 'of', 'and', 'for', 'with', 'maquina', 'equipo', 'machine'];

        return collect(preg_split('/[^a-z0-9]+/', self::comparable($texto)))
            ->filter(fn ($p) => strlen($p) >= 2 && ! in_array($p, $vacias, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Calcula los vectores que faltan o que quedaron viejos.
     *
     * La primera vez son todos —unos cien, en lotes de 64—; después, solo los
     * que se crearon o se renombraron desde la última foto.
     */
    public function ponerAlDiaLosVectores(): int
    {
        $existentes = VectorDeInventario::query()->get(['id', 'registro_type', 'registro_id', 'huella_texto'])
            ->keyBy(fn ($v) => $v->registro_type . ':' . $v->registro_id);

        $pendientes = [];

        // Con el área: «Máquina de Fibra xTool F1 Ultra · Corte Láser» se
        // acerca más a «Laser Engraver» que el nombre a secas.
        foreach (Asset::query()->with('area:id,name')->get(['id', 'name', 'brand', 'model', 'area_id']) as $a) {
            $pendientes[] = [Asset::class, $a->id, collect([$a->name, $a->brand, $a->model, $a->area?->name])->filter()->join(' · ')];
        }

        foreach (Supply::query()->with(['area:id,name', 'category:id,name'])->get(['id', 'name', 'sku', 'description', 'area_id', 'category_id']) as $s) {
            $pendientes[] = [Supply::class, $s->id, collect([$s->name, $s->sku, Str::limit((string) $s->description, 200, ''), $s->category?->name ?? $s->area?->name])->filter()->join(' · ')];
        }

        $vigentes = collect($pendientes)->map(fn ($p) => $p[0] . ':' . $p[1])->all();

        // Lo que se borró del inventario deja de sugerirse.
        // (filter y no only: en una colección de Eloquent, only filtra por id.)
        $sobrantes = $existentes->filter(fn ($v, $clave) => ! in_array($clave, $vigentes, true));
        if ($sobrantes->isNotEmpty()) {
            VectorDeInventario::whereIn('id', $sobrantes->pluck('id'))->delete();
        }

        $faltan = collect($pendientes)
            ->filter(fn ($p) => ($existentes[$p[0] . ':' . $p[1]]->huella_texto ?? null) !== hash('sha256', $p[2]))
            ->values();

        foreach ($faltan->chunk(64) as $lote) {
            $lote = $lote->values();
            $vectores = $this->servidor->vectores($lote->pluck(2)->all());

            foreach ($lote as $i => [$tipo, $id, $texto]) {
                VectorDeInventario::updateOrCreate(
                    ['registro_type' => $tipo, 'registro_id' => $id],
                    ['huella_texto' => hash('sha256', $texto), 'vector' => $vectores[$i], 'modelo' => config('fabos.servidor_fablab.modelo_texto')],
                );
            }
        }

        return $faltan->count();
    }

    /**
     * @param  list<array{tipo:string,id:int,similitud:float}>  $candidatos
     * @return list<array<string,mixed>>
     */
    private function conFicha(array $candidatos): array
    {
        // Dos consultas en total, no una por sugerencia: una familia trae doce.
        $deTipo = fn (string $clase) => collect($candidatos)->where('tipo', $clase)->pluck('id')->unique()->values()->all();
        $activos = ($ids = $deTipo(Asset::class)) ? Asset::with(['area', 'location', 'space'])->whereKey($ids)->get()->keyBy('id') : collect();
        $insumos = ($ids = $deTipo(Supply::class)) ? Supply::with(['area', 'location', 'category'])->whereKey($ids)->get()->keyBy('id') : collect();

        return collect($candidatos)->map(function ($c) use ($activos, $insumos) {
            $registro = $c['tipo'] === Asset::class ? $activos->get($c['id']) : $insumos->get($c['id']);

            if (! $registro) {
                return null;
            }

            return [
                'clave' => ($registro instanceof Asset ? 'activo' : 'insumo') . ':' . $registro->id,
                'tipo' => $registro instanceof Asset ? 'activo' : 'insumo',
                'id' => $registro->id,
                'nombre' => $registro->name,
                // Para ordenar cuenta todo; para mostrar, nunca un 100 % que no es.
                'similitud' => round(min(0.99, $c['similitud']), 3),
                'razones' => $c['razones'] ?? [],
                'probable' => (bool) ($c['probable'] ?? false),
                'familia' => (bool) ($c['familia'] ?? false),
                'ficha' => $registro instanceof Asset
                    ? $this->buscador->fichaDeActivo($registro)
                    : $this->buscador->fichaDeInsumo($registro),
            ];
        })->filter()->values()->all();
    }

    /** Áreas y categorías de insumo: para que la propuesta use las nuestras. */
    private function categorias(): array
    {
        return array_values(array_unique(array_merge(
            array_values($this->catalogo->areas()),
            array_values($this->catalogo->categorias()),
        )));
    }

    /** @return array<string,mixed>|null */
    private function limpiarPropuesta(?array $p): ?array
    {
        if (! $p) {
            return null;
        }

        $texto = fn ($v) => is_scalar($v) && trim((string) $v) !== '' ? Str::limit(trim((string) $v), 500, '') : null;
        $nada = fn ($v) => $v !== null && preg_match('/^(no legible|ilegible|desconocid[oa]|n\/?a|none|null|sin marca|sin modelo)$/u', self::comparable($v)) ? null : $v;

        $limpia = [
            'nombre' => $texto($p['nombre'] ?? null),
            'tipo' => $texto($p['tipo'] ?? null),
            'categoria' => $texto($p['categoria'] ?? null),
            'descripcion' => $texto($p['descripcion'] ?? null),
            'marca' => $nada($texto($p['marca'] ?? null)),
            'modelo' => $nada($texto($p['modelo'] ?? null)),
            'texto_visible' => $texto($p['texto_visible'] ?? null),
            'confianza' => $texto($p['confianza'] ?? null),
            'error' => $texto($p['error'] ?? null),
        ];

        /*
         * La marca, solo si se lee. A un Rigol sin logo a la vista el modelo
         * lo llamó «osciloscopio Tektronix»: lo adivinó por la forma. Si hay
         * texto leído en el objeto y la marca no está en él, se quita, también
         * del nombre, para que no empuje la búsqueda hacia otra cosa.
         */
        if ($limpia['marca'] && $limpia['texto_visible'] && ! self::seLee($limpia['marca'], $limpia['texto_visible'])) {
            if ($limpia['nombre']) {
                $limpia['nombre'] = trim(preg_replace('/\s+/u', ' ', preg_replace('/\b' . preg_quote($limpia['marca'], '/') . '\b/iu', '', $limpia['nombre'])));
            }

            $limpia['marca'] = null;
        }

        return $limpia;
    }

    /**
     * Si el modelo leído y el de la ficha son de verdad distintos: los dos
     * tienen un número de modelo y no coinciden ni pegando las partes
     * («DG1022 Z» es «DG1022Z»).
     */
    public static function otroModelo(?string $leido, ?string $deLaFicha): bool
    {
        $pegado = fn (?string $m) => preg_replace('/[^a-z0-9]/', '', self::comparable((string) $m));
        [$a, $b] = [$pegado($leido), $pegado($deLaFicha)];

        if (! preg_match('/\d/', $a) || ! preg_match('/\d/', $b)) {
            return false;
        }

        return ! str_contains($a, $b) && ! str_contains($b, $a);
    }

    /**
     * Los números de modelo que hay en un texto: letras y números juntos
     * —PL285, MR-60, DS2202A, F69, B-7000—, sin las medidas (500 ml, 200 MHz,
     * 1 kg, 300 V), que no dicen qué producto es.
     *
     * @return list<string>
     */
    public static function modelosEn(?string $texto): array
    {
        $unidad = '/^\d+(ml|l|lt|g|gr|kg|mg|mhz|ghz|khz|hz|gsa|gsas|gmem|oz|mm|cm|m|v|w|a|ma|mah|pf|nf|uf|x|k|pcs|cat|cc|in|min|h|s)$/';

        return collect(preg_split('/[\s,;:\/()·]+/u', self::comparable((string) $texto)))
            ->map(fn ($w) => preg_replace('/[^a-z0-9]/', '', $w))
            ->filter(fn ($w) => strlen($w) >= 3 && preg_match('/\d/', $w) && preg_match('/[a-z]/', $w) && ! preg_match($unidad, $w))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Si lo leído y la ficha dicen modelos distintos: los dos tienen al menos
     * uno y ninguno coincide (ni pegando las partes: «R-60» es «MR-60»). Un
     * Afix PL285 no es el «Afix Carpincol MR-60», aunque compartan la marca y
     * el «alta exigencia» del bote.
     */
    public static function modelosDistintos(array $propuesta, ?string $textoDeLaFicha): bool
    {
        $leidos = self::modelosEn(implode(' ', array_filter([$propuesta['modelo'] ?? null, $propuesta['nombre'] ?? null, $propuesta['texto_visible'] ?? null])));
        $deLaFicha = self::modelosEn($textoDeLaFicha);

        if ($leidos === [] || $deLaFicha === []) {
            return false;
        }

        return ! collect($leidos)->contains(fn ($a) => collect($deLaFicha)->contains(fn ($b) => str_contains($a, $b) || str_contains($b, $a)));
    }

    /**
     * Otro producto de la misma marca: la etiqueta trae un modelo que la ficha
     * no tiene (PL285), y la ficha un nombre de producto que la etiqueta no
     * dice (Carpincol). «Afix PL285» y «Afix Carpincol pegamento para madera»
     * comparten la marca, y por eso el PL285 salía con estrella.
     */
    public static function otroProductoDeLaMarca(array $propuesta, ?string $textoDeLaFicha): bool
    {
        $leido = implode(' ', array_filter([$propuesta['nombre'] ?? null, $propuesta['modelo'] ?? null, $propuesta['texto_visible'] ?? null]));
        $modelos = self::modelosEn($leido);
        $pegadoFicha = preg_replace('/[^a-z0-9]/', '', self::comparable((string) $textoDeLaFicha));

        if ($modelos === [] || collect($modelos)->contains(fn ($m) => str_contains($pegadoFicha, $m))) {
            return false;
        }

        $marca = self::palabras((string) ($propuesta['marca'] ?? ''));

        return collect(self::palabras((string) $textoDeLaFicha))
            ->reject(fn ($w) => strlen($w) < 6 || preg_match('/\d/', $w) || in_array($w, self::NO_DISTINGUEN, true)
                || in_array($w, $marca, true) || self::colores($w) !== [])
            ->contains(fn ($w) => ! self::seLee($w, $leido));
    }

    /** Si todas las palabras de algo se leen en el texto (una letra de diferencia en palabras largas). */
    private static function seLee(string $algo, string $texto): bool
    {
        $leidas = self::palabras($texto);

        return collect(self::palabras($algo))->every(fn ($w) => in_array($w, $leidas, true)
            || (strlen($w) >= 5 && collect($leidas)->contains(fn ($l) => levenshtein($w, $l) <= 1)));
    }

    public function porcentaje(float $s): string
    {
        return round($s * 100) . ' %';
    }

    private function claveDeHuella(string $clave): string
    {
        return 'activo-ia:huella:' . $clave;
    }
}
