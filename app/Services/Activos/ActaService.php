<?php

namespace App\Services\Activos;

use App\Models\Acta;
use App\Models\NotificationLog;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\ConfiguracionDeActas;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * El recorrido de un acta de movimiento de activos (§8).
 *
 * Borrador → esperando firmas → firmada → enviada a Planta Física. Cada paso
 * lo decide alguien: nada se manda fuera del laboratorio porque sí.
 */
class ActaService
{
    public function __construct(private NotificationService $avisos) {}

    /**
     * Cierra el borrador y manda a firmar a las dos partes.
     *
     * Cada una recibe su enlace. Si quien entrega y quien recibe son la misma
     * persona, recibe uno solo y su firma vale por las dos casillas.
     *
     * @throws ActaException si al acta le falta algo para poder firmarse
     */
    public function enviarAFirma(Acta $acta, ?User $quien = null): Acta
    {
        if (! $acta->esEditable()) {
            throw new ActaException('Esta acta ya se envió a firmar.');
        }

        if ($acta->items()->doesntExist()) {
            throw new ActaException('El acta no tiene activos: añade al menos uno.');
        }

        foreach (['entrega' => 'quien entrega', 'recibe' => 'quien recibe'] as $parte => $rol) {
            $persona = $acta->{$parte};

            if (! $persona || blank($persona->email)) {
                throw new ActaException('Falta ' . $rol . ', o no tiene correo: sin él no le llega el enlace para firmar.');
            }

            // La cédula no se exige aquí: quien prepara el acta casi nunca
            // la tiene a mano. Se la pide la página de firma a quien firma,
            // que es quien presenta el documento al retirar el activo.
        }

        $acta->update([
            'status'             => 'por_firmar',
            'entrega_token'      => Acta::nuevoToken(),
            'recibe_token'       => Acta::nuevoToken(),
            'enviada_a_firma_at' => now(),
        ]);

        $this->pedirFirma($acta, 'recibe', $quien);

        if (! $acta->esUnaSolaPersona()) {
            $this->pedirFirma($acta, 'entrega', $quien);
        }

        return $acta->refresh();
    }

    /** Le manda —o le vuelve a mandar— el enlace a una de las partes. */
    public function pedirFirma(Acta $acta, string $parte, ?User $quien = null): ?NotificationLog
    {
        $persona = $acta->{$parte};

        if (! $persona || $acta->firmo($parte)) {
            return null;
        }

        return $this->avisos->enviar('acta.firmar', $persona, [
            'codigo'  => $acta->code,
            'tipo'    => $acta->tipoLegible(),
            'activos' => $this->listaDeActivos($acta),
            'papel'   => $acta->esUnaSolaPersona()
                ? 'quien entrega y quien recibe'
                : ($parte === 'entrega' ? 'quien entrega' : 'quien recibe'),
            'enlace'  => $acta->enlaceDeFirma($parte),
            'quien'   => $quien?->name ?: config('fabos.lab.name'),
        ], $acta);
    }

    /**
     * Guarda la firma dibujada de una de las partes.
     *
     * @param  string  $dataUrl  el PNG del lienzo, en base64
     *
     * @throws ActaException si el dibujo no vale o el acta ya no admite firmas
     */
    public function firmar(Acta $acta, string $parte, string $dataUrl, ?string $ip = null, ?string $cedula = null): Acta
    {
        if ($acta->status !== 'por_firmar') {
            throw new ActaException($acta->status === 'anulada'
                ? 'Esta acta fue anulada.'
                : 'Esta acta ya no está esperando firmas.');
        }

        if ($acta->firmo($parte)) {
            throw new ActaException('Ya habías firmado esta acta.');
        }

        /*
         * El documento, si el acta no lo traía.
         *
         * El formato lo exige y quien prepara el acta casi nunca lo tiene a
         * mano; quien firma, sí: lo presenta al retirar el activo. Lo que
         * escriba queda en el acta y, si su cuenta no tenía, también ahí.
         */
        $cedula = filled($cedula) ? mb_substr(trim($cedula), 0, 40) : null;

        if (blank($acta->{"{$parte}_cedula"}) && blank($cedula)) {
            throw new ActaException('Escribe tu número de documento: el formato lo exige.');
        }

        $binario = $this->pngDe($dataUrl);

        return DB::transaction(function () use ($acta, $parte, $binario, $ip, $cedula) {
            // Una sola persona en las dos casillas: una firma para las dos.
            $partes = $acta->esUnaSolaPersona() ? ['entrega', 'recibe'] : [$parte];

            foreach ($partes as $p) {
                if ($cedula && blank($acta->{"{$p}_cedula"})) {
                    $acta->{"{$p}_cedula"} = $cedula;
                }

                $ruta = 'actas/firmas/' . $acta->code . '-' . $p . '-' . Str::random(24) . '.png';
                Storage::disk('local')->put($ruta, $binario);

                $acta->forceFill([
                    "{$p}_firma_path" => $ruta,
                    "{$p}_firmo_at"   => now(),
                    "{$p}_firmo_ip"   => $ip,
                ]);
            }

            if ($acta->estaFirmadaPorTodos()) {
                $acta->status = 'firmada';
            }

            $acta->save();

            // A su cuenta, si no tenía y nadie más la usa: la próxima acta ya
            // sale con ella.
            $persona = $acta->{$parte};

            if ($cedula && $persona && blank($persona->document_number)
                && User::where('document_number', $cedula)->doesntExist()) {
                $persona->forceFill(['document_number' => $cedula])->save();
            }

            if ($acta->status === 'firmada') {
                $this->guardarPdf($acta);
                $this->avisarQueSeFirmo($acta);
            }

            return $acta->refresh();
        });
    }

    /**
     * Envía el formato firmado a Planta Física.
     *
     * Solo lo que sale de la Universidad, y solo con las dos firmas: es lo que
     * ellos autorizan. Lo pulsa una persona; no sale solo.
     *
     * @throws ActaException
     */
    public function enviarAPlantaFisica(Acta $acta, User $quien): NotificationLog
    {
        if (! in_array($acta->status, ['firmada', 'enviada'], true)) {
            throw new ActaException('El acta todavía no tiene las dos firmas.');
        }

        if (! $acta->saleDeLaUniversidad()) {
            throw new ActaException('Este movimiento no saca el activo de la Universidad: no pasa por Planta Física.');
        }

        $correo = ConfiguracionDeActas::valor('correo_planta');

        if (! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            throw new ActaException('No hay un correo de Planta Física configurado. Se pone en Actas de movimiento → Configuración.');
        }

        $ruta = $this->guardarPdf($acta);

        $aviso = $this->avisos->enviarSinCuenta(
            'acta.planta_fisica',
            $correo,
            ConfiguracionDeActas::valor('autoriza_nombre'),
            [
                'codigo'  => $acta->code,
                'tipo'    => $acta->tipoLegible(),
                'fecha'   => $acta->fecha->format('d/m/Y'),
                'recibe'  => $acta->recibe_nombre . ' · C.C. ' . $acta->recibe_cedula,
                'activos' => $this->listaDeActivos($acta),
                'quien'   => $quien->name,
            ],
            $acta,
            [['ruta' => $ruta, 'nombre' => 'FRF-207-f7_' . $acta->code . '.pdf']],
        );

        $acta->update([
            'status'               => 'enviada',
            'enviada_a_planta_at'  => now(),
            'enviada_a_planta_a'   => $correo,
            'enviada_a_planta_por' => $quien->id,
        ]);

        return $aviso;
    }

    /**
     * Registra que volvieron unos activos, y en qué estado.
     *
     * Por partes: solo los que se marquen. Firma quien los recibe en el
     * laboratorio, que es quien los tiene delante y puede decir cómo llegaron;
     * no se espera a que quien los devuelve abra un correo, porque el equipo ya
     * está de vuelta y eso es un hecho.
     *
     * @param  array<int|string, array{estado?:string, nota?:?string}>  $items  id del renglón => cómo volvió
     *
     * @throws ActaException
     */
    public function registrarDevolucion(
        Acta $acta,
        User $quienRecibe,
        array $items,
        \Carbon\CarbonInterface $fecha,
        ?string $observaciones,
        string $firma,
    ): \App\Models\ActaDevolucion {
        if (! in_array($acta->status, ['firmada', 'enviada'], true)) {
            throw new ActaException('Esta acta todavía no está firmada: lo que no se ha entregado no puede volver.');
        }

        $pendientes = $acta->pendientesDeVolver()->get()->keyBy('id');
        $vuelven = collect($items)->filter(fn ($_, $id) => $pendientes->has((int) $id));

        if ($vuelven->isEmpty()) {
            throw new ActaException('Marca al menos un activo de los que faltan por volver.');
        }

        foreach ($vuelven as $como) {
            if (! isset(Acta::CONDICIONES[$como['estado'] ?? ''])) {
                throw new ActaException('Indica en qué estado volvió cada activo: bueno, regular o malo.');
            }
        }

        $binario = $this->pngDe($firma);

        return DB::transaction(function () use ($acta, $quienRecibe, $vuelven, $pendientes, $fecha, $observaciones, $binario) {
            $devolucion = \App\Models\ActaDevolucion::create([
                'acta_id'         => $acta->id,
                'fecha'           => $fecha->toDateString(),
                'devuelve_nombre' => $acta->recibe_nombre,
                'devuelve_cedula' => $acta->recibe_cedula,
                'recibe_user_id'  => $quienRecibe->id,
                'recibe_nombre'   => $quienRecibe->name,
                'recibe_cedula'   => $quienRecibe->document_number,
                'observaciones'   => filled($observaciones) ? trim($observaciones) : null,
            ]);

            $ruta = 'actas/firmas/' . $devolucion->code . '-recibe-' . Str::random(24) . '.png';
            Storage::disk('local')->put($ruta, $binario);
            $devolucion->forceFill(['firma_path' => $ruta, 'firmo_at' => now()])->save();

            foreach ($vuelven as $id => $como) {
                $pendientes[(int) $id]->update([
                    'devolucion_id'     => $devolucion->id,
                    'estado_devolucion' => $como['estado'],
                    'nota_devolucion'   => filled($como['nota'] ?? null) ? mb_substr(trim($como['nota']), 0, 300) : null,
                ]);
            }

            $this->guardarPdfDeLaDevolucion($devolucion);

            return $devolucion->refresh();
        });
    }

    /**
     * Le avisa a Planta Física que regresó lo que había salido.
     *
     * Opcional y a mano: es un aviso, no un trámite. Y solo si el acta era de
     * las que salen de la Universidad, que son las únicas que ellos conocen.
     *
     * @throws ActaException
     */
    public function enviarDevolucionAPlantaFisica(\App\Models\ActaDevolucion $devolucion, User $quien): NotificationLog
    {
        $acta = $devolucion->acta;

        if (! $acta->saleDeLaUniversidad()) {
            throw new ActaException('El acta era de un movimiento dentro de la Universidad: Planta Física no la conoce.');
        }

        $correo = ConfiguracionDeActas::valor('correo_planta');

        if (! filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            throw new ActaException('No hay un correo de Planta Física configurado. Se pone en Actas de movimiento → Configuración.');
        }

        $faltan = $acta->pendientesDeVolver()->count();

        $aviso = $this->avisos->enviarSinCuenta(
            'acta.devolucion_planta',
            $correo,
            ConfiguracionDeActas::valor('autoriza_nombre'),
            [
                'codigo'     => $devolucion->code,
                'acta'       => $acta->code,
                'fecha'      => $devolucion->fecha->format('d/m/Y'),
                'devuelve'   => $devolucion->devuelve_nombre . ($devolucion->devuelve_cedula ? ' · C.C. ' . $devolucion->devuelve_cedula : ''),
                'activos'    => $devolucion->items->map(fn ($i) => '· ' . $i->descripcion
                    . ($i->placa ? ' (placa ' . $i->placa . ')' : '')
                    . ' — volvió en estado ' . mb_strtolower(Acta::CONDICIONES[$i->estado_devolucion] ?? '?')
                    . ($i->nota_devolucion ? ': ' . $i->nota_devolucion : ''))->implode("\n"),
                'pendientes' => $faltan === 0
                    ? 'Con esto regresó todo lo que había salido con esa acta.'
                    : ($faltan === 1 ? 'Queda un activo de esa acta por regresar.' : 'Quedan ' . $faltan . ' activos de esa acta por regresar.'),
                'quien'      => $quien->name,
            ],
            $acta,
            [['ruta' => $this->guardarPdfDeLaDevolucion($devolucion), 'nombre' => 'Devolucion_' . $devolucion->code . '.pdf']],
        );

        if ($aviso->status === 'enviado') {
            $devolucion->update([
                'enviada_a_planta_at'  => now(),
                'enviada_a_planta_a'   => $correo,
                'enviada_a_planta_por' => $quien->id,
            ]);
        }

        return $aviso;
    }

    public function pdfDeLaDevolucion(\App\Models\ActaDevolucion $devolucion): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('actas.devolucion', $this->datosDeLaDevolucion($devolucion) + ['paraPdf' => true])->setPaper('letter');
    }

    public function guardarPdfDeLaDevolucion(\App\Models\ActaDevolucion $devolucion): string
    {
        $ruta = $devolucion->pdf_path ?: 'actas/pdf/' . $devolucion->code . '-' . Str::random(16) . '.pdf';

        Storage::disk('local')->put($ruta, $this->pdfDeLaDevolucion($devolucion)->output());

        if ($devolucion->pdf_path !== $ruta) {
            $devolucion->forceFill(['pdf_path' => $ruta])->save();
        }

        return $ruta;
    }

    /** @return array<string,mixed> */
    public function datosDeLaDevolucion(\App\Models\ActaDevolucion $devolucion): array
    {
        $devolucion->loadMissing(['items', 'acta']);

        return [
            'devolucion' => $devolucion,
            'acta'       => $devolucion->acta,
            'config'     => ConfiguracionDeActas::todo(),
            'firma'      => $this->firmaComoImagen($devolucion->firma_path),
            'faltan'     => $devolucion->acta->pendientesDeVolver()->count(),
        ];
    }

    /** Anula un acta que no llegó a nada: no se borra, queda dicho. */
    public function anular(Acta $acta): Acta
    {
        $acta->update(['status' => 'anulada', 'entrega_token' => null, 'recibe_token' => null]);

        return $acta->refresh();
    }

    /** El formato en PDF, tal como está ahora. */
    public function pdf(Acta $acta): \Barryvdh\DomPDF\PDF
    {
        return Pdf::loadView('actas.formato', $this->datosDelFormato($acta) + ['paraPdf' => true])->setPaper('letter');
    }

    /** Lo deja en el disco privado y devuelve dónde: es lo que se adjunta al correo. */
    public function guardarPdf(Acta $acta): string
    {
        $ruta = $acta->pdf_path ?: 'actas/pdf/' . $acta->code . '-' . Str::random(16) . '.pdf';

        Storage::disk('local')->put($ruta, $this->pdf($acta)->output());

        if ($acta->pdf_path !== $ruta) {
            $acta->forceFill(['pdf_path' => $ruta])->save();
        }

        return $ruta;
    }

    /**
     * Lo que la plantilla necesita, con las firmas ya como imagen incrustada.
     *
     * @return array<string,mixed>
     */
    public function datosDelFormato(Acta $acta): array
    {
        $acta->loadMissing('items');

        return [
            'acta'   => $acta,
            'config' => ConfiguracionDeActas::todo(),
            'firmas' => [
                'entrega' => $this->firmaComoImagen($acta->entrega_firma_path),
                'recibe'  => $this->firmaComoImagen($acta->recibe_firma_path),
            ],
        ];
    }

    private function firmaComoImagen(?string $ruta): ?string
    {
        $disco = Storage::disk('local');

        return filled($ruta) && $disco->exists($ruta)
            ? 'data:image/png;base64,' . base64_encode($disco->get($ruta))
            : null;
    }

    private function listaDeActivos(Acta $acta): string
    {
        return $acta->items->map(fn ($i) => '· ' . $i->descripcion
            . collect([$i->marca, $i->modelo])->filter()->map(fn ($v) => ' ' . $v)->implode('')
            . ($i->placa ? ' (placa ' . $i->placa . ')' : ''))->implode("\n");
    }

    private function avisarQueSeFirmo(Acta $acta): void
    {
        if (! $acta->createdBy) {
            return;
        }

        $this->avisos->enviar('acta.firmada', $acta->createdBy, [
            'codigo'    => $acta->code,
            'tipo'      => $acta->tipoLegible(),
            'siguiente' => $acta->saleDeLaUniversidad()
                ? 'Como el activo sale de la Universidad, falta enviarla a Planta Física: se hace desde el panel, con un botón.'
                : 'Este movimiento no sale de la Universidad, así que no hace falta enviarla a Planta Física.',
            'enlace'    => \App\Filament\Resources\Actas\ActaResource::getUrl('index'),
        ], $acta);
    }

    /**
     * El PNG del lienzo, comprobado.
     *
     * Lo compone el navegador de quien firma, y eso no se da por bueno: tiene
     * que ser de verdad un PNG, y con techo de tamaño.
     *
     * @throws ActaException
     */
    private function pngDe(string $dataUrl): string
    {
        $prefijo = 'data:image/png;base64,';

        if (! str_starts_with($dataUrl, $prefijo) || strlen($dataUrl) > 1_500_000) {
            throw new ActaException('No recibimos la firma. Dibújala en el recuadro y vuelve a intentarlo.');
        }

        $binario = base64_decode(substr($dataUrl, strlen($prefijo)), true);

        if ($binario === false || ! str_starts_with($binario, "\x89PNG\r\n\x1a\n")) {
            throw new ActaException('La firma no llegó bien. Vuelve a dibujarla.');
        }

        return $binario;
    }
}
