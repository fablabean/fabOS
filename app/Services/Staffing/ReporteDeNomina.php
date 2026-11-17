<?php

namespace App\Services\Staffing;

use App\Models\CorteDeNomina;
use App\Models\ReporteDeExtras;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Models\WorkSchedule;
use App\Models\NotificationLog;
use App\Services\Booking\BookingException;
use App\Services\Notifications\NotificationService;
use App\Support\ConfiguracionDeNomina;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * El reporte de horas extras que cada quien saca para nómina (§5).
 *
 * Cada mes hay un corte (entre el 14 y el 17, lo fija nómina). Entra en el
 * reporte lo que ya se hizo hasta el corte y todavía no se ha reportado: una
 * jornada programada para después no entra aunque ya esté en el calendario, y
 * una hecha que no alcanzó a reportarse sigue pendiente para el siguiente
 * corte. Al descargar el formato, sus jornadas pasan a «reportadas» y quedan
 * en el historial con el archivo tal como salió.
 *
 * Una jornada va en un solo reporte. Se bloquea al generar para que dos
 * descargas a la vez no la lleven dos veces.
 */
class ReporteDeNomina
{
    public function __construct(private FormatoAdf404 $formato, private NotificationService $avisos) {}

    /** Lo que entraría hoy en el reporte de esta persona. */
    public function porReportar(User $persona, ?Carbon $corte = null): Collection
    {
        $corte ??= CorteDeNomina::vigente();

        return ShiftAssignment::query()
            ->where('user_id', $persona->id)
            ->pendientesDeReporte()
            ->where('starts_at', '<=', $corte->copy()->endOfDay()->utc())
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * Minutos por estado, de toda la historia de la persona.
     *
     * @return array<string,int>
     */
    public function saldos(User $persona): array
    {
        $saldos = array_fill_keys(array_keys(ShiftAssignment::ESTADOS), 0);

        ShiftAssignment::query()->where('user_id', $persona->id)->get()
            ->each(function (ShiftAssignment $j) use (&$saldos) {
                $saldos[$j->estado()] += $j->minutos();
            });

        return $saldos;
    }

    /**
     * Cómo va cada jornada al formato: la fecha, el horario laboral de ese
     * día, el horario extra y las actividades.
     *
     * El horario laboral sale de su jornada semanal; un sábado, domingo o
     * festivo sin jornada va de 00:00 a 00:00, como pide la nota 2 del
     * formato.
     *
     * @return array{id:int,fecha:string,laboral_inicio:string,laboral_fin:string,extra_inicio:string,extra_fin:string,actividades:string}
     */
    public function fila(ShiftAssignment $j): array
    {
        $tz = config('fabos.lab.timezone');
        $desde = $j->starts_at->copy()->setTimezone($tz);
        $hasta = $j->ends_at->copy()->setTimezone($tz);
        [$ini, $fin] = $this->horarioLaboral($j->user_id, $desde);

        return [
            'id'             => $j->id,
            'fecha'          => $desde->toDateString(),
            'laboral_inicio' => $ini,
            'laboral_fin'    => $fin,
            'extra_inicio'   => $desde->format('H:i'),
            'extra_fin'      => $hasta->format('H:i'),
            'actividades'    => (string) ($j->actividades ?: $j->reason),
        ];
    }

    /** @return array{0:string,1:string} */
    public function horarioLaboral(int $userId, Carbon $dia): array
    {
        if (FormatoAdf404::esFestivo($dia)) {
            return ['00:00', '00:00'];
        }

        $turnos = WorkSchedule::query()
            ->where('user_id', $userId)
            ->where('weekday', $dia->dayOfWeekIso)
            ->vigenteEn($dia)
            ->get()
            // Una que sale antes de entrar —10:00 a 00:00— no es una jornada:
            // el formulario no la deja guardar, y las que hay vienen de cuando
            // el selector de hora corría cinco horas lo que se escribía.
            ->filter(fn (WorkSchedule $w) => substr((string) $w->ends_at, 0, 5) > substr((string) $w->starts_at, 0, 5));

        if ($turnos->isEmpty()) {
            return ['00:00', '00:00'];
        }

        return [
            substr((string) $turnos->min('starts_at'), 0, 5),
            substr((string) $turnos->max('ends_at'), 0, 5),
        ];
    }

    /**
     * Genera el reporte con lo pendiente hasta el corte, lo guarda y deja sus
     * jornadas como reportadas.
     *
     * Queda esperando firmas: primero la de quien lo generó, después la de
     * quien coordina, que firma como jefe inmediato.
     *
     * @param  array{documento?:?string,tipo_contrato?:?string,area?:?string,coordinador_id?:int|string|null}  $encabezado
     * @param  array<int,array{laboral_inicio?:string,laboral_fin?:string,actividades?:string}>  $cambios  por id de jornada
     *
     * @throws BookingException si no hay nada que reportar
     */
    public function generar(User $persona, array $encabezado, array $cambios = [], ?User $por = null): ReporteDeExtras
    {
        $corte = CorteDeNomina::vigente();
        $coordinador = $this->coordinadorValido($persona, $encabezado['coordinador_id'] ?? null);

        return DB::transaction(function () use ($persona, $encabezado, $cambios, $por, $corte, $coordinador) {
            $jornadas = ShiftAssignment::query()
                ->where('user_id', $persona->id)
                ->pendientesDeReporte()
                ->where('starts_at', '<=', $corte->copy()->endOfDay()->utc())
                ->orderBy('starts_at')
                ->limit(FormatoAdf404::FILAS)
                ->lockForUpdate()
                ->get();

            if ($jornadas->isEmpty()) {
                throw new BookingException('No tienes horas extras pendientes de reportar hasta el corte del ' . $corte->format('d/m/Y') . '.');
            }

            $filas = $jornadas->map(function (ShiftAssignment $j) use ($cambios) {
                $f = $this->fila($j);
                $c = $cambios[$j->id] ?? [];
                foreach (['laboral_inicio', 'laboral_fin'] as $campo) {
                    if (isset($c[$campo]) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $c[$campo])) {
                        $f[$campo] = $c[$campo];
                    }
                }
                if (isset($c['actividades']) && trim((string) $c['actividades']) !== '') {
                    $f['actividades'] = trim((string) $c['actividades']);
                }

                return $f;
            })->values()->all();

            $datos = [
                'nombre'        => $persona->name,
                'documento'     => trim((string) ($encabezado['documento'] ?? $persona->document_number)),
                'tipo_contrato' => $encabezado['tipo_contrato'] ?? $persona->tipo_contrato,
                'area'          => trim((string) ($encabezado['area'] ?? $persona->area_de_nomina)),
                'jefe'          => $coordinador->name,
            ];

            $totales = $this->totales($jornadas);

            $reporte = ReporteDeExtras::create([
                'user_id'      => $persona->id,
                'corte'        => $corte->toDateString(),
                'origen'       => ReporteDeExtras::FABOS,
                'minutos'      => $jornadas->sum(fn (ShiftAssignment $j) => $j->minutos()),
                'datos'        => ['encabezado' => $datos, 'filas' => $filas, 'jornadas' => $jornadas->pluck('id')->all()],
                'generado_por' => $por?->id ?? $persona->id,
                'coordinador_id'    => $coordinador->id,
                'estado'            => 'por_firmar',
                'trabajador_token'  => ReporteDeExtras::nuevoToken(),
                'coordinador_token' => ReporteDeExtras::nuevoToken(),
            ] + $totales);

            $temporal = $this->formato->guardar($datos, $filas);
            $ruta = 'nomina/' . $persona->id . '/reporte-' . $reporte->id . '.xlsx';
            Storage::disk('local')->put($ruta, file_get_contents($temporal));
            @unlink($temporal);
            $reporte->update(['archivo_path' => $ruta]);

            foreach ($filas as $f) {
                ShiftAssignment::whereKey($f['id'])->update(['reporte_id' => $reporte->id, 'actividades' => $f['actividades']]);
            }

            // Lo que escribió para el formato queda para la próxima vez.
            $persona->forceFill(array_filter([
                'document_number' => $datos['documento'] ?: null,
                'tipo_contrato'   => in_array($datos['tipo_contrato'], ['fijo', 'indefinido'], true) ? $datos['tipo_contrato'] : null,
                'area_de_nomina'  => $datos['area'] ?: null,
                'jefe_inmediato'  => $coordinador->name,
            ]))->save();

            return $reporte->refresh();
        });
    }

    /**
     * Jornadas que ya se reportaron a nómina por fuera de fabOS —antes de que
     * existiera esto—: quedan reportadas, sin archivo, para que no salgan
     * pendientes ni se vuelvan a cobrar.
     *
     * @param  list<int>  $ids
     */
    public function marcarPorFuera(User $persona, array $ids, ?User $por = null): ?ReporteDeExtras
    {
        return DB::transaction(function () use ($persona, $ids, $por) {
            $jornadas = ShiftAssignment::query()
                ->where('user_id', $persona->id)
                ->whereKey($ids)
                ->pendientesDeReporte()
                ->lockForUpdate()
                ->orderBy('starts_at')
                ->get();

            if ($jornadas->isEmpty()) {
                return null;
            }

            $reporte = ReporteDeExtras::create([
                'user_id'      => $persona->id,
                'corte'        => $jornadas->last()->starts_at->copy()->setTimezone(config('fabos.lab.timezone'))->toDateString(),
                'origen'       => ReporteDeExtras::POR_FUERA,
                'minutos'      => $jornadas->sum(fn (ShiftAssignment $j) => $j->minutos()),
                'datos'        => ['jornadas' => $jornadas->pluck('id')->all(), 'filas' => $jornadas->map(fn ($j) => $this->fila($j))->all()],
                'generado_por' => $por?->id ?? $persona->id,
            ] + $this->totales($jornadas));

            ShiftAssignment::whereKey($jornadas->pluck('id'))->update(['reporte_id' => $reporte->id]);

            return $reporte;
        });
    }

    /**
     * Un reporte de antes de las firmas, que se descargó sin ellas: queda
     * esperando firmas, con quien coordina como jefe inmediato. Las mismas
     * jornadas y los mismos datos; solo cambia el nombre del jefe.
     *
     * @throws BookingException
     */
    public function pedirFirmas(ReporteDeExtras $reporte, int|string|null $coordinadorId = null): ReporteDeExtras
    {
        if ($reporte->estaAnulado() || $reporte->origen !== ReporteDeExtras::FABOS || $reporte->estado !== 'generado') {
            throw new BookingException('Este reporte no está para firmar.');
        }

        $coordinador = $this->coordinadorValido($reporte->user, $coordinadorId ?: $reporte->coordinador_id);

        $datos = $reporte->datos;
        $datos['encabezado']['jefe'] = $coordinador->name;

        $reporte->forceFill([
            'datos'             => $datos,
            'coordinador_id'    => $coordinador->id,
            'estado'            => 'por_firmar',
            'trabajador_token'  => ReporteDeExtras::nuevoToken(),
            'coordinador_token' => ReporteDeExtras::nuevoToken(),
        ])->save();

        $this->rehacerArchivo($reporte);

        return $reporte->refresh();
    }

    /**
     * Guarda la firma dibujada de una de las partes.
     *
     * Cuando firma quien lo generó, le llega el enlace a quien coordina; con
     * las dos firmas, el Excel se rehace con ellas y queda listo para nómina.
     *
     * @throws BookingException si el dibujo no vale o el reporte ya no admite firmas
     */
    public function firmar(ReporteDeExtras $reporte, string $parte, string $dataUrl, ?string $ip = null): ReporteDeExtras
    {
        if ($reporte->estaAnulado()) {
            throw new BookingException('Este reporte fue anulado.');
        }

        if ($reporte->estado !== 'por_firmar') {
            throw new BookingException('Este reporte ya no está esperando firmas.');
        }

        if ($reporte->firmo($parte)) {
            throw new BookingException('Ya habías firmado este reporte.');
        }

        if ($parte === 'coordinador' && ! $reporte->firmo('trabajador')) {
            throw new BookingException('Primero lo firma ' . $reporte->user->name . '.');
        }

        $binario = $this->pngDe($dataUrl);

        DB::transaction(function () use ($reporte, $parte, $binario, $ip) {
            $ruta = 'nomina/firmas/reporte-' . $reporte->id . '-' . $parte . '-' . \Illuminate\Support\Str::random(24) . '.png';
            Storage::disk('local')->put($ruta, $binario);

            $reporte->forceFill([
                "{$parte}_firma_path" => $ruta,
                "{$parte}_firmo_at"   => now(),
                "{$parte}_firmo_ip"   => $ip,
            ]);

            if ($reporte->estaFirmadoPorTodos()) {
                $reporte->estado = 'firmado';
            }

            $reporte->save();
            $this->rehacerArchivo($reporte);
        });

        $reporte->refresh();

        if ($reporte->estado === 'firmado') {
            $this->avisos->enviar('nomina.firmado', $reporte->user, [
                'corte'       => $reporte->corte->format('d/m/Y'),
                'coordinador' => $reporte->coordinador?->name ?? 'Quien coordina',
                'enlace'      => \App\Filament\Pages\Personal\MisHorasExtras::getUrl(),
            ], $reporte);
        } elseif ($parte === 'trabajador') {
            $this->pedirFirmaAlCoordinador($reporte);
        }

        return $reporte;
    }

    /** Le manda —o le vuelve a mandar— el enlace a quien coordina. */
    public function pedirFirmaAlCoordinador(ReporteDeExtras $reporte): ?NotificationLog
    {
        $coordinador = $reporte->coordinador;

        if (! $coordinador || $reporte->firmo('coordinador') || ! $reporte->firmo('trabajador') || $reporte->estado !== 'por_firmar') {
            return null;
        }

        return $this->avisos->enviar('nomina.firmar', $coordinador, [
            'colaborador' => $reporte->user->name,
            'corte'       => $reporte->corte->format('d/m/Y'),
            'horas'       => $this->horas($reporte->minutos),
            'jornadas'    => $this->listaDeJornadas($reporte),
            'enlace'      => $reporte->enlaceDeFirma('coordinador'),
        ], $reporte);
    }

    /**
     * Envía el ADF-404-f4 firmado a novedades de nómina, en Excel.
     *
     * Solo con las dos firmas, y lo pulsa una persona: no sale solo. Van en
     * copia quien lo generó y quien lo firmó como jefe, y a ellos les llega lo
     * que conteste nómina.
     *
     * @throws BookingException
     */
    public function enviarANomina(ReporteDeExtras $reporte, User $quien): NotificationLog
    {
        if (! $reporte->puedeEnviarse()) {
            throw new BookingException('El reporte todavía no tiene las dos firmas.');
        }

        $correo = ConfiguracionDeNomina::valor('correo');

        if (! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            throw new BookingException('No hay un correo de nómina configurado. Se pone en Jornadas → Reportes de nómina → Configuración.');
        }

        if (! $reporte->tieneArchivo() || ! Storage::disk('local')->exists($reporte->archivo_path)) {
            $this->rehacerArchivo($reporte);
            $reporte->refresh();
        }

        $partes = collect([$reporte->user, $reporte->coordinador])
            ->filter(fn (?User $u) => $u && filter_var($u->email, FILTER_VALIDATE_EMAIL))
            ->reject(fn (User $u) => mb_strtolower($u->email) === mb_strtolower($correo))
            ->mapWithKeys(fn (User $u) => [mb_strtolower($u->email) => $u->name])
            ->all();

        $encabezado = $reporte->datos['encabezado'] ?? [];

        $aviso = $this->avisos->enviarSinCuenta(
            'nomina.novedades',
            $correo,
            ConfiguracionDeNomina::valor('destinatario'),
            [
                'colaborador' => $reporte->user->name,
                'documento'   => $encabezado['documento'] ?? '',
                'area'        => $encabezado['area'] ?? '',
                'corte'       => $reporte->corte->format('d/m/Y'),
                'horas'       => $this->horas($reporte->minutos),
                'detalle'     => $this->detalle($reporte),
                'coordinador' => $reporte->coordinador?->name ?? ($encabezado['jefe'] ?? ''),
            ],
            $reporte,
            [['ruta' => $reporte->archivo_path, 'nombre' => $reporte->nombreDelArchivo()]],
            array_keys($partes),
            $partes,
        );

        if ($aviso->status === 'enviado') {
            $reporte->update([
                'estado'               => 'enviado',
                'enviado_a_nomina_at'  => now(),
                'enviado_a_nomina_a'   => $correo,
                'enviado_a_nomina_por' => $quien->id,
            ]);
        }

        return $aviso;
    }

    /** Vuelve a escribir el Excel con los datos guardados y las firmas que ya tenga. */
    public function rehacerArchivo(ReporteDeExtras $reporte): void
    {
        $datos = $reporte->datos ?? [];
        if (empty($datos['encabezado']) || empty($datos['filas'])) {
            return;
        }

        $disco = Storage::disk('local');
        $firmas = [];
        foreach (ReporteDeExtras::PARTES as $parte) {
            $ruta = $reporte->{"{$parte}_firma_path"};
            if (filled($ruta) && $disco->exists($ruta)) {
                $firmas[$parte] = $disco->get($ruta);
            }
        }

        $temporal = $this->formato->guardar($datos['encabezado'], $datos['filas'], $firmas);
        $ruta = $reporte->archivo_path ?: 'nomina/' . $reporte->user_id . '/reporte-' . $reporte->id . '.xlsx';
        $disco->put($ruta, file_get_contents($temporal));
        @unlink($temporal);

        if ($reporte->archivo_path !== $ruta) {
            $reporte->forceFill(['archivo_path' => $ruta])->save();
        }
    }

    /**
     * Quien firma como jefe inmediato: el elegido, o el de la configuración.
     * Tiene que tener correo —le llega el enlace— y no puede ser la misma
     * persona que reporta: nadie se autoriza sus propias horas extras.
     *
     * @throws BookingException
     */
    private function coordinadorValido(User $persona, int|string|null $id): User
    {
        $coordinador = User::find((int) ($id ?: ConfiguracionDeNomina::valor('coordinador_id')));

        if (! $coordinador) {
            throw new BookingException('Elige quién firma como jefe inmediato.');
        }

        if ($coordinador->is($persona)) {
            throw new BookingException('Tu reporte lo firma otra persona como jefe inmediato: elige a alguien distinto de ti.');
        }

        if (! filter_var($coordinador->email, FILTER_VALIDATE_EMAIL)) {
            throw new BookingException($coordinador->name . ' no tiene correo: sin él no le llega el enlace para firmar.');
        }

        return $coordinador;
    }

    private function horas(int $minutos): string
    {
        return rtrim(rtrim(number_format($minutos / 60, 2, ',', '.'), '0'), ',') ?: '0';
    }

    private function detalle(ReporteDeExtras $reporte): string
    {
        return collect([
            'diurnas'            => $reporte->diurnas,
            'nocturnas'          => $reporte->nocturnas,
            'diurnas festivas'   => $reporte->diurnas_festivas,
            'nocturnas festivas' => $reporte->nocturnas_festivas,
        ])->filter()->map(fn ($m, $k) => $this->horas((int) $m) . ' h ' . $k)->implode(', ') ?: 'sin desglose';
    }

    private function listaDeJornadas(ReporteDeExtras $reporte): string
    {
        $tz = config('fabos.lab.timezone');

        return collect($reporte->datos['filas'] ?? [])->map(fn (array $f) => '· '
            . ucfirst(Carbon::parse($f['fecha'], $tz)->locale('es')->isoFormat('ddd D [de] MMM'))
            . ', de ' . $f['extra_inicio'] . ' a ' . $f['extra_fin']
            . (filled($f['actividades'] ?? null) ? ': ' . \Illuminate\Support\Str::limit($f['actividades'], 90) : ''))->implode("\n");
    }

    /**
     * El PNG del lienzo, comprobado: lo compone el navegador de quien firma,
     * y eso no se da por bueno.
     *
     * @throws BookingException
     */
    private function pngDe(string $dataUrl): string
    {
        $prefijo = 'data:image/png;base64,';

        if (! str_starts_with($dataUrl, $prefijo) || strlen($dataUrl) > 1_500_000) {
            throw new BookingException('No recibimos la firma. Dibújala en el recuadro y vuelve a intentarlo.');
        }

        $binario = base64_decode(substr($dataUrl, strlen($prefijo)), true);

        if ($binario === false || ! str_starts_with($binario, "\x89PNG\r\n\x1a\n")) {
            throw new BookingException('La firma no llegó bien. Vuelve a dibujarla.');
        }

        return $binario;
    }

    /**
     * Anula un reporte: sus jornadas vuelven a pendientes y el reporte queda
     * en el historial, marcado. Para cuando se generó por error o nómina lo
     * devolvió.
     */
    public function anular(ReporteDeExtras $reporte, ?User $por = null): void
    {
        DB::transaction(function () use ($reporte, $por) {
            ShiftAssignment::where('reporte_id', $reporte->id)->update(['reporte_id' => null]);
            // Los enlaces de firma dejan de servir.
            $reporte->forceFill(['trabajador_token' => null, 'coordinador_token' => null])
                ->update(['anulado_at' => now(), 'anulado_por' => $por?->id]);
        });
    }

    /** @return array{diurnas:int,nocturnas:int,diurnas_festivas:int,nocturnas_festivas:int} */
    private function totales(Collection $jornadas): array
    {
        return $jornadas->reduce(function (array $t, ShiftAssignment $j) {
            foreach (FormatoAdf404::partir($j) as $k => $v) {
                $t[$k] += $v;
            }

            return $t;
        }, ['diurnas' => 0, 'nocturnas' => 0, 'diurnas_festivas' => 0, 'nocturnas_festivas' => 0]);
    }
}
