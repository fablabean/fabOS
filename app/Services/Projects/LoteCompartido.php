<?php

namespace App\Services\Projects;

use App\Models\Candidate;
use App\Models\CandidateBatch;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;

/**
 * Compartir la evaluación de un lote con quien no entra al panel (§11).
 *
 * La convocatoria se evalúa aquí, pero el resultado lo quiere ver gente de
 * fuera: la dirección de emprendimiento, el jurado, el aliado que mandó el
 * tablero. Darles cuenta en el backoffice para eso es abrirles el
 * laboratorio entero; mandarles un pantallazo es mandarles algo que envejece
 * al día siguiente.
 *
 * Esto arma una sola cosa —la tabla completa, con las columnas fijas, la
 * evaluación y los datos extra que trajo la lista— y la sirve de tres
 * maneras: una página con enlace firmado, un CSV, y las cifras para las
 * gráficas. Las tres salen de la misma tabla, así que no pueden contradecirse.
 */
class LoteCompartido
{
    /** Cuántos valores distintos puede tener un dato extra para que valga la pena graficarlo. */
    private const MAXIMO_DE_CATEGORIAS = 8;

    public const DIAS_DEL_ENLACE = 90;

    public function enlace(CandidateBatch $lote): string
    {
        return URL::temporarySignedRoute('lotes.compartido', now()->addDays(self::DIAS_DEL_ENLACE), ['batch' => $lote->id]);
    }

    public function enlaceCsv(CandidateBatch $lote): string
    {
        return URL::temporarySignedRoute('lotes.compartido.csv', now()->addDays(self::DIAS_DEL_ENLACE), ['batch' => $lote->id]);
    }

    /**
     * La tabla entera: columnas y filas, en el orden en que se leen.
     *
     * Las columnas extra son la unión de las que trajo cada candidato: si uno
     * trae «Valor a financiar» y otro no, la columna existe y en el segundo va
     * vacía. Es lo que se espera de una tabla, y no de un montón de fichas.
     *
     * @return array{columnas:list<string>,filas:list<array<string,string>>,extras:list<string>}
     */
    public function tabla(CandidateBatch $lote): array
    {
        $candidatos = $lote->candidates()->with('evaluatedBy', 'project')->orderBy('position')->get();

        $extras = $candidatos
            ->flatMap(fn (Candidate $c) => array_keys($c->extras()))
            ->unique()
            ->values()
            ->all();

        $fijas = [
            'Candidato', 'Organización', 'Contacto', 'Correo', 'Teléfono', 'De qué va',
            'Decisión', 'Nota', 'Por qué', 'Qué puede hacer el Fablab', 'Quién evaluó', 'Evaluado el', 'Proyecto',
        ];

        $tz = config('fabos.lab.timezone');

        $filas = $candidatos->map(function (Candidate $c) use ($extras, $tz) {
            $fila = [
                'Candidato'                 => (string) $c->name,
                'Organización'              => (string) $c->organization,
                'Contacto'                  => (string) $c->contact_name,
                'Correo'                    => (string) $c->contact_email,
                'Teléfono'                  => (string) $c->contact_phone,
                'De qué va'                 => (string) $c->description,
                'Decisión'                  => Candidate::ESTADOS[$c->status] ?? $c->status,
                'Nota'                      => $c->score ? (string) $c->score : '',
                'Por qué'                   => (string) $c->evaluation_note,
                'Qué puede hacer el Fablab' => (string) $c->fablab_note,
                'Quién evaluó'              => (string) ($c->evaluatedBy?->name ?? ''),
                'Evaluado el'               => $c->evaluated_at?->timezone($tz)->format('Y-m-d') ?? '',
                'Proyecto'                  => (string) ($c->project?->code ?? ''),
            ];

            foreach ($extras as $k) {
                $fila[$k] = (string) ($c->extras()[$k] ?? '');
            }

            return $fila;
        })->values()->all();

        return ['columnas' => array_merge($fijas, $extras), 'filas' => $filas, 'extras' => $extras];
    }

    /**
     * El CSV, como lo abre Excel en Colombia: punto y coma, UTF-8 con BOM.
     *
     * Sin el BOM, Excel muestra «SeÃ±alÃ©tica»; con coma, mete todo en una
     * celda. Son dos cosas que se descubren al abrirlo, y ya no hay a quién
     * decírselo.
     */
    public function csv(CandidateBatch $lote): string
    {
        $tabla = $this->tabla($lote);
        $salida = fopen('php://temp', 'r+');

        fwrite($salida, "\xEF\xBB\xBF");
        fputcsv($salida, $tabla['columnas'], ';', '"', '\\');

        foreach ($tabla['filas'] as $fila) {
            fputcsv($salida, array_values($fila), ';', '"', '\\');
        }

        rewind($salida);
        $csv = stream_get_contents($salida);
        fclose($salida);

        return $csv;
    }

    /**
     * Las cifras para las gráficas: la decisión, la nota, y cada dato extra
     * que se deje contar —pocos valores distintos, como «Ruta» o
     * «Programa»—. Un dato con treinta valores distintos es una lista, no una
     * gráfica.
     *
     * @return list<array{titulo:string,total:int,barras:list<array{etiqueta:string,cuantos:int}>}>
     */
    public function graficas(CandidateBatch $lote): array
    {
        $candidatos = $lote->candidates()->get();
        $graficas = [];

        $decision = $candidatos->countBy(fn (Candidate $c) => Candidate::ESTADOS[$c->status] ?? $c->status);
        $graficas[] = $this->grafica('Decisión', collect(Candidate::ESTADOS)->values()->mapWithKeys(fn ($e) => [$e => $decision[$e] ?? 0])->all());

        $conNota = $candidatos->whereNotNull('score');

        if ($conNota->isNotEmpty()) {
            $porNota = $conNota->countBy('score');
            $graficas[] = $this->grafica('Nota (1 a 5)', collect(range(1, 5))->mapWithKeys(fn ($n) => [(string) $n => $porNota[$n] ?? 0])->all());
        }

        $extras = $candidatos->flatMap(fn (Candidate $c) => array_keys($c->extras()))->unique();

        foreach ($extras as $clave) {
            $valores = $candidatos
                ->map(fn (Candidate $c) => $c->extras()[$clave] ?? null)
                ->filter()
                ->map(fn ($v) => mb_strimwidth(trim((string) $v), 0, 40, '…'));

            $distintos = $valores->unique()->count();

            // Una categoria es algo que se repite: «Ruta 4-6» en doce filas.
            // Un puntaje distinto en cada fila es una lista, no una grafica.
            if ($distintos < 2 || $distintos > self::MAXIMO_DE_CATEGORIAS || $distintos >= $valores->count()) {
                continue;
            }

            $graficas[] = $this->grafica($clave, $valores->countBy()->sortDesc()->all());
        }

        return $graficas;
    }

    /** @param  array<string,int>  $cuentas */
    private function grafica(string $titulo, array $cuentas): array
    {
        return [
            'titulo' => $titulo,
            'total'  => array_sum($cuentas),
            'barras' => collect($cuentas)->map(fn ($n, $k) => ['etiqueta' => (string) $k, 'cuantos' => (int) $n])->values()->all(),
        ];
    }

    /**
     * Los datos extra que sirven de filtro: los mismos que se dejan graficar.
     *
     * @return array<string,list<string>>  columna => valores
     */
    public function filtros(CandidateBatch $lote): array
    {
        $candidatos = $lote->candidates()->get();
        $filtros = [];

        foreach ($candidatos->flatMap(fn (Candidate $c) => array_keys($c->extras()))->unique() as $clave) {
            $valores = $candidatos->map(fn (Candidate $c) => $c->extras()[$clave] ?? null)->filter()->unique()->sort()->values();

            $conValor = $candidatos->map(fn (Candidate $c) => $c->extras()[$clave] ?? null)->filter()->count();

            // El mismo criterio que las graficas: se filtra por lo que se repite.
            if ($valores->count() >= 2 && $valores->count() <= self::MAXIMO_DE_CATEGORIAS && $valores->count() < $conValor) {
                $filtros[$clave] = $valores->all();
            }
        }

        return $filtros;
    }
}
