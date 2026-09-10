<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\ProjectPayment;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Settings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

/**
 * Los pagos de un proyecto (§11).
 *
 * El laboratorio pide un pago: al cliente le llega el valor con el QR del
 * banco y el enlace a su proyecto. Paga, y desde esa pagina responde con el
 * comprobante, su nombre completo y su documento. El laboratorio mira el
 * comprobante y lo valida o lo devuelve con un motivo. Todo queda en la
 * conversacion del proyecto, que es donde despues se busca.
 *
 * Validar no mueve el proyecto de etapa: la regla es que la produccion
 * empieza con el pago validado, pero quien coordina decide cuando arranca.
 * Lo que si hace el sistema es enseñar el pago pendiente donde se decide.
 */
class PagosDeProyecto
{
    public function __construct(
        private NotificationService $avisos,
        private SoportesDeSolicitud $soportes,
    ) {}

    /**
     * Pide un pago: crea la fila y se lo dice al cliente, con el QR adjunto.
     *
     * @throws ProjectException
     */
    public function pedir(Project $proyecto, int $valor, ?string $concepto, ?string $mensaje, User $porQuien): ProjectPayment
    {
        if ($valor <= 0) {
            throw new ProjectException('El valor a pagar tiene que ser mayor que cero.');
        }

        if (! Settings::qrDePagos()) {
            throw new ProjectException('Todavía no hay un código QR de pagos: súbelo en Finanzas → Pagos antes de pedir un pago.');
        }

        $correo = $proyecto->correoDeLaPropuesta();

        if (blank($correo)) {
            throw new ProjectException('Este proyecto no tiene correo de contacto: no hay a quién pedirle el pago.');
        }

        return DB::transaction(function () use ($proyecto, $valor, $concepto, $mensaje, $porQuien, $correo) {
            $pago = $proyecto->payments()->create([
                'amount'       => $valor,
                'concept'      => trim((string) $concepto) ?: null,
                'status'       => ProjectPayment::SOLICITADO,
                'requested_by' => $porQuien->id,
                'requested_at' => now(),
            ]);

            $this->avisar('proyecto.pago_solicitado', $proyecto, $this->datosDelCobro($pago->valorFormateado(), $pago->concept, $mensaje, $porQuien), conQr: true);

            $proyecto->comments()->create([
                'user_id'     => $porQuien->id,
                'author_name' => $porQuien->name,
                'side'        => 'laboratorio',
                'body'        => 'Pedimos el pago de ' . $pago->titulo() . ' a ' . $correo . ', con el QR del banco.'
                    . (trim((string) $mensaje) !== '' ? "\n\n" . trim((string) $mensaje) : ''),
            ]);

            return $pago;
        });
    }

    /**
     * El cliente responde con el comprobante, su nombre y su documento.
     *
     * @throws ProjectException
     */
    public function enviarComprobante(
        ProjectPayment $pago,
        UploadedFile $comprobante,
        string $nombre,
        string $documento,
        ?User $quien = null,
    ): ProjectPayment {
        if (! $pago->esperaComprobante()) {
            throw new ProjectException('Este pago ya tiene comprobante' . ($pago->status === ProjectPayment::VALIDADO ? ' validado' : '') . '.');
        }

        $proyecto = $pago->project;

        return DB::transaction(function () use ($pago, $proyecto, $comprobante, $nombre, $documento, $quien) {
            $comentario = $proyecto->comments()->create([
                'user_id'     => $quien?->id,
                'author_name' => $quien?->name ?: trim($nombre),
                'side'        => 'cliente',
                'body'        => 'Envié el comprobante del pago de ' . $pago->titulo() . '.'
                    . "\nA nombre de " . trim($nombre) . ', documento ' . trim($documento) . '.',
            ]);

            // El comprobante va pegado a la respuesta, como cualquier archivo
            // del cliente, y ademas queda referido en el pago.
            $this->soportes->guardar($proyecto, [$comprobante], $comentario, $quien?->id);
            $ruta = $comentario->adjuntos()->latest('id')->value('file_path');

            $pago->update([
                'status'         => ProjectPayment::ENVIADO,
                'receipt_path'   => $ruta,
                'payer_name'     => trim($nombre),
                'payer_document' => trim($documento),
                'submitted_at'   => now(),
                'notes'          => null,
            ]);

            if ($proyecto->lead) {
                $this->avisos->enviar('proyecto.comprobante_recibido', $proyecto->lead, [
                    'proyecto'  => $proyecto->name,
                    'codigo'    => $proyecto->code,
                    'valor'     => $pago->valorFormateado(),
                    'nombre'    => trim($nombre),
                    'documento' => trim($documento),
                    'enlace'    => route('proyectos.tablero', $proyecto),
                ], $proyecto);
            }

            return $pago->refresh();
        });
    }

    /** @throws ProjectException */
    public function validar(ProjectPayment $pago, User $porQuien, ?string $nota = null): ProjectPayment
    {
        if ($pago->status !== ProjectPayment::ENVIADO) {
            throw new ProjectException('Solo se valida un pago con comprobante enviado.');
        }

        $proyecto = $pago->project;

        return DB::transaction(function () use ($pago, $proyecto, $porQuien, $nota) {
            $pago->update([
                'status'       => ProjectPayment::VALIDADO,
                'validated_by' => $porQuien->id,
                'validated_at' => now(),
                'notes'        => trim((string) $nota) ?: null,
            ]);

            $proyecto->comments()->create([
                'user_id'     => $porQuien->id,
                'author_name' => $porQuien->name,
                'side'        => 'laboratorio',
                'body'        => 'Pago de ' . $pago->titulo() . ' validado.' . (trim((string) $nota) !== '' ? ' ' . trim((string) $nota) : ''),
            ]);

            $this->avisar('proyecto.pago_validado', $proyecto, [
                'valor' => $pago->valorFormateado(),
                'quien' => $porQuien->name,
            ]);

            return $pago->refresh();
        });
    }

    /** @throws ProjectException */
    public function rechazar(ProjectPayment $pago, User $porQuien, string $motivo): ProjectPayment
    {
        if ($pago->status !== ProjectPayment::ENVIADO) {
            throw new ProjectException('Solo se devuelve un comprobante que se haya enviado.');
        }

        if (trim($motivo) === '') {
            throw new ProjectException('Di por qué no sirve el comprobante: el cliente tiene que saber qué corregir.');
        }

        $proyecto = $pago->project;

        return DB::transaction(function () use ($pago, $proyecto, $porQuien, $motivo) {
            $pago->update([
                'status' => ProjectPayment::RECHAZADO,
                'notes'  => trim($motivo),
            ]);

            $proyecto->comments()->create([
                'user_id'     => $porQuien->id,
                'author_name' => $porQuien->name,
                'side'        => 'laboratorio',
                'body'        => 'El comprobante del pago de ' . $pago->titulo() . ' no sirve: ' . trim($motivo) . ' Por favor envíalo de nuevo.',
            ]);

            $this->avisar('proyecto.pago_rechazado', $proyecto, [
                'valor'  => $pago->valorFormateado(),
                'motivo' => trim($motivo),
                'quien'  => $porQuien->name,
            ], conQr: true);

            return $pago->refresh();
        });
    }

    /**
     * Como le llegaria el cobro al cliente, sin mandarlo: el asunto, el texto
     * y el correo maquetado, con las mismas variables que al enviarlo.
     *
     * @return array{asunto:string,cuerpo:string,html:string,correo:?string}
     */
    public function vistaPrevia(Project $proyecto, int $valor, ?string $concepto, ?string $mensaje, User $porQuien): array
    {
        $formateado = config('fabos.money.symbol') . number_format((float) $valor, 0, ',', '.');
        $datos = $this->datosDelCobro($formateado, trim((string) $concepto) ?: null, $mensaje, $porQuien) + $this->datosDelProyecto($proyecto);

        $destinatario = $proyecto->destinatarioDeLaPropuesta();

        return $this->avisos->previsualizar(
            'proyecto.pago_solicitado',
            $destinatario,
            $proyecto->contact_name ?: $proyecto->organization ?: 'Hola',
            $datos,
        ) + ['correo' => $proyecto->correoDeLaPropuesta()];
    }

    /** Las variables propias de un cobro, iguales al enviar y al previsualizar. */
    private function datosDelCobro(string $valorFormateado, ?string $concepto, ?string $mensaje, User $porQuien): array
    {
        return [
            'valor'         => $valorFormateado,
            'concepto'      => $concepto ?: 'el proyecto',
            'mensaje'       => trim((string) $mensaje),
            'instrucciones' => Settings::instruccionesDePago(),
            'quien'         => $porQuien->name,
        ];
    }

    private function datosDelProyecto(Project $proyecto): array
    {
        return [
            'proyecto' => $proyecto->name,
            'codigo'   => $proyecto->code,
            'enlace'   => URL::temporarySignedRoute('proyectos.propuesta', now()->addDays(60), ['project' => $proyecto->id]) . '#pago',
        ];
    }

    /** Al cliente, con cuenta o sin ella, con el enlace firmado a su proyecto. */
    private function avisar(string $clave, Project $proyecto, array $datos, bool $conQr = false): void
    {
        $correo = $proyecto->correoDeLaPropuesta();

        if (blank($correo)) {
            return;
        }

        $datos += $this->datosDelProyecto($proyecto);

        $adjuntos = [];

        if ($conQr && ($qr = Settings::qrDePagos())) {
            $adjuntos[] = ['ruta' => $qr, 'nombre' => 'qr-de-pago.' . (pathinfo($qr, PATHINFO_EXTENSION) ?: 'png')];
        }

        $destinatario = $proyecto->destinatarioDeLaPropuesta();

        if ($destinatario) {
            $this->avisos->enviar($clave, $destinatario, $datos, $proyecto, $adjuntos);
        } else {
            $this->avisos->enviarSinCuenta($clave, $correo, $proyecto->contact_name ?: $proyecto->organization ?: 'Hola', $datos, $proyecto, $adjuntos);
        }
    }
}
