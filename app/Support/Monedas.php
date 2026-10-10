<?php

namespace App\Support;

use App\Models\Configuracion;

/**
 * Las monedas con las que trabaja Briela, y cómo se escriben.
 *
 * **La moneda base es la moneda de la casa** (el peso colombiano mientras la instalación no
 * diga otra). Costos, precios del catálogo, comisiones, cartera e informes se guardan
 * siempre en ella. En todo el código y los comentarios, «pesos» significa «la moneda base». Una cotización en dólares guarda sus ítems en pesos y
 * una tasa: los dólares son la forma de MOSTRARLE los valores al cliente. Guardar cada
 * cotización en su moneda habría obligado a convertir en cada informe, en cada liquidación de
 * comisiones y en cada suma del tablero, y un solo lugar que se olvidara de hacerlo sumaba
 * dólares con pesos sin avisar.
 *
 * Agregar una moneda es agregar una línea al catálogo (y su par en `formato.js`). La tasa
 * viene sola si el Banco Central Europeo la publica; si no, se escribe a mano.
 */
class Monedas
{
    /** La moneda base mientras la instalación no diga otra. */
    public const BASE_INICIAL = 'COP';

    /**
     * ISO 4217. Si se agrega una, se agrega también en `resources/js/formato.js`: una prueba
     * compara las dos listas.
     *
     * @var array<string, array{nombre: string, simbolo: string, decimales: int}>
     */
    public const CATALOGO = [
        'COP' => ['nombre' => 'Peso colombiano',       'simbolo' => '$',     'decimales' => 0],
        'USD' => ['nombre' => 'Dólar estadounidense',  'simbolo' => 'US$',   'decimales' => 2],
        'EUR' => ['nombre' => 'Euro',                  'simbolo' => '€',     'decimales' => 2],
        'VES' => ['nombre' => 'Bolívar venezolano',    'simbolo' => 'Bs.S',  'decimales' => 2],
        'PEN' => ['nombre' => 'Sol peruano',           'simbolo' => 'S/',    'decimales' => 2],
        'MXN' => ['nombre' => 'Peso mexicano',         'simbolo' => 'MX$',   'decimales' => 2],
        'CLP' => ['nombre' => 'Peso chileno',          'simbolo' => 'CL$',   'decimales' => 0],
        'ARS' => ['nombre' => 'Peso argentino',        'simbolo' => 'AR$',   'decimales' => 2],
        'BRL' => ['nombre' => 'Real brasileño',        'simbolo' => 'R$',    'decimales' => 2],
        'BOB' => ['nombre' => 'Boliviano',             'simbolo' => 'Bs',    'decimales' => 2],
        'PYG' => ['nombre' => 'Guaraní paraguayo',     'simbolo' => '₲',     'decimales' => 0],
        'UYU' => ['nombre' => 'Peso uruguayo',         'simbolo' => '$U',    'decimales' => 2],
        'CRC' => ['nombre' => 'Colón costarricense',   'simbolo' => '₡',     'decimales' => 2],
        'GTQ' => ['nombre' => 'Quetzal guatemalteco',  'simbolo' => 'Q',     'decimales' => 2],
        'HNL' => ['nombre' => 'Lempira hondureño',     'simbolo' => 'L',     'decimales' => 2],
        'NIO' => ['nombre' => 'Córdoba nicaragüense',  'simbolo' => 'C$',    'decimales' => 2],
        'DOP' => ['nombre' => 'Peso dominicano',       'simbolo' => 'RD$',   'decimales' => 2],
        'CAD' => ['nombre' => 'Dólar canadiense',      'simbolo' => 'CA$',   'decimales' => 2],
        'GBP' => ['nombre' => 'Libra esterlina',       'simbolo' => '£',     'decimales' => 2],
        'CNY' => ['nombre' => 'Yuan chino',            'simbolo' => 'CN¥',   'decimales' => 2],
    ];

    /**
     * La moneda de la casa: aquella en la que se guarda todo.
     *
     * Es una decisión de la instalación y se toma al empezar. Cambiarla con datos ya
     * guardados dejaría cada monto antiguo en una moneda que ya no es la base, por eso
     * `baseBloqueada()` la congela en cuanto hay una cotización u orden.
     */
    public static function base(): string
    {
        $moneda = strtoupper((string) Configuracion::get('moneda_base', self::BASE_INICIAL));

        return self::existe($moneda) ? $moneda : self::BASE_INICIAL;
    }

    /** ¿Ya hay documentos guardados en la moneda base actual? Entonces no se puede cambiar. */
    public static function baseBloqueada(): bool
    {
        return \App\Models\Cotizacion::query()->exists() || \App\Models\Op::query()->exists();
    }

    /** Las que necesitan tasa: todas menos la base. @return list<string> */
    public static function extranjeras(): array
    {
        return array_values(array_diff(array_keys(self::CATALOGO), [self::base()]));
    }

    public static function decimalesBase(): int
    {
        return self::CATALOGO[self::base()]['decimales'];
    }

    public static function existe(?string $moneda): bool
    {
        return $moneda !== null && isset(self::CATALOGO[$moneda]);
    }

    /**
     * Un valor en pesos, escrito en la moneda del documento.
     *
     * 6.075.000 pesos a 4.500 → «US$ 1.350,00». En pesos, sin decimales: los centavos no se
     * usan al cobrar.
     */
    public static function formatear(float|int|string|null $valorEnPesos, ?string $moneda = null, float $tasa = 1): string
    {
        $moneda ??= self::base();
        $info  = self::CATALOGO[$moneda] ?? self::CATALOGO[self::base()];
        $valor = self::desdePesos((float) $valorEnPesos, $moneda, $tasa);

        return $info['simbolo'] . ($moneda === self::base() ? '' : ' ')
            . number_format($valor, $info['decimales'], ',', '.');
    }

    /** Pesos → moneda del documento. Con una tasa inválida devuelve los pesos: nunca divide por cero. */
    public static function desdePesos(float $valor, string $moneda, float $tasa): float
    {
        if ($moneda === self::base() || $tasa <= 0) {
            return $valor;
        }

        return round($valor / $tasa, self::CATALOGO[$moneda]['decimales'] ?? 2);
    }

    // ─── Ajustes ─────────────────────────────────────────────────────────────

    /**
     * El colchón sobre la tasa al convertir un COSTO de otra moneda, en porcentaje.
     *
     * Entre el día que se cotiza y el día que se le paga al proveedor la tasa se mueve; el
     * colchón hace que el costo en pesos salga un poco más alto y el margen aguante esa
     * variación. Solo se aplica a costos: aplicarlo al precio que ve el cliente movería la
     * cifra hacia el lado equivocado.
     */
    public static function colchonPct(): float
    {
        return max(0.0, (float) Configuracion::get('monedas_colchon_pct', 0));
    }

    /**
     * Qué pasa con la tasa de una cotización en otra moneda mientras no la aprueben.
     *
     *  - `fija`: queda la del día en que se hizo. El cliente ve siempre el mismo precio en
     *    su moneda, y la diferencia de cambio la asume la empresa.
     *  - `diaria`: se actualiza cada día con la TRM. Lo que se protege es el valor en
     *    pesos, y el precio en la moneda del cliente se mueve.
     *
     * En cuanto la aprueban, la tasa queda congelada en cualquiera de los dos modos.
     */
    public static function modoCotizacion(): string
    {
        return Configuracion::get('monedas_modo_cotizacion', 'fija') === 'diaria' ? 'diaria' : 'fija';
    }
}
