<?php

namespace App\Services\Staffing;

use App\Models\ShiftAssignment;
use App\Support\DiasHabiles;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * El ADF-404-f4 de la Universidad, «Autorización trabajo horas extras»,
 * diligenciado con los datos de una persona (§5).
 *
 * Se parte del formato tal como lo usa el laboratorio
 * (`resources/formatos/ADF-404-f4.xlsx`) y solo se escriben las casillas en
 * blanco: el encabezado, y por cada jornada la fecha, el horario laboral, el
 * horario extra y las actividades. Las columnas que el formato calcula —día de
 * la semana, si es válida, si el día es festivo, diurnas, nocturnas y
 * festivas, los totales— se dejan con sus fórmulas, y Excel las calcula al
 * abrir el archivo: el que lo revisa en nómina ve las mismas cuentas de
 * siempre.
 *
 * La hoja oculta de festivos («Hoja1») traía los domingos y festivos de 2026.
 * Se reescribe con los de los años del reporte, para que un festivo de 2027
 * salga como festivo.
 */
class FormatoAdf404
{
    public const PLANTILLA = 'formatos/ADF-404-f4.xlsx';

    /** Filas de la relación de horas extras en el formato. */
    public const PRIMERA_FILA = 14;
    public const ULTIMA_FILA = 58;
    public const FILAS = self::ULTIMA_FILA - self::PRIMERA_FILA + 1;

    /** Donde el formato parte el día y la noche: 6:00 a 19:00 es diurno. */
    public const AMANECE = 6 * 60;
    public const ANOCHECE = 19 * 60;

    /**
     * Los minutos de una jornada, partidos como los parte el formato:
     * diurnas, nocturnas, y las mismas en domingo o festivo.
     *
     * @return array{diurnas:int,nocturnas:int,diurnas_festivas:int,nocturnas_festivas:int}
     */
    public static function partir(ShiftAssignment $jornada): array
    {
        return self::partirFranja($jornada->starts_at, $jornada->ends_at);
    }

    /** @return array{diurnas:int,nocturnas:int,diurnas_festivas:int,nocturnas_festivas:int} */
    public static function partirFranja(CarbonInterface $desde, CarbonInterface $hasta): array
    {
        $tz = config('fabos.lab.timezone');
        $t = Carbon::instance($desde)->copy()->setTimezone($tz);
        $fin = Carbon::instance($hasta)->copy()->setTimezone($tz);
        $r = ['diurnas' => 0, 'nocturnas' => 0, 'diurnas_festivas' => 0, 'nocturnas_festivas' => 0];

        while ($t->lt($fin)) {
            $minuto = $t->hour * 60 + $t->minute;
            // El siguiente borde: las 6:00, las 19:00 o la medianoche.
            $borde = $t->copy()->startOfDay()->addMinutes(match (true) {
                $minuto < self::AMANECE  => self::AMANECE,
                $minuto < self::ANOCHECE => self::ANOCHECE,
                default                  => 24 * 60,
            });
            $hastaAqui = $borde->lt($fin) ? $borde : $fin;
            $min = (int) $t->diffInMinutes($hastaAqui);
            $festivo = self::esFestivo($t);
            $diurno = $minuto >= self::AMANECE && $minuto < self::ANOCHECE;
            $r[($diurno ? 'diurnas' : 'nocturnas') . ($festivo ? '_festivas' : '')] += $min;
            $t = $hastaAqui;
        }

        return $r;
    }

    /** Domingo o festivo, como lo cuenta el formato. */
    public static function esFestivo(CarbonInterface $dia): bool
    {
        return $dia->isSunday() || DiasHabiles::festivo($dia) !== null;
    }

    /**
     * Escribe el formato y devuelve dónde quedó.
     *
     * @param  array{area?:?string,nombre:string,documento?:?string,tipo_contrato?:?string,jefe?:?string}  $encabezado
     * @param  list<array{fecha:string,laboral_inicio:string,laboral_fin:string,extra_inicio:string,extra_fin:string,actividades:?string}>  $filas
     * @param  array{trabajador?:?string,coordinador?:?string}  $firmas  el PNG de cada firma, si ya firmó
     */
    public function guardar(array $encabezado, array $filas, array $firmas = []): string
    {
        if (count($filas) > self::FILAS) {
            throw new \InvalidArgumentException('El formato tiene ' . self::FILAS . ' filas.');
        }

        $base = tempnam(sys_get_temp_dir(), 'adf404');
        @unlink($base);
        $ruta = $base . '.xlsx';
        copy(resource_path(self::PLANTILLA), $ruta);

        $zip = new \ZipArchive();
        if ($zip->open($ruta) !== true) {
            throw new \RuntimeException('No se pudo abrir la plantilla del ADF-404-f4.');
        }

        $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');

        $area = trim((string) ($encabezado['area'] ?? ''));
        $hoja = self::texto($hoja, 'B5', 'PROCESO Y/O ÁREA: ' . ($area !== '' ? mb_strtoupper($area) : '_________________'));
        $hoja = self::texto($hoja, 'B8', mb_strtoupper(trim($encabezado['nombre'])));

        $documento = preg_replace('/\D/', '', (string) ($encabezado['documento'] ?? ''));
        $hoja = $documento !== '' && strlen($documento) < 16
            ? self::celda($hoja, 'G8', '<v>' . $documento . '</v>')
            : self::texto($hoja, 'G8', trim((string) ($encabezado['documento'] ?? '')));

        $tipo = $encabezado['tipo_contrato'] ?? null;
        $hoja = self::texto($hoja, 'M8', 'TERMINO FIJO ' . ($tipo === 'fijo' ? '__X__' : '_____') . '      ');
        $hoja = self::texto($hoja, 'O8', 'TERMINO INDEFINIDO' . ($tipo === 'indefinido' ? '__X__' : '_____'));

        if (filled($encabezado['jefe'] ?? null)) {
            $hoja = self::texto($hoja, 'K65', mb_strtoupper(trim($encabezado['jefe'])));
        }

        $años = [];
        $totales = ['J' => 0.0, 'K' => 0.0, 'L' => 0.0, 'M' => 0.0];
        foreach (array_values($filas) as $i => $f) {
            $r = self::PRIMERA_FILA + $i;
            $dia = Carbon::parse($f['fecha'], config('fabos.lab.timezone'));
            $años[$dia->year] = true;

            $hoja = self::celda($hoja, "B{$r}", '<v>' . self::serial($dia) . '</v>', 103);
            foreach (['D' => 'laboral_inicio', 'E' => 'laboral_fin', 'F' => 'extra_inicio', 'G' => 'extra_fin'] as $col => $campo) {
                $hoja = self::celda($hoja, "{$col}{$r}", '<v>' . self::fraccion($f[$campo]) . '</v>');
            }
            $hoja = self::calculado($hoja, $r, $dia, $f, $totales);
            $hoja = self::texto($hoja, "N{$r}", (string) ($f['actividades'] ?? ''), 98);
            $hoja = self::celda($hoja, "O{$r}", null, 99);
            $hoja = self::celda($hoja, "P{$r}", null, 100);
            // Alta como en el formato diligenciado: las actividades son un párrafo.
            $hoja = preg_replace('/<row r="' . $r . '"((?: (?!ht=|customHeight=)[a-zA-Z0-9:]+="[^"]*")*)/', '<row r="' . $r . '"$1 ht="67.5" customHeight="1"', $hoja, 1);
        }

        foreach ($totales as $col => $valor) {
            $hoja = self::resultado($hoja, "{$col}59", $valor);
        }
        $total = array_sum($totales);
        $hoja = self::resultado($hoja, 'N59', $total);
        $hoja = self::resultado($hoja, 'O59', $total > 48 ? '🚫 EXCESO DE HORAS PERMITIDAS' : '✔ OK');

        $zip->addFromString('xl/worksheets/sheet1.xml', $hoja);
        self::ponerFirmas($zip, array_filter($firmas));
        $zip->addFromString('xl/worksheets/sheet2.xml', self::festivos(
            $zip->getFromName('xl/worksheets/sheet2.xml'),
            array_keys($años ?: [now()->year => true]),
        ));
        $zip->close();

        return $ruta;
    }

    /**
     * Deja escrito en la fila lo que dan sus fórmulas —el día de la semana, si
     * es válida, el tipo de día y las horas de cada categoría—, con la misma
     * cuenta que hacen ellas. Excel abre lo descargado en vista protegida, y
     * ahí no recalcula: sin esto esas columnas se veían vacías.
     *
     * @param  array<string,float>  $totales  se le suman las horas de la fila
     */
    private static function calculado(string $hoja, int $r, CarbonInterface $dia, array $f, array &$totales): string
    {
        [$d, $e, $fi, $g] = array_map(
            fn (string $campo) => (float) self::fraccion((string) $f[$campo]),
            ['laboral_inicio', 'laboral_fin', 'extra_inicio', 'extra_fin'],
        );

        $noValida = ($d < $e && $fi < $e && $g > $d)
            || ($d > $e && ($fi >= $d || $fi < $e || $g <= $e));
        $festivo = self::esFestivo($dia);

        // Las de J a M, tal cual: diurna hasta la hora «Nocturna» (19:00), nocturna después.
        $noche = self::ANOCHECE / 1440;
        $diurna = $fi == 0.0 ? 0.0 : ($g > $fi ? max(0, min($g, $noche) - $fi) : max(0, $noche - $fi));
        $nocturna = $fi == 0.0 ? 0.0 : ($g > $fi ? max(0, $g - max($fi, $noche)) : max(0, (24 - max($fi, $noche)) + $g));

        $horas = [
            'J' => $festivo ? 0.0 : $diurna,
            'K' => $festivo ? 0.0 : $nocturna,
            'L' => $festivo ? $diurna : 0.0,
            'M' => $festivo ? $nocturna : 0.0,
        ];

        $hoja = self::resultado($hoja, "C{$r}", mb_convert_case($dia->copy()->locale('es')->isoFormat('dddd'), MB_CASE_TITLE, 'UTF-8'));
        $hoja = self::resultado($hoja, "H{$r}", $noValida ? 'NO VÁLIDA' : 'VÁLIDA');
        $hoja = self::resultado($hoja, "I{$r}", $festivo ? 'Festivo' : 'Ordinario');
        foreach ($horas as $col => $valor) {
            $hoja = self::resultado($hoja, "{$col}{$r}", $valor);
            $totales[$col] += $valor;
        }

        return $hoja;
    }

    /**
     * Guarda junto a la fórmula de la casilla el valor que da, como lo deja
     * Excel al calcular. La fórmula sigue ahí y Excel la vuelve a correr.
     */
    private static function resultado(string $hoja, string $ref, string|float $valor): string
    {
        // La fórmula puede ser propia o compartida con la fila de arriba (<f t="shared" si="0"/>).
        $patron = '/(<c r="' . $ref . '"[^>]*)>(<f(?:[^>]*\/>|[^>]*>.*?<\/f>))(?:<v>.*?<\/v>)?(<\/c>)/s';

        if (! preg_match($patron, $hoja)) {
            throw new \RuntimeException("La plantilla del ADF-404-f4 no tiene la fórmula de {$ref}.");
        }

        if (is_string($valor)) {
            $v = '<v>' . htmlspecialchars($valor, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</v>';
            $tipo = ' t="str"';
        } else {
            $v = '<v>' . (rtrim(rtrim(number_format($valor, 15, '.', ''), '0'), '.') ?: '0') . '</v>';
            $tipo = '';
        }

        return preg_replace_callback($patron, fn ($m) => preg_replace('/ t="[^"]*"/', '', $m[1]) . $tipo . '>' . $m[2] . $v . $m[3], $hoja, 1);
    }

    /**
     * Dónde va cada firma: donde las ponía el formato diligenciado a mano.
     * La del colaborador, bajo «FIRMA COLABORADOR» (desde la columna C); la
     * del jefe, bajo «NOMBRE Y FIRMA DEL JEFE INMEDIATO» (columna L) y encima
     * de su nombre, sin taparlo: el lienzo es blanco, no transparente. Ancho
     * y alto en EMU, con la proporción del recuadro de firma.
     */
    private const FIRMAS = [
        'trabajador'  => ['col' => 2, 'colOff' => 60000, 'row' => 62, 'rowOff' => 10000, 'cx' => 1430000, 'cy' => 449400],
        'coordinador' => ['col' => 11, 'colOff' => 0, 'row' => 62, 'rowOff' => 10000, 'cx' => 1000000, 'cy' => 314300],
    ];

    /** @param  array<string,string>  $firmas  parte => PNG */
    private static function ponerFirmas(\ZipArchive $zip, array $firmas): void
    {
        if (! $firmas) {
            return;
        }

        $dibujo = $zip->getFromName('xl/drawings/drawing1.xml');
        $enlaces = $zip->getFromName('xl/drawings/_rels/drawing1.xml.rels');
        $id = 20;

        foreach (self::FIRMAS as $parte => $p) {
            if (empty($firmas[$parte])) {
                continue;
            }

            $rid = 'rIdFirma' . ucfirst($parte);
            $zip->addFromString("xl/media/firma-{$parte}.png", $firmas[$parte]);
            $enlaces = str_replace('</Relationships>', '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/firma-' . $parte . '.png"/></Relationships>', $enlaces);

            $id++;
            $dibujo = str_replace('</xdr:wsDr>', '<xdr:oneCellAnchor>'
                . '<xdr:from><xdr:col>' . $p['col'] . '</xdr:col><xdr:colOff>' . $p['colOff'] . '</xdr:colOff><xdr:row>' . $p['row'] . '</xdr:row><xdr:rowOff>' . $p['rowOff'] . '</xdr:rowOff></xdr:from>'
                . '<xdr:ext cx="' . $p['cx'] . '" cy="' . $p['cy'] . '"/>'
                . '<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="' . $id . '" name="Firma ' . $parte . '"/><xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
                . '<xdr:blipFill><a:blip xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
                . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . $p['cx'] . '" cy="' . $p['cy'] . '"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr></xdr:pic>'
                . '<xdr:clientData/></xdr:oneCellAnchor></xdr:wsDr>', $dibujo);
        }

        $zip->addFromString('xl/drawings/drawing1.xml', $dibujo);
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', $enlaces);
    }

    /** El número de día de Excel: días desde el 30 de diciembre de 1899. */
    public static function serial(CarbonInterface $dia): int
    {
        return (int) Carbon::parse('1899-12-30', 'UTC')->diffInDays(Carbon::parse($dia->toDateString(), 'UTC'));
    }

    /** «17:30» como fracción del día, que es como Excel guarda una hora. */
    public static function fraccion(string $hora): string
    {
        [$h, $m] = array_map('intval', explode(':', $hora) + [0, 0]);
        $valor = (($h * 60 + $m) % 1440) / 1440;

        return rtrim(rtrim(number_format($valor, 15, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * La hoja de festivos: los domingos y festivos de esos años, del más viejo
     * al más nuevo, cada uno con «Festivo» al lado como la buscan las fórmulas.
     *
     * @param  list<int>  $años
     */
    private static function festivos(string $xml, array $años): string
    {
        sort($años);
        $tz = config('fabos.lab.timezone');
        $dias = [];
        foreach ($años as $año) {
            for ($d = Carbon::create($año, 1, 1, 0, 0, 0, $tz); $d->year === $año; $d->addDay()) {
                if (self::esFestivo($d)) {
                    $dias[] = self::serial($d);
                }
            }
        }

        $filas = '';
        foreach (array_slice($dias, 0, 400) as $i => $serial) {
            $n = $i + 1;
            $filas .= '<row r="' . $n . '"><c r="A' . $n . '" s="48"><v>' . $serial . '</v></c>'
                . '<c r="B' . $n . '" t="inlineStr"><is><t>Festivo</t></is></c></row>';
        }

        $xml = preg_replace('/<sheetData>.*<\/sheetData>|<sheetData\/>/s', '<sheetData>' . $filas . '</sheetData>', $xml, 1);

        return preg_replace('/<dimension ref="[^"]*"\/>/', '<dimension ref="A1:B' . max(1, count($dias)) . '"/>', $xml, 1);
    }

    private static function texto(string $hoja, string $ref, string $texto, ?int $estilo = null): string
    {
        if ($texto === '') {
            return self::celda($hoja, $ref, null, $estilo);
        }

        $t = htmlspecialchars($texto, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return self::celda($hoja, $ref, '<is><t xml:space="preserve">' . $t . '</t></is>', $estilo, 'inlineStr');
    }

    /**
     * Reemplaza el contenido de una casilla que ya existe en la plantilla,
     * conservando su estilo salvo que se pida otro.
     */
    private static function celda(string $hoja, string $ref, ?string $contenido, ?int $estilo = null, ?string $tipo = null): string
    {
        $patron = '/<c r="' . $ref . '"((?: [a-zA-Z]+="[^"]*")*)\s*(?:\/>|>.*?<\/c>)/s';

        if (! preg_match($patron, $hoja, $m, PREG_OFFSET_CAPTURE)) {
            throw new \RuntimeException("La plantilla del ADF-404-f4 no tiene la casilla {$ref}.");
        }

        preg_match_all('/ ([a-zA-Z]+)="([^"]*)"/', $m[1][0], $a, PREG_SET_ORDER);
        $attrs = [];
        foreach ($a as [, $k, $v]) {
            $attrs[$k] = $v;
        }
        unset($attrs['r'], $attrs['t']);
        if ($estilo !== null) {
            $attrs['s'] = (string) $estilo;
        }
        if ($tipo !== null) {
            $attrs['t'] = $tipo;
        }

        $abre = '<c r="' . $ref . '"' . implode('', array_map(fn ($k, $v) => ' ' . $k . '="' . $v . '"', array_keys($attrs), $attrs));
        $nueva = $contenido === null ? $abre . '/>' : $abre . '>' . $contenido . '</c>';

        return substr_replace($hoja, $nueva, $m[0][1], strlen($m[0][0]));
    }
}
