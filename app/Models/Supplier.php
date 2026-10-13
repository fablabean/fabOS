<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un proveedor: a quién se le compra (§13).
 *
 * Se anota una vez y las solicitudes de compra lo eligen. La lista se llena
 * sola: un proveedor escrito a mano en una solicitud queda aquí al guardarla.
 */
class Supplier extends Model
{
    protected $fillable = [
        'name', 'tax_id', 'tax_id_clave', 'address', 'contact', 'phone', 'email', 'notes', 'professional_profile_id',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $p) {
            $p->name = trim((string) $p->name);
            $p->tax_id_clave = self::claveDelNit($p->tax_id);
        });
    }

    public function purchaseRequests(): HasMany
    {
        return $this->hasMany(PurchaseRequest::class);
    }

    /** El perfil profesional del que vino, si vino de uno. */
    public function professionalProfile(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ProfessionalProfile::class);
    }

    /**
     * El que tiene ese NIT, se escriba como se escriba.
     *
     * Con puntos o sin ellos, con guion o sin él, y con o sin el dígito de
     * verificación: «901.234.567-8», «9012345678» y «901234567» son el mismo.
     * Lo último se resuelve dejando pasar un carácter de más o de menos al
     * final, que es lo que ocupa ese dígito.
     */
    public function scopeConNit(\Illuminate\Database\Eloquent\Builder $query, ?string $nit): \Illuminate\Database\Eloquent\Builder
    {
        $clave = self::claveDelNit($nit);

        if ($clave === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($q) use ($clave) {
            $q->where('tax_id_clave', $clave)
                // Aquí falta el dígito de verificación, y allá lo tiene.
                ->orWhere('tax_id_clave', 'like', $clave . '_');

            // O al revés: aquí viene con él, y allá se anotó sin él.
            if (mb_strlen($clave) > 6) {
                $q->orWhere('tax_id_clave', mb_substr($clave, 0, -1));
            }
        });
    }

    /**
     * Lo que se teclea en un buscador: por nombre, o por NIT escrito de
     * cualquier forma —un trozo basta—.
     */
    public function scopeBuscar(\Illuminate\Database\Eloquent\Builder $query, ?string $texto): \Illuminate\Database\Eloquent\Builder
    {
        $texto = trim((string) $texto);

        if ($texto === '') {
            return $query;
        }

        $clave = self::claveDelNit($texto);

        return $query->where(function ($q) use ($texto, $clave) {
            $q->where('name', 'ilike', '%' . $texto . '%')
                ->orWhere('contact', 'ilike', '%' . $texto . '%');

            // Solo si lo tecleado tiene números: «tap» no es un trozo de NIT.
            if ($clave !== null && preg_match('/\d/', $clave)) {
                $q->orWhere('tax_id_clave', 'like', '%' . $clave . '%');
            }
        });
    }

    /**
     * El proveedor de un perfil profesional: el que ya tenía, o uno nuevo con
     * sus datos.
     *
     * Con quién se firma manda sobre el nombre de pila: si el perfil es de una
     * empresa, el proveedor es la empresa y la persona es su representante.
     */
    public static function desdeElPerfil(ProfessionalProfile $perfil): self
    {
        if ($ya = static::where('professional_profile_id', $perfil->id)->first()) {
            return $ya;
        }

        $nit = filled($perfil->document_number)
            ? trim($perfil->document_number) . (filled($perfil->document_dv) ? '-' . trim($perfil->document_dv) : '')
            : null;

        $proveedor = static::anotar([
            'name'    => $perfil->legal_name ?: $perfil->name,
            'tax_id'  => $nit,
            'address' => collect([$perfil->address, $perfil->city])->filter()->implode(', ') ?: null,
            'contact' => $perfil->representative ?: $perfil->name,
        ]);

        $proveedor->fill(array_filter([
            'phone' => $proveedor->phone ?: $perfil->phone,
            'email' => $proveedor->email ?: $perfil->email,
            'notes' => $proveedor->notes ?: (filled($perfil->specialty) ? 'Perfil profesional: ' . $perfil->specialty : null),
        ]));

        // Si ese proveedor ya venía de otro perfil, no se le quita.
        $proveedor->professional_profile_id ??= $perfil->id;
        $proveedor->save();

        return $proveedor;
    }

    /** El NIT sin puntos, guiones ni espacios: «901.234.567-8» y «9012345678» son el mismo. */
    public static function claveDelNit(?string $nit): ?string
    {
        $clave = preg_replace('/[^0-9A-Za-z]/', '', (string) $nit);

        return $clave !== '' ? mb_strtolower($clave) : null;
    }

    /**
     * El proveedor con esos datos: el que ya estaba, o uno nuevo.
     *
     * Se reconoce por el NIT; sin NIT, por el nombre. Al que ya estaba se le
     * rellena lo que le faltara, sin pisar lo que tenía: lo que alguien
     * corrigió en la lista manda sobre lo que se tecleó de prisa en una
     * solicitud.
     *
     * @param  array{name?:?string,tax_id?:?string,address?:?string,contact?:?string}  $datos
     */
    public static function anotar(array $datos): ?self
    {
        $nombre = trim((string) ($datos['name'] ?? ''));
        $clave = self::claveDelNit($datos['tax_id'] ?? null);

        if ($nombre === '' && $clave === null) {
            return null;
        }

        $proveedor = ($clave ? static::conNit($datos['tax_id'])->first() : null)
            ?? ($nombre !== '' ? static::whereRaw('lower(name) = ?', [mb_strtolower($nombre)])->first() : null);

        if (! $proveedor) {
            return static::create([
                'name'    => $nombre !== '' ? $nombre : 'Proveedor ' . ($datos['tax_id'] ?? ''),
                'tax_id'  => filled($datos['tax_id'] ?? null) ? trim($datos['tax_id']) : null,
                'address' => filled($datos['address'] ?? null) ? trim($datos['address']) : null,
                'contact' => filled($datos['contact'] ?? null) ? trim($datos['contact']) : null,
            ]);
        }

        // El NIT más completo gana: si aquí viene con dígito de verificación
        // y el que había no lo tenía, se guarda este.
        $masCompleto = $clave !== null && mb_strlen($clave) > mb_strlen((string) $proveedor->tax_id_clave);

        $proveedor->fill(array_filter([
            'tax_id'  => $masCompleto ? trim((string) $datos['tax_id']) : ($proveedor->tax_id ?: ($datos['tax_id'] ?? null)),
            'address' => $proveedor->address ?: ($datos['address'] ?? null),
            'contact' => $proveedor->contact ?: ($datos['contact'] ?? null),
        ]));

        if ($proveedor->isDirty()) {
            $proveedor->save();
        }

        return $proveedor;
    }
}
