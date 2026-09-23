<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * El intérprete de las plantillas PDF.
 *
 * Hasta el 23 sep 2026 las plantillas se resolvían con cuatro pasadas de
 * reemplazo de texto, y de ahí salían los errores que parecían sueltos:
 *
 * - `{{#if}}` corría ANTES que las tablas, así que un condicional dentro de
 *   `{{#items}}` preguntaba por la variable en la raíz y siempre daba falso.
 * - No había `{{else}}`, ni anidamiento: un `{{#if}}` dentro de otro se cerraba
 *   con el primer `{{/if}}` que encontrara.
 * - Una variable que no existía quedaba impresa tal cual —«{{cliente.nitt}}»—
 *   en el PDF del cliente.
 * - El valor de un campo se volvía a interpretar: un cliente llamado
 *   «{{empresa.nit}}» imprimía el NIT de la empresa.
 *
 * Ahora la plantilla se lee UNA vez, se arma un árbol y se recorre. Los datos
 * nunca se vuelven a leer como plantilla.
 *
 * Sintaxis (compatible con la anterior):
 *   {{var}}  {{var|moneda}}  {{var|fecha|upper}}   → escapado
 *   {{!var}}  {{{var}}}                            → sin escapar (imágenes base64, HTML)
 *   {{#if var}} … {{else}} … {{/if}}               → también «var == "x"», !=, >, <, >=, <=
 *   {{#unless var}} … {{/unless}}
 *   {{#each items}} … {{/each}}   y el legado {{#items}} … {{/items}}
 *       dentro: los campos de la fila, @index (desde 0), @numero (desde 1), @first, @last,
 *       y cualquier variable de la raíz.
 *   {{qr:var}}                                     → imagen QR del valor (o del texto literal)
 *   {{salto_pagina}}  {{pagina}}  {{total_paginas}}
 */
class PdfPlantillaMotor
{
    public const FILTROS = [
        'moneda'     => 'Dinero en pesos: $1.234.567',
        'numero'     => 'Número con miles y hasta 2 decimales: 1.234,5',
        'entero'     => 'Número redondeado sin decimales: 1.235',
        'pct'        => 'Porcentaje hasta 2 decimales: 2,25%',
        'fecha'      => 'Fecha: 23/09/2026',
        'fecha_hora' => 'Fecha y hora: 23/09/2026 14:30',
        'hora'       => 'Hora: 14:30',
        'upper'      => 'MAYÚSCULAS',
        'lower'      => 'minúsculas',
        'nl2br'      => 'Respeta los saltos de línea del texto',
    ];

    /** Variables que no son datos sino estructura del documento. */
    public const ESPECIALES = [
        'salto_pagina'  => '<div class="salto-pagina"></div>',
        'pagina'        => '<span class="pdf-pagina"></span>',
        'total_paginas' => '<span class="pdf-total-paginas"></span>',
    ];

    private array $raiz = [];
    private array $desconocidas = [];

    public static function render(string $html, array $datos): string
    {
        return (new self)->ejecutar($html, $datos)['html'];
    }

    /**
     * Lo mismo que render(), pero además dice qué salió mal: etiquetas mal
     * cerradas y variables que no existen en los datos reales.
     *
     * @return array{html: string, errores: string[], desconocidas: string[]}
     */
    public static function analizar(string $html, array $datos): array
    {
        return (new self)->ejecutar($html, $datos);
    }

    private function ejecutar(string $html, array $datos): array
    {
        $this->raiz = $datos;
        $this->desconocidas = [];
        [$arbol, $errores] = $this->parsear($html);
        $salida = $this->recorrer($arbol, []);

        return [
            'html'         => $salida,
            'errores'      => $errores,
            'desconocidas' => array_values(array_unique($this->desconocidas)),
        ];
    }

    // ─── Lectura ──────────────────────────────────────────────────────────────

    /** @return array{0: array, 1: string[]} */
    private function parsear(string $html): array
    {
        // {{{x}}} primero, para que no lo parta {{x}}.
        $partes = preg_split('/(\{\{\{.+?\}\}\}|\{\{.+?\}\})/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);

        $raiz    = ['hijos' => []];
        $pila    = [&$raiz];
        $errores = [];

        foreach ($partes as $i => $parte) {
            if ($i % 2 === 0) {
                if ($parte !== '') {
                    $pila[count($pila) - 1]['hijos'][] = ['t' => 'texto', 'v' => $parte];
                }
                continue;
            }

            $crudo = str_starts_with($parte, '{{{');
            $tag   = trim($crudo ? substr($parte, 3, -3) : substr($parte, 2, -2));
            $tope  = &$pila[count($pila) - 1];

            if (preg_match('/^#(if|unless)\s+(.+)$/s', $tag, $m)) {
                $nodo = ['t' => $m[1], 'cond' => trim($m[2]), 'hijos' => [], 'sino' => null, 'tag' => $parte];
                $tope['hijos'][] = &$nodo;
                $pila[] = &$nodo;
                unset($nodo);
            } elseif (preg_match('/^#each\s+([\w.@]+)$/', $tag, $m) || preg_match('/^#([\w.]+)$/', $tag, $m)) {
                $nodo = ['t' => 'each', 'lista' => $m[1], 'hijos' => [], 'sino' => null, 'tag' => $parte];
                $tope['hijos'][] = &$nodo;
                $pila[] = &$nodo;
                unset($nodo);
            } elseif ($tag === 'else') {
                if (count($pila) === 1 || $tope['sino'] !== null) {
                    $errores[] = '«{{else}}» sin un {{#if}} o {{#each}} abierto.';
                    $tope['hijos'][] = ['t' => 'texto', 'v' => $parte];
                } else {
                    // Lo que sigue va a la rama «si no»: se cambia el destino.
                    $tope['sino'] = ['hijos' => $tope['hijos']];
                    $tope['hijos'] = [];
                    $tope['cambiado'] = true;
                }
            } elseif (preg_match('/^\/([\w.]+)$/', $tag, $m)) {
                $cierra = $m[1];
                $abierto = count($pila) > 1 ? $tope : null;
                $esperado = $abierto ? ($abierto['t'] === 'each' ? ['each', $abierto['lista']] : [$abierto['t']]) : [];

                if (! $abierto || ! in_array($cierra, $esperado, true)) {
                    $errores[] = "«{$parte}» cierra algo que no está abierto"
                        . ($abierto ? " (falta cerrar «{$abierto['tag']}»)." : '.');
                    $tope['hijos'][] = ['t' => 'texto', 'v' => ''];
                } else {
                    if (! empty($tope['cambiado'])) {
                        // Con {{else}}: lo primero era el «sí», lo último el «no».
                        [$tope['hijos'], $tope['sino']] = [$tope['sino']['hijos'], $tope['hijos']];
                    }
                    array_pop($pila);
                }
            } else {
                $tope['hijos'][] = ['t' => 'var', 'expr' => $tag, 'crudo' => $crudo];
            }
            unset($tope);
        }

        while (count($pila) > 1) {
            $abierto = array_pop($pila);
            $errores[] = "Falta cerrar «{$abierto['tag']}».";
        }

        return [$raiz['hijos'], $errores];
    }

    // ─── Recorrido ────────────────────────────────────────────────────────────

    private function recorrer(array $nodos, array $alcances): string
    {
        $out = '';
        foreach ($nodos as $n) {
            $out .= match ($n['t']) {
                'texto'  => $n['v'],
                'var'    => $this->variable($n['expr'], $n['crudo'], $alcances),
                'if'     => $this->recorrer($this->condicion($n['cond'], $alcances) ? $n['hijos'] : ($n['sino'] ?? []), $alcances),
                'unless' => $this->recorrer(! $this->condicion($n['cond'], $alcances) ? $n['hijos'] : ($n['sino'] ?? []), $alcances),
                'each'   => $this->cada($n, $alcances),
            };
        }
        return $out;
    }

    private function cada(array $n, array $alcances): string
    {
        [$existe, $lista] = $this->buscar($n['lista'], $alcances);
        if (! $existe) {
            $this->desconocidas[] = $n['lista'];
        }
        if (! is_iterable($lista) || empty($lista)) {
            return $this->recorrer($n['sino'] ?? [], $alcances);
        }

        $lista = array_values(is_array($lista) ? $lista : iterator_to_array($lista));
        $total = count($lista);
        $out   = '';
        foreach ($lista as $i => $fila) {
            $fila = is_array($fila) ? $fila : ['this' => $fila];
            $fila += ['@index' => $i, '@numero' => $i + 1, '@first' => $i === 0, '@last' => $i === $total - 1];
            // index (desde 1) es el legado de las plantillas viejas.
            $fila += ['index' => $i + 1];
            $out .= $this->recorrer($n['hijos'], [...$alcances, $fila]);
        }
        return $out;
    }

    private function variable(string $expr, bool $crudo, array $alcances): string
    {
        if (isset(self::ESPECIALES[$expr])) {
            return self::ESPECIALES[$expr];
        }

        if (str_starts_with($expr, 'qr:')) {
            $clave = trim(substr($expr, 3));
            [$existe, $valor] = $this->buscar($clave, $alcances);
            return $this->qr($existe ? (string) $this->escalar($valor) : $clave);
        }

        if (str_starts_with($expr, '!')) {
            $crudo = true;
            $expr  = ltrim(substr($expr, 1));
        }

        $filtros = array_map('trim', explode('|', $expr));
        $clave   = array_shift($filtros);

        [$existe, $valor] = $this->buscar($clave, $alcances);
        if (! $existe) {
            $this->desconocidas[] = $clave;
            return '';
        }

        $texto = (string) $this->escalar($valor);
        $esHtml = false;
        foreach ($filtros as $f) {
            [$texto, $esHtml] = $this->filtrar($f, $texto, $esHtml);
        }

        return ($crudo || $esHtml) ? $texto : htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
    }

    /** @return array{0: bool, 1: mixed} */
    private function buscar(string $clave, array $alcances): array
    {
        for ($i = count($alcances) - 1; $i >= 0; $i--) {
            if ($clave === 'this' && array_key_exists('this', $alcances[$i])) {
                return [true, $alcances[$i]['this']];
            }
            if (array_key_exists($clave, $alcances[$i])) {
                return [true, $alcances[$i][$clave]];
            }
        }
        if (array_key_exists($clave, $this->raiz)) {
            return [true, $this->raiz[$clave]];
        }
        return [false, null];
    }

    private function escalar(mixed $v): mixed
    {
        return match (true) {
            $v === null          => '',
            is_bool($v)          => $v ? '1' : '',
            $v instanceof \DateTimeInterface => $v->format('Y-m-d H:i:s'),
            is_array($v), is_object($v) => '',
            default              => $v,
        };
    }

    private function condicion(string $cond, array $alcances): bool
    {
        if (preg_match('/^([\w.@]+)\s*(==|!=|>=|<=|>|<)\s*(.+)$/', $cond, $m)) {
            [$existe, $izq] = $this->buscar($m[1], $alcances);
            if (! $existe) $this->desconocidas[] = $m[1];
            $der = trim($m[3]);
            if (preg_match('/^(["\'])(.*)\1$/', $der, $q)) {
                $der = $q[2];
            } else {
                [$e2, $v2] = $this->buscar($der, $alcances);
                if ($e2) $der = $v2;
            }
            $izq = $this->escalar($izq);
            $der = $this->escalar($der);
            if (is_numeric($izq) && is_numeric($der)) {
                $izq = (float) $izq;
                $der = (float) $der;
            } else {
                $izq = (string) $izq;
                $der = (string) $der;
            }
            return match ($m[2]) {
                '==' => $izq == $der,
                '!=' => $izq != $der,
                '>'  => $izq >  $der,
                '<'  => $izq <  $der,
                '>=' => $izq >= $der,
                '<=' => $izq <= $der,
            };
        }

        $negar = str_starts_with($cond, '!');
        $clave = ltrim($cond, '! ');
        [$existe, $v] = $this->buscar($clave, $alcances);
        if (! $existe) $this->desconocidas[] = $clave;

        return $negar xor $this->verdadero($v);
    }

    /** «0.00» de un decimal de MySQL es falso: un descuento en cero no se muestra. */
    private function verdadero(mixed $v): bool
    {
        if (is_iterable($v)) return ! empty($v) && (is_array($v) ? count($v) > 0 : true);
        $v = $this->escalar($v);
        if (is_numeric($v)) return (float) $v != 0.0;
        return $v !== '' && $v !== false;
    }

    /** @return array{0: string, 1: bool} el texto y si ya es HTML */
    private function filtrar(string $filtro, string $v, bool $esHtml): array
    {
        $num = fn () => is_numeric($v) ? (float) $v : 0.0;
        $sinCeros = fn (string $s) => str_contains($s, ',') ? rtrim(rtrim($s, '0'), ',') : $s;

        return match (strtolower($filtro)) {
            'moneda'              => [$v === '' ? '' : '$' . number_format($num(), 0, ',', '.'), $esHtml],
            'numero'              => [$v === '' ? '' : $sinCeros(number_format($num(), 2, ',', '.')), $esHtml],
            'entero'              => [$v === '' ? '' : number_format($num(), 0, ',', '.'), $esHtml],
            'pct'                 => [$v === '' ? '' : $sinCeros(number_format($num(), 2, ',', '.')) . '%', $esHtml],
            'fecha'               => [$this->fecha($v, 'd/m/Y'), $esHtml],
            'fecha_hora'          => [$this->fecha($v, 'd/m/Y H:i'), $esHtml],
            'hora'                => [$this->fecha($v, 'H:i'), $esHtml],
            'upper', 'mayus'      => [mb_strtoupper($v), $esHtml],
            'lower', 'minus'      => [mb_strtolower($v), $esHtml],
            'nl2br'               => [nl2br($esHtml ? $v : htmlspecialchars($v, ENT_QUOTES, 'UTF-8')), true],
            default               => [$v, $esHtml],
        };
    }

    private function fecha(string $v, string $formato): string
    {
        if ($v === '') return '';
        try {
            return Carbon::parse($v)->setTimezone(config('app.timezone'))->format($formato);
        } catch (\Throwable) {
            return $v;
        }
    }

    private function qr(string $valor): string
    {
        if ($valor === '') return '';
        try {
            $svg = \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->size(150)->generate($valor);
            return '<img src="data:image/svg+xml;base64,' . base64_encode((string) $svg) . '" style="width:80px;height:80px;"/>';
        } catch (\Throwable) {
            return '';
        }
    }
}
