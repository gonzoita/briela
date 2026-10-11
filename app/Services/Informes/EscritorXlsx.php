<?php

namespace App\Services\Informes;

/**
 * Un libro de Excel (.xlsx) con varias hojas, escrito a mano.
 *
 * Sin librerías a propósito: el producto se instala en el hosting de cada cliente, sin composer
 * ni permisos garantizados, y un .xlsx no es más que unos XML dentro de un zip. Lo único que
 * necesita es la extensión `zip` de PHP; si no está, `disponible()` lo dice y quien llama cae
 * al CSV (regla 5: verificar y degradar con elegancia).
 *
 * Las celdas llevan su tipo de verdad —número, moneda, fecha, porcentaje— para que Excel sume,
 * filtre y ordene sin que nadie convierta texto a número a mano.
 */
class EscritorXlsx
{
    // Índices de estilos en styles.xml (cellXfs).
    private const E_NORMAL = 0, E_ENCABEZADO = 1, E_MONEDA = 2, E_DECIMAL = 3, E_FECHA = 4,
        E_PCT = 5, E_TEXTO_LARGO = 6, E_TOTAL = 7, E_TITULO = 8, E_ENTERO = 9, E_SUBTITULO = 10;

    /** @var array<int, array{nombre:string, xml:string}> */
    private array $hojas = [];

    public static function disponible(): bool
    {
        return class_exists(\ZipArchive::class);
    }

    /**
     * Una hoja con una tabla.
     *
     * @param  array<int, array{clave:string, etiqueta:string, tipo:string}>  $columnas
     * @param  array<int, array<string, mixed>>  $filas
     * @param  array<string, mixed>|null  $total  fila de totales al final
     * @param  array<int, string>  $encabezado  líneas de título encima de la tabla
     */
    public function tabla(string $nombre, array $columnas, array $filas, ?array $total = null, array $encabezado = [], bool $filtro = true): self
    {
        $filasXml = [];
        $r = 0;

        foreach ($encabezado as $i => $linea) {
            $r++;
            $filasXml[] = "<row r=\"{$r}\">".$this->celdaTexto(1, $r, $linea, $i === 0 ? self::E_TITULO : self::E_SUBTITULO).'</row>';
        }
        if ($encabezado) {
            $r++; // un renglón en blanco antes de la tabla
        }

        $filaEncabezado = ++$r;
        $celdas = '';
        foreach ($columnas as $c => $col) {
            $celdas .= $this->celdaTexto($c + 1, $r, $col['etiqueta'], self::E_ENCABEZADO);
        }
        $filasXml[] = "<row r=\"{$r}\">{$celdas}</row>";

        foreach ($filas as $fila) {
            $r++;
            $celdas = '';
            foreach ($columnas as $c => $col) {
                $celdas .= $this->celda($c + 1, $r, $fila[$col['clave']] ?? null, $col['tipo']);
            }
            $filasXml[] = "<row r=\"{$r}\">{$celdas}</row>";
        }

        $ultimaDatos = $r;

        if ($total) {
            $r++;
            $celdas = '';
            foreach ($columnas as $c => $col) {
                $valor = $total[$col['clave']] ?? null;
                $celdas .= $c === 0 && ! is_numeric($valor)
                    ? $this->celdaTexto(1, $r, (string) ($valor ?? 'Total'), self::E_TOTAL)
                    : $this->celda($c + 1, $r, $valor, $col['tipo'], in_array($col['tipo'], ['moneda', 'entero'], true) ? self::E_TOTAL : null);
            }
            $filasXml[] = "<row r=\"{$r}\">{$celdas}</row>";
        }

        $ultimaCol = self::columna(max(1, count($columnas)));
        $anchos    = $this->anchos($columnas, $filas);
        $vista     = "<sheetViews><sheetView workbookViewId=\"0\"><pane ySplit=\"{$filaEncabezado}\" topLeftCell=\"A".($filaEncabezado + 1)
            ."\" activePane=\"bottomLeft\" state=\"frozen\"/></sheetView></sheetViews>";
        $autoFiltro = $filtro && $filas ? "<autoFilter ref=\"A{$filaEncabezado}:{$ultimaCol}{$ultimaDatos}\"/>" : '';

        $this->hojas[] = [
            'nombre' => $nombre,
            'xml'    => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .$vista.'<cols>'.$anchos.'</cols><sheetData>'.implode('', $filasXml).'</sheetData>'.$autoFiltro.'</worksheet>',
        ];

        return $this;
    }

    /** Una hoja de texto: un párrafo por renglón, con ajuste de línea. */
    public function texto(string $nombre, string $titulo, array $parrafos): self
    {
        $filas = ['<row r="1">'.$this->celdaTexto(1, 1, $titulo, self::E_TITULO).'</row>'];
        $r = 2;

        foreach ($parrafos as $p) {
            $r++;
            $filas[] = "<row r=\"{$r}\">".$this->celdaTexto(1, $r, (string) $p, self::E_TEXTO_LARGO).'</row>';
        }

        $this->hojas[] = [
            'nombre' => $nombre,
            'xml'    => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<cols><col min="1" max="1" width="110" customWidth="1"/></cols><sheetData>'.implode('', $filas).'</sheetData></worksheet>',
        ];

        return $this;
    }

    /** El libro, como bytes listos para descargar. */
    public function contenido(): string
    {
        if (! self::disponible()) {
            throw new \RuntimeException('Este servidor no tiene la extensión zip de PHP.');
        }

        $ruta = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip  = new \ZipArchive();
        $zip->open($ruta, \ZipArchive::OVERWRITE);

        $nombres = $this->nombresUnicos();
        $hojasWb = $rels = $tipos = '';

        foreach ($this->hojas as $i => $hoja) {
            $n = $i + 1;
            $zip->addFromString("xl/worksheets/sheet{$n}.xml", $hoja['xml']);
            $hojasWb .= '<sheet name="'.self::xml($nombres[$i])."\" sheetId=\"{$n}\" r:id=\"rId{$n}\"/>";
            $rels    .= "<Relationship Id=\"rId{$n}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet\" Target=\"worksheets/sheet{$n}.xml\"/>";
            $tipos   .= "<Override PartName=\"/xl/worksheets/sheet{$n}.xml\" ContentType=\"application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml\"/>";
        }

        $nEstilos = count($this->hojas) + 1;

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$tipos.'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$hojasWb.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels
            ."<Relationship Id=\"rId{$nEstilos}\" Type=\"http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles\" Target=\"styles.xml\"/>"
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', self::estilos());
        $zip->close();

        $bytes = file_get_contents($ruta);
        @unlink($ruta);

        return $bytes;
    }

    // ─── Celdas ──────────────────────────────────────────────────────────────

    private function celda(int $c, int $r, mixed $valor, string $tipo, ?int $estiloForzado = null): string
    {
        $ref = self::columna($c).$r;

        if ($valor === null || $valor === '') {
            return $estiloForzado !== null ? "<c r=\"{$ref}\" s=\"{$estiloForzado}\"/>" : '';
        }

        if ($tipo === 'bool') {
            return $this->celdaTexto($c, $r, $valor ? 'Sí' : 'No', $estiloForzado ?? self::E_NORMAL);
        }

        if ($tipo === 'fecha') {
            $ts = strtotime((string) $valor.' UTC');

            return $ts === false
                ? $this->celdaTexto($c, $r, (string) $valor, $estiloForzado ?? self::E_NORMAL)
                // Excel cuenta días desde el 30 dic 1899; 25569 es el 1 ene 1970.
                : "<c r=\"{$ref}\" s=\"".($estiloForzado ?? self::E_FECHA).'"><v>'.(floor($ts / 86400) + 25569).'</v></c>';
        }

        if (in_array($tipo, ['moneda', 'numero', 'entero', 'pct'], true) && is_numeric($valor)) {
            $estilo = $estiloForzado ?? match ($tipo) {
                'moneda' => self::E_MONEDA,
                'pct'    => self::E_PCT,
                'entero' => self::E_ENTERO,
                default  => self::E_DECIMAL,
            };

            return "<c r=\"{$ref}\" s=\"{$estilo}\"><v>".(0 + $valor).'</v></c>';
        }

        return $this->celdaTexto($c, $r, (string) $valor, $estiloForzado ?? self::E_NORMAL);
    }

    /** Texto en línea: nunca es una fórmula, así que «=HIPERVINCULO(…)» llegado de un formulario es inofensivo. */
    private function celdaTexto(int $c, int $r, string $texto, int $estilo): string
    {
        $ref = self::columna($c).$r;

        return "<c r=\"{$ref}\" t=\"inlineStr\" s=\"{$estilo}\"><is><t xml:space=\"preserve\">".self::xml($texto).'</t></is></c>';
    }

    private function anchos(array $columnas, array $filas): string
    {
        $xml = '';
        $muestra = array_slice($filas, 0, 200);

        foreach ($columnas as $i => $col) {
            $largo = mb_strlen($col['etiqueta']);
            foreach ($muestra as $f) {
                $v = $f[$col['clave']] ?? '';
                $largo = max($largo, mb_strlen(is_bool($v) ? 'No' : (string) $v) + ($col['tipo'] === 'moneda' ? 4 : 0));
            }
            $ancho = min(50, max(8, $largo + 2));
            $n = $i + 1;
            $xml .= "<col min=\"{$n}\" max=\"{$n}\" width=\"{$ancho}\" customWidth=\"1\"/>";
        }

        return $xml;
    }

    private function nombresUnicos(): array
    {
        $usados = [];

        return array_map(function ($hoja) use (&$usados) {
            // Excel: máximo 31 caracteres y sin : \ / ? * [ ]
            $base = mb_substr(trim(str_replace([':', '\\', '/', '?', '*', '[', ']'], ' ', $hoja['nombre'])) ?: 'Hoja', 0, 28);
            $nombre = $base;
            $n = 2;
            while (in_array(mb_strtolower($nombre), $usados, true)) {
                $nombre = $base.' '.$n++;
            }
            $usados[] = mb_strtolower($nombre);

            return $nombre;
        }, $this->hojas);
    }

    public static function columna(int $n): string
    {
        $letras = '';
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $letras = chr(65 + $m).$letras;
            $n = intdiv($n - 1, 26);
        }

        return $letras;
    }

    private static function xml(string $texto): string
    {
        // Fuera los caracteres de control que XML no admite: un solo carácter así daña el libro entero.
        $limpio = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $texto) ?? '';

        return htmlspecialchars($limpio, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private static function estilos(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="2"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/><numFmt numFmtId="165" formatCode="0.0&quot;%&quot;"/></numFmts>'
            .'<fonts count="4"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font>'
            .'<font><b/><sz val="14"/><name val="Calibri"/></font><font><sz val="10"/><color rgb="FF667085"/><name val="Calibri"/></font></fonts>'
            .'<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
            .'<fill><patternFill patternType="solid"><fgColor rgb="FFEEF2F6"/><bgColor indexed="64"/></patternFill></fill></fills>'
            .'<borders count="2"><border><left/><right/><top/><bottom/><diagonal/></border>'
            .'<border><left/><right/><top style="thin"><color rgb="FF98A2B3"/></top><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="11">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'                                         // 0 normal
            .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'             // 1 encabezado
            .'<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'                    // 2 moneda
            .'<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'                    // 3 decimal
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'                  // 4 fecha
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'                  // 5 porcentaje
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment wrapText="1" vertical="top"/></xf>' // 6 texto largo
            .'<xf numFmtId="3" fontId="1" fillId="0" borderId="1" xfId="0" applyFont="1" applyBorder="1" applyNumberFormat="1"/>' // 7 total
            .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                            // 8 título
            .'<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'                    // 9 entero
            .'<xf numFmtId="0" fontId="3" fillId="0" borderId="0" xfId="0" applyFont="1"/>'                            // 10 subtítulo
            .'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }
}
