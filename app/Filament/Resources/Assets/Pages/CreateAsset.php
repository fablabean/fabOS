<?php

namespace App\Filament\Resources\Assets\Pages;

use App\Filament\Resources\Assets\AssetResource;
use App\Filament\Resources\Assets\Schemas\AssetForm;
use App\Models\Asset;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CreateAsset extends CreateRecord
{
    protected static string $resource = AssetResource::class;

    /** Cuántas fichas se crearon de una: manda el mensaje y a dónde se vuelve. */
    private int $creadas = 1;

    /**
     * Varias unidades iguales, en una sola pasada (§7).
     *
     * Siete multímetros son siete fichas: cada uno tiene su placa, su
     * historial de mantenimiento y su hoja de vida. Repetir el formulario
     * siete veces es la clase de tarea que se hace mal a la cuarta.
     */
    protected function handleRecordCreation(array $data): Model
    {
        $cantidad = max(1, min((int) ($data['cantidad'] ?? 1), AssetForm::MAXIMAS_UNIDADES));
        unset($data['cantidad']);

        if ($cantidad === 1) {
            return Asset::create($data);
        }

        $base = trim((string) $data['name']);

        /*
         * La placa y el serie son de CADA aparato: copiarlos en las siete
         * fichas seria inventar datos, y ademas la placa es unica en la base.
         * Se anotan despues, ficha por ficha, con el aparato delante.
         */
        $data['asset_tag'] = null;
        $data['serial'] = null;

        /*
         * Y si se reservan, son unidades equivalentes por definicion: se
         * pidio «un multimetro», no el numero tres. Sin el grupo, quien
         * reserva tendria que adivinar cual esta libre.
         */
        if (($data['is_reservable'] ?? false) && blank($data['pool_key'] ?? null)) {
            $data['pool_key'] = Str::slug($base) ?: Str::slug(Str::random(8));
        }

        // Si ya hay «Multimetro 1..3», los nuevos siguen desde el 4: dos
        // fichas con el mismo nombre no se distinguen en ninguna lista.
        $desde = $this->ultimoNumero($base) + 1;

        $this->creadas = $cantidad;

        return DB::transaction(function () use ($data, $base, $cantidad, $desde) {
            $primera = null;

            foreach (range($desde, $desde + $cantidad - 1) as $numero) {
                $unidad = Asset::create(array_merge($data, ['name' => $base . ' ' . $numero]));
                $primera ??= $unidad;
            }

            return $primera;
        });
    }

    /** El número más alto que ya lleva una unidad de este nombre. */
    private function ultimoNumero(string $base): int
    {
        return (int) Asset::query()
            ->where('name', 'like', $base . ' %')
            ->get()
            ->map(fn (Asset $a) => (int) Str::of($a->name)->after($base . ' ')->trim()->toString())
            ->max();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return $this->creadas > 1
            ? 'Se crearon ' . $this->creadas . ' fichas'
            : 'Activo creado';
    }

    protected function getCreatedNotification(): ?Notification
    {
        $aviso = parent::getCreatedNotification();

        if ($this->creadas > 1 && $aviso) {
            $aviso->body('Una por aparato, numeradas. La placa y el serie se anotan en cada ficha.'
                . ($this->getRecord()->pool_key ? ' Se reservan como unidades equivalentes.' : ''));
        }

        return $aviso;
    }

    /** Con varias, a la lista: la ficha de la primera no las representa. */
    protected function getRedirectUrl(): string
    {
        return $this->creadas > 1
            ? AssetResource::getUrl()
            : parent::getRedirectUrl();
    }
}
