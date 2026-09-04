<?php

namespace App\Services\Projects;

use App\Models\Candidate;
use App\Models\CandidateBatch;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Meter una lista entera, evaluarla, y convertir lo aceptado (§11).
 *
 * Sin esto, la lista vive en un Excel que alguien reenvía, se evalúa en una
 * reunión, y lo acordado se pierde entre la reunión y el momento de arrancar.
 */
class LoteDeCandidatos
{
    /**
     * A dónde puede ir cada columna de la lista.
     *
     * La nota (1 a 5) no está a propósito: es lo que pone quien evalúa aquí,
     * y un «puntaje 913» de otra convocatoria no es eso. Va como dato extra,
     * que es lo que es.
     */
    public const DESTINOS = [
        'name'            => 'Candidato (nombre del proyecto)',
        'organization'    => 'Organización',
        'contact_name'    => 'Persona de contacto',
        'contact_email'   => 'Correo',
        'contact_phone'   => 'Teléfono',
        'description'     => 'Estado actual',
        'evaluation_note' => 'Nota de evaluación previa',
        'extra'           => 'Guardar como dato extra',
        'ignorar'         => 'No importar',
    ];

    /**
     * Palabras con las que suele venir cada cosa en una cabecera. Es una
     * sugerencia: quien importa la corrige antes de confirmar.
     *
     * @var array<string,list<string>>
     */
    private const PISTAS = [
        'name'            => ['proyecto', 'nombre', 'candidato', 'emprendimiento', 'empresa', 'iniciativa', 'titulo', 'título'],
        'organization'    => ['organizacion', 'organización', 'entidad', 'institucion', 'institución'],
        'contact_name'    => ['contacto', 'responsable', 'lider', 'líder', 'representante'],
        'contact_email'   => ['correo', 'email', 'e-mail', 'mail'],
        'contact_phone'   => ['telefono', 'teléfono', 'celular', 'movil', 'móvil', 'whatsapp'],
        'description'     => ['resumen', 'descripcion', 'descripción', 'estado actual', 'de que va', 'de qué va', 'detalle'],
        'evaluation_note' => ['sintesis', 'síntesis', 'evaluacion 1', 'evaluación 1', 'concepto', 'observaciones'],
    ];

    /**
     * Mira la lista antes de meterla: qué separador trae, si la primera fila
     * es cabecera, y qué columnas tiene.
     *
     * Se acepta lo que exportan Excel y Google Sheets en Colombia -punto y
     * coma, tabulador, coma, barra- y se respetan las comillas: un resumen
     * con punto y coma dentro no puede partir la fila.
     *
     * @return array{separador:string,cabecera:bool,columnas:list<string>,filas:int,mapa:array<int,string>,muestra:list<list<string>>}
     */
    public function analizar(string $texto): array
    {
        $lineas = $this->lineasDe($texto);

        if ($lineas === []) {
            return ['separador' => "\t", 'cabecera' => false, 'columnas' => [], 'filas' => 0, 'mapa' => [], 'muestra' => []];
        }

        $separador = $this->separadorDe($lineas[0]);
        $primera = $this->partir($lineas[0], $separador);
        $cabecera = $this->pareceCabeceraEntera($primera);

        $columnas = $cabecera
            ? array_map(fn ($c, $i) => $c !== '' ? $c : 'Columna ' . ($i + 1), $primera, array_keys($primera))
            : array_map(fn ($i) => 'Columna ' . ($i + 1), array_keys($primera));

        $datos = $cabecera ? array_slice($lineas, 1) : $lineas;

        return [
            'separador' => $separador,
            'cabecera'  => $cabecera,
            'columnas'  => array_values($columnas),
            'filas'     => count($datos),
            'mapa'      => $this->sugerirMapa(array_values($columnas), $cabecera),
            'muestra'   => array_map(fn ($l) => $this->partir($l, $separador), array_slice($datos, 0, 3)),
        ];
    }

    /**
     * Mete la lista con las columnas donde quien importa dijo.
     *
     * @param  array<int,string>  $mapa  posición de la columna => destino
     * @return int cuántos entraron
     */
    public function importar(CandidateBatch $lote, string $texto, array $mapa, bool $conCabecera): int
    {
        $lineas = $this->lineasDe($texto);

        if ($lineas === []) {
            return 0;
        }

        $separador = $this->separadorDe($lineas[0]);
        $columnas = $conCabecera ? $this->partir(array_shift($lineas), $separador) : [];

        $deNombre = array_search('name', $mapa, true);

        if ($deNombre === false) {
            throw new ProjectException('Alguna columna tiene que ser el nombre del candidato: sin nombre no hay a quién evaluar.');
        }

        $posicion = (int) $lote->candidates()->max('position');
        $cuantos = 0;

        foreach ($lineas as $linea) {
            $campos = $this->partir($linea, $separador);
            $nombre = trim((string) ($campos[$deNombre] ?? ''));

            if ($nombre === '') {
                continue;
            }

            $fila = ['name' => mb_substr($nombre, 0, 255), 'extra' => []];

            foreach ($mapa as $i => $destino) {
                $valor = trim((string) ($campos[$i] ?? ''));

                if ($destino === 'name' || $destino === 'ignorar' || $valor === '') {
                    continue;
                }

                if ($destino === 'extra') {
                    $fila['extra'][$columnas[$i] ?? 'Columna ' . ($i + 1)] = $valor;
                } elseif ($destino === 'contact_email') {
                    $fila[$destino] = filter_var($valor, FILTER_VALIDATE_EMAIL) ? $valor : null;
                } else {
                    // Si dos columnas van al mismo sitio, se juntan: mejor
                    // largo que perdido.
                    $fila[$destino] = isset($fila[$destino]) ? $fila[$destino] . "\n" . $valor : $valor;
                }
            }

            $fila['extra'] = $fila['extra'] ?: null;
            $fila['position'] = ++$posicion;

            $lote->candidates()->create($fila);
            $cuantos++;
        }

        return $cuantos;
    }

    /**
     * Qué columna va a dónde, a partir de la cabecera.
     *
     * @param  list<string>  $columnas
     * @return array<int,string>
     */
    public function sugerirMapa(array $columnas, bool $cabecera): array
    {
        // Sin cabecera, el orden de siempre: nombre, organización, contacto,
        // correo, descripción, y el resto como extra.
        if (! $cabecera) {
            $orden = ['name', 'organization', 'contact_name', 'contact_email', 'description'];

            return array_map(fn ($i) => $orden[$i] ?? 'extra', array_keys($columnas));
        }

        $mapa = [];
        $usados = [];

        foreach ($columnas as $i => $columna) {
            $plano = mb_strtolower(trim($columna));
            $destino = 'extra';

            foreach (self::PISTAS as $candidato => $pistas) {
                if (in_array($candidato, $usados, true)) {
                    continue;
                }

                foreach ($pistas as $pista) {
                    if (str_contains($plano, $pista)) {
                        $destino = $candidato;
                        break 2;
                    }
                }
            }

            if ($destino !== 'extra') {
                $usados[] = $destino;
            }

            $mapa[$i] = $destino;
        }

        // Sin nombre no hay lista: si nada parecía nombre, la primera columna.
        if (! in_array('name', $mapa, true) && $mapa !== []) {
            $mapa[0] = 'name';
        }

        return $mapa;
    }

    /** @return list<string> */
    private function lineasDe(string $texto): array
    {
        // El BOM con que Excel marca el UTF-8 se colaba en la primera
        // cabecera: «﻿Puntaje» no es «Puntaje».
        $texto = preg_replace('/^\xEF\xBB\xBF/', '', $texto);

        return array_values(array_filter(
            array_map('rtrim', preg_split('/\r\n|\r|\n/', $texto)),
            fn ($l) => trim($l) !== '',
        ));
    }

    private function separadorDe(string $primera): string
    {
        $cuenta = [
            "\t" => substr_count($primera, "\t"),
            ';'  => substr_count($primera, ';'),
            '|'  => substr_count($primera, '|'),
            ','  => substr_count($primera, ','),
        ];

        arsort($cuenta);

        return array_key_first($cuenta);
    }

    /** @return list<string> */
    private function partir(string $linea, string $separador): array
    {
        return array_map('trim', str_getcsv($linea, $separador, '"', '\\'));
    }

    /**
     * Si la primera fila es cabecera: ninguna celda parece un correo o un
     * número largo, y alguna dice cómo se llama una columna.
     *
     * @param  list<string>  $celdas
     */
    private function pareceCabeceraEntera(array $celdas): bool
    {
        $todasPistas = array_merge(...array_values(self::PISTAS));

        foreach ($celdas as $c) {
            if (filter_var($c, FILTER_VALIDATE_EMAIL) || preg_match('/^\d{3,}$/', $c)) {
                return false;
            }
        }

        foreach ($celdas as $c) {
            $plano = mb_strtolower($c);

            if ($this->pareceCabecera($c)) {
                return true;
            }

            foreach ($todasPistas as $pista) {
                if (str_contains($plano, $pista)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Mete la lista tal como llega: pegada desde una hoja de cálculo.
     *
     * Se acepta tabulador, punto y coma o barra vertical porque eso es lo que
     * sale al copiar de Excel, de Google Sheets o de un correo, y pedirle a
     * quien pega que primero convierta el formato es pedirle que no lo haga.
     *
     * Las columnas, en orden: **nombre, organización, contacto, correo,
     * descripción**. Solo la primera es obligatoria; una lista de nombres a
     * secas ya sirve para empezar a evaluar.
     *
     * @return int cuántos entraron
     */
    public function pegar(CandidateBatch $lote, string $texto): int
    {
        $posicion = (int) $lote->candidates()->max('position');
        $cuantos = 0;

        foreach (preg_split('/\r\n|\r|\n/', $texto) as $linea) {
            $linea = trim($linea);

            if ($linea === '') {
                continue;
            }

            $campos = array_map('trim', preg_split('/\t|;|\|/', $linea));

            if (($campos[0] ?? '') === '') {
                continue;
            }

            // Una cabecera pegada por descuido no es un candidato.
            if ($cuantos === 0 && $posicion === 0 && $this->pareceCabecera($campos[0])) {
                continue;
            }

            $lote->candidates()->create([
                'name'          => mb_substr($campos[0], 0, 255),
                'organization'  => $this->campo($campos, 1),
                'contact_name'  => $this->campo($campos, 2),
                'contact_email' => filter_var($this->campo($campos, 3) ?? '', FILTER_VALIDATE_EMAIL)
                    ? $campos[3]
                    : null,
                'description'   => $this->campo($campos, 4),
                'position'      => ++$posicion,
            ]);

            $cuantos++;
        }

        return $cuantos;
    }

    /**
     * Evalúa un candidato.
     *
     * Queda quién lo hizo y cuándo. Una decisión sin autor se discute otra vez
     * dentro de un mes, y nadie recuerda por qué se dijo que no.
     */
    public function evaluar(
        Candidate $candidato,
        string $decision,
        ?int $nota = null,
        ?string $comentario = null,
        ?User $quien = null,
        ?string $fablab = null,
    ): Candidate {
        if (! array_key_exists($decision, Candidate::ESTADOS)) {
            throw new ProjectException('Esa decisión no existe.');
        }

        // Con nota puesta, «sin evaluar» ya no es verdad: alguien lo miro.
        // Si no lo acepto ni lo descarto, lo dejo en espera.
        if ($decision === 'pendiente' && $nota !== null) {
            $decision = 'espera';
        }

        $candidato->update([
            'status'          => $decision,
            'score'           => $nota,
            'evaluation_note' => $comentario,
            // Lo que el laboratorio le ofrece si sigue. Iba dentro del porque
            // con un «Fablab:» delante; en su columna se filtra, se exporta y
            // pasa al proyecto.
            'fablab_note'     => $fablab,
            'evaluated_at'    => $decision === 'pendiente' ? null : now(),
            'evaluated_by'    => $decision === 'pendiente' ? null : $quien?->id,
        ]);

        return $candidato->refresh();
    }

    /**
     * Convierte un candidato aceptado en proyecto.
     *
     * Solo lo aceptado, y solo una vez: convertir dos veces daría dos proyectos
     * para el mismo encargo, y el segundo aparecería sin explicación en el
     * listado de quien coordina.
     *
     * @throws ProjectException
     */
    public function convertir(Candidate $candidato, ?User $quien = null): Project
    {
        if ($candidato->status !== 'aceptado') {
            throw new ProjectException('Solo se convierten los candidatos aceptados. Evalúalo primero.');
        }

        if ($candidato->yaEsProyecto()) {
            throw new ProjectException('Este candidato ya es ' . $candidato->project->code . '.');
        }

        return DB::transaction(function () use ($candidato, $quien) {
            $proyecto = app(ProjectService::class)->registrarIdea([
                'name'          => $candidato->name,
                'source'        => 'interno',
                'organization'  => $candidato->organization,
                'contact_name'  => $candidato->contact_name,
                'contact_email' => $candidato->contact_email,
                'contact_phone' => $candidato->contact_phone,
                // Lo que se escribió al evaluarlo es lo primero que hay que
                // recordar al arrancar: por qué se aceptó.
                'summary'       => trim(implode("\n\n", array_filter([
                    $candidato->description,
                    $candidato->evaluation_note
                        ? 'Al evaluarlo: ' . $candidato->evaluation_note
                        : null,
                    $candidato->fablab_note
                        ? 'Lo que puede hacer el Fablab: ' . $candidato->fablab_note
                        : null,
                ]))) ?: null,
                'notes'         => 'Viene del lote «' . $candidato->batch->name . '».',
            ], $quien);

            $candidato->update(['project_id' => $proyecto->id]);

            return $proyecto;
        });
    }

    /**
     * Convierte todo lo aceptado que quede pendiente.
     *
     * @return int cuántos proyectos se crearon
     */
    public function convertirLoAceptado(CandidateBatch $lote, ?User $quien = null): int
    {
        $pendientes = $lote->candidates()
            ->where('status', 'aceptado')
            ->whereNull('project_id')
            ->get();

        foreach ($pendientes as $candidato) {
            $this->convertir($candidato, $quien);
        }

        return $pendientes->count();
    }

    private function campo(array $campos, int $indice): ?string
    {
        $valor = trim($campos[$indice] ?? '');

        return $valor === '' ? null : mb_substr($valor, 0, 255);
    }

    /** «Nombre», «Proyecto», «Empresa»: lo que encabeza una hoja de cálculo. */
    private function pareceCabecera(string $primero): bool
    {
        $limpio = mb_strtolower(trim($primero));

        return in_array($limpio, [
            'nombre', 'proyecto', 'nombre del proyecto', 'empresa',
            'organización', 'organizacion', 'spinoff', 'spin-off', 'candidato',
        ], true);
    }
}
