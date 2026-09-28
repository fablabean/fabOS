<?php

namespace App\Services\Reports;

use App\Models\ProjectPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Lo que se cobró por QR en un mes (§11).
 *
 * Cuenta el pago en el mes en que llegó el comprobante: es cuando la persona
 * pagó, y lo que Finanzas cruza con el extracto del banco. Entran los
 * validados y los que esperan validación —el dinero ya está en la cuenta—;
 * los pedidos sin comprobante y los devueltos no son una venta todavía.
 */
class VentasPorQr
{
    public const ESTADOS = [ProjectPayment::VALIDADO, ProjectPayment::ENVIADO];

    /** @return Collection<int, array<string,mixed>> */
    public function delMes(Carbon $mes): Collection
    {
        $tz = config('fabos.lab.timezone');
        $desde = $mes->copy()->timezone($tz)->startOfMonth();
        $hasta = $desde->copy()->endOfMonth();

        return ProjectPayment::query()
            ->whereIn('status', self::ESTADOS)
            ->whereBetween('submitted_at', [$desde->copy()->utc(), $hasta->copy()->utc()])
            ->with('project')
            ->orderBy('submitted_at')
            ->get()
            ->map(fn (ProjectPayment $p) => $this->fila($p));
    }

    /** @return array<string,mixed> */
    private function fila(ProjectPayment $p): array
    {
        $proyecto = $p->project;

        // Lo que escribió quien pagó; si no lo escribió, lo del contrato.
        $nombre = trim((string) $p->payer_name)
            ?: trim((string) ($proyecto?->client_legal_name ?: $proyecto?->contact_name));
        $documento = trim((string) $p->payer_document)
            ?: trim(trim((string) $proyecto?->client_document_type) . ' ' . trim((string) $proyecto?->client_document));

        $que = $proyecto ? 'Proyecto ' . $proyecto->code . ' · ' . $proyecto->name : 'Proyecto';

        return [
            'pago'      => $p,
            'fecha'     => $p->submitted_at?->timezone(config('fabos.lab.timezone')),
            'nombre'    => $nombre,
            'documento' => $documento,
            'que'       => $que,
            'concepto'  => (string) $p->concept,
            'valor'     => (int) $p->amount,
            'estado'    => ProjectPayment::ESTADOS[$p->status] ?? $p->status,
            'validado'  => $p->status === ProjectPayment::VALIDADO,
        ];
    }

    /**
     * La planilla, como la abre Excel en Colombia: punto y coma, UTF-8 con
     * BOM, y el valor como número entero para que se pueda sumar.
     *
     * @param  Collection<int, array<string,mixed>>  $filas
     */
    public function csv(Collection $filas): string
    {
        $salida = fopen('php://temp', 'r+');
        fwrite($salida, "\xEF\xBB\xBF");

        fputcsv($salida, ['Fecha', 'Nombre', 'Documento', 'Qué hizo', 'Concepto', 'Valor', 'Estado'], ';', '"', '\\');

        foreach ($filas as $f) {
            fputcsv($salida, [
                $f['fecha']?->format('d/m/Y'),
                $f['nombre'],
                $f['documento'],
                $f['que'],
                $f['concepto'],
                $f['valor'],
                $f['estado'],
            ], ';', '"', '\\');
        }

        rewind($salida);
        $csv = stream_get_contents($salida);
        fclose($salida);

        return $csv;
    }
}
