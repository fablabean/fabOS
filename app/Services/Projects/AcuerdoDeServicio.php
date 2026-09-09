<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\Setting;
use App\Models\User;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * El acuerdo de servicio que el sistema redacta (§11).
 *
 * Hasta ahora el contrato era un archivo que alguien escribía fuera y subía.
 * Para un laboratorio que hace veinte proyectos al semestre eso es veinte
 * documentos de Word con los mismos párrafos y los datos copiados a mano, y
 * el dato copiado mal es el que después importa. Aquí el acuerdo sale de lo
 * que el proyecto ya sabe —quién, qué, cuánto, cuándo— sobre una base de
 * cláusulas que el laboratorio escribe una vez y ajusta cuando quiera.
 *
 * Es un acuerdo de servicio y no un contrato en el sentido solemne: describe
 * el encargo, el valor, el plazo y las reglas de la casa. Lo que pida más que
 * eso —una entidad con su propio formato— se sube como archivo, como siempre.
 *
 * Todo lo que va en el documento se puede corregir antes de generarlo: la
 * base es un punto de partida, no una camisa de fuerza.
 */
class AcuerdoDeServicio
{
    public const CLAUSULAS_BASE = <<<'TXT'
1. Objeto. {laboratorio}, de {institucion}, presta a {cliente} el servicio de fabricación digital descrito en este acuerdo para el proyecto «{proyecto}» ({codigo}): {objeto}

2. Entregables. {entregables}

3. Plazo. El trabajo empieza el {inicio} y se entrega el {entrega}. Los plazos cuentan desde que el cliente entrega la información, los archivos y el material que le corresponden; un cambio de alcance mueve la fecha de común acuerdo.

4. Valor y forma de pago. El valor del servicio es de {valor}. {forma_pago}

5. Archivos y propiedad. Los diseños y archivos que entrega el cliente siguen siendo suyos. Lo que el laboratorio produce para este proyecto queda para el cliente una vez pagado. El laboratorio puede citar el proyecto como referencia de su trabajo, salvo que el cliente pida lo contrario por escrito.

6. Material y tolerancias. La fabricación digital tiene tolerancias propias de cada tecnología. El laboratorio las informa antes de fabricar y el cliente las acepta al aprobar la propuesta. El material lo aporta el laboratorio, salvo que se acuerde otra cosa.

7. Recogida. El cliente recoge lo entregado en el laboratorio, en el horario de atención, dentro de los treinta días siguientes al aviso de entrega. Pasado ese plazo, el laboratorio no responde por su custodia.

8. Uso responsable. El cliente responde por el uso que dé a lo fabricado y garantiza que tiene derecho a fabricar lo que encarga.

9. Vigencia. Este acuerdo vale desde su aceptación hasta la entrega y el pago. Cualquiera de las partes puede terminarlo antes por escrito; en ese caso el cliente paga lo ejecutado hasta la fecha.
TXT;

    public const FORMA_PAGO_BASE = 'Se paga la mitad al aceptar este acuerdo y el resto contra entrega, por los medios que indique {institucion}.';

    /** Lo que se puede escribir entre llaves en las cláusulas, y qué es. */
    public const VARIABLES = [
        'laboratorio' => 'nombre del laboratorio',
        'institucion' => 'institución a la que pertenece',
        'ciudad'      => 'ciudad',
        'cliente'     => 'quién firma por el cliente, con su documento',
        'proyecto'    => 'nombre del proyecto',
        'codigo'      => 'código del proyecto',
        'objeto'      => 'lo que se hace',
        'entregables' => 'lo que se entrega',
        'valor'       => 'valor del servicio, en pesos',
        'inicio'      => 'fecha de inicio',
        'entrega'     => 'fecha de entrega',
        'forma_pago'  => 'cómo se paga',
        'responsable' => 'quién responde por el proyecto en el laboratorio',
        'fecha'       => 'fecha del acuerdo',
    ];

    /** La base del laboratorio: la guardada, o la que trae el sistema. */
    public static function clausulasBase(): string
    {
        return trim((string) Setting::get(Settings::ACUERDO_CLAUSULAS, '')) ?: self::CLAUSULAS_BASE;
    }

    public static function formaDePagoBase(): string
    {
        return trim((string) Setting::get(Settings::ACUERDO_FORMA_PAGO, '')) ?: self::FORMA_PAGO_BASE;
    }

    /**
     * Lo que el proyecto ya sabe, como punto de partida del formulario.
     *
     * @return array<string,mixed>
     */
    public function datosSugeridos(Project $proyecto): array
    {
        $tz = config('fabos.lab.timezone');
        $propuesta = $proyecto->propuestaVigente();

        $entregables = $proyecto->deliverables()->orderBy('position')->get()
            ->map(fn ($e) => '- ' . $e->title . ($e->detail ? ': ' . $e->detail : ''))
            ->implode("\n");

        if ($entregables === '' && is_array($propuesta?->deliverables)) {
            $entregables = collect($propuesta->deliverables)
                ->map(fn ($e) => '- ' . (is_array($e) ? ($e['title'] ?? $e['titulo'] ?? implode(' ', $e)) : $e))
                ->implode("\n");
        }

        return [
            'objeto'      => trim((string) $proyecto->summary),
            'entregables' => $entregables,
            'valor'       => (int) ($proyecto->agreed_value ?: $propuesta?->estimated_value ?: $proyecto->estimated_value ?: 0),
            'inicio'      => ($proyecto->starts_on ?? $propuesta?->starts_on)?->format('Y-m-d') ?? Carbon::now($tz)->toDateString(),
            'entrega'     => ($proyecto->due_on ?? $propuesta?->due_on)?->format('Y-m-d'),
            'forma_pago'  => self::formaDePagoBase(),
            'clausulas'   => self::clausulasBase(),
        ];
    }

    /**
     * Las variables ya resueltas para este proyecto y estos datos.
     *
     * @param  array<string,mixed>  $datos
     * @return array<string,string>
     */
    public function variables(Project $proyecto, array $datos): array
    {
        $tz = config('fabos.lab.timezone');
        $fecha = fn (?string $v) => $v ? Carbon::parse($v)->locale('es')->isoFormat('D [de] MMMM [de] YYYY') : 'por definir';

        $base = [
            'laboratorio' => (string) config('fabos.lab.name'),
            'institucion' => (string) config('fabos.lab.institution'),
            'ciudad'      => (string) config('fabos.lab.city'),
            'cliente'     => $proyecto->quienFirma() ?: $proyecto->quienPide(),
            'proyecto'    => $proyecto->name,
            'codigo'      => $proyecto->code,
            'objeto'      => trim((string) ($datos['objeto'] ?? '')) ?: 'lo descrito en la propuesta aceptada.',
            'entregables' => trim((string) ($datos['entregables'] ?? '')) ?: 'Los descritos en la propuesta aceptada.',
            'valor'       => config('fabos.money.symbol') . number_format((float) ($datos['valor'] ?? 0), 0, ',', '.') . ' pesos colombianos',
            'inicio'      => $fecha($datos['inicio'] ?? null),
            'entrega'     => $fecha($datos['entrega'] ?? null),
            'responsable' => $proyecto->lead?->name ?? 'la coordinación del laboratorio',
            'fecha'       => Carbon::now($tz)->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
        ];

        // La forma de pago también lleva variables, y se resuelve antes de
        // meterla en las cláusulas.
        $base['forma_pago'] = $this->reemplazar(trim((string) ($datos['forma_pago'] ?? '')) ?: self::formaDePagoBase(), $base);

        return $base;
    }

    /** El texto de las cláusulas con todo puesto. */
    public function clausulas(Project $proyecto, array $datos): string
    {
        return $this->reemplazar(
            trim((string) ($datos['clausulas'] ?? '')) ?: self::clausulasBase(),
            $this->variables($proyecto, $datos),
        );
    }

    /** La página, para verla o para el PDF: lo que se ve es lo que se firma. */
    public function render(Project $proyecto, array $datos, bool $paraPdf = false): string
    {
        return view('proyectos.acuerdo', [
            'proyecto'  => $proyecto,
            'variables' => $this->variables($proyecto, $datos),
            'clausulas' => $this->clausulas($proyecto, $datos),
            'paraPdf'   => $paraPdf,
        ])->render();
    }

    /** El PDF en bruto. */
    public function pdf(Project $proyecto, array $datos): string
    {
        return Pdf::loadHTML($this->render($proyecto, $datos, paraPdf: true))
            ->setPaper('letter')
            ->output();
    }

    /**
     * Genera el acuerdo y lo deja como contrato del proyecto, listo para
     * enviarse como cualquier otro.
     */
    public function generar(Project $proyecto, array $datos, ?User $quien = null, ?string $titulo = null): ProjectDocument
    {
        $ruta = 'proyectos/acuerdo-' . strtolower($proyecto->code) . '-' . now()->format('Ymd-His') . '.pdf';

        Storage::disk('local')->put($ruta, $this->pdf($proyecto, $datos));

        return $proyecto->documents()->create([
            'kind'        => 'contrato',
            'title'       => trim((string) $titulo) ?: 'Acuerdo de servicio ' . $proyecto->code,
            'file_path'   => $ruta,
            'uploaded_by' => $quien?->id,
            'notes'       => 'Generado por el sistema con la base del laboratorio.',
        ]);
    }

    /** @param array<string,string> $variables */
    private function reemplazar(string $texto, array $variables): string
    {
        foreach ($variables as $clave => $valor) {
            $texto = str_replace('{' . $clave . '}', $valor, $texto);
        }

        return $texto;
    }
}
