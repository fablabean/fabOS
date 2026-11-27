<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * La copia permanente de una sesión en vivo: lo que se votó, el muro, los
 * tweets y la agenda de una sala en un día.
 */
class SesionEnVivo extends Model
{
    protected $table = 'sesiones_en_vivo';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fecha' => 'date', 'datos' => 'array'];
    }

    public function titulo(): string
    {
        return (string) config("fabos.envivo.salas.{$this->sala}.titulo", $this->sala);
    }

    /** @return array<string,int> */
    public function cifras(): array
    {
        $d = $this->datos ?? [];

        return [
            'votos' => collect($d['votos'] ?? [])->flatten()->sum(),
            'muro' => count($d['muro'] ?? []),
            'tweets' => count($d['tweets'] ?? []),
            'bloques' => count($d['listos'] ?? []),
            'pico' => (int) ($d['pico'] ?? 0),
        ];
    }
}
