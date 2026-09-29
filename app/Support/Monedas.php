<?php

namespace App\Support;

use App\Models\Configuracion;

/**
 * Las monedas con las que trabaja Briela, y cómo se escriben.
 *
 * **El peso es la moneda de la casa.** Costos, precios del catálogo, comisiones, cartera e
 * informes se guardan siempre en pesos. Una cotización en dólares guarda sus ítems en pesos y
 * una tasa: los dólares son la forma de MOSTRARLE los valores al cliente. Guardar cada
 * cotización en su moneda habría obligado a convertir en cada informe, en cada liquidación de
 * comisiones y en cada suma del tablero, y un solo lugar que se olvidara de hacerlo sumaba
 * dólares con pesos sin avisar.
 *
 * La lista es corta a propósito: son las monedas de la tabla `cotizaciones` (un `enum`), y
 * agregar una exige una migración y una fuente para su tasa.
 */
class Monedas
{
    public const LOCAL = 'COP';

    /** @var array<string, array{nombre: string, simbolo: string, decimales: int}> */
    public const CATALOGO = [
        'COP' => ['nombre' => 'Peso colombiano',   'simbolo' => '$',   'decimales' => 0],
        'USD' => ['nombre' => 'Dólar',             'simbolo' => 'US$', 'decimales' => 2],
        'EUR' => ['nombre' => 'Euro',              'simbolo' => '€',   'decimales' => 2],
    ];

    /** Las que necesitan tasa: todas menos el peso. @return list<string> */
    public static function extranjeras(): array
    {
        return array_values(array_diff(array_keys(self::CATALOGO), [self::LOCAL]));
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
    public static function formatear(float|int|string|null $valorEnPesos, string $moneda = self::LOCAL, float $tasa = 1): string
    {
        $info  = self::CATALOGO[$moneda] ?? self::CATALOGO[self::LOCAL];
        $valor = self::desdePesos((float) $valorEnPesos, $moneda, $tasa);

        return $info['simbolo'] . ($moneda === self::LOCAL ? '' : ' ')
            . number_format($valor, $info['decimales'], ',', '.');
    }

    /** Pesos → moneda del documento. Con una tasa inválida devuelve los pesos: nunca divide por cero. */
    public static function desdePesos(float $valor, string $moneda, float $tasa): float
    {
        if ($moneda === self::LOCAL || $tasa <= 0) {
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
