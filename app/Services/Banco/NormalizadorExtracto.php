<?php

namespace App\Services\Banco;

use Carbon\Carbon;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Convierte las filas de un extracto en movimientos con la misma forma, sea cual sea el banco.
 *
 * Es la mitad «universal» de la fuente por extracto: cada banco nombra distinto sus columnas
 * («Valor», «Monto», «Abono», «Crédito»…) y escribe distinto el dinero y las fechas
 * («1.234.567,89», «$1,234,567.89», «11/10/2026», un número de Excel). Aquí se adivina cuál es
 * cuál; si no se puede, se dice qué encabezados hay para que la persona lo indique, y **nunca se
 * inventa**: un valor mal leído es dinero mal conciliado.
 *
 * Solo pasan los **abonos** —lo que entra—. El verificador responde «¿llegó el dinero?» y no lleva
 * la contabilidad de la cuenta.
 */
class NormalizadorExtracto
{
    /** Palabras que delatan cada columna, sin tildes y en minúsculas. El orden importa: la primera gana. */
    private const PISTAS = [
        'fecha'       => ['fecha'],
        'credito'     => ['credito', 'abono', 'deposito', 'ingreso', 'entrada'],
        'debito'      => ['debito', 'retiro', 'egreso', 'salida', 'cargo'],
        'valor'       => ['valor', 'monto', 'importe'],
        // `id` antes que `descripcion`: «ID transacción» no es la descripción por decir «transacción».
        'id'          => ['consecutivo', 'secuencia', 'idtransaccion', 'id'],
        'descripcion' => ['descripcion', 'detalle', 'concepto', 'observ', 'transaccion', 'movimiento'],
        'referencia'  => ['referencia', 'documento', 'comprobante'],
        'contraparte' => ['ordenante', 'beneficiario', 'remitente', 'nombre', 'origen'],
    ];

    /**
     * Busca la fila de encabezados y qué columna es cada cosa.
     *
     * @param  list<list<string>>  $filas
     * @return array{encabezado: int, columnas: array<string, int>}
     */
    public function detectar(array $filas): array
    {
        foreach (array_slice($filas, 0, 25, true) as $i => $fila) {
            $columnas = $this->columnas($fila);

            $tieneFecha = isset($columnas['fecha']);
            $tieneDinero = isset($columnas['credito']) || isset($columnas['valor']);

            if ($tieneFecha && $tieneDinero) {
                return ['encabezado' => $i, 'columnas' => $columnas];
            }
        }

        throw new RuntimeException('No pude reconocer las columnas del extracto. Indica cuál es la fecha y cuál el valor.');
    }

    /**
     * @param  list<string>  $fila
     * @return array<string, int>
     */
    public function columnas(array $fila): array
    {
        $limpios = array_map(fn ($c) => $this->limpiar($c), $fila);
        $out     = [];

        foreach (self::PISTAS as $clave => $pistas) {
            foreach ($limpios as $i => $texto) {
                // «Saldo» nunca es el valor del movimiento, aunque diga «valor del saldo».
                // Y «fecha valor» es una fecha, no el valor.
                if ($texto === '' || str_contains($texto, 'saldo') || in_array($i, $out, true)
                    || ($clave !== 'fecha' && str_contains($texto, 'fecha'))) {
                    continue;
                }
                foreach ($pistas as $p) {
                    if ($p === 'id' ? $texto === 'id' : str_contains($texto, $p)) {
                        $out[$clave] = $i;
                        continue 3;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Las filas de abonos, ya normalizadas.
     *
     * @param  list<list<string>>  $filas
     * @param  array<string, int>  $columnas  lo que se detectó, o lo que la persona indicó
     * @return array{abonos: list<array<string, mixed>>, salidas: int, invalidas: int}
     */
    public function normalizar(array $filas, int $encabezado, array $columnas): array
    {
        $abonos = [];
        $salidas = 0;
        $invalidas = 0;

        foreach (array_slice($filas, $encabezado + 1) as $fila) {
            $fecha = $this->fecha($fila[$columnas['fecha']] ?? '');
            if ($fecha === null) {
                // Filas de totales, saldos o texto suelto: no son movimientos.
                if (trim(implode('', $fila)) !== '') {
                    $invalidas++;
                }
                continue;
            }

            $valor = $this->valorDeLaFila($fila, $columnas);

            if ($valor === null) {
                $invalidas++;
                continue;
            }

            if ($valor <= 0) {
                $salidas++;
                continue;
            }

            $abonos[] = [
                'fecha'       => $fecha,
                'valor'       => $valor,
                'descripcion' => $this->texto($fila, $columnas, 'descripcion'),
                'referencia'  => $this->texto($fila, $columnas, 'referencia'),
                'contraparte' => $this->texto($fila, $columnas, 'contraparte'),
                'id_externo'  => $this->texto($fila, $columnas, 'id'),
            ];
        }

        return ['abonos' => $abonos, 'salidas' => $salidas, 'invalidas' => $invalidas];
    }

    /** Con columnas de crédito y débito, entra lo del crédito; con una sola de valor, lo positivo. */
    private function valorDeLaFila(array $fila, array $columnas): ?float
    {
        if (isset($columnas['credito'])) {
            $credito = $this->dinero($fila[$columnas['credito']] ?? '');
            $debito  = isset($columnas['debito']) ? $this->dinero($fila[$columnas['debito']] ?? '') : null;

            if ($credito !== null && $credito > 0) {
                return $credito;
            }

            // Sin crédito pero con débito: es una salida, no una fila ilegible.
            return ($debito !== null && $debito > 0) || $credito === 0.0 ? 0.0 : null;
        }

        return $this->dinero($fila[$columnas['valor']] ?? '');
    }

    /** @return string|null  vacío es null: una celda en blanco no es un cero. */
    private function texto(array $fila, array $columnas, string $clave): ?string
    {
        if (! isset($columnas[$clave])) {
            return null;
        }

        $t = trim((string) ($fila[$columnas[$clave]] ?? ''));

        return $t === '' ? null : Str::limit($t, 190, '');
    }

    /**
     * «1.234.567,89» · «$ 1,234,567.89» · «2500000» · «(1.500)» · «-250.000».
     *
     * El último separador que aparece es el decimal; si solo hay uno y lo siguen exactamente tres
     * dígitos, es de miles —en Colombia «250.000» son doscientos cincuenta mil, no doscientos
     * cincuenta—.
     */
    public function dinero(string $texto): ?float
    {
        $t = trim(str_replace(['$', 'COP', 'cop', ' ', "\xC2\xA0"], '', $texto));

        if ($t === '' || ! preg_match('/\d/', $t)) {
            return null;
        }

        $negativo = str_starts_with($t, '-') || (str_starts_with($t, '(') && str_ends_with($t, ')')) || str_ends_with($t, '-');
        $t        = trim($t, '()-+');

        if (! preg_match('/^[\d.,]+$/', $t)) {
            return null;
        }

        $punto = strrpos($t, '.');
        $coma  = strrpos($t, ',');

        if ($punto !== false && $coma !== false) {
            $decimal = $punto > $coma ? '.' : ',';
        } elseif ($punto !== false || $coma !== false) {
            $sep     = $punto !== false ? '.' : ',';
            $cuantos = substr_count($t, $sep);
            $despues = strlen($t) - strrpos($t, $sep) - 1;
            // Repetido, o seguido de tres dígitos exactos: son miles.
            $decimal = ($cuantos === 1 && $despues !== 3) ? $sep : null;
        } else {
            $decimal = null;
        }

        if ($decimal === null) {
            $t = str_replace(['.', ','], '', $t);
        } else {
            $miles = $decimal === '.' ? ',' : '.';
            $t     = str_replace($miles, '', $t);
            $t     = str_replace($decimal, '.', $t);
        }

        $n = (float) $t;

        return $negativo ? -$n : $n;
    }

    /** «11/10/2026» · «2026-10-11» · «11-10-26» · un número de Excel (días desde 1900). */
    public function fecha(string $texto): ?string
    {
        $t = trim($texto);

        if ($t === '') {
            return null;
        }

        // Excel guarda las fechas como días desde 1899-12-30.
        if (preg_match('/^\d{5}(\.\d+)?$/', $t) && (float) $t > 30000 && (float) $t < 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int) floor((float) $t))->toDateString();
        }

        try {
            if (preg_match('/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})/', $t, $m)) {
                return checkdate((int) $m[2], (int) $m[3], (int) $m[1])
                    ? Carbon::create((int) $m[1], (int) $m[2], (int) $m[3])->toDateString()
                    : null;
            }

            // En Colombia el día va primero: 04/05/2026 es el 4 de mayo.
            if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})/', $t, $m)) {
                $anio = (int) $m[3] < 100 ? 2000 + (int) $m[3] : (int) $m[3];

                // `Carbon::create` «arregla» el 31 de febrero con el 3 de marzo: una fecha inventada.
                return checkdate((int) $m[2], (int) $m[1], $anio)
                    ? Carbon::create($anio, (int) $m[2], (int) $m[1])->toDateString()
                    : null;
            }
        } catch (\Throwable) {
            return null; // 31/02/2026 y compañía
        }

        return null;
    }

    private function limpiar(string $texto): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(Str::ascii($texto))) ?? '';
    }
}
