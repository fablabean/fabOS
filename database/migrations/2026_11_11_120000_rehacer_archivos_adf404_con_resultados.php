<?php

use App\Models\ReporteDeExtras;
use App\Services\Staffing\FormatoAdf404;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;

/**
 * Los ADF-404-f4 que se generaron antes salieron sin el resultado de sus
 * fórmulas, y en la vista protegida de Excel el día, la validación, el tipo
 * de día y las horas se veían vacíos. Se rehacen con los mismos datos y las
 * mismas filas que quedaron guardados: no cambia ni una jornada ni un saldo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $formato = app(FormatoAdf404::class);

        ReporteDeExtras::query()
            ->where('origen', ReporteDeExtras::FABOS)
            ->whereNotNull('archivo_path')
            ->each(function (ReporteDeExtras $reporte) use ($formato) {
                $datos = $reporte->datos ?? [];
                if (empty($datos['encabezado']) || empty($datos['filas'])) {
                    return;
                }

                try {
                    $temporal = $formato->guardar($datos['encabezado'], $datos['filas']);
                    Storage::disk('local')->put($reporte->archivo_path, file_get_contents($temporal));
                    @unlink($temporal);
                } catch (\Throwable $e) {
                    report($e);
                }
            });
    }

    public function down(): void
    {
        //
    }
};
