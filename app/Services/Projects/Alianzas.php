<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\ProjectPartner;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Las alianzas (§11): un proyecto con varias partes que aportan.
 *
 *   se convierte → se suman aliados → se confirman → se firma el acuerdo
 *
 * Convertir un proyecto no borra nada: quien llegó con la idea pasa a ser el
 * primer aliado, el laboratorio aparece como otro, y el embudo sigue donde
 * estaba. Lo que cambia es la pregunta del dinero: ya no es «cuánto cobra el
 * laboratorio» sino «cuánto pone cada quien».
 *
 * Tres invariantes:
 *
 *  1. **El laboratorio siempre es parte** de una alianza, aunque su aporte
 *     esté todavía en cero.
 *  2. **La participación repartida no pasa de 100.** Se comprueba al confirmar,
 *     que es cuando cuenta; un propuesto puede pedir lo que quiera.
 *  3. **Quien viene del sitio queda propuesto**, nunca confirmado: confirmar
 *     es una decisión de la coordinación, con nombre.
 */
class Alianzas
{
    public function __construct(private NotificationService $avisos) {}

    /**
     * Convierte un proyecto de servicio en alianza.
     *
     * @throws ProjectException
     */
    public function convertir(Project $proyecto, ?User $quien = null): Project
    {
        if ($proyecto->estaCerrado()) {
            throw new ProjectException('Un proyecto cerrado no se convierte en alianza: se abre uno nuevo.');
        }

        if ($proyecto->esAlianza()) {
            return $proyecto;
        }

        return DB::transaction(function () use ($proyecto, $quien) {
            $proyecto->update(['modality' => 'alianza']);

            $this->asegurarAlLaboratorio($proyecto);

            // Quien llegó con la idea es el primer aliado. Sus datos ya están
            // en el proyecto; se copian, no se mueven: el proyecto los sigue
            // necesitando para escribirle.
            if (! $proyecto->partners()->where('role', 'iniciador')->exists() && filled($proyecto->quienPide()) && $proyecto->quienPide() !== 'sin identificar') {
                $proyecto->partners()->create([
                    'role'         => 'iniciador',
                    'user_id'      => $proyecto->requested_by,
                    'name'         => $proyecto->contact_name ?: $proyecto->requestedBy?->name ?: $proyecto->organization,
                    'organization' => $proyecto->organization,
                    'email'        => $proyecto->correoDeLaPropuesta(),
                    'phone'        => $proyecto->contact_phone,
                    'document'     => $proyecto->client_document,
                    'contribution_kind' => 'conocimiento',
                    'contribution_note' => 'La idea y el trabajo sobre ella',
                    'status'       => 'confirmado',
                    'source'       => 'panel',
                    'joined_on'    => now(config('fabos.lab.timezone'))->toDateString(),
                    'confirmed_by' => $quien?->id,
                    'confirmed_at' => now(),
                ]);
            }

            $proyecto->comments()->create([
                'user_id'     => $quien?->id,
                'author_name' => $quien?->name ?: 'El laboratorio',
                'side'        => 'laboratorio',
                'body'        => 'Este proyecto pasa a ser una alianza: el laboratorio es parte, y cada parte pone lo suyo.',
            ]);

            return $proyecto->refresh();
        });
    }

    /**
     * Suma una parte desde el panel. Nace confirmada: quien la anota ya habló
     * con ella.
     *
     * @param  array<string,mixed>  $datos
     *
     * @throws ProjectException
     */
    public function agregar(Project $proyecto, array $datos, ?User $quien = null): ProjectPartner
    {
        $this->exigirAlianza($proyecto);

        $datos = $this->normalizar($datos);

        return DB::transaction(function () use ($proyecto, $datos, $quien) {
            $parte = $proyecto->partners()->create(array_merge($datos, [
                'status'       => 'confirmado',
                'source'       => 'panel',
                'joined_on'    => $datos['joined_on'] ?? now(config('fabos.lab.timezone'))->toDateString(),
                'confirmed_by' => $quien?->id,
                'confirmed_at' => now(),
            ]));

            $this->exigirQueQuepaLaParticipacion($proyecto);

            return $parte;
        });
    }

    /**
     * Alguien pide unirse desde el sitio. Queda propuesto, y se le avisa a
     * quien lleva el proyecto. El segundo envío con el mismo correo corrige el
     * primero.
     *
     * @param  array<string,mixed>  $datos
     *
     * @throws ProjectException
     */
    public function proponerse(Project $proyecto, array $datos): ProjectPartner
    {
        if (! $proyecto->admiteAliados()) {
            throw new ProjectException('Esta alianza no está recibiendo aliados por ahora.');
        }

        $datos = $this->normalizar($datos);
        $correo = mb_strtolower(trim((string) $datos['email']));

        $parte = DB::transaction(function () use ($proyecto, $datos, $correo) {
            $existente = $proyecto->partners()->where('email', $correo)->first();

            $campos = array_merge($datos, ['email' => $correo, 'consent_at' => now(), 'source' => 'web']);

            if ($existente) {
                // Lo confirmado no se toca al corregir; lo retirado vuelve a proponerse.
                if ($existente->status === 'retirado') {
                    $campos['status'] = 'propuesto';
                }

                unset($campos['role']);
                $existente->update($campos);

                return $existente->refresh();
            }

            return $proyecto->partners()->create(array_merge($campos, ['status' => 'propuesto']));
        });

        if ($proyecto->lead) {
            $this->avisos->enviar('alianza.union_propuesta', $proyecto->lead, [
                'proyecto' => $proyecto->name,
                'codigo'   => $proyecto->code,
                'quien'    => $parte->quien(),
                'aporte'   => $parte->aporteLegible(),
                'enlace'   => url('/admin/projects/' . $proyecto->id . '/edit'),
            ], $parte);
        }

        return $parte;
    }

    /**
     * La coordinación confirma a un propuesto. Aquí se comprueba que la
     * participación quepa: es cuando cuenta.
     *
     * @throws ProjectException
     */
    public function confirmar(ProjectPartner $parte, ?User $quien = null, ?float $participacion = null): ProjectPartner
    {
        if ($parte->estaConfirmado()) {
            return $parte;
        }

        return DB::transaction(function () use ($parte, $quien, $participacion) {
            $parte->update([
                'status'        => 'confirmado',
                'share_percent' => $participacion ?? $parte->share_percent,
                'joined_on'     => $parte->joined_on ?? now(config('fabos.lab.timezone'))->toDateString(),
                'confirmed_by'  => $quien?->id,
                'confirmed_at'  => now(),
            ]);

            $this->exigirQueQuepaLaParticipacion($parte->project);

            if (filled($parte->email)) {
                $datos = [
                    'proyecto' => $parte->project->name,
                    'codigo'   => $parte->project->code,
                    'aporte'   => $parte->aporteLegible(),
                    'quien'    => $quien?->name ?: 'la coordinación',
                ];

                $parte->user
                    ? $this->avisos->enviar('alianza.confirmado', $parte->user, $datos, $parte)
                    : $this->avisos->enviarSinCuenta('alianza.confirmado', $parte->email, $parte->name, $datos, $parte);
            }

            return $parte->refresh();
        });
    }

    /** Se retira. No se borra: saber quién estuvo también es un dato. */
    public function retirar(ProjectPartner $parte, ?string $motivo = null): ProjectPartner
    {
        if ($parte->esElLaboratorio()) {
            throw new ProjectException('El laboratorio no se retira de su propia alianza.');
        }

        $parte->update([
            'status' => 'retirado',
            'notes'  => trim(($parte->notes ? $parte->notes . "\n" : '') . ($motivo ? 'Se retiró: ' . $motivo : '')) ?: null,
        ]);

        return $parte->refresh();
    }

    // ------------------------------------------------------------ por dentro

    private function exigirAlianza(Project $proyecto): void
    {
        if (! $proyecto->esAlianza()) {
            throw new ProjectException('Este proyecto es un servicio. Conviértelo en alianza para sumar partes.');
        }
    }

    private function asegurarAlLaboratorio(Project $proyecto): void
    {
        if ($proyecto->partners()->where('role', 'laboratorio')->exists()) {
            return;
        }

        $proyecto->partners()->create([
            'role'         => 'laboratorio',
            'user_id'      => $proyecto->lead_id,
            'name'         => (string) config('fabos.lab.name'),
            'organization' => (string) config('fabos.lab.institution'),
            'contribution_kind' => 'equipos',
            'contribution_note' => 'Máquinas, espacio y horas del equipo',
            'status'       => 'confirmado',
            'source'       => 'panel',
            'joined_on'    => now(config('fabos.lab.timezone'))->toDateString(),
            'confirmed_at' => now(),
        ]);
    }

    /** @throws ProjectException */
    private function exigirQueQuepaLaParticipacion(Project $proyecto): void
    {
        $repartida = $proyecto->participacionRepartida();

        if ($repartida > 100) {
            throw new ProjectException(
                'La participación repartida suma ' . rtrim(rtrim(number_format($repartida, 2, ',', '.'), '0'), ',') . ' %, y no puede pasar de 100.'
            );
        }
    }

    /** @param array<string,mixed> $datos */
    private function normalizar(array $datos): array
    {
        $datos['contribution_kind'] = array_key_exists($datos['contribution_kind'] ?? '', ProjectPartner::APORTES)
            ? $datos['contribution_kind'] : 'otro';
        $datos['contribution_value'] = max(0, (int) ($datos['contribution_value'] ?? 0));
        $datos['role'] = array_key_exists($datos['role'] ?? '', ProjectPartner::ROLES) && ($datos['role'] ?? '') !== 'laboratorio'
            ? $datos['role'] : 'aliado';
        $datos['name'] = trim((string) ($datos['name'] ?? ''));

        if (isset($datos['email'])) {
            $datos['email'] = mb_strtolower(trim((string) $datos['email'])) ?: null;
        }

        return $datos;
    }
}
