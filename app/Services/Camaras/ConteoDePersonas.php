<?php

namespace App\Services\Camaras;

use App\Services\Vision\Pgx;
use App\Support\Camaras;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Cuenta personas distintas, sin saber quién es cada una.
 *
 * El PGX dice cuántas personas hay en un cuadro y da la huella de cada rostro.
 * Aquí se decide a qué persona anónima pertenece: se compara con todas las
 * huellas guardadas de cada una (la similitud es el producto punto) y desde
 * `umbral_misma_persona` (0,363) es la misma. Si no se parece a ninguna, es
 * una persona nueva.
 *
 * Una persona anónima junta varias huellas —de frente, de lado, de cada
 * cámara— y quien revisa puede unir dos fotos que son la misma persona: sus
 * huellas pasan a ser de una sola, y la próxima vez se la reconoce mejor. Solo
 * lleva un número, nunca un nombre ni un documento. Quien no aparece en
 * `dias_de_personas` se borra con sus huellas y sus fotos (dato sensible,
 * Ley 1581).
 *
 * Las personas sin rostro fiable (de espaldas, lejos, borrosas) no se pueden
 * distinguir entre sí: no entran a «distintas», y del día solo se guarda
 * cuántas hubo a la vez como máximo.
 */
class ConteoDePersonas
{
    /** Dónde viven los recortes: una carpeta por persona. */
    public const CARPETA = 'camaras/personas';

    /** Cuántas huellas junta sola una persona (al unir pueden quedar más). */
    public const HUELLAS_POR_PERSONA = 12;

    /** Una huella así de parecida a una que ya tiene no aporta nada nuevo. */
    public const REDUNDANTE = 0.8;

    /**
     * Para ignorar algo por parecerse a una huella descartada se pide más
     * parecido que para reconocer a una persona: mejor contar una mano de más
     * que dejar de contar a alguien.
     */
    public const DESCARTADA = 0.45;

    /**
     * Registra un cuadro ya analizado por el PGX.
     *
     * @return array{personas:int,identificadas:int,sin_identificar:int,nuevas:int}
     */
    public function registrar(int $camara, array $respuesta, CarbonInterface $ahora, ?string $foto = null): array
    {
        $fecha = $ahora->copy()->setTimezone(config('fabos.lab.timezone'))->toDateString();
        // La base guarda la hora tal cual, sin zona: siempre en la de la aplicación.
        $ahora = $ahora->copy()->setTimezone(config('app.timezone'));
        $umbral = (float) ($respuesta['umbral_misma_persona'] ?? 0.363);

        // Cada rostro con la caja de su persona, que es lo que se recorta: con
        // la ropa se reconoce mejor que con la cara sola.
        $rostros = [];

        foreach ($respuesta['detalle'] ?? [] as $d) {
            if (! empty($d['rostro'])) {
                $rostros[] = $d['rostro'] + ['caja_persona' => $d['caja'] ?? null, 'suelto' => false];
            }
        }

        foreach ($respuesta['rostros_sin_persona'] ?? [] as $r) {
            $rostros[] = $r + ['caja_persona' => null, 'suelto' => true];
        }

        // Los rostros sueltos también son personas (alguien cortado por el borde).
        $personas = (int) ($respuesta['personas'] ?? 0) + count($respuesta['rostros_sin_persona'] ?? []);

        // Cada cámara cuenta en su propio proceso: de a una, para que dos no
        // creen la misma persona nueva en el mismo segundo.
        return Cache::lock('conteo-de-personas', 20)->block(20, fn () => DB::transaction(function () use ($camara, $rostros, $personas, $fecha, $umbral, $ahora, $foto) {
            $conocidas = DB::table('huellas_de_persona')->get(['persona_id', 'huella'])
                ->map(fn ($h) => ['persona' => (int) $h->persona_id, 'huella' => json_decode($h->huella, true)])
                ->all();

            $descartadas = DB::table('huellas_descartadas')->get(['id', 'huella'])
                ->map(fn ($h) => ['persona' => (int) $h->id, 'huella' => json_decode($h->huella, true)])
                ->all();

            $identificadas = 0;
            $nuevas = 0;
            $enEsteCuadro = [];

            foreach ($rostros as $rostro) {
                if (! $this->esFiable($rostro)) {
                    continue;
                }

                // Algo que ya se marcó como «no es una persona»: no se cuenta.
                [$descartada, $parecido] = $this->masParecida($rostro['huella'], $descartadas, []);

                if ($descartada !== null && $parecido >= max($umbral, self::DESCARTADA)) {
                    DB::table('huellas_descartadas')->where('id', $descartada)->update(['ultima_vez' => $ahora]);

                    if ($rostro['suelto']) {
                        $personas--;
                    }

                    continue;
                }

                $identificadas++;
                [$persona, $similitud] = $this->masParecida($rostro['huella'], $conocidas, $enEsteCuadro);

                if ($persona === null || $similitud < $umbral) {
                    $persona = DB::table('personas_anonimas')->insertGetId([
                        'primera_vez' => $ahora, 'ultima_vez' => $ahora, 'created_at' => $ahora, 'updated_at' => $ahora,
                    ]);
                    $this->guardarHuella($persona, $rostro, $camara, $ahora, $foto);
                    $conocidas[] = ['persona' => $persona, 'huella' => $rostro['huella']];
                } else {
                    DB::table('personas_anonimas')->where('id', $persona)->update(['ultima_vez' => $ahora, 'updated_at' => $ahora]);
                    $tiene = count(array_filter($conocidas, fn ($c) => $c['persona'] === $persona));

                    // Otro ángulo de la misma persona: se guarda, y la próxima
                    // vez se la reconoce también así.
                    if ($similitud < self::REDUNDANTE && $tiene < self::HUELLAS_POR_PERSONA) {
                        $this->guardarHuella($persona, $rostro, $camara, $ahora, $foto);
                        $conocidas[] = ['persona' => $persona, 'huella' => $rostro['huella']];
                    }
                }

                $enEsteCuadro[] = $persona;

                if ($this->anotarVisita($persona, $fecha, $rostro, $camara, $ahora, $foto)) {
                    $nuevas++;
                }
            }

            $sinIdentificar = max($personas - $identificadas, 0);
            $lectura = ['personas' => $personas, 'identificadas' => $identificadas,
                'sin_identificar' => $sinIdentificar, 'nuevas' => $nuevas];

            DB::table('lecturas_de_aforo')->insert(['camara' => $camara, 'leido_at' => $ahora] + $lectura);
            $this->resumir($fecha, $personas, $sinIdentificar, $ahora);

            return $lectura;
        }));
    }

    /**
     * Une varias personas anónimas en una: la que se vio primero se queda con
     * las huellas y las visitas de las demás. Si dos venían el mismo día, esa
     * visita se junta en una (desde la más temprana hasta la más tarde, con la
     * mejor foto). Devuelve con qué persona quedaron.
     *
     * @param  list<int>  $ids
     */
    public function unir(array $ids): ?int
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (count($ids) < 2) {
            return null;
        }

        return Cache::lock('conteo-de-personas', 20)->block(20, fn () => DB::transaction(function () use ($ids) {
            $todas = DB::table('personas_anonimas')->whereIn('id', $ids)->orderBy('primera_vez')->orderBy('id')->get();

            if ($todas->count() < 2) {
                return $todas->first()?->id;
            }

            $queda = $todas->first();
            $fechas = [];

            foreach ($todas->slice(1) as $otra) {
                DB::table('huellas_de_persona')->where('persona_id', $otra->id)->update(['persona_id' => $queda->id]);

                foreach (DB::table('visitas_del_dia')->where('persona_id', $otra->id)->get() as $v) {
                    $fechas[] = substr((string) $v->fecha, 0, 10);
                    $suya = DB::table('visitas_del_dia')->where('persona_id', $queda->id)->where('fecha', $v->fecha)->first();

                    if (! $suya) {
                        DB::table('visitas_del_dia')->where('id', $v->id)->update(['persona_id' => $queda->id]);

                        continue;
                    }

                    $mejor = $v->ancho_rostro > $suya->ancho_rostro;

                    DB::table('visitas_del_dia')->where('id', $v->id)->delete();
                    DB::table('visitas_del_dia')->where('id', $suya->id)->update([
                        'primera_vez' => min($suya->primera_vez, $v->primera_vez),
                        'ultima_vez' => max($suya->ultima_vez, $v->ultima_vez),
                        'veces' => $suya->veces + $v->veces,
                    ] + ($mejor ? ['foto' => $v->foto, 'ancho_rostro' => $v->ancho_rostro, 'camara' => $v->camara] : []));

                    // La foto que no quedó se va.
                    $sobra = $mejor ? $suya->foto : $v->foto;

                    if ($sobra) {
                        $this->borrarFotos([$sobra]);
                    }
                }

                DB::table('personas_anonimas')->where('id', $otra->id)->delete();
            }

            DB::table('personas_anonimas')->where('id', $queda->id)->update([
                'ultima_vez' => $todas->max('ultima_vez'), 'updated_at' => now(),
            ]);

            // Unir baja el total de personas distintas de esos días.
            foreach (array_unique($fechas) as $fecha) {
                DB::table('aforo_diario')->where('fecha', $fecha)->update([
                    'personas_distintas' => DB::table('visitas_del_dia')->where('fecha', $fecha)->count(),
                ]);
            }

            return (int) $queda->id;
        }));
    }

    /**
     * «No es una persona»: se borra con sus visitas, huellas y fotos, y baja
     * el total de esos días. Sus huellas quedan como descartadas (solo el
     * número, sin foto), para que lo mismo no se vuelva a contar.
     */
    public function descartarPersona(int $persona): bool
    {
        return Cache::lock('conteo-de-personas', 20)->block(20, fn () => DB::transaction(function () use ($persona) {
            if (! DB::table('personas_anonimas')->where('id', $persona)->lockForUpdate()->exists()) {
                return false;
            }

            $huellas = DB::table('huellas_de_persona')->where('persona_id', $persona)->get(['huella', 'foto']);
            $visitas = DB::table('visitas_del_dia')->where('persona_id', $persona)->get(['fecha', 'foto']);

            DB::table('huellas_descartadas')->insert($huellas->map(fn ($h) => [
                'huella' => $h->huella, 'ultima_vez' => now(), 'created_at' => now(), 'updated_at' => now(),
            ])->all());

            // Las huellas y visitas se van con la persona.
            DB::table('personas_anonimas')->where('id', $persona)->delete();
            $this->borrarFotos($huellas->pluck('foto')->merge($visitas->pluck('foto'))->all());

            foreach ($visitas->pluck('fecha')->map(fn ($f) => substr((string) $f, 0, 10))->unique() as $fecha) {
                DB::table('aforo_diario')->where('fecha', $fecha)->update([
                    'personas_distintas' => DB::table('visitas_del_dia')->where('fecha', $fecha)->count(),
                ]);
            }

            return true;
        }));
    }

    /**
     * Quita fotos que no son de esa persona: huellas mal asignadas (con sus
     * recortes) y, si se pide, la foto del día de esa visita, que pasa a ser
     * la mejor de las huellas que le quedan. Si la persona tiene huellas, le
     * tiene que quedar al menos una: sin ninguna ya no se la reconocería.
     *
     * @param  list<int>  $huellas
     * @return int cuántas fotos se quitaron (0 si no se pudo)
     */
    public function quitarFotos(int $visita, array $huellas, bool $fotoDelDia = false): int
    {
        return Cache::lock('conteo-de-personas', 20)->block(20, fn () => DB::transaction(function () use ($visita, $huellas, $fotoDelDia) {
            [$visita, $huellas] = $this->fotosDeUnaVisita($visita, $huellas, $fotoDelDia) ?? [null, null];

            if ($visita === null) {
                return 0;
            }

            DB::table('huellas_de_persona')->whereIn('id', $huellas->pluck('id'))->delete();

            // Si la foto del día era el recorte de una huella que se fue, también se cambia.
            $fotoDelDia = $fotoDelDia || $huellas->pluck('foto')->contains($visita->foto);

            if ($fotoDelDia) {
                $this->rehacerFotoDelDia($visita);
            }

            $this->borrarFotos($huellas->pluck('foto')->all());

            return $huellas->count() + ($fotoDelDia ? 1 : 0);
        }));
    }

    /**
     * «Son otra persona»: las huellas elegidas (y la foto del día, si se
     * marcó) pasan juntas a una persona nueva, con su visita en cada día en
     * que se la vio, y el total de esos días sube. A la de antes le quedan
     * las demás huellas (al menos una, si tenía).
     *
     * @param  list<int>  $huellas
     * @return int|null la persona nueva, o null si no se pudo
     */
    public function separarFotos(int $visita, array $huellas, bool $fotoDelDia = false): ?int
    {
        return Cache::lock('conteo-de-personas', 20)->block(20, fn () => DB::transaction(function () use ($visita, $huellas, $fotoDelDia) {
            [$visita, $huellas] = $this->fotosDeUnaVisita($visita, $huellas, $fotoDelDia) ?? [null, null];

            if ($visita === null) {
                return null;
            }

            $tz = config('fabos.lab.timezone');
            $fechaDeLaVisita = substr((string) $visita->fecha, 0, 10);
            $fotoDelDia = $fotoDelDia || $huellas->pluck('foto')->contains($visita->foto);

            $nueva = DB::table('personas_anonimas')->insertGetId([
                'primera_vez' => $huellas->min('vista_at') ?? $visita->ultima_vez,
                'ultima_vez' => $huellas->max('vista_at') ?? $visita->ultima_vez,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('huellas_de_persona')->whereIn('id', $huellas->pluck('id'))->update(['persona_id' => $nueva]);

            $porDia = $huellas->groupBy(fn ($h) => \Illuminate\Support\Carbon::parse($h->vista_at, config('app.timezone'))->setTimezone($tz)->toDateString());

            if ($fotoDelDia && ! $porDia->has($fechaDeLaVisita)) {
                $porDia[$fechaDeLaVisita] = collect();
            }

            foreach ($porDia as $fecha => $delDia) {
                $mejor = $delDia->whereNotNull('foto')->sortByDesc('ancho_rostro')->first();
                // La foto del día marcada se va con ella; si no, la de su mejor huella (el mismo archivo).
                $suFoto = $fotoDelDia && $fecha === $fechaDeLaVisita
                    ? ['foto' => $visita->foto, 'ancho_rostro' => (int) $visita->ancho_rostro, 'camara' => $visita->camara]
                    : ['foto' => $mejor?->foto, 'ancho_rostro' => $mejor ? (int) $mejor->ancho_rostro : 0, 'camara' => ($mejor ?? $delDia->first())->camara];

                DB::table('visitas_del_dia')->insert([
                    'persona_id' => $nueva, 'fecha' => $fecha,
                    'primera_vez' => $delDia->min('vista_at') ?? $visita->ultima_vez,
                    'ultima_vez' => $delDia->max('vista_at') ?? $visita->ultima_vez,
                    'veces' => max(1, $delDia->count()),
                ] + $suFoto);

                DB::table('aforo_diario')->where('fecha', $fecha)->update([
                    'personas_distintas' => DB::table('visitas_del_dia')->where('fecha', $fecha)->count(),
                ]);

                // La foto del día de la de antes podía ser de la que se fue:
                // pasa a ser la mejor de las huellas que le quedan de ese día.
                $suya = DB::table('visitas_del_dia')->where('persona_id', $visita->persona_id)->where('fecha', $fecha)->first();

                if ($suya) {
                    $this->rehacerFotoDelDia($suya, soloSiHayOtra: ! ($fotoDelDia && $fecha === $fechaDeLaVisita));
                }
            }

            return $nueva;
        }));
    }

    /**
     * La visita y las huellas pedidas, si las huellas son de la persona de
     * esa visita, hay algo que mover y, si la persona tiene huellas, le queda
     * al menos una. Si no, null.
     *
     * @param  list<int>  $ids
     * @return array{0:object,1:\Illuminate\Support\Collection}|null
     */
    private function fotosDeUnaVisita(int $visita, array $ids, bool $fotoDelDia): ?array
    {
        $visita = DB::table('visitas_del_dia')->lockForUpdate()->find($visita);
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if (! $visita || ($ids === [] && ! ($fotoDelDia && $visita->foto))) {
            return null;
        }

        $huellas = DB::table('huellas_de_persona')->whereIn('id', $ids)->where('persona_id', $visita->persona_id)->lockForUpdate()->get();
        $tiene = DB::table('huellas_de_persona')->where('persona_id', $visita->persona_id)->count();

        if ($huellas->count() !== count($ids) || ($tiene > 0 && $tiene - count($ids) < 1)) {
            return null;
        }

        return [$visita, $huellas];
    }

    /**
     * La foto del día de una visita pasa a ser la mejor de las huellas de
     * esa persona en ese día (o ninguna). Con $soloSiHayOtra, no se toca si
     * no hay con qué reemplazarla.
     */
    private function rehacerFotoDelDia(object $visita, bool $soloSiHayOtra = false): void
    {
        $tz = config('fabos.lab.timezone');
        $fecha = substr((string) $visita->fecha, 0, 10);
        $mejor = DB::table('huellas_de_persona')->where('persona_id', $visita->persona_id)->whereNotNull('foto')
            ->whereBetween('vista_at', [
                \Illuminate\Support\Carbon::parse($fecha, $tz)->startOfDay()->setTimezone(config('app.timezone')),
                \Illuminate\Support\Carbon::parse($fecha, $tz)->endOfDay()->setTimezone(config('app.timezone')),
            ])->orderByDesc('ancho_rostro')->first();

        if ((! $mejor && $soloSiHayOtra) || ($mejor && $mejor->foto === $visita->foto)) {
            return;
        }

        DB::table('visitas_del_dia')->where('id', $visita->id)->update($mejor
            ? ['foto' => $mejor->foto, 'ancho_rostro' => (int) $mejor->ancho_rostro, 'camara' => $mejor->camara]
            : ['foto' => null, 'ancho_rostro' => 0]);
        $this->borrarFotos([$visita->foto]);
    }

    /**
     * Toma un cuadro de la cámara, se lo pasa al PGX y lo registra.
     *
     * Deja en caché lo que ve ahora la cámara, para la pantalla. Si la cámara
     * o el PGX fallan, se anota el error y se lanza la excepción.
     *
     * @return array{personas:int,identificadas:int,sin_identificar:int,nuevas:int}
     */
    public function fotografiar(int $camara): array
    {
        try {
            $foto = Camaras::fotoParaContar($camara);

            if ($foto === null) {
                throw new RuntimeException('La cámara no responde.');
            }

            $lectura = $this->registrar($camara, app(Pgx::class)->personas($foto), now(), $foto);
        } catch (Throwable $e) {
            Cache::put("camara.{$camara}.conteo_error", $e->getMessage(), now()->addMinutes(10));

            throw $e;
        }

        $this->anotarLoQueVe($camara, $lectura);

        return $lectura;
    }

    /** Lo que ve ahora la cámara, para «Ahora en cámara». */
    public function anotarLoQueVe(int $camara, array $lectura): void
    {
        Cache::put("camara.{$camara}.conteo", $lectura + ['at' => now()->toIso8601String()], now()->addMinutes(10));
        Cache::forget("camara.{$camara}.conteo_error");
    }

    /**
     * De noche: se olvida a quien no ha venido en `dias_de_personas`, con sus
     * huellas y sus fotos, y se borran las visitas de hace más de esos días.
     * Los totales de cada día se quedan.
     */
    public function cerrarDiasPasados(CarbonInterface $ahora): int
    {
        $dias = (int) config('fabos.conteo.dias_de_personas');
        $limite = $ahora->copy()->setTimezone(config('app.timezone'))->subDays($dias);
        $fechaLimite = $ahora->copy()->setTimezone(config('fabos.lab.timezone'))->subDays($dias)->toDateString();

        DB::table('lecturas_de_aforo')
            ->where('leido_at', '<', $ahora->copy()->subDays(config('fabos.conteo.dias_de_lecturas')))
            ->delete();

        $viejas = DB::table('personas_anonimas')->where('ultima_vez', '<', $limite)->pluck('id')->all();
        DB::table('huellas_descartadas')->where('ultima_vez', '<', $limite)->delete();

        $fotos = DB::table('huellas_de_persona')->whereIn('persona_id', $viejas)->whereNotNull('foto')->pluck('foto')
            ->merge(DB::table('visitas_del_dia')->whereNotNull('foto')
                ->where(fn ($q) => $q->whereIn('persona_id', $viejas)->orWhere('fecha', '<', $fechaLimite))
                ->pluck('foto'))
            ->unique()->values()->all();

        DB::table('visitas_del_dia')->where('fecha', '<', $fechaLimite)->delete();
        DB::table('personas_anonimas')->whereIn('id', $viejas)->delete();
        $this->borrarFotos($fotos);

        // Las carpetas que quedaron vacías.
        foreach (Storage::disk('local')->directories(self::CARPETA) as $carpeta) {
            if (Storage::disk('local')->allFiles($carpeta) === []) {
                Storage::disk('local')->deleteDirectory($carpeta);
            }
        }

        return count($viejas);
    }

    /**
     * Borra los recortes que ya nadie usa. Una misma foto puede ser de una
     * huella y de una visita (las que venían del conteo anterior), así que
     * antes de borrar se mira que ninguna de las dos la siga usando.
     *
     * @param  list<string>  $rutas
     */
    private function borrarFotos(array $rutas): void
    {
        $rutas = array_values(array_unique(array_filter($rutas)));

        if ($rutas === []) {
            return;
        }

        $enUso = DB::table('huellas_de_persona')->whereIn('foto', $rutas)->pluck('foto')
            ->merge(DB::table('visitas_del_dia')->whereIn('foto', $rutas)->pluck('foto'))->all();

        Storage::disk('local')->delete(array_values(array_diff($rutas, $enUso)));
    }

    private function guardarHuella(int $persona, array $rostro, int $camara, CarbonInterface $ahora, ?string $foto): void
    {
        $id = DB::table('huellas_de_persona')->insertGetId([
            'persona_id' => $persona,
            'huella' => json_encode(array_map('floatval', $rostro['huella'])),
            'ancho_rostro' => (int) ($rostro['ancho_px'] ?? 0),
            'camara' => $camara,
            'vista_at' => $ahora,
        ]);

        $ruta = self::CARPETA."/{$persona}/h{$id}.jpg";

        if ($foto !== null && $this->recortar($foto, $rostro, $ruta)) {
            DB::table('huellas_de_persona')->where('id', $id)->update(['foto' => $ruta]);
        }
    }

    /**
     * La visita del día de esa persona: se crea la primera vez que se la ve
     * en el día (y entonces cuenta como nueva del día), y si su cara sale más
     * grande que en la foto del día, la foto se cambia por la mejor.
     */
    private function anotarVisita(int $persona, string $fecha, array $rostro, int $camara, CarbonInterface $ahora, ?string $foto): bool
    {
        $visita = DB::table('visitas_del_dia')->where('persona_id', $persona)->where('fecha', $fecha)->first();
        $ancho = (int) ($rostro['ancho_px'] ?? 0);
        $ruta = self::CARPETA."/{$persona}/{$fecha}.jpg";

        if (! $visita) {
            $conFoto = $foto !== null && $this->recortar($foto, $rostro, $ruta);

            DB::table('visitas_del_dia')->insert([
                'persona_id' => $persona, 'fecha' => $fecha, 'primera_vez' => $ahora, 'ultima_vez' => $ahora,
                'foto' => $conFoto ? $ruta : null, 'ancho_rostro' => $conFoto ? $ancho : 0, 'camara' => $camara,
            ]);

            return true;
        }

        $cambios = ['ultima_vez' => $ahora, 'veces' => DB::raw('veces + 1')];

        if ($foto !== null && $ancho > $visita->ancho_rostro && $this->recortar($foto, $rostro, $ruta)) {
            $cambios += ['foto' => $ruta, 'ancho_rostro' => $ancho, 'camara' => $camara];
        }

        DB::table('visitas_del_dia')->where('id', $visita->id)->update($cambios);

        return false;
    }

    /**
     * La persona recortada del cuadro, con un poco de margen.
     *
     * La caja de la persona entera si la hay; si no, la cara agrandada.
     * Se reduce a 480 px de alto: es para reconocerla, no para ampliarla.
     */
    private function recortar(string $foto, array $rostro, string $ruta): bool
    {
        $imagen = @imagecreatefromstring($foto);

        if (! $imagen) {
            return false;
        }

        [$w, $h] = [imagesx($imagen), imagesy($imagen)];

        if ($c = $rostro['caja_persona'] ?? null) {
            $m = 0.08;
            [$x, $y, $an, $al] = [$c['x'] - $c['ancho'] * $m, $c['y'] - $c['alto'] * $m, $c['ancho'] * (1 + 2 * $m), $c['alto'] * (1 + 2 * $m)];
        } elseif ($c = $rostro['caja'] ?? null) {
            [$x, $y, $an, $al] = [$c['x'] - $c['ancho'], $c['y'] - $c['alto'] * 0.6, $c['ancho'] * 3, $c['alto'] * 3];
        } else {
            imagedestroy($imagen);

            return false;
        }

        $x = (int) max(0, $x);
        $y = (int) max(0, $y);
        $an = (int) min($an, $w - $x);
        $al = (int) min($al, $h - $y);

        if ($an < 8 || $al < 8) {
            imagedestroy($imagen);

            return false;
        }

        $recorte = imagecrop($imagen, ['x' => $x, 'y' => $y, 'width' => $an, 'height' => $al]);
        imagedestroy($imagen);

        if ($al > 480) {
            $chico = imagescale($recorte, (int) round($an * 480 / $al), 480);
            imagedestroy($recorte);
            $recorte = $chico;
        }

        ob_start();
        imagejpeg($recorte, null, 82);
        imagedestroy($recorte);

        return Storage::disk('local')->put($ruta, ob_get_clean());
    }

    private function esFiable(array $rostro): bool
    {
        return ! empty($rostro['huella'])
            && ($rostro['ancho_px'] ?? 0) >= config('fabos.conteo.ancho_minimo')
            && ($rostro['nitidez'] ?? 0) >= config('fabos.conteo.nitidez_minima');
    }

    /**
     * La persona más parecida: la dueña de la huella más parecida, sin
     * repetir una que ya se asignó en este mismo cuadro (dos rostros en la
     * misma foto son dos personas).
     *
     * @param  list<array{persona:int,huella:array}>  $conocidas
     * @return array{0:?int,1:float}
     */
    private function masParecida(array $huella, array $conocidas, array $excluidas): array
    {
        $mejor = null;
        $similitud = -1.0;

        foreach ($conocidas as $c) {
            if (in_array($c['persona'], $excluidas, true)) {
                continue;
            }

            $s = self::similitud($huella, $c['huella']);

            if ($s > $similitud) {
                [$mejor, $similitud] = [$c['persona'], $s];
            }
        }

        return [$mejor, $similitud];
    }

    /** Producto punto: las huellas ya vienen con norma 1. */
    public static function similitud(array $a, array $b): float
    {
        $s = 0.0;

        foreach ($a as $i => $x) {
            $s += $x * ($b[$i] ?? 0);
        }

        return $s;
    }

    private function resumir(string $fecha, int $personas, int $sinIdentificar, CarbonInterface $ahora): void
    {
        $distintas = DB::table('visitas_del_dia')->where('fecha', $fecha)->count();
        $dia = DB::table('aforo_diario')->where('fecha', $fecha)->first();

        if (! $dia) {
            DB::table('aforo_diario')->insert([
                'fecha' => $fecha, 'personas_distintas' => $distintas,
                'maximo_a_la_vez' => $personas, 'maximo_at' => $personas > 0 ? $ahora : null,
                'maximo_sin_identificar' => $sinIdentificar, 'lecturas' => 1,
                'created_at' => $ahora, 'updated_at' => $ahora,
            ]);

            return;
        }

        $cambios = [
            'personas_distintas' => $distintas,
            'maximo_sin_identificar' => max($sinIdentificar, $dia->maximo_sin_identificar),
            'lecturas' => $dia->lecturas + 1,
            'updated_at' => $ahora,
        ];

        if ($personas > $dia->maximo_a_la_vez) {
            $cambios += ['maximo_a_la_vez' => $personas, 'maximo_at' => $ahora];
        }

        DB::table('aforo_diario')->where('fecha', $fecha)->update($cambios);
    }
}
