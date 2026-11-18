<?php

namespace App\Models\Iot;

use App\Casts\UtcDateTime;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** Una actividad en la agenda de un salón: lo que muestra su pantalla. */
class ActividadDePantalla extends Model
{
    protected $table = 'pantalla_actividades';

    protected $fillable = ['pantalla_id', 'titulo', 'responsable', 'imagen_path', 'inicia_at', 'minutos', 'nota'];

    protected function casts(): array
    {
        return ['inicia_at' => UtcDateTime::class, 'minutos' => 'integer'];
    }

    public function pantalla(): BelongsTo
    {
        return $this->belongsTo(Pantalla::class);
    }

    public function llegadas(): HasMany
    {
        return $this->hasMany(LlegadaDePantalla::class, 'actividad_id');
    }

    public function terminaAt(): Carbon
    {
        return $this->inicia_at->copy()->addMinutes($this->minutos);
    }

    /** «10:00 – 12:00», en la hora del laboratorio. */
    public function horario(): string
    {
        $tz = config('fabos.lab.timezone');

        return $this->inicia_at->copy()->timezone($tz)->format('H:i') . ' – ' . $this->terminaAt()->timezone($tz)->format('H:i');
    }

    /** «2 h», «45 min», «1 h 30 min». */
    public function duracion(): string
    {
        $h = intdiv($this->minutos, 60);
        $m = $this->minutos % 60;

        return trim(($h ? $h . ' h ' : '') . ($m ? $m . ' min' : ''));
    }
}
