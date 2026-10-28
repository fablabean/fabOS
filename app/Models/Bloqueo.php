<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Un bloqueo de las reservas del laboratorio (App\Support\BloqueoDeReservas).
 *
 * Va de `desde` a `hasta` —sin `hasta`, hasta que alguien lo levante—, y en
 * el sitio se avisa desde `avisar_desde`: antes de empezar, como aviso de
 * que viene; mientras dura, como bloqueo.
 */
class Bloqueo extends Model
{
    protected $table = 'bloqueos_de_reservas';

    protected $fillable = ['motivo', 'desde', 'hasta', 'avisar_desde', 'dias_completos', 'por', 'levantado_at'];

    protected static function booted(): void
    {
        static::saved(fn () => \App\Support\BloqueoDeReservas::olvidar());
        static::deleted(fn () => \App\Support\BloqueoDeReservas::olvidar());
    }

    protected function casts(): array
    {
        return [
            'desde'          => 'datetime',
            'hasta'          => 'datetime',
            'avisar_desde'   => 'datetime',
            'levantado_at'   => 'datetime',
            'dias_completos' => 'boolean',
        ];
    }

    /** Los que no se han levantado ni terminado: en curso o por venir. */
    public function scopeVigentes(Builder $q): Builder
    {
        return $q->whereNull('levantado_at')
            ->where(fn ($q) => $q->whereNull('hasta')->orWhere('hasta', '>', now()))
            ->orderBy('desde');
    }

    public function enCurso(): bool
    {
        return $this->desde->lte(now()) && ($this->hasta === null || $this->hasta->gt(now()));
    }

    /** Si alcanza algo de `$desde` a `$hasta`. Tocarse por el borde no es alcanzarse. */
    public function alcanza(CarbonInterface $desde, ?CarbonInterface $hasta = null): bool
    {
        $hasta ??= $desde;

        return ($this->hasta === null || $desde->lt($this->hasta))
            && ($hasta->gt($this->desde) || $desde->gte($this->desde));
    }

    /**
     * Cuándo, dicho para quien reserva, en la hora del laboratorio:
     * «el viernes 31 de octubre», «del lunes 3 al miércoles 5 de noviembre»,
     * «del lunes 3 de noviembre a las 8:00 al martes 4 a las 12:00».
     */
    public function cuando(): string
    {
        $tz = config('fabos.lab.timezone');
        $d = $this->desde->copy()->timezone($tz)->locale('es');
        $h = $this->hasta?->copy()->timezone($tz)->locale('es');

        if ($h === null) {
            return 'desde el ' . $d->isoFormat('dddd D [de] MMMM [a las] H:mm') . ', hasta nuevo aviso';
        }

        if ($this->dias_completos) {
            // Se guarda hasta la medianoche del día siguiente: el último día es el anterior.
            $ultimo = $h->copy()->subSecond();

            return $d->isSameDay($ultimo)
                ? 'el ' . $d->isoFormat('dddd D [de] MMMM') . ', todo el día'
                : 'del ' . $d->isoFormat($d->isSameMonth($ultimo) ? 'dddd D' : 'dddd D [de] MMMM')
                    . ' al ' . $ultimo->isoFormat('dddd D [de] MMMM') . ', todo el día';
        }

        return $d->isSameDay($h)
            ? 'el ' . $d->isoFormat('dddd D [de] MMMM') . ', de ' . $d->format('G:i') . ' a ' . $h->format('G:i')
            : 'del ' . $d->isoFormat('dddd D [de] MMMM [a las] H:mm') . ' al ' . $h->isoFormat('dddd D [de] MMMM [a las] H:mm');
    }

    /** Cuántas reservas siguen en pie dentro del bloqueo: para decidir si se cancelan. */
    public function reservasDentro(): int
    {
        return Reservation::query()
            ->whereIn('status', ['solicitada', 'confirmada'])
            ->where('ends_at', '>', max($this->desde, now()))
            ->when($this->hasta, fn ($q) => $q->where('starts_at', '<', $this->hasta))
            ->count();
    }
}
