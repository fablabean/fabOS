<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un activo dentro de un acta, con sus datos copiados el día que se hizo.
 *
 * Copiados y no leídos del inventario: el acta es un documento firmado, y no
 * puede cambiar porque después se corrija la serie del equipo o se le cambie
 * la placa.
 */
class ActaItem extends Model
{
    protected $table = 'acta_items';

    protected $fillable = ['acta_id', 'asset_id', 'placa', 'descripcion', 'marca', 'modelo', 'serie', 'estado', 'position'];

    public function acta(): BelongsTo
    {
        return $this->belongsTo(Acta::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /**
     * Lo que el formato pide de un activo, tomado del inventario.
     *
     * @return array{asset_id:int,placa:?string,descripcion:string,marca:?string,modelo:?string,serie:?string,estado:string}
     */
    public static function desdeElActivo(Asset $activo): array
    {
        return [
            'asset_id'    => $activo->id,
            'placa'       => $activo->asset_tag,
            'descripcion' => $activo->name,
            'marca'       => $activo->brand,
            'modelo'      => $activo->model,
            'serie'       => $activo->serial,
            // Operativo es bueno; lo demás se revisa a mano antes de firmar.
            'estado'      => $activo->status === 'operativo' ? 'B' : 'R',
        ];
    }
}
