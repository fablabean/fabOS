<?php

namespace App\Services\Ia\Inventario;

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Supplies\SupplyResource;
use App\Models\AccionDeInventarioIa;
use App\Models\Area;
use App\Models\Asset;
use App\Models\Location;
use App\Models\Space;
use App\Models\Supply;
use App\Models\SupplyCategory;
use App\Models\User;
use App\Services\Inventory\StockService;
use App\Support\Secciones;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Lo único que escribe en el inventario desde Activo IA.
 *
 * Solo se llega aquí cuando una persona pulsa «Confirmar» en una propuesta.
 * La IA interpreta; esto valida con reglas fijas —permisos, que el área
 * exista, que la placa no esté repetida— y solo entonces guarda, dejando en
 * el historial quién, cuándo, el valor de antes y el de después.
 *
 * Nada de lo que propuso el modelo se cree por haberlo propuesto: cada número
 * de área, de espacio o de insumo se vuelve a buscar en la base.
 */
class AccionesDeInventario
{
    /** Los campos que puede llevar cada propuesta, y nada más. */
    public const CAMPOS_ACTIVO = ['nombre', 'tipo_de_activo', 'area_id', 'espacio_id', 'ubicacion_id', 'marca', 'modelo', 'serie', 'placa', 'descripcion'];

    public const CAMPOS_INSUMO = ['nombre', 'area_id', 'categoria_id', 'ubicacion_id', 'sku', 'unidad', 'cantidad', 'descripcion'];

    public function __construct(
        private BuscadorDeInventario $buscador,
        private StockService $existencias,
        private ReconocedorDeFotos $reconocedor,
    ) {}

    /**
     * «Es este»: la foto queda como ejemplo de un registro que ya existía.
     *
     * No cambia ningún dato del activo; le suma una foto con su huella, y la
     * próxima foto de ese objeto se reconoce sola. Aun así queda en el
     * historial: se eligió un registro, y elegir mal también hay que poder
     * verlo.
     */
    public function vincularFoto(Asset|Supply $registro, string $claveDeFoto, ?float $similitud, User $quien, ?int $consultaId): AccionDeInventarioIa
    {
        $this->exigir($quien, 'editar', $registro instanceof Asset ? AssetResource::class : SupplyResource::class, 'agregar fotos a este registro');

        $antes = \App\Models\FotoDeInventario::where('registro_type', $registro::class)->where('registro_id', $registro->getKey())->count();

        $pendiente = $this->reconocedor->huellaDe($claveDeFoto);

        if (! $pendiente) {
            throw new AccionRechazada('La foto ya no está disponible (pasaron muchas horas). Vuelve a enviarla.');
        }

        return DB::transaction(function () use ($registro, $claveDeFoto, $similitud, $quien, $consultaId, $antes, $pendiente) {
            // De un artículo casi tapado se anota cuál era, pero su recorte no
            // se guarda como foto para reconocerlo.
            $guardo = (bool) $this->reconocedor->guardarFoto($claveDeFoto, $registro, $quien);

            return $this->anotar(
                'vincular_foto', $registro,
                ['fotos' => $antes],
                [
                    'fotos' => $antes + ($guardo ? 1 : 0),
                    'similitud' => $similitud !== null ? round($similitud, 3) : null,
                    'sin_foto' => ! $guardo && ($pendiente['sin_referencia'] ?? false) ? 'estaba casi tapado' : null,
                ],
                [], $quien, $consultaId, [],
            );
        });
    }

    /** Después de registrar algo nuevo desde una foto, la foto queda con él. */
    public function guardarFotoDelNuevo(?string $claveDeFoto, Asset|Supply $registro, User $quien): void
    {
        if ($claveDeFoto) {
            $this->reconocedor->guardarFoto($claveDeFoto, $registro, $quien);
        }
    }

    /**
     * Registra un activo nuevo.
     *
     * Nace operativo y **sin reservar**: si se reserva, cuánto dura una sesión
     * y quién lo asesora son decisiones de la coordinación, no algo que se
     * deduzca de una foto. Se completan después en su ficha.
     *
     * @param  array<string,mixed>  $datos  lo que quedó en la tarjeta, ya corregido
     * @param  array<string,mixed>  $propuesta  lo que propuso la IA, tal cual
     */
    public function crearActivo(array $datos, array $propuesta, User $quien, ?int $consultaId, bool $confirmoQueEsOtro = false): AccionDeInventarioIa
    {
        $this->exigir($quien, 'crear', AssetResource::class, 'registrar activos');

        $datos = $this->limpiar($datos, self::CAMPOS_ACTIVO);

        if (blank($datos['nombre'])) {
            throw new AccionRechazada('Falta el nombre del activo.');
        }

        if (! $datos['area_id'] || ! Area::whereKey($datos['area_id'])->exists()) {
            throw new AccionRechazada('Elige el área del activo: es obligatoria.');
        }

        $tipo = $datos['tipo_de_activo'] ?: 'fijo';

        if (! isset(Asset::TIPOS[$tipo])) {
            throw new AccionRechazada('El tipo de activo no es válido.');
        }

        $this->comprobarLugar($datos['espacio_id'], $datos['ubicacion_id']);

        if (filled($datos['placa']) && Asset::withTrashed()->whereRaw('lower(asset_tag) = ?', [Str::lower($datos['placa'])])->exists()) {
            throw new AccionRechazada('Ya hay un activo con la placa «' . $datos['placa'] . '». Una placa es de un solo equipo.');
        }

        $this->exigirRevisionDeDuplicados('activo', $datos, $confirmoQueEsOtro);

        return DB::transaction(function () use ($datos, $propuesta, $quien, $consultaId, $tipo) {
            $activo = Asset::create([
                'name' => $datos['nombre'],
                'kind' => $tipo,
                'area_id' => $datos['area_id'],
                'space_id' => $datos['espacio_id'],
                'location_id' => $datos['ubicacion_id'],
                'brand' => $datos['marca'],
                'model' => $datos['modelo'],
                'serial' => $datos['serie'],
                'asset_tag' => $datos['placa'],
                'public_description' => $datos['descripcion'],
                'status' => 'operativo',
                'is_reservable' => false,
            ]);

            return $this->anotar('crear_activo', $activo, null, $datos, $propuesta, $quien, $consultaId, self::CAMPOS_ACTIVO);
        });
    }

    /**
     * Registra un insumo nuevo.
     *
     * Si se dijo cuántos hay, entran como **movimiento de entrada**, igual
     * que al crearlo a mano: la existencia nunca se escribe sin rastro.
     *
     * @param  array<string,mixed>  $datos
     * @param  array<string,mixed>  $propuesta
     */
    public function crearInsumo(array $datos, array $propuesta, User $quien, ?int $consultaId, bool $confirmoQueEsOtro = false): AccionDeInventarioIa
    {
        $this->exigir($quien, 'crear', SupplyResource::class, 'registrar insumos');

        $datos = $this->limpiar($datos, self::CAMPOS_INSUMO);

        if (blank($datos['nombre'])) {
            throw new AccionRechazada('Falta el nombre del insumo.');
        }

        if (blank($datos['unidad'])) {
            throw new AccionRechazada('Falta la unidad de medida (unidad, caja, g, m…): sin ella la existencia no significa nada.');
        }

        if ($datos['area_id'] && ! Area::whereKey($datos['area_id'])->exists()) {
            throw new AccionRechazada('El área elegida no existe.');
        }

        if ($datos['categoria_id'] && ! SupplyCategory::whereKey($datos['categoria_id'])->exists()) {
            throw new AccionRechazada('La categoría elegida no existe.');
        }

        $this->comprobarLugar(null, $datos['ubicacion_id']);

        if (filled($datos['sku']) && Supply::whereRaw('lower(sku) = ?', [Str::lower($datos['sku'])])->exists()) {
            throw new AccionRechazada('Ya hay un insumo con el código «' . $datos['sku'] . '».');
        }

        $cantidad = (float) ($datos['cantidad'] ?? 0);

        if ($cantidad < 0) {
            throw new AccionRechazada('La cantidad inicial no puede ser negativa.');
        }

        $this->exigirRevisionDeDuplicados('insumo', $datos, $confirmoQueEsOtro);

        return DB::transaction(function () use ($datos, $propuesta, $quien, $consultaId, $cantidad) {
            $insumo = Supply::create([
                'name' => $datos['nombre'],
                'kind' => 'insumo',
                'area_id' => $datos['area_id'],
                'category_id' => $datos['categoria_id'],
                'location_id' => $datos['ubicacion_id'],
                'sku' => $datos['sku'],
                'unit' => $datos['unidad'],
                'description' => $datos['descripcion'],
                'is_active' => true,
            ]);

            if ($cantidad > 0) {
                $this->existencias->entrada($insumo, $cantidad, 'Existencia inicial, registrada con Activo IA', $insumo, $quien);
            }

            return $this->anotar('crear_insumo', $insumo, null, $datos, $propuesta, $quien, $consultaId, self::CAMPOS_INSUMO);
        });
    }

    /**
     * Cambia de sala o de mueble a un activo.
     *
     * @param  array<string,mixed>  $datos  activo_id, espacio_id, ubicacion_id, motivo
     * @param  array<string,mixed>  $propuesta
     */
    public function trasladarActivo(array $datos, array $propuesta, User $quien, ?int $consultaId): AccionDeInventarioIa
    {
        $this->exigir($quien, 'editar', AssetResource::class, 'editar activos');

        $datos = $this->limpiar($datos, ['activo_id', 'espacio_id', 'ubicacion_id', 'motivo']);
        $activo = Asset::find($datos['activo_id']);

        if (! $activo) {
            throw new AccionRechazada('El activo ya no existe.');
        }

        if (! $datos['espacio_id'] && ! $datos['ubicacion_id']) {
            throw new AccionRechazada('Elige a qué espacio o ubicación se traslada.');
        }

        // Lo que está en un estante de vencidos se queda ahí: desde aquí se
        // puede guardar en él, pero no sacarle nada.
        if ($activo->location_id && app(CatalogoDeInventario::class)->esDeDescarte((int) $activo->location_id)) {
            throw new AccionRechazada('Este equipo está en un estante de vencidos, y de ahí no se saca nada desde Activo IA. Si de verdad hay que moverlo, hazlo desde su ficha.');
        }

        // Si solo se dio el mueble, la sala es la del mueble.
        $espacioId = $datos['espacio_id'] ?: Location::find($datos['ubicacion_id'])?->espacio()?->id ?? $activo->space_id;

        $this->comprobarLugar($espacioId, $datos['ubicacion_id']);

        $antes = $this->lugarDe($activo);

        if ((int) $activo->space_id === (int) $espacioId && (int) $activo->location_id === (int) $datos['ubicacion_id']) {
            throw new AccionRechazada('El activo ya está ahí: no hay nada que trasladar.');
        }

        return DB::transaction(function () use ($activo, $espacioId, $datos, $antes, $propuesta, $quien, $consultaId) {
            $activo->update([
                'space_id' => $espacioId,
                'location_id' => $datos['ubicacion_id'],
            ]);

            $despues = $this->lugarDe($activo->fresh(['space', 'location'])) + ['motivo' => $datos['motivo']];

            return $this->anotar('trasladar_activo', $activo, $antes, $despues, $propuesta, $quien, $consultaId, null, $datos);
        });
    }

    /**
     * Completa la ficha de un activo con la marca, el modelo o la placa que
     * se leyeron en su foto, cuando la persona lo confirma. Solo llena lo que
     * está vacío: nunca cambia un dato que ya tenía la ficha.
     *
     * Las fuentes y los osciloscopios estaban como «Osciloscopio 1» a «5»,
     * sin marca ni modelo: la foto decía DS2202A y no había con qué
     * compararlo. Con el modelo en la ficha, la próxima foto va directo.
     *
     * @param  array{marca?:?string,modelo?:?string,placa?:?string}  $datos
     */
    public function completarFicha(Asset $activo, array $datos, array $propuesta, User $quien, ?int $consultaId): AccionDeInventarioIa
    {
        $this->exigir($quien, 'editar', AssetResource::class, 'editar activos');

        $columnas = ['marca' => 'brand', 'modelo' => 'model', 'placa' => 'asset_tag'];
        $nuevos = collect($columnas)
            ->filter(fn ($columna, $campo) => filled($datos[$campo] ?? null) && blank($activo->{$columna}))
            ->mapWithKeys(fn ($columna, $campo) => [$columna => Str::limit(trim((string) $datos[$campo]), 120, '')])
            ->all();

        if ($nuevos === []) {
            throw new AccionRechazada('La ficha ya tiene esos datos: no hay nada que completar.');
        }

        // Como al registrar: también los dados de baja, y sin importar mayúsculas
        // (el índice único los cuenta, y si no, la base fallaba con un error 500).
        if (isset($nuevos['asset_tag']) && Asset::withTrashed()->whereRaw('lower(asset_tag) = ?', [Str::lower($nuevos['asset_tag'])])->whereKeyNot($activo->id)->exists()) {
            throw new AccionRechazada('La placa ' . $nuevos['asset_tag'] . ' ya la tiene otro activo: revisa cuál es antes de guardarla.');
        }

        $antes = collect($nuevos)->map(fn ($v, $columna) => $activo->{$columna})->all();

        return DB::transaction(function () use ($activo, $nuevos, $antes, $propuesta, $quien, $consultaId) {
            $activo->update($nuevos);

            return $this->anotar('completar_ficha', $activo, $antes, $nuevos, $propuesta, $quien, $consultaId, null);
        });
    }

    /**
     * Suma existencias a un insumo que ya existe, como movimiento de entrada.
     *
     * @param  array<string,mixed>  $datos  insumo_id, cantidad, motivo
     * @param  array<string,mixed>  $propuesta
     */
    public function entradaDeInsumo(array $datos, array $propuesta, User $quien, ?int $consultaId): AccionDeInventarioIa
    {
        $this->exigir($quien, 'editar', SupplyResource::class, 'mover existencias');

        $datos = $this->limpiar($datos, ['insumo_id', 'cantidad', 'motivo']);
        $insumo = Supply::find($datos['insumo_id']);

        if (! $insumo) {
            throw new AccionRechazada('El insumo ya no existe.');
        }

        $cantidad = (float) $datos['cantidad'];

        if ($cantidad <= 0) {
            throw new AccionRechazada('La cantidad de la entrada tiene que ser mayor que cero.');
        }

        if (blank($datos['motivo'])) {
            throw new AccionRechazada('Escribe de dónde sale la entrada (compra, donación, conteo…): queda en el movimiento.');
        }

        return DB::transaction(function () use ($insumo, $cantidad, $datos, $propuesta, $quien, $consultaId) {
            $antes = ['existencia' => (float) $insumo->stock, 'unidad' => $insumo->unit];

            $this->existencias->entrada($insumo, $cantidad, $datos['motivo'] . ' (Activo IA)', null, $quien);

            $despues = [
                'existencia' => (float) $insumo->fresh()->stock,
                'unidad' => $insumo->unit,
                'cantidad' => $cantidad,
                'motivo' => $datos['motivo'],
            ];

            return $this->anotar('entrada_insumo', $insumo, $antes, $despues, $propuesta, $quien, $consultaId, null, $datos);
        });
    }

    /**
     * Qué puede hacer esta persona con el asistente, para enseñar solo los
     * botones que van a funcionar.
     *
     * @return array{crear_activo:bool,crear_insumo:bool,trasladar_activo:bool,entrada_insumo:bool}
     */
    public function permisosDe(User $quien): array
    {
        return [
            'crear_activo' => $quien->puedeEnLaSeccion('crear', Secciones::claveDe(AssetResource::class)),
            'crear_insumo' => $quien->puedeEnLaSeccion('crear', Secciones::claveDe(SupplyResource::class)),
            'trasladar_activo' => $quien->puedeEnLaSeccion('editar', Secciones::claveDe(AssetResource::class)),
            'entrada_insumo' => $quien->puedeEnLaSeccion('editar', Secciones::claveDe(SupplyResource::class)),
        ];
    }

    private function exigir(User $quien, string $accion, string $recurso, string $que): void
    {
        if (! $quien->puedeEnLaSeccion($accion, Secciones::claveDe($recurso))) {
            throw new AccionRechazada('Tu rol no permite ' . $que . '. Pídeselo a quien administra los accesos.');
        }
    }

    /**
     * Si hay una coincidencia fuerte, no se crea otro sin que lo digan.
     *
     * Misma serie o misma placa casi siempre es el mismo equipo fotografiado
     * otra vez. Crear un segundo registro es el error que más cuesta deshacer
     * después, así que se pide una confirmación explícita.
     *
     * @param  array<string,mixed>  $datos
     */
    private function exigirRevisionDeDuplicados(string $tipo, array $datos, bool $confirmoQueEsOtro): void
    {
        $fuertes = collect($this->buscador->posiblesDuplicados($tipo, (string) $datos['nombre'], $datos))
            ->where('fuerte', true);

        if ($fuertes->isNotEmpty() && ! $confirmoQueEsOtro) {
            throw new AccionRechazada(
                'Parece que ya existe: ' . $fuertes->pluck('nombre')->join(', ')
                . '. Revísalo y marca «Es otro, no un duplicado» si de verdad es distinto.'
            );
        }
    }

    private function comprobarLugar(?int $espacioId, ?int $ubicacionId): void
    {
        if ($espacioId && ! Space::whereKey($espacioId)->exists()) {
            throw new AccionRechazada('El espacio elegido no existe.');
        }

        if (! $ubicacionId) {
            return;
        }

        $mueble = Location::find($ubicacionId);

        if (! $mueble) {
            throw new AccionRechazada('La ubicación elegida no existe.');
        }

        $suSala = $mueble->espacio();

        if ($espacioId && $suSala && $suSala->id !== $espacioId) {
            throw new AccionRechazada('La ubicación «' . (app(CatalogoDeInventario::class)->rutas()[$mueble->id] ?? $mueble->name) . '» está en otro espacio (' . $suSala->name . ').');
        }
    }

    /** @return array{espacio:?string,ubicacion:?string} */
    private function lugarDe(Asset $activo): array
    {
        return [
            'espacio' => $activo->space?->name,
            'ubicacion' => $activo->location_id ? (app(CatalogoDeInventario::class)->rutas()[$activo->location_id] ?? $activo->location?->name) : null,
        ];
    }

    /**
     * Solo los campos conocidos, recortados, y los números como números.
     *
     * @param  array<string,mixed>  $datos
     * @param  list<string>  $campos
     * @return array<string,mixed>
     */
    private function limpiar(array $datos, array $campos): array
    {
        $limpios = [];

        foreach ($campos as $campo) {
            $valor = $datos[$campo] ?? null;

            if (is_string($valor)) {
                $valor = trim($valor);
                $valor = $valor === '' ? null : Str::limit($valor, 2000, '');
            }

            if (str_ends_with($campo, '_id')) {
                $valor = filled($valor) ? (int) $valor : null;
            }

            if ($campo === 'cantidad') {
                $valor = filled($valor) ? (float) str_replace(',', '.', (string) $valor) : null;
            }

            $limpios[$campo] = $valor;
        }

        return $limpios;
    }

    /**
     * El historial: una fila por confirmación.
     *
     * @param  array<string,mixed>|null  $antes
     * @param  array<string,mixed>  $despues
     * @param  array<string,mixed>  $propuesta
     * @param  list<string>|null  $campos  los campos a comparar contra lo propuesto
     * @param  array<string,mixed>|null  $confirmado  lo confirmado, si no es `$despues`
     */
    private function anotar(
        string $accion,
        Asset|Supply $registro,
        ?array $antes,
        array $despues,
        array $propuesta,
        User $quien,
        ?int $consultaId,
        ?array $campos,
        ?array $confirmado = null,
    ): AccionDeInventarioIa {
        $confirmado ??= $despues;
        $campos ??= array_keys($confirmado);

        // Qué cambió la persona respecto a lo que propuso la IA.
        $corregidos = collect($campos)
            ->filter(fn ($c) => array_key_exists($c, $confirmado))
            ->filter(fn ($c) => (string) ($propuesta[$c] ?? '') !== (string) ($confirmado[$c] ?? ''))
            ->values()
            ->all();

        return AccionDeInventarioIa::create([
            'consulta_id' => $consultaId,
            'user_id' => $quien->id,
            'accion' => $accion,
            'registro_type' => $registro::class,
            'registro_id' => $registro->getKey(),
            'antes' => $antes,
            'despues' => $despues,
            'propuesta' => $propuesta,
            'corregidos' => $corregidos,
        ]);
    }
}
