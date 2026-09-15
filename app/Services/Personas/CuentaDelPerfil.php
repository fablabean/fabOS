<?php

namespace App\Services\Personas;

use App\Filament\Componentes\NuevaPersona;
use App\Models\ProfessionalProfile;
use App\Models\User;
use App\Models\UserCategory;
use Illuminate\Support\Facades\DB;

/**
 * Cuando un perfil pasa a tener cuenta (§5).
 *
 * La mayoría de los perfiles no llegan aquí nunca, y eso es lo normal: se les
 * contrata, entregan y se van. Pero a quien acaba trabajando de forma estable
 * hay que darle acceso —para que vea sus reservas, reciba avisos, tenga su
 * ficha— y entonces hace falta una cuenta.
 *
 * Es el mismo gesto que convierte un candidato en proyecto (§11): se hace
 * **una vez**, deja el vínculo escrito, y no se puede repetir por accidente.
 */
class CuentaDelPerfil
{
    /**
     * Le crea la cuenta, o la enlaza si ya existía.
     *
     * @param  array{user_category_id?: int|string|null, roles?: array<int, string>}  $datos
     *
     * @throws PerfilException
     */
    public function crear(ProfessionalProfile $perfil, array $datos = []): User
    {
        if (blank($perfil->email)) {
            throw new PerfilException(
                'Sin correo no hay cuenta: el correo es la llave con la que la persona entra. Anótaselo primero.'
            );
        }

        if ($perfil->yaTieneCuenta()) {
            throw new PerfilException(
                'Este perfil ya es la cuenta de ' . $perfil->user->name . '.'
            );
        }

        return DB::transaction(function () use ($perfil, $datos) {
            /*
             * `NuevaPersona::crear` ya trae lo que hace falta: reutiliza la
             * cuenta si el correo ya existía —dos cuentas con el mismo correo
             * parten el historial en dos—, la deja activa, con la categoria
             * confirmada y validada por quien la crea.
             */
            $persona = NuevaPersona::crear([
                'name'             => $perfil->name,
                'email'            => $perfil->email,
                'phone'            => $perfil->phone,
                'user_category_id' => $datos['user_category_id'] ?? $this->categoriaPorDefecto(),
                // Ninguno por defecto: un contratista no administra el
                // laboratorio. Sin rol de backoffice la persona usa el sitio y
                // «Mi cuenta», que es lo que necesita.
                'roles'            => $datos['roles'] ?? [],
            ]);

            /*
             * Si la cuenta ya existía, se rellenan sus huecos y nada más. El
             * perfil es más nuevo que la cuenta, pero no necesariamente más
             * cierto: pisar un teléfono que alguien ya corrigió sería deshacer
             * su trabajo con un dato de origen desconocido.
             */
            $persona->fill(array_filter([
                'phone'           => $persona->phone ?: $perfil->phone,
                'document_number' => $persona->document_number ?: $perfil->document_number,
            ]))->save();

            // Tener cuenta e inscribirse como proveedor son cosas distintas: el
            // estado del perfil no se toca.
            $perfil->update(['user_id' => $persona->id]);

            return $persona->refresh();
        });
    }

    /**
     * Con qué categoría nace.
     *
     * La de **externo**, que ya existe y es lo que es: alguien de fuera que usa
     * el laboratorio. No se siembra una categoría «proveedor» desde aquí —las
     * categorías las arma quien coordina, sin desplegar nada— y decidir su
     * taxonomía dentro de un cambio que iba de otra cosa sería decidir por
     * ellos.
     */
    private function categoriaPorDefecto(): ?int
    {
        return UserCategory::where('slug', 'externo')->value('id')
            ?? UserCategory::where('slug', 'invitado')->value('id');
    }
}
