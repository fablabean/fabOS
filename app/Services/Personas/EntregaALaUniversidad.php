<?php

namespace App\Services\Personas;

use App\Models\ProfessionalProfile;
use App\Models\ProfileDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;

/**
 * Lo que se le manda a compras de la Universidad para que inscriba a alguien
 * como proveedor (§5).
 *
 * Dos piezas, porque compras hace dos cosas distintas con esto: una **hoja por
 * perfil** que se lee y se archiva, y una **planilla** que se pega en su propio
 * formato. Mandar solo la hoja obliga a teclear treinta campos; mandar solo la
 * planilla deja fuera qué papeles tiene cada quien.
 *
 * Lo que **no** se manda es un enlace: aquí hay cédulas y certificaciones
 * bancarias, y una URL se reenvía sola. La hoja dice qué documentos existen;
 * los adjuntos se piden por el panel, a quien tenga acceso.
 */
class EntregaALaUniversidad
{
    /**
     * Las columnas que compras teclea, en el orden en que las teclea.
     *
     * Sin número de cuenta, a propósito y para siempre: no está en la base, y
     * una columna vacía invitaría a llenarla a mano.
     */
    public const COLUMNAS = [
        'Nombre', 'Perfil', 'Tipo de persona', 'Tipo de documento', 'Número de documento', 'DV',
        'Razón social', 'Representante legal', 'Correo', 'Teléfono', 'Dirección', 'Ciudad', 'País',
        'Responsable de IVA', 'Régimen', 'CIIU', 'Responsabilidades (RUT)',
        'Banco', 'Tipo de cuenta', 'EPS', 'Fondo de pensión', 'ARL', 'Clase de riesgo',
        'Autorizó datos el', 'Documentos que tiene', 'Documentos que faltan',
        'Estado', 'Código de proveedor',
    ];

    /** @param  Collection<int, ProfessionalProfile>  $perfiles */
    public function hojas(Collection $perfiles): \Illuminate\Http\Response
    {
        // A cada uno y no a la coleccion: aqui llega tanto la coleccion de
        // Eloquent que arma la accion por lotes como un `collect()` de una sola
        // ficha, y la segunda no sabe de relaciones.
        $perfiles->each->loadMissing(['documents', 'area']);

        return Pdf::loadView('perfiles.entrega', [
            'perfiles' => $perfiles,
            'paraPdf'  => true,
        ])
            ->setPaper('letter')
            ->download('perfiles-' . now(config('fabos.lab.timezone'))->format('Y-m-d') . '.pdf');
    }

    /**
     * La planilla, como la abre Excel en Colombia: punto y coma, UTF-8 con BOM.
     *
     * Sin el BOM, Excel muestra «SeÃ±alÃ©tica»; con coma, mete todo en una
     * celda. Son dos cosas que se descubren al abrirlo, y ya no hay a quién
     * decírselo.
     *
     * @param  Collection<int, ProfessionalProfile>  $perfiles
     */
    public function csv(Collection $perfiles): string
    {
        $perfiles->each->loadMissing('documents');

        $salida = fopen('php://temp', 'r+');

        fwrite($salida, "\xEF\xBB\xBF");
        fputcsv($salida, self::COLUMNAS, ';', '"', '\\');

        foreach ($perfiles as $perfil) {
            fputcsv($salida, $this->fila($perfil), ';', '"', '\\');
        }

        rewind($salida);
        $csv = stream_get_contents($salida);
        fclose($salida);

        return $csv;
    }

    /** @return array<int, string> */
    private function fila(ProfessionalProfile $perfil): array
    {
        return [
            $perfil->name,
            $perfil->specialty ?? '',
            ProfessionalProfile::PERSONAS[$perfil->person_kind] ?? '',
            $perfil->document_type ?? '',
            $perfil->document_number ?? '',
            $perfil->document_dv ?? '',
            $perfil->legal_name ?? '',
            $perfil->representative ?? '',
            $perfil->email ?? '',
            $perfil->phone ?? '',
            $perfil->address ?? '',
            $perfil->city ?? '',
            $perfil->country ?? 'Colombia',
            $perfil->vat_liable === null ? '' : ($perfil->vat_liable ? 'Sí' : 'No'),
            ProfessionalProfile::REGIMENES[$perfil->tax_regime] ?? '',
            $perfil->ciiu_code ?? '',
            $perfil->tax_responsibilities ?? '',
            $perfil->bank_name ?? '',
            ProfessionalProfile::CUENTAS[$perfil->bank_account_kind] ?? '',
            $perfil->eps_name ?? '',
            $perfil->pension_fund ?? '',
            $perfil->arl_name ?? '',
            $perfil->arl_risk_level ?? '',
            $perfil->consent_at?->format('d/m/Y') ?? '',
            $this->losQueTiene($perfil),
            implode(' · ', $perfil->documentosQueFaltan()),
            $perfil->estadoLegible(),
            $perfil->vendor_code ?? '',
        ];
    }

    private function losQueTiene(ProfessionalProfile $perfil): string
    {
        return $perfil->documents
            ->filter(fn (ProfileDocument $d) => $d->existe())
            ->map(fn (ProfileDocument $d) => $d->tipoLegible())
            ->unique()
            ->implode(' · ');
    }

    /**
     * Da por presentados los que salieron en la entrega.
     *
     * Solo los que todavía esperaban: volver a mandar la lista entera no
     * desinscribe a nadie ni resucita a un descartado.
     *
     * @param  Collection<int, ProfessionalProfile>  $perfiles
     * @return int cuántos cambiaron
     */
    public function sellar(Collection $perfiles): int
    {
        $sellados = 0;

        foreach ($perfiles as $perfil) {
            if (! in_array($perfil->status, ProfessionalProfile::PRESENTABLES, true)) {
                continue;
            }

            $perfil->update(['status' => 'presentado', 'submitted_at' => now()]);
            $sellados++;
        }

        return $sellados;
    }
}
