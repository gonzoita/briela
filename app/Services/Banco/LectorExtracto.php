<?php

namespace App\Services\Banco;

use RuntimeException;
use ZipArchive;

/**
 * Lee un extracto bancario (CSV o XLSX) y lo devuelve como filas de texto, sin interpretar nada.
 *
 * Es la primera fuente del verificador: funciona con **cualquier banco**, porque todos dejan
 * descargar sus movimientos. Interpretar qué columna es cuál es trabajo de
 * {@see NormalizadorExtracto}; aquí solo se abre el archivo.
 *
 * No hay librería de hojas de cálculo en el producto —el cliente instala sin composer—, así que el
 * XLSX se lee a mano: es un ZIP con XML. El `.xls` viejo y el PDF no se leen, y se dice.
 */
class LectorExtracto
{
    /** Más filas que esto no es un extracto, es otra cosa. */
    public const MAX_FILAS = 20000;

    /** @return list<list<string>> */
    public function leer(string $ruta, string $extension): array
    {
        return match (strtolower($extension)) {
            'csv', 'txt' => $this->csv($ruta),
            'xlsx'       => $this->xlsx($ruta),
            'xls'        => throw new RuntimeException('El formato .xls antiguo no se puede leer. Guárdalo como .xlsx o .csv desde Excel y vuelve a subirlo.'),
            'pdf'        => throw new RuntimeException('Un PDF no se puede leer con seguridad. Descarga el extracto como Excel o CSV desde la página del banco.'),
            default      => throw new RuntimeException('Sube el extracto como .csv o .xlsx.'),
        };
    }

    /** @return list<list<string>> */
    private function csv(string $ruta): array
    {
        $contenido = (string) file_get_contents($ruta);

        // Los bancos exportan en UTF-8 o en ISO-8859-1 («Descripción» se ve rota si se adivina mal).
        $contenido = preg_replace('/^\xEF\xBB\xBF/', '', $contenido);
        if (! mb_check_encoding($contenido, 'UTF-8')) {
            $contenido = mb_convert_encoding($contenido, 'UTF-8', 'ISO-8859-1');
        }

        $delimitador = $this->delimitador($contenido);
        $flujo       = fopen('php://temp', 'r+');
        fwrite($flujo, $contenido);
        rewind($flujo);

        $filas = [];
        while (($fila = fgetcsv($flujo, 0, $delimitador, '"', '')) !== false) {
            if ($fila === [null] || $fila === []) {
                continue;
            }
            $filas[] = array_map(fn ($c) => trim((string) $c), $fila);

            if (count($filas) > self::MAX_FILAS) {
                throw new RuntimeException('El archivo tiene demasiadas filas para ser un extracto.');
            }
        }
        fclose($flujo);

        return $filas;
    }

    /** El separador que más se repite en las primeras líneas: coma, punto y coma, tabulador o barra. */
    private function delimitador(string $contenido): string
    {
        $muestra = implode("\n", array_slice(preg_split('/\R/', $contenido) ?: [], 0, 8));
        $mejor   = ',';
        $max     = 0;

        foreach ([',', ';', "\t", '|'] as $d) {
            $n = substr_count($muestra, $d);
            if ($n > $max) {
                $max   = $n;
                $mejor = $d;
            }
        }

        return $mejor;
    }

    /** @return list<list<string>> */
    private function xlsx(string $ruta): array
    {
        $zip = new ZipArchive();
        if ($zip->open($ruta) !== true) {
            throw new RuntimeException('No se pudo abrir el archivo de Excel. ¿Está dañado?');
        }

        try {
            $compartidas = $this->cadenasCompartidas($zip->getFromName('xl/sharedStrings.xml') ?: '');
            $hoja        = $this->primeraHoja($zip);
            $xml         = $zip->getFromName($hoja);

            if ($xml === false) {
                throw new RuntimeException('El Excel no tiene hojas con datos.');
            }
        } finally {
            $zip->close();
        }

        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        if (! $doc) {
            throw new RuntimeException('No se pudo leer la hoja de Excel.');
        }

        $filas = [];
        foreach ($doc->sheetData->row ?? [] as $row) {
            $fila = [];
            foreach ($row->c as $c) {
                $columna = $this->columna((string) $c['r']);
                $fila[$columna] = $this->valorCelda($c, $compartidas);
            }

            if ($fila === []) {
                continue;
            }

            $ancho = max(array_keys($fila)) + 1;
            $lleno = array_fill(0, $ancho, '');
            foreach ($fila as $i => $v) {
                $lleno[$i] = $v;
            }

            if (trim(implode('', $lleno)) === '') {
                continue;
            }

            $filas[] = $lleno;

            if (count($filas) > self::MAX_FILAS) {
                throw new RuntimeException('El archivo tiene demasiadas filas para ser un extracto.');
            }
        }

        return $filas;
    }

    /** La primera hoja del libro, que es donde los bancos ponen los movimientos. */
    private function primeraHoja(ZipArchive $zip): string
    {
        $mejor = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = (string) $zip->getNameIndex($i);
            if (preg_match('#^xl/worksheets/sheet(\d+)\.xml$#', $nombre, $m) && ($mejor === null || (int) $m[1] < $mejor[0])) {
                $mejor = [(int) $m[1], $nombre];
            }
        }

        return $mejor[1] ?? 'xl/worksheets/sheet1.xml';
    }

    /** @return list<string> */
    private function cadenasCompartidas(string $xml): array
    {
        if ($xml === '') {
            return [];
        }

        $doc = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET);
        $out = [];

        foreach ($doc->si ?? [] as $si) {
            // Un texto puede venir partido en varios tramos con formato distinto.
            $texto = isset($si->t) ? (string) $si->t : '';
            foreach ($si->r ?? [] as $r) {
                $texto .= (string) $r->t;
            }
            $out[] = $texto;
        }

        return $out;
    }

    private function valorCelda(\SimpleXMLElement $c, array $compartidas): string
    {
        $tipo = (string) $c['t'];

        if ($tipo === 'inlineStr') {
            return trim((string) ($c->is->t ?? ''));
        }

        $v = (string) ($c->v ?? '');

        return trim($tipo === 's' ? ($compartidas[(int) $v] ?? '') : $v);
    }

    /** «C12» → 2 (columnas desde cero). */
    private function columna(string $referencia): int
    {
        preg_match('/^([A-Z]+)/', strtoupper($referencia), $m);
        $n = 0;
        foreach (str_split($m[1] ?? 'A') as $letra) {
            $n = $n * 26 + (ord($letra) - 64);
        }

        return $n - 1;
    }
}
