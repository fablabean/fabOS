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
    protected $fillable = ['name', 'tax_id', 'tax_id_clave', 'address', 'contact', 'phone', 'email', 'notes'];

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

        $proveedor = ($clave ? static::where('tax_id_clave', $clave)->first() : null)
            ?? ($nombre !== '' ? static::whereRaw('lower(name) = ?', [mb_strtolower($nombre)])->first() : null);

        if (! $proveedor) {
            return static::create([
                'name'    => $nombre !== '' ? $nombre : 'Proveedor ' . ($datos['tax_id'] ?? ''),
                'tax_id'  => filled($datos['tax_id'] ?? null) ? trim($datos['tax_id']) : null,
                'address' => filled($datos['address'] ?? null) ? trim($datos['address']) : null,
                'contact' => filled($datos['contact'] ?? null) ? trim($datos['contact']) : null,
            ]);
        }

        $proveedor->fill(array_filter([
            'tax_id'  => $proveedor->tax_id ?: ($datos['tax_id'] ?? null),
            'address' => $proveedor->address ?: ($datos['address'] ?? null),
            'contact' => $proveedor->contact ?: ($datos['contact'] ?? null),
        ]));

        if ($proveedor->isDirty()) {
            $proveedor->save();
        }

        return $proveedor;
    }
}
