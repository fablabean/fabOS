<?php

namespace App\Services\Redes;

use App\Models\Publicacion;
use App\Models\User;

/**
 * Los pasos de una publicación, de idea a publicada (§21).
 *
 * Cada paso dice qué le falta para darse, en palabras: «falta el texto» le
 * sirve a quien está diseñando; un botón que simplemente no aparece, no.
 */
class FlujoDePublicacion
{
    /**
     * Quién aprueba: la dirección del laboratorio.
     *
     * Aprobar es poner el nombre del laboratorio detrás de lo que se publica,
     * y eso no se delega por tener permiso de editar la parrilla.
     */
    public static function puedeAprobar(?User $quien): bool
    {
        return $quien !== null && $quien->hasAnyRole([User::ROL_SUPERADMIN, User::ROL_ADMINISTRADOR]);
    }

    /** Pasa a diseño: alguien la está haciendo. */
    public function aDiseno(Publicacion $p): Publicacion
    {
        $this->exigir(in_array($p->estado, ['idea', 'en_diseno'], true), 'Solo una idea pasa a diseño.');

        return $this->guardar($p, ['estado' => 'en_diseno']);
    }

    /** La manda a aprobación, con lo mínimo para poder mirarla. */
    public function aAprobacion(Publicacion $p): Publicacion
    {
        $this->exigir(in_array($p->estado, ['idea', 'en_diseno'], true), 'Ya está en aprobación o más allá.');
        $this->exigir(filled($p->texto), 'Falta el texto: es lo primero que se aprueba.');
        $this->exigir(! empty($p->redes), 'Falta decir en qué red sale.');

        return $this->guardar($p, ['estado' => 'por_aprobar', 'devuelta_con' => null]);
    }

    public function aprobar(Publicacion $p, User $quien): Publicacion
    {
        $this->exigir(self::puedeAprobar($quien), 'Aprobar es de la dirección del laboratorio.');
        $this->exigir($p->estado === 'por_aprobar', 'Solo se aprueba lo que está por aprobar.');

        return $this->guardar($p, [
            'estado'       => 'aprobada',
            'aprobada_por' => $quien->id,
            'aprobada_at'  => now(),
            'devuelta_con' => null,
        ]);
    }

    /** La devuelve a diseño, diciendo qué cambiar. */
    public function devolver(Publicacion $p, User $quien, string $motivo): Publicacion
    {
        $this->exigir(self::puedeAprobar($quien), 'Devolver es de la dirección del laboratorio.');
        $this->exigir($p->estado === 'por_aprobar', 'Solo se devuelve lo que está por aprobar.');
        $this->exigir(trim($motivo) !== '', 'Di qué hay que cambiar: devolver sin motivo deja a quien diseña adivinando.');

        return $this->guardar($p, ['estado' => 'en_diseno', 'devuelta_con' => trim($motivo)]);
    }

    /** Quedó programada en la red o en Meta Business Suite. */
    public function programar(Publicacion $p): Publicacion
    {
        $this->exigir($p->estado === 'aprobada', 'Primero tiene que estar aprobada.');
        $this->exigir($p->programada_para !== null, 'Falta la fecha y hora en que sale.');

        return $this->guardar($p, ['estado' => 'programada']);
    }

    /** Ya salió: con el enlace, que es la evidencia. */
    public function publicar(Publicacion $p, string $enlace, $cuando = null): Publicacion
    {
        $this->exigir(in_array($p->estado, ['aprobada', 'programada'], true), 'Primero tiene que estar aprobada.');
        $this->exigir((bool) filter_var(trim($enlace), FILTER_VALIDATE_URL), 'Pega el enlace de la publicación: es lo que prueba que salió.');

        return $this->guardar($p, [
            'estado'       => 'publicada',
            'enlace'       => trim($enlace),
            'publicada_at' => $cuando ?? now(),
        ]);
    }

    public function descartar(Publicacion $p): Publicacion
    {
        $this->exigir($p->estado !== 'publicada', 'Lo publicado no se descarta: ya salió.');

        return $this->guardar($p, ['estado' => 'descartada']);
    }

    /** Vuelve a ser una idea. */
    public function recuperar(Publicacion $p): Publicacion
    {
        $this->exigir($p->estado === 'descartada', 'Solo se recupera lo descartado.');

        return $this->guardar($p, ['estado' => 'idea']);
    }

    private function guardar(Publicacion $p, array $cambios): Publicacion
    {
        // Un cambio de estado no es editar el texto: que la regla de «lo
        // aprobado que cambia vuelve a aprobación» no lo tome por eso.
        $p->forceFill($cambios)->save();

        return $p->refresh();
    }

    private function exigir(bool $condicion, string $motivo): void
    {
        if (! $condicion) {
            throw new \DomainException($motivo);
        }
    }
}
