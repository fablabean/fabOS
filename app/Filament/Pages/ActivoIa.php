<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\ControlaSuAcceso;
use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Supplies\SupplyResource;
use App\Models\AccionDeInventarioIa;
use App\Models\Asset;
use App\Models\ConsultaDeInventarioIa;
use App\Models\Supply;
use App\Services\Ia\Inventario\AccionesDeInventario;
use App\Services\Ia\Inventario\AccionRechazada;
use App\Services\Ia\Inventario\AsistenteDeInventario;
use App\Services\Ia\Inventario\CatalogoDeInventario;
use App\Services\Ia\Inventario\FotoParaLaIa;
use App\Services\Ia\Inventario\ReconocedorDeFotos;
use App\Services\Ia\Inventario\ServidorDeIaDelFablab;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\WithFileUploads;

/**
 * Activo IA: mostrarle una foto al inventario y decirle qué hacer con ella.
 *
 * Se adjunta la foto de un equipo, una etiqueta o una caja de tornillos, se
 * escribe qué se quiere —«regístralo», «¿ya lo tenemos?», «trasládalo al
 * taller»— y el asistente contesta con una **propuesta**: los datos que pudo
 * leer, lo que no, los registros parecidos que ya existen. La propuesta se
 * corrige ahí mismo y solo se guarda cuando alguien pulsa «Confirmar».
 *
 * Cada confirmación queda en el historial de abajo con el valor de antes y el
 * de después, y lo que se corrigió de lo que propuso la IA.
 */
class ActivoIa extends Page
{
    use ControlaSuAcceso;
    use WithFileUploads;

    protected string $view = 'filament.pages.activo-ia';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $navigationLabel = 'Activo IA';

    protected static ?string $title = 'Activo IA';

    protected static ?string $slug = 'activo-ia';

    /** Debajo de Activos en el menú: es otra forma de llegar al mismo inventario. */
    protected static ?string $navigationParentItem = 'Activos';

    protected static ?int $navigationSort = 3;

    public string $conversacion = '';

    /** @var list<array{rol:string,texto:string,miniatura:?string,propuestas:list<string>,error:?string,hora:string}> */
    // Solo lo cambia el servidor: lleva las rutas de las fotos, que no deben
    // poder reescribirse desde el navegador.
    #[Locked]
    public array $mensajes = [];

    /** @var array<string,array<string,mixed>> clave => propuesta */
    public array $propuestas = [];

    public string $texto = '';

    /** @var \Livewire\Features\SupportFileUploads\TemporaryUploadedFile|null */
    public $foto = null;

    /** Varias fotos en un mensaje: de la misma repisa desde otros ángulos, o de otras. */
    public array $fotos = [];

    public const MAX_FOTOS = 6;

    /**
     * El reconocimiento que corre en la cola: su id, qué es y lo que hace
     * falta para terminarlo cuando llegue. Mientras está, la página pregunta
     * cada pocos segundos (revisarReconocimiento) y no se manda otra foto.
     *
     * @var array{id:string,tipo:string,desde:int,contexto:array<string,mixed>}|null
     */
    #[Locked]
    public ?array $enCurso = null;

    /** Más que esto sin respuesta es que el trabajador no está corriendo. */
    public const ESPERA_MAXIMA = 1800;

    public static function getNavigationGroup(): string|\UnitEnum|null
    {
        return 'Laboratorio';
    }

    public function getSubheading(): ?string
    {
        return 'Registra activos e insumos desde una foto, consulta existencias y traslada equipos sin llenar formularios.';
    }

    public function mount(): void
    {
        $this->conversacion = app(AsistenteDeInventario::class)->nuevaConversacion();
    }

    public function enviar(): void
    {
        if ($this->enCurso) {
            return;
        }

        $this->validate([
            'texto' => ['nullable', 'string', 'max:4000'],
            'foto' => ['nullable', 'image', 'max:15360'],
            'fotos' => ['array', 'max:' . self::MAX_FOTOS],
            'fotos.*' => ['image', 'max:15360'],
        ], [
            'foto.image' => 'El archivo tiene que ser una imagen (JPG, PNG o WebP).',
            'foto.max' => 'La foto pesa demasiado (máximo 15 MB).',
            'fotos.max' => 'Como máximo ' . self::MAX_FOTOS . ' fotos por mensaje.',
            'fotos.*.image' => 'Todos los archivos tienen que ser imágenes (JPG, PNG o WebP).',
            'fotos.*.max' => 'Alguna foto pesa demasiado (máximo 15 MB cada una).',
        ]);

        $texto = trim($this->texto);
        $archivos = array_values(array_filter(array_merge($this->foto ? [$this->foto] : [], $this->fotos)));

        if ($texto === '' && $archivos === []) {
            return;
        }

        $asistente = app(AsistenteDeInventario::class);
        $reconocedor = app(ReconocedorDeFotos::class);
        $preparadas = [];

        foreach ($archivos as $archivo) {
            try {
                $preparadas[] = app(FotoParaLaIa::class)->preparar($archivo->getRealPath());
            } catch (\RuntimeException $e) {
                $this->addError('foto', $e->getMessage());

                return;
            }
        }

        $foto = $preparadas[0] ?? null;

        $this->mensajes[] = [
            'rol' => 'persona',
            'texto' => $texto,
            'miniatura' => $foto['miniatura'] ?? null,
            'miniaturas' => count($preparadas) > 1 ? array_column($preparadas, 'miniatura') : [],
            'propuestas' => [],
            'error' => null,
            'hora' => now()->format('H:i'),
        ];

        // Las fotos las reconoce el servidor del FabLab: no salen del laboratorio.
        // Se encolan: con varios objetos tarda minutos, más de lo que el túnel
        // deja esperar a una página.
        if ($foto && $reconocedor->disponible()) {
            $this->reset('texto', 'foto', 'fotos');

            count($preparadas) > 1
                ? $this->lanzar('varias', ['fotos' => $preparadas], ['fotos' => $preparadas, 'texto' => $texto])
                : $this->reconocer($foto, $texto);

            return;
        }

        // Una foto nunca va a Claude: si el servidor del FabLab no está, se
        // dice y ya. Mandarla a Claude la sacaría del laboratorio y se cobra.
        if ($foto) {
            $this->mensajes[] = [
                'rol' => 'ia',
                'texto' => '',
                'miniatura' => null,
                'propuestas' => [],
                'error' => 'Las fotos las reconoce el servidor de IA del FabLab, y ahora está apagado en la configuración (FABLAB_IA_ACTIVO / FABLAB_IA_URL). Escribe la pregunta sin foto o vuelve a intentarlo cuando esté encendido.',
                'hora' => now()->format('H:i'),
            ];
            $this->reset('texto', 'foto', 'fotos');

            return;
        }

        // Sin Claude, las preguntas escritas no tienen quién las conteste.
        if (! $asistente->disponible() && $reconocedor->disponible()) {
            $this->mensajes[] = [
                'rol' => 'ia',
                'texto' => '',
                'miniatura' => null,
                'propuestas' => [],
                'error' => 'Por ahora reconozco objetos por foto: adjunta una. Las preguntas escritas necesitan la conexión con Claude (' . $asistente->porQueNoEsta() . ')',
                'hora' => now()->format('H:i'),
            ];
            $this->reset('texto', 'foto', 'fotos');

            return;
        }

        $respuesta = $asistente->responder($this->conversacion, $texto, null, auth()->user());

        foreach ($respuesta['propuestas'] as $propuesta) {
            $this->propuestas[$propuesta['clave']] = $propuesta + [
                'estado' => 'pendiente',
                'consulta_id' => $respuesta['consulta_id'],
                'confirmo_distinto' => false,
                'resultado' => null,
                'error' => null,
            ];
        }

        $this->mensajes[] = [
            'rol' => 'ia',
            'texto' => $respuesta['texto'],
            'miniatura' => null,
            'propuestas' => array_column($respuesta['propuestas'], 'clave'),
            'error' => $respuesta['error'],
            'hora' => now()->format('H:i'),
        ];

        $this->reset('texto', 'foto', 'fotos');
    }

    /**
     * Manda la foto al servidor del FabLab y deja la tarjeta de reconocimiento.
     *
     * @param  array{ruta:string,miniatura:string}  $foto
     */
    /**
     * Varias fotos: cada una se reconoce, y luego se decide si son la misma
     * repisa desde otro ángulo —y entonces lo repetido no se cuenta dos veces—
     * o fotos de otras cosas.
     *
     * @param  list<array{ruta:string,miniatura:string}>  $fotos
     */
    private function terminarVarias(array $fotos, string $texto, array $r): void
    {
        $indice = count($this->mensajes);
        $consultas = [];
        $fallidas = [];

        // El reconocimiento lo hizo la cola (ReconocerParaActivoIa); aquí se
        // registra y se arma la conversación.
        if (isset($r['error'])) {
            $r = ['por_foto' => array_fill(0, count($fotos), []), 'errores' => array_fill(0, count($fotos), $r['error']['mensaje']), 'relacion' => ['tarjetas' => [], 'relaciones' => []]];
        }

        foreach ($fotos as $n => $foto) {
            if (array_key_exists($n, $r['errores'])) {
                $fallidas[] = $n + 1;
                ConsultaDeInventarioIa::create([
                    'user_id' => auth()->id(), 'conversacion' => $this->conversacion, 'instruccion' => $texto,
                    'foto_path' => $foto['ruta'], 'modelo' => 'servidor del FabLab', 'estado' => 'fallida',
                    'error' => \Illuminate\Support\Str::limit((string) $r['errores'][$n], 2000),
                ]);

                continue;
            }

            $tarjetas = $r['por_foto'][$n] ?? [];
            $total = count($tarjetas);

            $consultas[$n] = ConsultaDeInventarioIa::create([
                'user_id' => auth()->id(),
                'conversacion' => $this->conversacion,
                'instruccion' => $texto,
                'foto_path' => $foto['ruta'],
                'respuesta' => 'Foto ' . ($n + 1) . ' de ' . count($fotos) . ': ' . $total . ' objetos.',
                'propuestas' => collect($tarjetas)->map(fn ($t) => collect($t)->only(['clase', 'propuesta', 'mismo', 'segundos'])->all()
                    + ['por_foto' => count($t['por_foto']), 'por_texto' => count($t['por_texto'])])->all(),
                'duplicados_detectados' => collect($tarjetas)->sum(fn ($t) => count($t['por_foto']) + count($t['por_texto'])),
                'modelo' => 'servidor del FabLab',
                'estado' => 'respondida',
            ])->id;
        }

        $relacion = $r['relacion'];
        $claves = [];

        foreach ($relacion['tarjetas'] as $n => $tarjetas) {
            foreach ($tarjetas as $t) {
                $this->propuestas[$t['clave']] = $t + [
                    // Lo que ya se vio desde otro ángulo no se cuenta dos veces.
                    'estado' => isset($t['repetida_de']) ? 'repetida' : 'pendiente',
                    'consulta_id' => $consultas[$n] ?? null,
                    'mensaje' => $indice,
                    'busqueda' => '',
                    'cantidad_a_sumar' => 1,
                    'por_busqueda' => [],
                    'resultado' => null,
                    'error' => null,
                ];
                $claves[] = $t['clave'];
            }
        }

        $this->juntarLasPartes($claves);
        $this->paraRevisar($claves);

        $this->mensajes[] = [
            'rol' => 'ia',
            'texto' => $this->resumenDeVarias(count($fotos), $relacion, $fallidas),
            'miniatura' => null,
            'propuestas' => $claves,
            'error' => $fallidas && count($fallidas) === count($fotos)
                ? 'El servidor de IA del FabLab no pudo analizar las fotos. ¿Estás en la red del FabLab?'
                : null,
            'hora' => now()->format('H:i'),
            'filtro' => $this->conFiltro() && $claves !== [],
            'fotos_filtro' => collect($fotos)->mapWithKeys(fn ($f, $n) => [$n + 1 => [
                'ruta' => $f['ruta'], 'miniatura' => $f['miniatura'] ?? null, 'consulta_id' => $consultas[$n] ?? null,
            ]])->all(),
        ];

        $this->olvidarMedicion();
    }

    /** Qué se entendió de las fotos, dicho en pocas frases. */
    private function resumenDeVarias(int $cuantas, array $relacion, array $fallidas): string
    {
        $frases = ['Recibí ' . $cuantas . ' fotos.'];

        foreach ($relacion['relaciones'] as $r) {
            $yaEstaban = $r['comunes'] . ($r['comunes'] === 1 ? ' objeto ya estaba' : ' objetos ya estaban') . ' en la ' . $r['con'] . ' y no los cuento dos veces.';

            $frases[] = match (true) {
                $r['misma'] => 'La foto ' . $r['foto'] . ' es lo mismo que la foto ' . $r['con'] . ' desde otro ángulo: ' . $yaEstaban,
                $r['parte'] ?? false => 'La foto ' . $r['foto'] . ' muestra otra parte de la misma repisa que la foto ' . $r['con'] . ': ' . $yaEstaban,
                $r['comparte'] ?? false => 'La foto ' . $r['foto'] . ' tiene ' . ($r['comunes'] === 1 ? 'un objeto' : $r['comunes'] . ' objetos') . ' de la foto ' . $r['con']
                    . '; puede ser el mismo lugar desde otro ángulo o más de cerca: ' . $yaEstaban . ' Lo demás va aparte; revisa si algo se repite.',
                default => 'En la foto ' . $r['foto'] . ' no encontré nada de las anteriores: sus objetos van aparte. Si alguno es lo mismo desde otro ángulo, '
                    . 'pulsa «Sí, es el mismo» en los que aparezcan en naranja, o «Es este» con el registro que ya elegiste.',
            };
        }

        $unicos = collect($relacion['tarjetas'])->flatten(1)->reject(fn ($t) => isset($t['repetida_de']))->count();
        $frases[] = 'En total, ' . $unicos . ($unicos === 1 ? ' objeto distinto' : ' objetos distintos') . ($this->conFiltro()
            ? '. Antes de buscar en el inventario, dime cuáles sí son objetos; si falta alguno, pulsa «Busca otro en la foto».'
            : '; para cada uno elige si ya está en el inventario o si es nuevo.');

        $dudosos = collect($relacion['tarjetas'])->flatten(1)->filter(fn ($t) => isset($t['quizas_de']) && ! isset($t['repetida_de']))->count();
        if ($dudosos > 0) {
            $frases[] = ($dudosos === 1 ? 'Uno de ellos puede ser' : $dudosos . ' de ellos pueden ser') . ' lo mismo que algo de otra foto: lo marqué en naranja; si lo es, pulsa «Sí, es el mismo» para no registrarlo dos veces.';
        }

        if ($fallidas) {
            $frases[] = 'No pude analizar ' . (count($fallidas) === 1 ? 'la foto ' : 'las fotos ') . implode(', ', $fallidas) . '.';
        }

        return implode(' ', $frases);
    }

    /**
     * «No, es otro»: el objeto que se tomó por repetido de otra foto se cuenta
     * aparte. Deja de ser un ángulo del otro.
     */
    public function esOtro(string $clave): void
    {
        $p = $this->propuestas[$clave] ?? null;

        if (! $p || $p['estado'] !== 'repetida') {
            return;
        }

        $original = $p['repetida_de']['clave'] ?? null;

        if ($original && isset($this->propuestas[$original])) {
            $this->propuestas[$original]['otros_angulos'] = array_values(array_diff($this->propuestas[$original]['otros_angulos'] ?? [], [$clave]));
        }

        $this->propuestas[$clave]['estado'] = 'pendiente';
        $this->propuestas[$clave]['repetida_de'] = null;
    }

    /**
     * «Sí, es el mismo»: lo que la IA no se atrevió a dar por repetido, pero
     * se parecía a un objeto de otra foto de la misma repisa, se cuenta una
     * sola vez. Al confirmar aquel, esta foto queda como otro ángulo.
     */
    public function esElMismo(string $clave): void
    {
        $p = $this->propuestas[$clave] ?? null;
        $original = $p['quizas_de']['clave'] ?? null;

        if (! $p || $p['estado'] !== 'pendiente' || ! $original || ! isset($this->propuestas[$original]) || $this->propuestas[$original]['estado'] === 'olvidada') {
            return;
        }

        $this->propuestas[$original]['otros_angulos'] = array_values(array_unique([
            ...($this->propuestas[$original]['otros_angulos'] ?? []), $clave,
        ]));
        $this->propuestas[$clave]['repetida_de'] = collect($p['quizas_de'])->only(['foto', 'objeto', 'clave'])->all();
        $this->propuestas[$clave]['estado'] = 'repetida';
    }

    /** Las fotos de otros ángulos del mismo objeto quedan también como ejemplo. */
    private function guardarAngulos(array $p, Asset|Supply $registro): void
    {
        $acciones = app(AccionesDeInventario::class);

        foreach ($p['otros_angulos'] ?? [] as $angulo) {
            $acciones->guardarFotoDelNuevo($angulo, $registro, auth()->user());

            if (isset($this->propuestas[$angulo])) {
                $this->propuestas[$angulo]['estado'] = 'angulo_guardado';
            }
        }
    }

    private function reconocer(array $foto, string $texto): void
    {
        $this->lanzar('una', ['foto' => $foto], ['foto' => $foto, 'texto' => $texto]);
    }

    /**
     * Encola el reconocimiento y, si ya terminó (en las pruebas la cola es
     * inmediata), lo recoge en el acto.
     *
     * @param  array<string,mixed>  $datos  lo que necesita la cola
     * @param  array<string,mixed>  $contexto  lo que necesita la página para terminar
     */
    private function lanzar(string $tipo, array $datos, array $contexto): void
    {
        $id = (string) \Illuminate\Support\Str::uuid();
        \App\Jobs\ReconocerParaActivoIa::marcarEnEspera($id);
        // El contexto (las fotos con sus miniaturas) se queda en el servidor:
        // en la página iba de ida y vuelta en cada pregunta de wire:poll y,
        // con una conversación larga, pasaba el límite de 1 MB de Livewire.
        \Illuminate\Support\Facades\Cache::put(self::claveDelContexto($id), $contexto, now()->addHours(2));
        $this->enCurso = ['id' => $id, 'tipo' => $tipo, 'desde' => now()->getTimestamp()];

        \App\Jobs\ReconocerParaActivoIa::dispatch($id, $tipo, $datos);

        $this->revisarReconocimiento();
    }

    private static function claveDelContexto(string $id): string
    {
        return 'activo-ia:contexto:' . auth()->id() . ':' . $id;
    }

    /** La página pregunta cada pocos segundos (wire:poll) si la cola ya terminó. */
    public function revisarReconocimiento(): void
    {
        if (! $this->enCurso) {
            return;
        }

        $id = $this->enCurso['id'];
        $r = \App\Jobs\ReconocerParaActivoIa::resultado($id);

        if (($r['estado'] ?? null) !== 'listo') {
            if ($r !== null && now()->getTimestamp() - $this->enCurso['desde'] < self::ESPERA_MAXIMA) {
                return;
            }

            // Se perdió (la caché se vació) o nadie lo tomó en media hora.
            $r = ['estado' => 'listo', 'error' => ['mensaje' => 'El reconocimiento no terminó: el trabajador de la cola de Activo IA no respondió.', 'conexion' => false]];
        }

        $tipo = $this->enCurso['tipo'];
        $c = \Illuminate\Support\Facades\Cache::pull(self::claveDelContexto($id));
        $this->enCurso = null;
        \App\Jobs\ReconocerParaActivoIa::olvidar($id);

        if (! is_array($c)) {
            $this->mensajes[] = [
                'rol' => 'ia', 'texto' => '', 'miniatura' => null, 'propuestas' => [],
                'error' => 'Se perdió el reconocimiento en curso. Vuelve a enviar la foto.',
                'hora' => now()->format('H:i'),
            ];

            return;
        }

        match ($tipo) {
            'una' => $this->terminarDeReconocer($c['foto'], (string) $c['texto'], $r),
            'varias' => $this->terminarVarias($c['fotos'], (string) $c['texto'], $r),
            'otros' => $this->terminarBuscarOtro((int) $c['indice'], (int) $c['foto'], $r),
        };
    }

    /** @param  array<string,mixed>  $r  lo que dejó la cola */
    private function terminarDeReconocer(array $foto, string $texto, array $r): void
    {
        $indice = count($this->mensajes);

        if (isset($r['error'])) {
            $e = \App\Jobs\ReconocerParaActivoIa::excepcion($r['error']);

            ConsultaDeInventarioIa::create([
                'user_id' => auth()->id(), 'conversacion' => $this->conversacion, 'instruccion' => $texto,
                'foto_path' => $foto['ruta'], 'modelo' => 'servidor del FabLab', 'estado' => 'fallida',
                'error' => \Illuminate\Support\Str::limit($e->getMessage(), 2000),
            ]);

            // Que la barra lateral deje de decir «Conectado» ya, no en un minuto.
            app(ServidorDeIaDelFablab::class)->olvidarEstado();

            $this->mensajes[] = [
                'rol' => 'ia', 'texto' => '', 'miniatura' => null, 'propuestas' => [],
                'error' => $this->porQueFallo($e),
                'hora' => now()->format('H:i'),
                // La foto ya está guardada: se puede volver a intentar sin subirla.
                'reintentar' => ['ruta' => $foto['ruta'], 'miniatura' => $foto['miniatura'] ?? null, 'texto' => $texto],
            ];
            $this->olvidarMedicion();

            return;
        }

        $tarjetas = $r['tarjetas'];

        // Las partes de una máquina (su consola, su torre de luces, su mesa) no
        // cuentan como objetos aparte.
        // Lo que se juntó con la máquina antes de recortar también se cuenta así.
        $partes = collect($tarjetas)->filter(fn ($t) => $t['objeto']['pieza_segura'] ?? false)
            ->merge(collect($tarjetas)->flatMap(fn ($t) => collect($t['objeto']['incluye'] ?? [])->map(fn ($n) => ['propuesta' => ['nombre' => $n]])));
        $enteros = collect($tarjetas)->reject(fn ($t) => $t['objeto']['pieza_segura'] ?? false)->values();
        $cuantos = $enteros->count();

        // Con uno, el resumen de siempre; con varios, se dice cuántos y se
        // deja a cada tarjeta su propio resumen.
        $texto_ia = match (true) {
            $cuantos === 0 => 'No logré separar ningún objeto en la foto. Prueba con una foto más de cerca.',
            $this->conFiltro() => 'Veo ' . ($cuantos === 1 ? '1 objeto' : $cuantos . ' objetos') . ' en la foto: '
                . $enteros->map(fn ($t) => '«' . ($t['propuesta']['nombre'] ?? '¿?') . '»')->join(', ', ' y ')
                . '. Antes de buscar en el inventario, dime cuáles sí son objetos; si falta alguno, pulsa «Busca otro en la foto».',
            $cuantos === 1 => $enteros[0]['resumen'],
            default => 'Veo ' . $cuantos . ' objetos en la foto: '
                . $enteros->map(fn ($t) => '«' . ($t['propuesta']['nombre'] ?? '¿?') . '»')->join(', ', ' y ')
                . '. Te los muestro uno por uno; para cada uno elige si ya está en el inventario o si es nuevo.',
        } . ($partes->isNotEmpty()
            ? ' ' . ($partes->count() === 1 ? 'Una cosa más es parte' : $partes->count() . ' cosas más son partes') . ' de la máquina (como «'
                . $partes->first()['propuesta']['nombre'] . '»): las junté con ella en un solo objeto.'
            : '');

        $consulta = ConsultaDeInventarioIa::create([
            'user_id' => auth()->id(),
            'conversacion' => $this->conversacion,
            'instruccion' => $texto,
            'foto_path' => $foto['ruta'],
            'respuesta' => $texto_ia,
            'propuestas' => collect($tarjetas)->map(fn ($t) => collect($t)->only(['clase', 'propuesta', 'mismo', 'segundos'])->all()
                + ['por_foto' => count($t['por_foto']), 'por_texto' => count($t['por_texto'])])->all(),
            'duplicados_detectados' => collect($tarjetas)->sum(fn ($t) => count($t['por_foto']) + count($t['por_texto'])),
            'modelo' => 'servidor del FabLab',
            'estado' => 'respondida',
        ]);

        foreach ($tarjetas as $t) {
            $this->propuestas[$t['clave']] = $t + [
                'estado' => 'pendiente',
                'consulta_id' => $consulta->id,
                'mensaje' => $indice,
                'busqueda' => '',
                'cantidad_a_sumar' => 1,
                'por_busqueda' => [],
                'resultado' => null,
                'error' => null,
            ];
        }

        $this->juntarLasPartes(array_column($tarjetas, 'clave'));
        $this->paraRevisar(array_column($tarjetas, 'clave'));

        $this->mensajes[] = [
            'rol' => 'ia',
            'texto' => $texto_ia,
            'miniatura' => null,
            'propuestas' => array_column($tarjetas, 'clave'),
            'error' => null,
            'hora' => now()->format('H:i'),
            // Primero se revisa qué es de verdad un objeto; después, el inventario.
            'filtro' => $this->conFiltro() && $cuantos > 0,
            'fotos_filtro' => [1 => ['ruta' => $foto['ruta'], 'miniatura' => $foto['miniatura'] ?? null, 'consulta_id' => $consulta->id]],
        ];

        $this->olvidarMedicion();
    }

    /**
     * «Falta un artículo»: el modelo no vio todo lo que hay en la foto.
     *
     * Pasa con lo que está tapado por otro —un bote del que solo asoma la
     * tapa—. Se agrega una tarjeta vacía al mismo mensaje: se busca en el
     * inventario y se marca «Es este», o se registra como nuevo. No lleva
     * recorte, así que no se guarda foto de referencia.
     */
    public function agregarArticulo(int $indice): void
    {
        $mensaje = $this->mensajes[$indice] ?? null;
        $hermana = collect($mensaje['propuestas'] ?? [])->map(fn ($c) => $this->propuestas[$c] ?? null)
            ->first(fn ($p) => ($p['clase'] ?? null) === 'reconocimiento');

        if (! $hermana) {
            return;
        }

        $clave = app(ReconocedorDeFotos::class)->tarjetaManual($hermana['clave']);

        if (! $clave) {
            return;
        }

        $total = collect($mensaje['propuestas'])->filter(fn ($c) => ($this->propuestas[$c]['clase'] ?? null) === 'reconocimiento')->count() + 1;

        $this->propuestas[$clave] = [
            'clave' => $clave,
            'clase' => 'reconocimiento',
            'propuesta' => null,
            'segundos' => null,
            'por_foto' => [],
            'mismo' => false,
            'por_texto' => [],
            'aviso_texto' => null,
            'lugar' => null,
            'objeto' => ['indice' => $total, 'total' => $total, 'manual' => true],
            'resumen' => 'Búscalo en el inventario o regístralo como nuevo.',
            'tipo_sugerido' => $hermana['propuesta']['tipo'] ?? null,
            'estado' => 'pendiente',
            'consulta_id' => $hermana['consulta_id'],
            'mensaje' => $indice,
            'busqueda' => '',
            'cantidad_a_sumar' => 1,
            'por_busqueda' => [],
            'resultado' => null,
            'error' => null,
        ];

        $this->mensajes[$indice]['propuestas'][] = $clave;
    }

    /** Vuelve a mandar la foto de un intento que falló, sin subirla otra vez. */
    public function reintentar(int $indice): void
    {
        $intento = $this->mensajes[$indice]['reintentar'] ?? null;

        // Solo fotos de esta carpeta: la ruta viene de la pantalla.
        if ($this->enCurso || ! $intento || ! str_starts_with((string) $intento['ruta'], 'activo-ia/') || str_contains((string) $intento['ruta'], '..')
            || ! \Illuminate\Support\Facades\Storage::disk('local')->exists($intento['ruta'])) {
            return;
        }

        $this->mensajes[$indice]['reintentar'] = null;
        $this->mensajes[$indice]['error'] .= ' (reintentado a las ' . now()->format('H:i') . ')';

        $this->reconocer(['ruta' => $intento['ruta'], 'miniatura' => $intento['miniatura']], (string) $intento['texto']);
    }

    /** Qué pasó, dicho para quien está con la foto en la mano. */
    private function porQueFallo(\Throwable $e): string
    {
        $m = $e->getMessage();
        $donde = parse_url((string) config('fabos.servidor_fablab.url'), PHP_URL_HOST) ?: 'el servidor';

        return match (true) {
            str_contains($m, 'Could not resolve host'), str_contains($m, 'Failed to connect'), str_contains($m, 'Connection refused'),
            str_contains($m, 'timed out') && str_contains($m, 'onnect') =>
                'No encuentro el servidor de IA del FabLab (' . $donde . '): parece apagado o cambió de dirección. '
                . 'Tu foto quedó guardada; pulsa «Reintentar» cuando vuelva. Si sigue así, avísale a Erick con la hora (' . now()->format('H:i') . ').',
            $e instanceof \Illuminate\Http\Client\ConnectionException =>
                'El servidor de IA del FabLab tardó demasiado en contestar. Puede estar ocupado con otra foto: reintenta en un momento.',
            default => 'El servidor de IA del FabLab tuvo un problema con esta foto (' . \Illuminate\Support\Str::limit($m, 120) . '). Reintenta en un momento.',
        };
    }

    /** «Es este»: la foto queda como ejemplo de un registro existente. */
    public function esEste(string $clave, string $candidato): void
    {
        $r = $this->propuestas[$clave] ?? null;

        if (! $r || $r['clase'] !== 'reconocimiento' || $r['estado'] !== 'pendiente') {
            return;
        }

        // Solo uno de los que se ofrecieron, no cualquier registro.
        $elegido = collect($r['por_busqueda'] ?? [])->merge($r['por_foto'])->merge($r['por_texto'])->firstWhere('clave', $candidato);

        if (! $elegido) {
            return;
        }

        $registro = $elegido['tipo'] === 'activo' ? Asset::find($elegido['id']) : Supply::find($elegido['id']);

        if (! $registro) {
            $this->propuestas[$clave]['error'] = 'Ese registro ya no existe.';

            return;
        }

        try {
            app(AccionesDeInventario::class)->vincularFoto($registro, $clave, isset($elegido['similitud']) ? (float) $elegido['similitud'] : null, auth()->user(), $r['consulta_id']);
        } catch (AccionRechazada $e) {
            $this->propuestas[$clave]['error'] = $e->getMessage();

            return;
        }

        $this->propuestas[$clave]['estado'] = 'vinculada';
        $this->guardarAngulos($r, $registro);
        $this->propuestas[$clave]['error'] = null;
        $this->propuestas[$clave]['resultado'] = ['nombre' => $registro->name, 'url' => $this->urlDe($registro)];

        if ($registro instanceof Asset) {
            $this->yaNoOfrecer($candidato);
            $this->propuestas[$clave]['completar'] = $this->queLeFalta($registro, $r['propuesta'] ?? []);
        }

        unset($this->historial);
        $this->olvidarMedicion();

        Notification::make()->title('Foto guardada')->body('La próxima foto de «' . $registro->name . '» se reconocerá sola.')->success()->send();
    }

    /**
     * «Sí, es una sola pieza»: la bomba y su cámara de vacío, unidas por la
     * manguera, son un equipo. La parte se cuelga del principal: no se
     * registra aparte, y al confirmar aquel su foto se guarda con él.
     */
    public function esUnaPieza(string $clave): void
    {
        $p = $this->propuestas[$clave] ?? null;
        $principal = $p['objeto']['pieza_de_clave'] ?? null;

        if (! $p || $p['estado'] !== 'pendiente' || ! $principal || ! isset($this->propuestas[$principal])
            || $this->propuestas[$principal]['estado'] !== 'pendiente') {
            return;
        }

        $this->propuestas[$principal]['otros_angulos'] = array_values(array_unique([
            ...($this->propuestas[$principal]['otros_angulos'] ?? []), $clave,
        ]));

        // Lo que se sugirió para la parte también vale para el equipo: la ficha
        // «Bomba de Vacío con Compresor» salía en la bomba, no en la cámara.
        foreach (['por_foto', 'por_texto'] as $lista) {
            $this->propuestas[$principal][$lista] = collect($this->propuestas[$principal][$lista] ?? [])
                ->concat($p[$lista] ?? [])
                ->sortByDesc('similitud')
                ->unique('clave')
                ->values()
                ->all();
        }

        [$this->propuestas[$principal]['por_foto'], $this->propuestas[$principal]['por_texto']] = ReconocedorDeFotos::unSoloMasProbable(
            $this->propuestas[$principal]['por_foto'], $this->propuestas[$principal]['por_texto'],
        );

        $this->propuestas[$clave]['parte_de'] = ['objeto' => $p['objeto']['pieza_de'], 'clave' => $principal];
        $this->propuestas[$clave]['estado'] = 'parte';
    }

    /*
     * El primer filtro: antes de buscar en el inventario, la persona dice qué
     * es de verdad un objeto. Lo que no, se olvida; si falta algo, se busca
     * otro en la foto. Con los objetos ya confirmados, se sigue al inventario.
     */

    /**
     * Antes de devolver la página: a lo que ya quedó resuelto se le quitan
     * las listas de sugerencias, que ya no se muestran. La conversación va
     * acumulando tarjetas, y con las listas la página volvía a acercarse al
     * límite de 1 MB de Livewire. Lo que todavía se puede deshacer («Es
     * nuevo», una parte, un repetido) las conserva.
     */
    public function dehydrate(): void
    {
        foreach ($this->propuestas as $clave => $p) {
            if (($p['clase'] ?? null) === 'reconocimiento'
                && in_array($p['estado'] ?? null, ['vinculada', 'sumada', 'olvidada', 'descartada', 'angulo_guardado', 'guardada'], true)
                && (($p['por_foto'] ?? []) !== [] || ($p['por_texto'] ?? []) !== [] || ($p['por_busqueda'] ?? []) !== [])) {
                $this->propuestas[$clave]['por_foto'] = [];
                $this->propuestas[$clave]['por_texto'] = [];
                $this->propuestas[$clave]['por_busqueda'] = [];
            }
        }
    }

    private function conFiltro(): bool
    {
        return (bool) config('fabos.servidor_fablab.filtro_de_objetos', true);
    }

    /** @param  list<string>  $claves */
    private function paraRevisar(array $claves): void
    {
        if (! $this->conFiltro()) {
            return;
        }

        foreach ($claves as $clave) {
            if (($this->propuestas[$clave]['estado'] ?? null) === 'pendiente') {
                $this->propuestas[$clave]['estado'] = 'por_revisar';
                $this->propuestas[$clave]['filtro'] = null;
            }
        }
    }

    /** «Sí, es un objeto» / «No, olvídalo» / «Cambiar». */
    public function decidirObjeto(string $clave, ?string $decision): void
    {
        if (($this->propuestas[$clave]['estado'] ?? null) === 'por_revisar' && in_array($decision, ['si', 'no', null], true)) {
            $this->propuestas[$clave]['filtro'] = $decision;
        }
    }

    /** «Todos son objetos»: los que faltan por decidir, de una vez. */
    public function todosSonObjetos(int $indice): void
    {
        foreach ($this->mensajes[$indice]['propuestas'] ?? [] as $clave) {
            if (($this->propuestas[$clave]['estado'] ?? null) === 'por_revisar' && ($this->propuestas[$clave]['filtro'] ?? null) === null) {
                $this->propuestas[$clave]['filtro'] = 'si';
            }
        }
    }

    /**
     * «Busca otro en la foto»: se le pide al modelo que mire otra vez, sin
     * repetir lo ya revisado (lo que sí y lo que no), y lo nuevo entra a la
     * lista para revisarlo igual.
     */
    public function buscarOtro(int $indice, int $foto = 1): void
    {
        $mensaje = $this->mensajes[$indice] ?? null;
        $datos = $mensaje['fotos_filtro'][$foto] ?? null;

        if ($this->enCurso || ! $mensaje || ! ($mensaje['filtro'] ?? false) || ! $datos
            || ! str_starts_with((string) $datos['ruta'], 'activo-ia/') || str_contains((string) $datos['ruta'], '..')) {
            return;
        }

        $deLaFoto = collect($mensaje['propuestas'])->map(fn ($c) => $this->propuestas[$c] ?? null)->filter()
            ->filter(fn ($p) => ($p['clase'] ?? null) === 'reconocimiento' && (int) ($p['objeto']['foto'] ?? 1) === $foto);

        $this->lanzar('otros', [
            'foto' => ['ruta' => $datos['ruta']],
            'revisados' => $deLaFoto->map(fn ($p) => ['nombre' => $p['propuesta']['nombre'] ?? null, 'caja' => $p['objeto']['caja'] ?? null, 'clave' => $p['clave']])->values()->all(),
            'ya_hay' => $deLaFoto->max(fn ($p) => (int) ($p['objeto']['indice'] ?? 0)) ?? 0,
        ], ['indice' => $indice, 'foto' => $foto]);
    }

    /** @param  array<string,mixed>  $r  lo que dejó la cola */
    private function terminarBuscarOtro(int $indice, int $foto, array $r): void
    {
        $mensaje = $this->mensajes[$indice] ?? null;
        $datos = $mensaje['fotos_filtro'][$foto] ?? null;

        if (! $mensaje || ! $datos) {
            return;
        }

        $nuevas = $r['nuevas'] ?? [];

        if (isset($r['error'])) {
            Notification::make()->title('No pude volver a mirar la foto')->body('El servidor de IA del FabLab no respondió. Prueba otra vez en un momento.')->danger()->send();

            return;
        }

        if ($nuevas === []) {
            Notification::make()->title('No encontré otro objeto en la foto')->body('Si falta algo, usa «Falta un artículo» cuando sigas al inventario, o tómale una foto aparte.')->warning()->send();

            return;
        }

        foreach ($nuevas as $t) {
            $t['objeto']['foto'] = count($mensaje['fotos_filtro']) > 1 ? $foto : ($t['objeto']['foto'] ?? null);
            $this->propuestas[$t['clave']] = $t + [
                'estado' => 'por_revisar',
                'filtro' => null,
                'consulta_id' => $datos['consulta_id'],
                'mensaje' => $indice,
                'busqueda' => '',
                'cantidad_a_sumar' => 1,
                'por_busqueda' => [],
                'resultado' => null,
                'error' => null,
            ];
            $this->mensajes[$indice]['propuestas'][] = $t['clave'];
        }

        Notification::make()->title(count($nuevas) === 1 ? 'Encontré otro objeto' : 'Encontré ' . count($nuevas) . ' objetos más')
            ->body(collect($nuevas)->map(fn ($t) => '«' . ($t['propuesta']['nombre'] ?? '¿?') . '»')->join(', '))->success()->send();
    }

    /**
     * «Seguir: ¿ya están en el inventario?»: los confirmados pasan a buscarse
     * en el inventario; los que no, se olvidan, y con ellos sus partes y sus
     * otros ángulos.
     */
    public function seguirAlInventario(int $indice): void
    {
        $mensaje = $this->mensajes[$indice] ?? null;

        if (! $mensaje || ! ($mensaje['filtro'] ?? false)) {
            return;
        }

        $claves = $mensaje['propuestas'];
        $porRevisar = collect($claves)->filter(fn ($c) => ($this->propuestas[$c]['estado'] ?? null) === 'por_revisar');

        if ($porRevisar->contains(fn ($c) => ($this->propuestas[$c]['filtro'] ?? null) === null)) {
            return;
        }

        $olvidados = $porRevisar->filter(fn ($c) => $this->propuestas[$c]['filtro'] === 'no')->values()->all();

        foreach ($porRevisar as $c) {
            $this->propuestas[$c]['estado'] = $this->propuestas[$c]['filtro'] === 'si' ? 'pendiente' : 'olvidada';
        }

        // Las partes y los otros ángulos de lo olvidado se olvidan con él.
        foreach ($claves as $c) {
            $de = $this->propuestas[$c]['parte_de']['clave'] ?? $this->propuestas[$c]['repetida_de']['clave'] ?? null;

            if ($de && in_array($de, $olvidados, true)) {
                $this->propuestas[$c]['estado'] = 'olvidada';
            }
        }

        $quedan = collect($claves)->filter(fn ($c) => ($this->propuestas[$c]['estado'] ?? null) === 'pendiente')->count();
        $this->mensajes[$indice]['filtro'] = false;
        $this->mensajes[$indice]['texto'] .= ' ' . ($quedan === 0
            ? 'Ninguno era un objeto del inventario: no hay nada que registrar.'
            : 'Listo: ' . ($quedan === 1 ? '1 objeto' : $quedan . ' objetos') . '. Ahora, para cada uno, elige si ya está en el inventario o si es nuevo.');
    }

    /**
     * Lo que por su nombre es parte de una máquina (la consola, la torre de
     * luces, la mesa de trabajo) se junta solo con ella; se deshace con «No,
     * va aparte».
     *
     * @param  list<string>  $claves
     */
    private function juntarLasPartes(array $claves): void
    {
        foreach ($claves as $clave) {
            // Las partes por nombre (la consola, el soporte) y las que son una
            // sola pieza con otra (la bomba de vacío con su cámara, de la misma
            // marca y pegadas): las dos cuentan como un solo objeto desde el
            // filtro. Se separan con «Separar» si no lo son.
            $objeto = $this->propuestas[$clave]['objeto'] ?? [];

            if (($objeto['pieza_segura'] ?? false) || filled($objeto['pieza_de_clave'] ?? null)) {
                $this->esUnaPieza($clave);
            }
        }
    }

    /** «No, va aparte»: deshace «es una sola pieza». */
    public function noEsUnaPieza(string $clave): void
    {
        $p = $this->propuestas[$clave] ?? null;

        if (! $p || $p['estado'] !== 'parte') {
            return;
        }

        $principal = $p['parte_de']['clave'] ?? null;

        if ($principal && isset($this->propuestas[$principal])) {
            $this->propuestas[$principal]['otros_angulos'] = array_values(array_diff($this->propuestas[$principal]['otros_angulos'] ?? [], [$clave]));
        }

        // Si todavía se está revisando qué es un objeto, vuelve a esa lista.
        $enElFiltro = (bool) ($this->mensajes[$p['mensaje'] ?? -1]['filtro'] ?? false);

        $this->propuestas[$clave]['estado'] = $enElFiltro ? 'por_revisar' : 'pendiente';
        $this->propuestas[$clave]['filtro'] = null;
        $this->propuestas[$clave]['parte_de'] = null;
        $this->propuestas[$clave]['objeto']['pieza_de'] = null;
        $this->propuestas[$clave]['objeto']['pieza_de_clave'] = null;
    }

    /**
     * Lo que a la ficha le falta y en la foto se leyó: marca, modelo, la placa
     * («ACTIVO 54573»). Se ofrece, no se guarda solo.
     */
    private function queLeFalta(Asset $activo, array $propuesta): ?array
    {
        $placa = preg_match('/\bactivo\s*[:#]?\s*(\d{4,8})\b/iu', (string) ($propuesta['texto_visible'] ?? ''), $m) ? $m[1] : null;

        $datos = array_filter([
            'marca' => blank($activo->brand) ? ($propuesta['marca'] ?? null) : null,
            'modelo' => blank($activo->model) ? ($propuesta['modelo'] ?? null) : null,
            'placa' => blank($activo->asset_tag) ? $placa : null,
        ], fn ($v) => filled($v));

        return $datos ? ['id' => $activo->id, 'nombre' => $activo->name, 'datos' => $datos] : null;
    }

    /** «Guardar en la ficha»: lo que la persona dejó en los campos, solo en lo vacío. */
    public function completarFicha(string $clave): void
    {
        $r = $this->propuestas[$clave] ?? null;
        $completar = $r['completar'] ?? null;

        if (! $r || $r['estado'] !== 'vinculada' || ! $completar) {
            return;
        }

        $activo = Asset::find($completar['id']);

        if (! $activo) {
            $this->propuestas[$clave]['error'] = 'Ese activo ya no existe.';

            return;
        }

        try {
            app(AccionesDeInventario::class)->completarFicha($activo, $completar['datos'], $r['propuesta'] ?? [], auth()->user(), $r['consulta_id']);
        } catch (AccionRechazada $e) {
            $this->propuestas[$clave]['error'] = $e->getMessage();

            return;
        }

        $this->propuestas[$clave]['completar'] = null;
        $this->propuestas[$clave]['completada'] = 'Se guardó en la ficha de «' . $activo->name . '»: ' . collect($completar['datos'])->filter()->map(fn ($v, $k) => $k . ' ' . $v)->join(', ') . '.';
        $this->propuestas[$clave]['error'] = null;

        unset($this->historial);
    }

    /**
     * Un equipo es uno solo: si ya se dijo que este objeto es la «Fuente
     * voltaje 3», las demás tarjetas no la vuelven a ofrecer. Un insumo sí
     * puede repetirse —dos botes del mismo—, así que a esos no se les quita.
     */
    private function yaNoOfrecer(string $candidato): void
    {
        foreach ($this->propuestas as $k => $p) {
            if (($p['clase'] ?? null) !== 'reconocimiento' || $p['estado'] !== 'pendiente') {
                continue;
            }

            foreach (['por_foto', 'por_texto', 'por_busqueda'] as $lista) {
                $this->propuestas[$k][$lista] = array_values(array_filter($p[$lista] ?? [], fn ($c) => $c['clave'] !== $candidato));
            }

            // Si era el reconocido por foto, ya no lo es.
            if ($p['mismo'] && ($p['por_foto'][0]['clave'] ?? null) === $candidato) {
                $this->propuestas[$k]['mismo'] = false;
            }
        }
    }

    /**
     * «¿No está en la lista? Búscalo»: la búsqueda de siempre, dentro de la
     * tarjeta, para cuando las sugerencias no aciertan. Lo que se encuentra
     * pasa a poder elegirse con «Es este».
     */
    public function buscarEnInventario(string $clave): void
    {
        $r = $this->propuestas[$clave] ?? null;

        if (! $r || $r['clase'] !== 'reconocimiento' || $r['estado'] !== 'pendiente') {
            return;
        }

        $texto = trim((string) ($r['busqueda'] ?? ''));

        if (mb_strlen($texto) < 2) {
            $this->propuestas[$clave]['por_busqueda'] = [];

            return;
        }

        $buscador = app(\App\Services\Ia\Inventario\BuscadorDeInventario::class);

        $encontrados = collect($buscador->activos(['texto' => $texto, 'limite' => 6]))
            ->map(fn ($f) => ['clave' => 'activo:' . $f['id'], 'tipo' => 'activo', 'id' => $f['id'], 'nombre' => $f['nombre'], 'similitud' => null, 'razones' => [], 'ficha' => $f])
            ->merge(collect($buscador->insumos(['texto' => $texto, 'limite' => 4]))
                ->map(fn ($f) => ['clave' => 'insumo:' . $f['id'], 'tipo' => 'insumo', 'id' => $f['id'], 'nombre' => $f['nombre'], 'similitud' => null, 'razones' => [], 'ficha' => $f]))
            ->values()
            ->all();

        $this->propuestas[$clave]['por_busqueda'] = $encontrados;
        $this->propuestas[$clave]['busco'] = $texto;
    }

    /**
     * «Ya lo teníamos: sumar»: un insumo que ya está inventariado no se
     * registra otra vez; se suma a su existencia.
     *
     * Dos botes de la misma resina son una ficha con existencia 2, no dos
     * fichas iguales. Sirve desde la tarjeta de reconocimiento —cuando la IA
     * cree que ya existe— y desde el borrador de «Es nuevo», cuando aparece
     * como duplicado. Solo insumos: un activo es una unidad con su placa y su
     * historial, y dos equipos iguales son dos fichas.
     *
     * Queda como movimiento de entrada, con quién y por qué, y la foto se
     * guarda con él para reconocerlo después.
     */
    public function sumarAlExistente(string $clave, string $candidato): void
    {
        $p = $this->propuestas[$clave] ?? null;

        // Solo insumos: en el borrador de un activo, los parecidos son activos.
        if (! $p || $p['estado'] !== 'pendiente' || ! str_starts_with($candidato, 'insumo:')
            || ($p['clase'] !== 'reconocimiento' && ($p['tipo'] ?? null) !== 'insumo')) {
            return;
        }

        // Solo uno de los que se ofrecieron en esta tarjeta.
        $ofrecidos = $p['clase'] === 'reconocimiento'
            ? collect($p['por_busqueda'] ?? [])->merge($p['por_foto'])->merge($p['por_texto'])->pluck('clave')
            : collect($p['duplicados'] ?? [])->map(fn ($d) => 'insumo:' . $d['id']);

        if (! $ofrecidos->contains($candidato)) {
            return;
        }

        $insumo = Supply::find((int) str_replace('insumo:', '', $candidato));

        if (! $insumo) {
            $this->propuestas[$clave]['error'] = 'Ese insumo ya no existe.';

            return;
        }

        $cantidad = (float) str_replace(',', '.', (string) ($p['cantidad_a_sumar'] ?? ($p['datos']['cantidad'] ?? 1)));

        // Vacío o en cero no suma «1» en silencio: se pide la cantidad.
        if ($cantidad <= 0) {
            $this->propuestas[$clave]['error'] = 'Escribe cuántos se suman: tiene que ser más de 0.';

            return;
        }
        $acciones = app(AccionesDeInventario::class);
        $quien = auth()->user();

        try {
            $acciones->entradaDeInsumo(
                ['insumo_id' => $insumo->id, 'cantidad' => $cantidad, 'motivo' => 'Contado en una foto con Activo IA'],
                ['insumo_id' => $insumo->id, 'cantidad' => $cantidad],
                $quien,
                $p['consulta_id'],
            );

            // La foto queda con el insumo que ya existía, para reconocerlo la próxima vez.
            $claveDeFoto = $p['clase'] === 'reconocimiento' ? $clave : ($p['foto_clave'] ?? null);
            if ($claveDeFoto) {
                $acciones->guardarFotoDelNuevo($claveDeFoto, $insumo, $quien);
            }
        } catch (AccionRechazada $e) {
            $this->propuestas[$clave]['error'] = $e->getMessage();

            return;
        }

        $insumo->refresh();
        $existencia = rtrim(rtrim(number_format((float) $insumo->stock, 3, ',', '.'), '0'), ',');

        $this->propuestas[$clave]['estado'] = 'sumada';
        $this->guardarAngulos($p['clase'] === 'reconocimiento' ? $p : ($this->propuestas[$p['foto_clave'] ?? ''] ?? []), $insumo);
        $this->propuestas[$clave]['error'] = null;
        $this->propuestas[$clave]['resultado'] = [
            'nombre' => $insumo->name,
            'url' => $this->urlDe($insumo),
            'existencia' => $existencia . ' ' . $insumo->unit,
        ];

        unset($this->historial);
        $this->olvidarMedicion();

        Notification::make()->title('Sumado al inventario')->body('Ahora hay ' . $existencia . ' ' . $insumo->unit . ' de «' . $insumo->name . '».')->success()->send();
    }

    /** «Es nuevo»: lo que vio el servidor se vuelve un borrador de registro. */
    public function esNuevo(string $clave): void
    {
        $r = $this->propuestas[$clave] ?? null;

        if (! $r || $r['clase'] !== 'reconocimiento' || $r['estado'] !== 'pendiente') {
            return;
        }

        $borrador = $this->borradorDesde($r);

        $this->propuestas[$borrador['clave']] = $borrador;
        $this->propuestas[$clave]['estado'] = 'nuevo';

        // Justo debajo de su objeto, no al final de todos los de la foto.
        $lista = $this->mensajes[$r['mensaje']]['propuestas'];
        $donde = array_search($clave, $lista, true);
        array_splice($lista, $donde === false ? count($lista) : $donde + 1, 0, [$borrador['clave']]);
        $this->mensajes[$r['mensaje']]['propuestas'] = $lista;
    }

    /**
     * La tarjeta de registro, llena con lo que vio el servidor.
     *
     * La categoría que propone se busca entre nuestras áreas y categorías por
     * nombre; si no está, queda vacía. La serie y la placa no se adivinan: el
     * texto que se leyó en la foto se muestra para copiarlas de ahí.
     *
     * @param  array<string,mixed>  $r
     * @return array<string,mixed>
     */
    private function borradorDesde(array $r): array
    {
        // Una tarjeta agregada a mano no trae lo que vio la IA: toma el tipo de las demás de la foto.
        $p = $r['propuesta'] ?? ['tipo' => $r['tipo_sugerido'] ?? null];
        $catalogo = app(CatalogoDeInventario::class);
        $esInsumo = in_array($p['tipo'] ?? null, ['insumo', 'componente', 'producto'], true);
        $comparable = fn ($t) => \Illuminate\Support\Str::of((string) $t)->ascii()->lower()->squish()->value();

        $buscar = fn (array $opciones) => collect($opciones)
            ->search(fn ($nombre) => filled($p['categoria'] ?? null) && $comparable($nombre) === $comparable($p['categoria'])) ?: null;

        $datos = $esInsumo
            ? [
                'nombre' => $p['nombre'] ?? null, 'area_id' => $buscar($catalogo->areas()),
                'categoria_id' => $buscar($catalogo->categorias()), 'ubicacion_id' => null, 'sku' => null,
                'unidad' => null, 'cantidad' => null, 'descripcion' => $p['descripcion'] ?? null,
            ]
            : [
                'nombre' => $p['nombre'] ?? null,
                'tipo_de_activo' => ($p['tipo'] ?? null) === 'herramienta' ? 'herramienta' : 'fijo',
                'area_id' => $buscar($catalogo->areas()), 'espacio_id' => null, 'ubicacion_id' => null,
                'marca' => $p['marca'] ?? null, 'modelo' => $p['modelo'] ?? null, 'serie' => null, 'placa' => null,
                'descripcion' => $p['descripcion'] ?? null,
            ];

        $certeza = in_array($p['confianza'] ?? null, ['alta', 'media', 'baja'], true) ? $p['confianza'] : 'media';
        $evidencia = collect(['nombre', 'marca', 'modelo', 'area'])
            ->filter(fn ($c) => filled($datos[$c] ?? $datos[$c . '_id'] ?? null))
            ->map(fn ($c) => ['campo' => $c, 'certeza' => $certeza, 'de_donde' => 'lo vio el servidor del FabLab en la foto'])
            ->values()->all();

        $tipo = $esInsumo ? 'insumo' : 'activo';

        /*
         * Dónde va, según dónde están los que más se le parecen.
         *
         * Solo llena lo que quedó vacío —lo que se leyó en la foto manda— y
         * cada campo dice de dónde salió, para que se vea que es una
         * sugerencia y no un dato. La persona confirma o lo cambia.
         */
        $lugar = ($r['lugar']['clase'] ?? null) === $tipo ? $r['lugar']['campos'] : [];
        $sugeridos = [];

        foreach ($lugar as $campo => $s) {
            if (! array_key_exists($campo, $datos) || filled($datos[$campo])) {
                continue;
            }

            $datos[$campo] = $s['valor'];
            $sugeridos[] = $campo;

            $como = collect($s['como'])->take(2)->map(fn ($n) => '«' . trim($n) . '»')->join(' y ')
                . ($s['cuantos'] > 2 ? ' y ' . ($s['cuantos'] - 2) . ' más' : '');

            $evidencia[] = [
                'campo' => str_replace('_id', '', $campo),
                'certeza' => $s['cuantos'] >= 3 ? 'alta' : 'media',
                'de_donde' => 'como ' . $como . ', que ya están en el inventario',
            ];
        }

        $dondeVa = isset($lugar['ubicacion_id'])
            ? 'Lo guardaría con sus parecidos (' . $lugar['ubicacion_id']['cuantos'] . ' de ' . $lugar['ubicacion_id']['de']
                . ' están ahí): ' . $lugar['ubicacion_id']['etiqueta'] . '.'
            : null;

        return [
            'clave' => 'n' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8)),
            'clase' => 'registro',
            'tipo' => $tipo,
            'datos' => $datos,
            'original' => $datos,
            'confianza' => $certeza,
            'razon' => filled($p['texto_visible'] ?? null) ? 'Texto que se lee en la foto: «' . $p['texto_visible'] . '». Copia de ahí la serie o la placa si están.' : '',
            'donde_va' => $dondeVa,
            'evidencia' => $evidencia,
            'no_se_pudo_leer' => $esInsumo ? [] : ['número de serie y placa: escríbelos si se ven'],
            'preguntas' => $esInsumo
                ? [in_array('unidad', $sugeridos, true) ? '¿Cuántos hay? La unidad la tomé de los parecidos: revísala.' : '¿En qué unidad se cuenta y cuántos hay?']
                : [],
            'avisos' => blank($datos['area_id']) && ! $esInsumo ? ['Elige el área: la categoría que propuso no coincide con ninguna.'] : [],
            'duplicados' => app(\App\Services\Ia\Inventario\BuscadorDeInventario::class)->posiblesDuplicados($tipo, (string) ($datos['nombre'] ?? ''), $datos),
            'estado' => 'pendiente',
            'consulta_id' => $r['consulta_id'],
            'confirmo_distinto' => false,
            'foto_clave' => $r['clave'],
            'resultado' => null,
            'error' => null,
        ];
    }

    public function quitarFoto(?int $cual = null): void
    {
        if ($cual === null) {
            $this->reset('foto');

            return;
        }

        unset($this->fotos[$cual]);
        $this->fotos = array_values($this->fotos);
    }

    public function nuevaConversacion(): void
    {
        $asistente = app(AsistenteDeInventario::class);
        $asistente->olvidar($this->conversacion);

        $this->conversacion = $asistente->nuevaConversacion();
        $this->reset('mensajes', 'propuestas', 'texto', 'foto', 'fotos');
    }

    /** La persona revisó la tarjeta y la confirma: ahora sí se escribe. */
    public function confirmar(string $clave): void
    {
        $p = $this->propuestas[$clave] ?? null;

        // Solo lo que se confirma: registros, traslados y entradas. Una tarjeta
        // de reconocimiento se resuelve con «Es este», «sumar» o «Es nuevo».
        if (! $p || $p['estado'] !== 'pendiente' || ! in_array($p['clase'] ?? null, ['registro', 'traslado', 'entrada'], true)) {
            return;
        }

        $acciones = app(AccionesDeInventario::class);
        $quien = auth()->user();

        try {
            $accion = match (true) {
                $p['clase'] === 'registro' && $p['tipo'] === 'activo' => $acciones->crearActivo($p['datos'], $p['original'], $quien, $p['consulta_id'], (bool) $p['confirmo_distinto']),
                $p['clase'] === 'registro' => $acciones->crearInsumo($p['datos'], $p['original'], $quien, $p['consulta_id'], (bool) $p['confirmo_distinto']),
                $p['clase'] === 'traslado' => $acciones->trasladarActivo($p['datos'], $p['original'], $quien, $p['consulta_id']),
                $p['clase'] === 'entrada' => $acciones->entradaDeInsumo($p['datos'], $p['original'], $quien, $p['consulta_id']),
            };
        } catch (AccionRechazada $e) {
            $this->propuestas[$clave]['error'] = $e->getMessage();

            return;
        }

        $registro = $accion->registro;

        // Registrado desde una foto: la foto queda con él, para reconocerlo después.
        if ($p['clase'] === 'registro' && filled($p['foto_clave'] ?? null) && $registro) {
            $acciones->guardarFotoDelNuevo($p['foto_clave'], $registro, $quien);
            // Y las de otros ángulos del mismo objeto, si vino en varias fotos.
            $this->guardarAngulos($this->propuestas[$p['foto_clave']] ?? [], $registro);
        }

        $this->propuestas[$clave]['estado'] = 'guardada';
        $this->propuestas[$clave]['error'] = null;
        $this->propuestas[$clave]['resultado'] = [
            'nombre' => $registro?->name,
            'url' => $this->urlDe($registro),
        ];

        unset($this->historial);
        $this->olvidarMedicion();

        Notification::make()
            ->title(AccionDeInventarioIa::ACCIONES[$accion->accion] ?? 'Guardado')
            ->body($registro?->name)
            ->success()
            ->send();
    }

    public function descartar(string $clave): void
    {
        if (isset($this->propuestas[$clave]) && $this->propuestas[$clave]['estado'] === 'pendiente') {
            $this->propuestas[$clave]['estado'] = 'descartada';

            // Si era el borrador de «Es nuevo», su objeto vuelve a quedar por
            // decidir: si no, se quedaba en «revisa el registro de abajo» sin
            // ningún botón.
            $objeto = $this->propuestas[$clave]['foto_clave'] ?? null;
            if ($objeto && ($this->propuestas[$objeto]['estado'] ?? null) === 'nuevo') {
                $this->propuestas[$objeto]['estado'] = 'pendiente';
            }
        }
    }

    public function urlDe(mixed $registro): ?string
    {
        return match (true) {
            $registro instanceof Asset => AssetResource::getUrl('edit', ['record' => $registro]),
            $registro instanceof Supply => SupplyResource::getUrl('edit', ['record' => $registro]),
            default => null,
        };
    }

    #[Computed]
    public function estado(): array
    {
        $asistente = app(AsistenteDeInventario::class);
        $servidor = app(ServidorDeIaDelFablab::class);

        return [
            // Con cualquiera de los dos ya se puede trabajar.
            'disponible' => $asistente->disponible() || $servidor->configurado(),
            'claude' => $asistente->disponible(),
            'fotos' => $servidor->configurado(),
            'fotos_responde' => $servidor->responde(),
            'porQue' => $asistente->porQueNoEsta(),
            'quedan' => $asistente->quedanHoy(),
            'tope' => (int) config('fabos.asistente_inventario.max_por_dia'),
            'modelo' => config('fabos.asistente_inventario.modelo'),
        ];
    }

    /** @return array{crear_activo:bool,crear_insumo:bool,trasladar_activo:bool,entrada_insumo:bool} */
    #[Computed]
    public function permisos(): array
    {
        return app(AccionesDeInventario::class)->permisosDe(auth()->user());
    }

    /** @return array<string,mixed> */
    #[Computed]
    public function catalogo(): array
    {
        $c = app(CatalogoDeInventario::class);

        return [
            'areas' => $c->areas(),
            'espacios' => $c->espacios(),
            'ubicaciones' => $c->ubicacionesPorEspacio(),
            'categorias' => $c->categorias(),
            'unidades' => $c->unidades(),
            'tipos' => Asset::TIPOS,
        ];
    }

    /** Las últimas confirmaciones, con quién y qué cambió. */
    #[Computed]
    public function historial(): \Illuminate\Support\Collection
    {
        return AccionDeInventarioIa::query()
            ->with(['user', 'registro'])
            ->latest('id')
            ->limit(20)
            ->get();
    }

    /**
     * Cuánto acierta, medido con lo que pasó de verdad (§11 de la propuesta).
     *
     * Las propuestas que se confirmaron sin tocar nada son la medida honesta
     * del trabajo que ahorra; los campos que más se corrigen dicen dónde se
     * equivoca.
     *
     * @return array<string,mixed>
     */
    // Un minuto en caché: antes se recalculaba en cada clic, leyendo todas
    // las consultas del mes. Tras confirmar algo se borra y se recalcula.
    //
    // Solo datos simples en la caché, nunca objetos: aquí la caché vive en la
    // base de datos y Laravel no reconstruye objetos desde ahí (una colección
    // volvía «incompleta» y la página daba error 500).
    #[Computed]
    public function medicion(): array
    {
        return \Illuminate\Support\Facades\Cache::remember(self::CLAVE_DE_MEDICION, 60, fn () => $this->calcularMedicion());
    }

    private const CLAVE_DE_MEDICION = 'activo-ia:medicion:v2';

    private function olvidarMedicion(): void
    {
        \Illuminate\Support\Facades\Cache::forget(self::CLAVE_DE_MEDICION);
        unset($this->medicion);
    }

    private function calcularMedicion(): array
    {
        $desde = now()->subDays(30);

        $consultas = ConsultaDeInventarioIa::query()->where('created_at', '>=', $desde);
        $acciones = AccionDeInventarioIa::query()->where('created_at', '>=', $desde)->get();

        $propuestas = (clone $consultas)->get()->sum(fn ($c) => count($c->propuestas ?? []));
        $sinCorregir = $acciones->filter(fn ($a) => empty($a->corregidos))->count();

        $masCorregidos = $acciones->flatMap(fn ($a) => $a->corregidos ?? [])
            ->countBy()
            ->sortDesc()
            ->take(4);

        return [
            'consultas' => (clone $consultas)->count(),
            'fallidas' => (clone $consultas)->where('estado', 'fallida')->count(),
            'con_foto' => (clone $consultas)->whereNotNull('foto_path')->count(),
            'propuestas' => $propuestas,
            'confirmadas' => $acciones->count(),
            'sin_corregir' => $sinCorregir,
            'porcentaje_sin_corregir' => $acciones->count() > 0 ? (int) round(100 * $sinCorregir / $acciones->count()) : null,
            'mas_corregidos' => $masCorregidos->all(),
            'duplicados' => (clone $consultas)->sum('duplicados_detectados'),
            'tokens' => (int) (clone $consultas)->sum('tokens_entrada') + (int) (clone $consultas)->sum('tokens_salida'),
            // Los que nunca aparecen en una foto son candidatos a revisar o dar de baja.
            'activos' => Asset::count(),
            'activos_sin_foto' => Asset::query()->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fotos_de_inventario')
                ->whereColumn('fotos_de_inventario.registro_id', 'assets.id')
                ->where('fotos_de_inventario.registro_type', Asset::class))->count(),
        ];
    }
}
