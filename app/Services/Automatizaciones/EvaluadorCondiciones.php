<?php

namespace App\Services\Automatizaciones;

/**
 * Evalúa las condiciones de un nodo contra los datos del documento.
 *
 * Solo compara; no ejecuta nada ni interpreta texto como código (las fórmulas del cotizador usan
 * un motor aparte, y esto no lo necesita). Un dato que no existe nunca cumple una condición
 * positiva, y siempre cumple «es distinto de» y «no contiene».
 */
class EvaluadorCondiciones
{
    /**
     * @param  list<array{campo: string, operador: string, valor: mixed}>  $reglas
     * @param  array<string, mixed>  $contexto
     */
    public static function cumple(array $reglas, string $combinador, array $contexto): bool
    {
        if ($reglas === []) {
            return true;
        }

        foreach ($reglas as $regla) {
            $ok = self::regla($regla, $contexto);

            if ($combinador === 'o' && $ok) {
                return true;
            }

            if ($combinador !== 'o' && ! $ok) {
                return false;
            }
        }

        return $combinador !== 'o';
    }

    /** @param array{campo?: string, operador?: string, valor?: mixed} $r */
    private static function regla(array $r, array $ctx): bool
    {
        $campo = (string) ($r['campo'] ?? '');
        $op    = (string) ($r['operador'] ?? 'igual');
        $valor = $r['valor'] ?? null;

        $existe = array_key_exists($campo, $ctx) && $ctx[$campo] !== null;
        $dato   = $existe ? $ctx[$campo] : null;

        if (! $existe) {
            return in_array($op, ['distinto', 'no_contiene'], true);
        }

        $numericos = is_numeric($dato) && is_numeric($valor);

        return match ($op) {
            'igual'       => $numericos ? (float) $dato === (float) $valor : self::texto($dato) === self::texto($valor),
            'distinto'    => $numericos ? (float) $dato !== (float) $valor : self::texto($dato) !== self::texto($valor),
            'mayor'       => $numericos && (float) $dato > (float) $valor,
            'mayor_igual' => $numericos && (float) $dato >= (float) $valor,
            'menor'       => $numericos && (float) $dato < (float) $valor,
            'menor_igual' => $numericos && (float) $dato <= (float) $valor,
            'contiene'    => $valor !== null && $valor !== '' && str_contains(self::texto($dato), self::texto($valor)),
            'no_contiene' => $valor === null || $valor === '' || ! str_contains(self::texto($dato), self::texto($valor)),
            default       => false,
        };
    }

    private static function texto(mixed $v): string
    {
        return mb_strtolower(trim((string) $v));
    }

    /** Rellena `{{campo}}` en un texto con los datos del documento. Lo que no existe queda como está. */
    public static function rellenar(string $texto, array $contexto): string
    {
        return preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', function ($m) use ($contexto) {
            $v = $contexto[$m[1]] ?? null;

            return is_scalar($v) ? (string) $v : $m[0];
        }, $texto);
    }
}
