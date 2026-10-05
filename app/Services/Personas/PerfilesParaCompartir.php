<?php

namespace App\Services\Personas;

use App\Models\ProfessionalProfile;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

/**
 * Los perfiles, para alguien de fuera que los va a contactar (§5).
 *
 * No es la hoja de compras (EntregaALaUniversidad): esa lleva cédula, banco,
 * seguridad social y datos tributarios, y no debe salir del circuito de la
 * Universidad. Esta lleva lo justo para escribirle o llamar a alguien —quién
 * es, qué hace, su correo y su celular— y la razón por la que se comparte,
 * con el membrete del laboratorio: es una recomendación, y se presenta como
 * tal.
 */
class PerfilesParaCompartir
{
    /** Los que se comparten por defecto: los que el laboratorio ya propone. */
    public const POR_DEFECTO = 'propuesto';

    /** @return Collection<int, ProfessionalProfile> */
    public function cuales(string $alcance): Collection
    {
        return ProfessionalProfile::query()
            ->when(
                $alcance === 'todos',
                fn ($q) => $q->where('status', '<>', 'descartado'),
                fn ($q) => $q->where('status', self::POR_DEFECTO),
            )
            ->orderBy('name')
            ->get();
    }

    /** @param  Collection<int, ProfessionalProfile>  $perfiles */
    public function pdf(Collection $perfiles, string $razon, ?string $para = null): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $tz = config('fabos.lab.timezone');

        $pdf = Pdf::loadView('perfiles.compartir', [
            'perfiles' => $perfiles->sortBy(fn ($p) => mb_strtolower($p->name))->values(),
            'razon'    => trim($razon),
            'para'     => filled($para) ? trim($para) : null,
            'logo'     => Settings::logoParaPdf(),
            'fecha'    => now($tz)->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
        ])->setPaper('letter');

        // En flujo y no como respuesta normal: el botón es de Livewire, y una
        // respuesta con el PDF dentro se intenta pasar por JSON y revienta.
        return response()->streamDownload(
            fn () => print($pdf->output()),
            'perfiles-' . str(config('fabos.lab.name'))->slug() . '-' . now($tz)->format('Y-m-d') . '.pdf',
            ['Content-Type' => 'application/pdf'],
        );
    }
}
