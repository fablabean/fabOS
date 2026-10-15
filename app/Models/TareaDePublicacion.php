<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Una cosa por hacer para que la publicación salga: grabar, diseñar, escribir (§21). */
class TareaDePublicacion extends Model
{
    protected $table = 'publicacion_tareas';

    protected $fillable = ['publicacion_id', 'que', 'responsable_id', 'vence_el', 'hecha_at', 'orden'];

    protected function casts(): array
    {
        return [
            'vence_el' => 'date',
            'hecha_at' => 'datetime',
        ];
    }

    /**
     * Lo que suele llevar cada formato, y cuántos días antes de salir conviene
     * tenerlo. Es un punto de partida: se quitan, se cambian y se añaden.
     *
     * @var array<string,list<array{0:string,1:int}>>
     */
    public const TIPICAS = [
        'post'     => [['Diseñar la pieza', 3], ['Escribir el texto', 2]],
        'carrusel' => [['Escoger las fotos', 5], ['Diseñar las láminas', 3], ['Escribir el texto', 2]],
        'reel'     => [['Guion', 7], ['Grabar', 5], ['Editar el video', 3], ['Escribir el texto', 2]],
        'historia' => [['Diseñar la historia', 1]],
        'video'    => [['Guion', 10], ['Grabar', 7], ['Editar el video', 4], ['Miniatura', 3], ['Escribir el texto', 2]],
        'articulo' => [['Escribir el artículo', 5], ['Imágenes', 3], ['Revisión', 2]],
    ];

    public function publicacion(): BelongsTo
    {
        return $this->belongsTo(Publicacion::class);
    }

    public function responsable(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsable_id');
    }

    public function scopePendientes(Builder $q): Builder
    {
        return $q->whereNull('hecha_at');
    }

    public function estaHecha(): bool
    {
        return $this->hecha_at !== null;
    }

    public function vaTarde(): bool
    {
        return ! $this->estaHecha() && $this->vence_el !== null
            && $this->vence_el->lt(now(config('fabos.lab.timezone'))->startOfDay());
    }
}
