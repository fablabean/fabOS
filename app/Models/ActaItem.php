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

    protected $fillable = [
        'acta_id', 'asset_id', 'placa', 'descripcion', 'marca', 'modelo', 'serie', 'estado', 'position',
        'devolucion_id', 'estado_devolucion', 'nota_devolucion',
    ];

    public function devolucion(): BelongsTo
    {
        return $this->belongsTo(ActaDevolucion::class, 'devolucion_id');
    }

    public function yaVolvio(): bool
    {
        return $this->devolucion_id !== null;
    }

    /** Si volvió peor de como salió: B es mejor que R, y R que M. */
    public function volvioPeor(): bool
    {
        $orden = ['B' => 0, 'R' => 1, 'M' => 2];

        return $this->yaVolvio()
            && ($orden[$this->estado_devolucion] ?? 0) > ($orden[$this->estado] ?? 0);
    }

    /**
     * Lo que se escribe en el acta completa la ficha del equipo.
     *
     * En el inventario casi ningún equipo tenía placa, marca, modelo ni
     * serie: el acta habría salido vacía cada vez, y cada vez se habría
     * tecleado lo mismo. La primera acta de un equipo le deja esos datos; las
     * siguientes ya salen llenas.
     *
     * Solo rellena lo que estaba vacío: lo que el inventario ya dice no se
     * toca desde aquí. Y la placa es única: si otra ficha ya la tiene, no se
     * copia, que eso es un error a revisar y no algo que arreglar en silencio.
     */
    protected static function booted(): void
    {
        static::saved(function (self $item) {
            $activo = $item->asset_id ? Asset::find($item->asset_id) : null;

            if (! $activo) {
                return;
            }

            // Recortado: lo pegado de una hoja de cálculo trae tabuladores.
            $faltan = array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : $v, [
                'brand'  => blank($activo->brand) ? $item->marca : null,
                'model'  => blank($activo->model) ? $item->modelo : null,
                'serial' => blank($activo->serial) ? $item->serie : null,
            ]), 'filled');

            if (blank($activo->asset_tag) && filled($item->placa)
                && Asset::where('asset_tag', $item->placa)->whereKeyNot($activo->id)->doesntExist()) {
                $faltan['asset_tag'] = $item->placa;
            }

            if ($faltan !== []) {
                $activo->forceFill($faltan)->save();
            }
        });
    }

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
