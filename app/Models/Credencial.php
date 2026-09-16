<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Cómo se entra a un servicio del laboratorio (§19).
 *
 * El problema que resuelve no es la comodidad: es que cuando la persona que
 * montó el proveedor de correo se va, el laboratorio se queda fuera de su
 * propio servicio y nadie sabe ni a qué cuenta pertenece.
 *
 * ## Qué protege esto, y qué no
 *
 * El secreto se guarda **cifrado con la clave de la aplicación**. Eso protege
 * del caso realista —un respaldo de la base que acaba en un disco, una consulta
 * SQL mal dirigida, alguien mirando la tabla— y **no** de quien tenga a la vez
 * la base y el `APP_KEY`.
 *
 * Conviene decirlo así de claro porque la tentación es tratarlo como una
 * bóveda: **no sustituye a un gestor de contraseñas dedicado**. Es el sitio
 * donde el laboratorio guarda lo que necesita para no quedarse fuera de sus
 * servicios, con dueño y con registro de quién miró.
 *
 * ## Quién ve qué
 *
 * El superadmin las ve todas; quien administra ve **las suyas**. No es una
 * jerarquía por gusto: una sección donde todo el que administra ve todas las
 * claves del laboratorio es un tablón de contraseñas con una puerta, y basta
 * con que una de esas cuentas se pierda para perderlo todo a la vez.
 *
 * Y cada vez que alguien revela un secreto queda escrito (§5). Sin ese
 * registro, «¿quién tenía esta clave cuando se filtró?» no tiene respuesta.
 */
class Credencial extends Model
{
    protected $table = 'credenciales';

    protected $fillable = [
        'software_id', 'nombre', 'tipo', 'usuario', 'secreto',
        'url', 'notas', 'owner_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            /*
             * Cifrado por el cast y no a mano.
             *
             * Escribirlo con `Crypt::` en el servicio funciona hasta el dia en
             * que alguien guarda por otro camino —una accion del panel, un
             * comando de consola— y el secreto entra en claro sin que nada
             * avise. Aqui la tabla no sabe guardar otra cosa.
             */
            'secreto' => 'encrypted',
        ];
    }

    /** Qué clase de secreto es. Cambia lo que se pregunta en el formulario. */
    public const TIPOS = [
        'usuario' => 'Usuario y contraseña',
        'api' => 'Clave de API o token',
        'licencia' => 'Número de licencia',
        'otro' => 'Otro',
    ];

    public function software(): BelongsTo
    {
        return $this->belongsTo(Software::class);
    }

    /** De quién es. Decide quién la ve. */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lecturas(): HasMany
    {
        return $this->hasMany(CredencialLectura::class)->latest('created_at');
    }

    // --------------------------------------------------------- quién la ve

    /**
     * Si esta persona puede ver esta credencial.
     *
     * Una sola regla, y aquí: la política, la tabla y cualquier pantalla que se
     * añada mañana preguntan a este método. Repartida en tres sitios, acabaría
     * habiendo una lista que enseña de más y un botón que dice que no.
     */
    public function laPuedeVer(?User $quien): bool
    {
        if (! $quien) {
            return false;
        }

        return $quien->hasRole(User::ROL_SUPERADMIN)
            || $this->owner_id === $quien->id;
    }

    /**
     * Las que esta persona puede ver, en SQL.
     *
     * Es `laPuedeVer()` dicho para la base de datos. Hay una prueba que compara
     * las dos lecturas registro a registro: si se separan, la lista enseña unas
     * y la ficha deja abrir otras.
     */
    public function scopeVisiblesPara(Builder $q, ?User $quien): Builder
    {
        if (! $quien) {
            return $q->whereRaw('1 = 0');
        }

        if ($quien->hasRole(User::ROL_SUPERADMIN)) {
            return $q;
        }

        return $q->where('owner_id', $quien->id);
    }

    /**
     * Deja constancia de que alguien miró el secreto.
     *
     * Devuelve el secreto para que quien llama no tenga que acordarse de pedir
     * las dos cosas: si revelar y registrar fueran dos llamadas, tarde o
     * temprano habría un camino que hace la primera y olvida la segunda.
     */
    public function revelarPara(User $quien, ?string $ip = null): ?string
    {
        $this->lecturas()->create([
            'user_id' => $quien->id,
            'ip' => $ip,
            'created_at' => now(),
        ]);

        return $this->secreto;
    }

    public function tieneSecreto(): bool
    {
        return filled($this->getRawOriginal('secreto'));
    }

    /** Cuándo se miró por última vez, para enseñarlo en la lista. */
    public function ultimaLectura(): ?CredencialLectura
    {
        return $this->lecturas()->first();
    }
}
