<?php

namespace App\Services\Projects;

use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectPartner;
use App\Models\Setting;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\Settings;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * El acuerdo de alianza que el sistema redacta (§11).
 *
 * El acuerdo de servicio dice «el laboratorio presta a {cliente}», y en una
 * alianza nadie presta nada a nadie: varias partes ponen algo cada una, y lo
 * que se construye es de todas según lo que pusieron. Son cláusulas
 * distintas —partes, aportes, participación, salida de un aliado— sobre la
 * misma mecánica: una base que el laboratorio escribe una vez, variables
 * que el proyecto ya sabe, y un PDF que queda como contrato del proyecto y
 * le llega a cada parte.
 */
class AcuerdoDeAlianza
{
    public const CLAUSULAS_BASE = <<<'TXT'
1. Objeto. Las partes que firman este acuerdo se alían para construir el proyecto «{proyecto}» ({codigo}) en {laboratorio}, de {institucion}: {objeto}

2. Las partes y sus aportes. Cada parte pone lo que aquí se dice, y responde por ello:
{aportes}

El valor total de los aportes es de {total}.

3. Participación. Lo que se construya, incluidos los diseños, los prototipos y los resultados, es de las partes en la proporción que aquí se fija:
{participacion}

Lo que no esté repartido se decide de común acuerdo antes de cerrar el proyecto.

4. Cómo se trabaja. El proyecto se lleva en {laboratorio}, con su responsable, sus tareas y su cronograma a la vista de todas las partes. Cada parte designa a una persona que responde por ella. Las decisiones que cambien el alcance, los aportes o la participación se toman por escrito y con acuerdo de todas las partes.

5. Nuevos aliados. Una parte nueva puede entrar si las partes existentes lo aceptan; su aporte y su participación se fijan al entrar, y este acuerdo se actualiza.

6. Salida de una parte. Una parte puede retirarse por escrito. Lo que ya aportó se queda en el proyecto y su participación se redistribuye entre las que siguen, salvo que se acuerde otra cosa. Si el proyecto se termina antes de tiempo, cada parte recupera lo suyo en la medida en que exista.

7. Uso del laboratorio. Las máquinas, el espacio y el material de {laboratorio} se usan bajo sus reglas de seguridad y habilitación. El laboratorio puede citar el proyecto como referencia de su trabajo, y las partes pueden hacerlo también, nombrando a las demás.

8. Confidencialidad. Lo que una parte comparta marcándolo como confidencial no sale del proyecto sin su permiso.

9. Vigencia. Este acuerdo vale desde su firma hasta que el proyecto se cierre. Se firma en {ciudad} el {fecha}.
TXT;

    /** Lo que se puede escribir entre llaves en las cláusulas, y qué es. */
    public const VARIABLES = [
        'laboratorio'   => 'nombre del laboratorio',
        'institucion'   => 'institución a la que pertenece',
        'ciudad'        => 'ciudad',
        'proyecto'      => 'nombre del proyecto',
        'codigo'        => 'código del proyecto',
        'objeto'        => 'lo que se construye',
        'partes'        => 'las partes, una por línea',
        'aportes'       => 'qué pone cada parte, una por línea',
        'participacion' => 'la participación de cada parte, una por línea',
        'total'         => 'valor total de los aportes, en pesos',
        'responsable'   => 'quién responde por el proyecto en el laboratorio',
        'fecha'         => 'fecha del acuerdo',
    ];

    public function __construct(private NotificationService $avisos) {}

    /** La base del laboratorio: la guardada, o la que trae el sistema. */
    public static function clausulasBase(): string
    {
        return trim((string) Setting::get(Settings::ALIANZA_CLAUSULAS, '')) ?: self::CLAUSULAS_BASE;
    }

    /** @return array<string,mixed> */
    public function datosSugeridos(Project $proyecto): array
    {
        return [
            'objeto'    => trim((string) $proyecto->summary),
            'clausulas' => self::clausulasBase(),
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
        $partes = $proyecto->partners()->confirmados()->get();
        $simbolo = config('fabos.money.symbol');

        return [
            'laboratorio'   => (string) config('fabos.lab.name'),
            'institucion'   => (string) config('fabos.lab.institution'),
            'ciudad'        => (string) config('fabos.lab.city'),
            'proyecto'      => $proyecto->name,
            'codigo'        => $proyecto->code,
            'objeto'        => trim((string) ($datos['objeto'] ?? '')) ?: 'lo descrito en el proyecto.',
            'partes'        => $partes->map(fn (ProjectPartner $p) => '- ' . $p->quien() . ($p->document ? ' (' . $p->document . ')' : '') . ' · ' . mb_strtolower($p->papel()))->implode("\n"),
            'aportes'       => $partes->map(fn (ProjectPartner $p) => '- ' . $p->quien() . ': ' . $p->aporteLegible())->implode("\n"),
            'participacion' => $partes->map(fn (ProjectPartner $p) => '- ' . $p->quien() . ': '
                . ($p->share_percent !== null ? rtrim(rtrim(number_format((float) $p->share_percent, 2, ',', '.'), '0'), ',') . ' %' : 'por definir'))->implode("\n"),
            'total'         => $simbolo . number_format($proyecto->totalAportado(), 0, ',', '.') . ' pesos colombianos',
            'responsable'   => $proyecto->lead?->name ?? 'la coordinación del laboratorio',
            'fecha'         => Carbon::now($tz)->locale('es')->isoFormat('D [de] MMMM [de] YYYY'),
        ];
    }

    public function clausulas(Project $proyecto, array $datos): string
    {
        $texto = trim((string) ($datos['clausulas'] ?? '')) ?: self::clausulasBase();

        foreach ($this->variables($proyecto, $datos) as $clave => $valor) {
            $texto = str_replace('{' . $clave . '}', $valor, $texto);
        }

        return $texto;
    }

    public function render(Project $proyecto, array $datos, bool $paraPdf = false): string
    {
        return view('proyectos.acuerdo-alianza', [
            'proyecto'  => $proyecto,
            'partes'    => $proyecto->partners()->confirmados()->get(),
            'variables' => $this->variables($proyecto, $datos),
            'clausulas' => $this->clausulas($proyecto, $datos),
            'paraPdf'   => $paraPdf,
        ])->render();
    }

    public function pdf(Project $proyecto, array $datos): string
    {
        return Pdf::loadHTML($this->render($proyecto, $datos, paraPdf: true))
            ->setPaper('letter')
            ->output();
    }

    /**
     * Genera el acuerdo, lo deja como contrato del proyecto y, si se pide,
     * se lo manda a cada parte confirmada con correo.
     *
     * @throws ProjectException
     */
    public function generar(Project $proyecto, array $datos, ?User $quien = null, bool $enviar = false, ?string $mensaje = null): ProjectDocument
    {
        if (! $proyecto->esAlianza()) {
            throw new ProjectException('Este proyecto no es una alianza.');
        }

        if ($proyecto->partners()->confirmados()->count() < 2) {
            throw new ProjectException('Un acuerdo de alianza necesita al menos dos partes confirmadas.');
        }

        $ruta = 'proyectos/alianza-' . strtolower($proyecto->code) . '-' . now()->format('Ymd-His') . '.pdf';
        Storage::disk('local')->put($ruta, $this->pdf($proyecto, $datos));

        $documento = $proyecto->documents()->create([
            'kind'        => 'contrato',
            'title'       => 'Acuerdo de alianza ' . $proyecto->code,
            'file_path'   => $ruta,
            'uploaded_by' => $quien?->id,
            'notes'       => 'Generado por el sistema con la base de alianzas del laboratorio.',
        ]);

        if ($enviar) {
            $this->enviar($proyecto, $documento, $mensaje, $quien);
        }

        return $documento;
    }

    /** A cada parte confirmada con correo, con el enlace firmado al documento. */
    public function enviar(Project $proyecto, ProjectDocument $documento, ?string $mensaje = null, ?User $quien = null): int
    {
        $enviados = 0;

        foreach ($proyecto->partners()->confirmados()->get() as $parte) {
            if (blank($parte->email) || $parte->esElLaboratorio()) {
                continue;
            }

            $datos = [
                'proyecto' => $proyecto->name,
                'codigo'   => $proyecto->code,
                'mensaje'  => $mensaje ?? '',
                'enlace'   => URL::temporarySignedRoute('proyectos.documento', now()->addDays(60), [
                    'project' => $proyecto->id, 'document' => $documento->id,
                ]),
            ];

            $parte->user
                ? $this->avisos->enviar('alianza.acuerdo', $parte->user, $datos, $parte)
                : $this->avisos->enviarSinCuenta('alianza.acuerdo', $parte->email, $parte->name, $datos, $parte);

            $enviados++;
        }

        $proyecto->update(['contract_sent_at' => now()]);
        $proyecto->comments()->create([
            'user_id'     => $quien?->id,
            'author_name' => $quien?->name ?: 'El laboratorio',
            'side'        => 'laboratorio',
            'body'        => 'Enviamos el acuerdo de alianza «' . $documento->title . '» a ' . $enviados . ' ' . ($enviados === 1 ? 'parte' : 'partes') . ' para su firma.'
                . (filled($mensaje) ? "\n\n" . $mensaje : ''),
        ]);

        return $enviados;
    }
}
