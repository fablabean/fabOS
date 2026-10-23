<?php

namespace App\Services\Purchasing;

use App\Models\PurchaseRequest;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\CellAlignment;
use OpenSpout\Common\Entity\Style\CellVerticalAlignment;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * La requisición en Excel (§13): la misma hoja que se ve en pantalla.
 *
 * Compras a veces la necesita en hoja de cálculo para pegarla en su sistema o
 * completar lo que le toca. Sale con las mismas casillas que el PDF —el
 * ADF-228-f5 si es una solicitud de cotización, la requisición con precios si
 * no—, y las cantidades y los valores van como números, no como texto: así se
 * pueden sumar y corregir en Excel.
 *
 * La firma dibujada del solicitante va encajada en su casilla, como en el
 * PDF. OpenSpout no pone imágenes, así que se mete en el archivo ya cerrado
 * (ver `firmaEnLaCelda`).
 */
class RequisicionEnExcel
{
    private Options $opciones;

    private Writer $hoja;

    private int $fila = 0;

    /** La fila donde va la firma dibujada, si la hay. */
    private ?int $filaDeFirma = null;

    /** El PNG de la firma del solicitante. */
    private ?string $firma = null;

    /** Escribe el archivo y devuelve dónde quedó. */
    public function guardar(PurchaseRequest $solicitud): string
    {
        $solicitud->loadMissing(['items', 'adjustments', 'budget', 'area', 'project', 'requestedBy', 'approvedBy', 'signedBy']);

        $base = tempnam(sys_get_temp_dir(), 'requisicion');
        @unlink($base);
        $ruta = $base . '.xlsx';
        $this->opciones = new Options();
        $this->fila = 0;
        $this->filaDeFirma = null;
        $this->firma = $solicitud->pideCotizacion() ? self::pngDeLaFirma($solicitud) : null;

        $cotizacion = $solicitud->pideCotizacion();
        foreach ($cotizacion ? [6, 30, 32, 14, 11, 13, 30] : [6, 48, 11, 12, 16, 16] as $i => $ancho) {
            $this->opciones->setColumnWidth($ancho, $i + 1);
        }

        $this->hoja = new Writer($this->opciones);
        $this->hoja->openToFile($ruta);
        $this->hoja->getCurrentSheet()->setName($cotizacion ? 'ADF-228-f5' : 'Requisición');

        $cotizacion ? $this->cotizacion($solicitud) : $this->requisicion($solicitud);

        $this->hoja->close();

        if ($this->firma !== null && $this->filaDeFirma !== null) {
            self::firmaEnLaCelda($ruta, $this->firma, $this->filaDeFirma);
        }

        return $ruta;
    }

    public static function nombre(PurchaseRequest $solicitud): string
    {
        return preg_replace('/\.pdf$/', '.xlsx', $solicitud->nombreDelPdf());
    }

    // ------------------------------------------------- solicitud de cotización

    /** El ADF-228-f5 de la Universidad, casilla por casilla como en el PDF. */
    private function cotizacion(PurchaseRequest $s): void
    {
        $tz = config('fabos.lab.timezone');
        $quien = $s->requestedBy;
        $firmante = $s->signedBy ?? $quien;
        $fecha = ($s->submitted_at ?? now())->copy()->timezone($tz);
        $g = $this->estilo(gris: true);
        $c = $this->estilo(centro: true);

        // Encabezado del formato.
        $this->fila([[config('fabos.lab.institution'), $this->estilo(negrita: true, centro: true)], null, ['FORMATO', $this->estilo(negrita: true, centro: true, tamano: 14)], null, null, ['Versión: 1'], null], 22);
        $this->fila([null, null, null, null, null, ['Código: ADF-228-f5'], null], 22);
        $this->fila([null, null, ['Términos de Referencia para Solicitud de Cotización', $this->estilo(negrita: true, centro: true)], null, null, ['Fecha: 13/Ene/2025'], null], 22);
        $this->unir(0, 1, 1, 3);
        $this->unir(2, 1, 4, 2);
        $this->unir(2, 3, 4, 3);
        foreach ([1, 2, 3] as $r) {
            $this->unir(5, $r, 6, $r);
        }

        $this->vacia();
        $this->titulo('INFORMACION DEL SOLICITANTE', 7);
        $this->vacia();

        $this->pares([['Fecha de Solicitud', $fecha->format('d/m/Y')], ['Solicitante', $quien?->name]]);
        $this->pares([['Correo Electrónico', $quien?->email], ['Teléfono', $quien?->phone]]);
        $this->pares([['Supervisor', $s->supervisor], ['Proceso Solicitante y/o Área', $s->process_area]]);
        $this->pares([['Requiere Arte', $s->requires_art ? 'SI [X]   NO [  ]' : 'SI [  ]   NO [X]']]);

        $this->vacia();
        $this->fila([['«Recuerda registrar la información de forma clara y completa para agilizar el proceso.»', $this->estilo(cursiva: true, centro: true)]], ancho: 7);
        $this->vacia();

        $this->titulo('TERMINOS DE REFERENCIA', 7);
        $encabezado = $this->estilo(gris: true, centro: true);
        $this->fila(array_map(fn ($t) => [$t, $encabezado],
            ['Item', 'Descripción', 'Especificaciones Técnicas', 'Marca', 'Cantidad', 'Unidad de Medida', 'Observaciones']), 30);

        $texto = $this->estilo();
        $numero = $this->estilo(centro: true);
        for ($i = 0; $i < max(10, $s->items->count()); $i++) {
            $l = $s->items[$i] ?? null;
            $this->fila([
                [$i + 1, $c],
                [$l?->description, $texto],
                [$l?->specs, $texto],
                [$l?->brand, $c],
                [$l ? (float) $l->quantity : null, $numero],
                [$l?->unit, $c],
                [$l?->notes, $texto],
            ], 30);
        }

        $this->vacia();
        $this->fila([['Observaciones Generales «Registra observaciones para información adicional y/o aclaraciones relevantes.»', $g]], ancho: 7);
        $obs = trim(($s->justification ? 'Para qué: ' . $s->justification . "\n" : '') . (string) $s->notes);
        $this->fila([[$obs, $this->estilo(arriba: true)]], 70, ancho: 7);

        $this->vacia();
        $this->firma('Solicitado Por: ' . $firmante?->name);
        // La firma dibujada se encaja en esta casilla al cerrar el archivo. Con
        // un espacio y no vacía: OpenSpout no escribe las filas sin nada.
        $this->firma($this->firma !== null ? ' ' : 'Sin firmar', 60, $this->estilo(centro: true));
        $this->filaDeFirma = $this->firma !== null ? $this->fila : null;
        $this->firma('Firma del Solicitante', estilo: $this->estilo(negrita: true));
        $this->firma('Nombre del Solicitante: ' . $firmante?->name);
        $this->firma('Fecha: ' . $fecha->format('d/m/Y'));

        $this->vacia();
        $this->nota('Solicitud ' . $s->code . ' · ' . config('fabos.lab.name') . ' · generado por fabOS el ' . now()->timezone($tz)->format('d/m/Y H:i') . '.', 7);
    }

    /** Media hoja a la izquierda, con su pareja vacía a la derecha, como el formato. */
    private function firma(string $texto, ?float $alto = null, ?Style $estilo = null): void
    {
        $vacio = $this->estilo();
        $this->fila([[$texto, $estilo ?? $this->estilo()], null, null, [null, $vacio], null, null, null], $alto);
        $this->unir(0, $this->fila, 2, $this->fila);
        $this->unir(3, $this->fila, 6, $this->fila);
    }

    /**
     * Una fila de rótulo y valor, una o dos parejas: rótulo en A:B y valor en
     * C; la segunda, rótulo en D y valor en E:G.
     *
     * @param  list<array{0:string,1:mixed}>  $parejas
     */
    private function pares(array $parejas): void
    {
        [$r1, $v1] = $parejas[0];
        [$r2, $v2] = $parejas[1] ?? [null, null];
        $g = $this->estilo(gris: true);
        $t = $this->estilo();

        // Sin segunda pareja, el resto de la fila va sin recuadro, como en el formato.
        $this->fila([[$r1, $g], null, [$v1, $t], $r2 !== null ? [$r2, $g] : [null, $this->estilo(borde: false)],
            $r2 !== null ? [$v2, $t] : null, null, null], 28);
        $this->unir(0, $this->fila, 1, $this->fila);
        if ($r2 !== null) {
            $this->unir(4, $this->fila, 6, $this->fila);
        }
    }

    // ------------------------------------------------- requisición con precios

    private function requisicion(PurchaseRequest $s): void
    {
        $tz = config('fabos.lab.timezone');
        $iva = $s->tasaDeImpuesto();
        $dinero = $s->esEnPesos() ? '"' . $s->simbolo() . '"#,##0' : '"US$"#,##0.00';
        $plata = $this->estilo(derecha: true, formato: $dinero);
        $plataFuerte = $this->estilo(derecha: true, negrita: true, formato: $dinero);
        $derecha = $this->estilo(derecha: true);
        $derechaFuerte = $this->estilo(derecha: true, negrita: true);
        $texto = $this->estilo();

        $this->fila([[config('fabos.lab.name'), $this->estilo(negrita: true, tamano: 16, borde: false)], null, null, null,
            [$s->code, $this->estilo(negrita: true, tamano: 16, derecha: true, borde: false)], null], 26);
        $this->unir(0, $this->fila, 3, $this->fila);
        $this->unir(4, $this->fila, 5, $this->fila);
        $this->fila([['Requisición de compra', $this->estilo(borde: false)], null, null, null,
            [PurchaseRequest::ESTADOS[$s->status] ?? $s->status, $this->estilo(derecha: true, borde: false)], null]);
        $this->unir(0, $this->fila, 3, $this->fila);
        $this->unir(4, $this->fila, 5, $this->fila);
        $this->vacia();

        $datos = array_filter([
            'Solicita' => trim($s->requestedBy?->name . ' · ' . $s->requestedBy?->email, ' ·'),
            'Área' => $s->area?->name,
            'Proyecto' => $s->project?->name,
            'Para qué' => $s->justification,
            'Fecha de envío' => $s->submitted_at?->timezone($tz)->format('d/m/Y') ?? 'sin enviar',
            'Presupuesto' => $s->budget ? $s->budget->name . ' ' . $s->budget->year : null,
            'Proveedor' => $s->tieneProveedor() ? ($s->supplier_name ?: '—') : null,
            'NIT' => $s->tieneProveedor() ? $s->supplier_tax_id : null,
            'Dirección' => $s->tieneProveedor() ? $s->supplier_address : null,
            'Representante' => $s->tieneProveedor() ? $s->supplier_contact : null,
            'Cotización' => $s->quote_path ? 'Adjunta a esta requisición (' . $s->nombreDeLaCotizacion() . ')' : null,
            'Aprobó' => $s->approvedBy ? $s->approvedBy->name . ' · ' . $s->decided_at?->timezone($tz)->format('d/m/Y') : null,
            'Carrito ya armado' => $s->cart_url,
        ], fn ($v) => filled($v));

        foreach ($datos as $rotulo => $valor) {
            $this->fila([[$rotulo, $this->estilo(gris: true)], [$valor, $this->estilo(negrita: true)], null, null, null, null]);
            $this->unir(1, $this->fila, 5, $this->fila);
        }

        $this->vacia();
        $encabezado = $this->estilo(gris: true, centro: true);
        $this->fila(array_map(fn ($t) => [$t, $encabezado], ['#', 'Descripción', 'Cantidad', 'Unidad', 'Valor unitario', 'Total']), 22);

        $numero = $this->estilo(derecha: true);
        foreach ($s->items as $i => $l) {
            $detalle = array_filter([
                $l->description,
                $l->supplier ? 'Proveedor sugerido: ' . $l->supplier : null,
                $l->reference_url,
                $l->received_quantity > 0 ? 'Recibido: ' . rtrim(rtrim(number_format((float) $l->received_quantity, 3, ',', '.'), '0'), ',') . ' ' . $l->unit : null,
            ]);

            $this->fila([
                [$i + 1, $this->estilo(centro: true)],
                [implode("\n", $detalle), $texto],
                [(float) $l->quantity, $numero],
                [$l->unit, $this->estilo(centro: true)],
                [(float) $l->unit_price, $plata],
                [(float) $l->total(), $plata],
            ]);
        }

        $total = function (string $rotulo, float $valor, bool $fuerte = false, ?string $formato = null) use ($derecha, $derechaFuerte, $plata, $plataFuerte) {
            $this->fila([[$rotulo, $fuerte ? $derechaFuerte : $derecha], null, null, null, null,
                [$valor, $formato ? $this->estilo(derecha: true, negrita: $fuerte, formato: $formato) : ($fuerte ? $plataFuerte : $plata)]]);
            $this->unir(0, $this->fila, 4, $this->fila);
        };

        $total('Subtotal', $s->subtotalEnMoneda());
        foreach ($s->adjustments as $a) {
            $total($a->description . ($iva > 0 && ! $a->applies_tax ? ' (sin impuesto)' : ''), $a->conSigno());
        }
        if ($iva > 0) {
            $total('Impuesto estimado (' . (int) round($iva * 100) . '%)', $s->impuestoEnMoneda());
        }
        $total('Total estimado' . ($s->esEnPesos() ? '' : ' en dólares'), $s->totalEnMoneda(), true);
        if (! $s->esEnPesos()) {
            $total('En pesos, a ' . config('fabos.money.symbol') . number_format((float) $s->exchange_rate, 0, ',', '.') . ' por dólar',
                (float) $s->totalEstimado(), true, '"' . config('fabos.money.symbol') . '"#,##0');
        }

        if ($s->notes) {
            $this->vacia();
            $this->fila([['Observaciones', $this->estilo(gris: true)], [$s->notes, $texto], null, null, null, null], 45);
            $this->unir(1, $this->fila, 5, $this->fila);
        }

        $this->vacia();
        $this->vacia();
        $this->fila([['Solicita · ' . $s->requestedBy?->name, $this->estilo(borde: false)], null, null,
            ['Aprueba · ' . ($s->approvedBy?->name ?? ''), $this->estilo(borde: false)], null, null]);
        $this->unir(0, $this->fila, 2, $this->fila);
        $this->unir(3, $this->fila, 5, $this->fila);

        $this->vacia();
        $this->nota('Los valores unitarios son estimados de referencia tomados del último costo conocido o de la cotización consultada; '
            . 'el valor definitivo lo fija el proveedor. Documento generado por fabOS el ' . now()->timezone($tz)->format('d/m/Y H:i') . '.', 6);
    }

    // ------------------------------------------------------------ la firma

    private static function pngDeLaFirma(PurchaseRequest $s): ?string
    {
        $disco = \Illuminate\Support\Facades\Storage::disk('local');

        if (! $s->estaFirmada() || ! $disco->exists($s->signature_path)) {
            return null;
        }

        $png = $disco->get($s->signature_path);

        return $png !== null && @getimagesizefromstring($png) !== false ? $png : null;
    }

    /**
     * Encaja el dibujo de la firma en su casilla (A:C de la fila dada).
     *
     * Va anclado a la casilla —se mueve y cambia de tamaño con ella—, no
     * suelto encima. Meterla como VALOR de la celda («Colocar en celda») solo
     * lo entiende Excel de Microsoft 365: el Excel 2021 del laboratorio la
     * muestra como #¡DESCONOCIDO!. Anclada se ve igual en cualquier versión.
     *
     * Una imagen en un .xlsx son: el PNG en xl/media, un dibujo que dice entre
     * qué casillas va, la relación del dibujo con el PNG y la de la hoja con
     * el dibujo, más sus tipos en [Content_Types].xml. La etiqueta <drawing>
     * va antes de <legacyDrawing> —la que OpenSpout usa para comentarios—:
     * Excel exige ese orden, y si no, pide «reparar» el archivo.
     */
    private static function firmaEnLaCelda(string $ruta, string $png, int $fila): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($ruta) !== true) {
            return;
        }

        $hoja = $zip->getFromName('xl/worksheets/sheet1.xml');
        $tipos = $zip->getFromName('[Content_Types].xml');
        if ($hoja === false || $tipos === false || str_contains($hoja, '<drawing ')) {
            $zip->close();

            return;
        }

        // Las columnas A, B y C en píxeles (ancho en caracteres × 7 + 5), y la
        // fila de 60 puntos = 80 px. La firma, centrada y con su proporción.
        $columnas = array_map(fn ($c) => $c * 7 + 5, [6, 30, 32]);
        [$w, $h] = getimagesizefromstring($png);
        $alto = 70;
        $ancho = min(array_sum($columnas) - 10, (int) round($alto * $w / max(1, $h)));
        $izquierda = (int) round((array_sum($columnas) - $ancho) / 2);
        $donde = function (int $x) use ($columnas): array {
            foreach ($columnas as $i => $c) {
                if ($x < $c || $i === count($columnas) - 1) {
                    return [$i, min($x, $c)];
                }
                $x -= $c;
            }
        };
        [$c1, $x1] = $donde($izquierda);
        [$c2, $x2] = $donde($izquierda + $ancho);
        $emu = 9525;
        $punto = fn (int $col, int $x, int $y) => '<xdr:col>' . $col . '</xdr:col><xdr:colOff>' . ($x * $emu) . '</xdr:colOff>'
            . '<xdr:row>' . ($fila - 1) . '</xdr:row><xdr:rowOff>' . ($y * $emu) . '</xdr:rowOff>';

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";

        $zip->addFromString('xl/media/firma1.png', $png);
        $zip->addFromString('xl/drawings/drawing1.xml', $xml
            . '<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<xdr:twoCellAnchor editAs="twoCell">'
            . '<xdr:from>' . $punto($c1, $x1, 5) . '</xdr:from>'
            . '<xdr:to>' . $punto($c2, $x2, 5 + $alto) . '</xdr:to>'
            . '<xdr:pic><xdr:nvPicPr><xdr:cNvPr id="2" name="Firma del solicitante" descr="Firma del solicitante"/>'
            . '<xdr:cNvPicPr><a:picLocks noChangeAspect="1"/></xdr:cNvPicPr></xdr:nvPicPr>'
            . '<xdr:blipFill><a:blip r:embed="rId1"/><a:stretch><a:fillRect/></a:stretch></xdr:blipFill>'
            . '<xdr:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="' . ($ancho * $emu) . '" cy="' . ($alto * $emu) . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></xdr:spPr>'
            . '</xdr:pic><xdr:clientData/></xdr:twoCellAnchor></xdr:wsDr>');
        $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', $xml
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="../media/firma1.png"/>'
            . '</Relationships>');

        $relacion = '<Relationship Id="rId_firma1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/>';
        $rels = $zip->getFromName('xl/worksheets/_rels/sheet1.xml.rels');
        $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', $rels !== false
            ? str_replace('</Relationships>', $relacion . '</Relationships>', $rels)
            : $xml . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $relacion . '</Relationships>');

        $etiqueta = '<drawing r:id="rId_firma1"/>';
        $zip->addFromString('xl/worksheets/sheet1.xml', str_contains($hoja, '<legacyDrawing')
            ? preg_replace('/<legacyDrawing/', $etiqueta . '<legacyDrawing', $hoja, 1)
            : str_replace('</worksheet>', $etiqueta . '</worksheet>', $hoja));

        $zip->addFromString('[Content_Types].xml', str_replace('</Types>',
            (str_contains($tipos, 'Extension="png"') ? '' : '<Default ContentType="image/png" Extension="png"/>')
            . '<Override ContentType="application/vnd.openxmlformats-officedocument.drawing+xml" PartName="/xl/drawings/drawing1.xml"/></Types>', $tipos));

        $zip->close();
    }

    // ------------------------------------------------------------ las piezas

    /**
     * Escribe una fila. Cada casilla es [valor, estilo] o null; un null
     * después de una casilla con estilo hereda su estilo, para que una casilla
     * unida conserve el borde en todo su ancho. Con `ancho`, la primera casilla
     * se une hasta esa columna.
     *
     * @param  list<array{0:mixed,1?:Style}|null>  $casillas
     */
    private function fila(array $casillas, ?float $alto = null, ?int $ancho = null): void
    {
        $this->fila++;

        if ($ancho !== null) {
            $casillas = array_pad($casillas, $ancho, null);
            $this->unir(0, $this->fila, $ancho - 1, $this->fila);
        }

        $estilo = null;
        $celdas = [];
        foreach ($casillas as $c) {
            if ($c !== null) {
                $estilo = $c[1] ?? $this->estilo();
            }
            $valor = $c[0] ?? null;
            // Lo que cae bajo una casilla unida lleva borde igual: si no, el
            // recuadro sale cortado.
            $celdas[] = Cell::fromValue(is_string($valor) && $valor === '' ? null : $valor, $estilo ?? $this->estilo());
        }

        $row = new Row($celdas);
        if ($alto !== null) {
            $row->setHeight($alto);
        }
        $this->hoja->addRow($row);
    }

    private function vacia(): void
    {
        $this->fila++;
        $this->hoja->addRow(new Row([]));
    }

    private function titulo(string $texto, int $ancho): void
    {
        $this->fila([[$texto, $this->estilo(gris: true, centro: true)]], 20, ancho: $ancho);
    }

    private function nota(string $texto, int $ancho): void
    {
        $this->fila([[$texto, $this->estilo(borde: false, tamano: 9, color: '555555')]], 30, ancho: $ancho);
    }

    /** Columnas desde 0 (A), filas desde 1, como las cuenta OpenSpout. */
    private function unir(int $c1, int $f1, int $c2, int $f2): void
    {
        $this->opciones->mergeCells($c1, $f1, $c2, $f2);
    }

    private function estilo(
        bool $negrita = false,
        bool $cursiva = false,
        bool $gris = false,
        bool $centro = false,
        bool $derecha = false,
        bool $arriba = false,
        bool $borde = true,
        int $tamano = 10,
        ?string $formato = null,
        ?string $color = null,
    ): Style {
        $e = (new Style())
            ->setFontName('Arial')
            ->setFontSize($tamano)
            ->setShouldWrapText()
            ->setCellVerticalAlignment($arriba ? CellVerticalAlignment::TOP : CellVerticalAlignment::CENTER);

        if ($negrita || $gris) {
            $e->setFontBold();
        }
        if ($cursiva) {
            $e->setFontItalic();
        }
        if ($gris) {
            $e->setBackgroundColor('D9D9D9');
        }
        if ($centro || $derecha) {
            $e->setCellAlignment($centro ? CellAlignment::CENTER : CellAlignment::RIGHT);
        }
        if ($formato) {
            $e->setFormat($formato);
        }
        if ($color) {
            $e->setFontColor($color);
        }
        if ($borde) {
            $e->setBorder(new Border(...array_map(
                fn ($lado) => new BorderPart($lado, Color::BLACK, Border::WIDTH_THIN),
                [Border::TOP, Border::RIGHT, Border::BOTTOM, Border::LEFT],
            )));
        }

        return $e;
    }
}
