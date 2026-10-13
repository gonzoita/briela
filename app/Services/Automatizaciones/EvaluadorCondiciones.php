<?php

namespace App\Services\Automatizaciones;

/**
 * Evalúa las condiciones de un nodo contra los datos del documento, y rellena los textos.
 *
 * Solo compara; no ejecuta nada ni interpreta texto como código (las fórmulas del cotizador usan
 * un motor aparte, y esto no lo necesita). Un dato que no existe nunca cumple una condición
 * positiva, y siempre cumple «es distinto de», «no contiene» y «está vacío».
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

        $existe = array_key_exists($campo, $ctx) && $ctx[$campo] !== null && $ctx[$campo] !== '';
        $dato   = $existe ? $ctx[$campo] : null;

        if ($op === 'vacio') {
            return ! $existe;
        }

        if ($op === 'no_vacio') {
            return $existe;
        }

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
            'en_lista'    => in_array(self::texto($dato), array_map(fn ($v) => self::texto($v), explode(',', (string) $valor)), true),
            default       => false,
        };
    }

    /** Sin mayúsculas, sin espacios de sobra y sin tildes: «Bogotá» y «bogota» son lo mismo. */
    private static function texto(mixed $v): string
    {
        $t = mb_strtolower(trim((string) $v));

        return strtr($t, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
    }

    /**
     * Rellena `{{campo}}` en un texto con los datos del documento. Admite un formato:
     * `{{total|dinero}}`, `{{cliente|mayusculas}}`. Además de los campos hay `{{enlace}}` (la
     * dirección completa del documento) y `{{hoy}}`. Lo que no existe queda como está, para que se
     * note en el aviso en vez de desaparecer en silencio.
     */
    public static function rellenar(string $texto, array $contexto): string
    {
        return preg_replace_callback('/\{\{\s*(\w+)\s*(?:\|\s*(\w+)\s*)?\}\}/u', function ($m) use ($contexto) {
            $clave = $m[1];

            $v = match ($clave) {
                'enlace' => isset($contexto['_url']) ? rtrim((string) config('app.url'), '/') . $contexto['_url'] : null,
                'hoy'    => $contexto['hoy'] ?? now()->toDateString(),
                default  => $contexto[$clave] ?? null,
            };

            if (! is_scalar($v)) {
                return $m[0];
            }

            return match ($m[2] ?? '') {
                'dinero'     => is_numeric($v) ? '$ ' . number_format((float) $v, 0, ',', '.') : (string) $v,
                'mayusculas' => mb_strtoupper((string) $v),
                'minusculas' => mb_strtolower((string) $v),
                default      => (string) $v,
            };
        }, $texto);
    }

    /** Los datos que se pueden mostrar o mandar fuera: los del documento, sin las claves internas. */
    public static function publicos(array $contexto): array
    {
        return array_filter($contexto, fn ($k) => ! str_starts_with((string) $k, '_'), ARRAY_FILTER_USE_KEY);
    }
}
