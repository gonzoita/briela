<?php

namespace Tests\Unit;

use App\Services\CanalesPrecioService;
use App\Services\PreciosPorCanalService;
use PHPUnit\Framework\TestCase;

/**
 * Fija los números del reparto de comisiones.
 *
 * La regla está escrita dos veces —aquí en PHP para el comando `comisiones:recalcular`, y en
 * `resources/js/composables/usePreciosPorCanal.js` para el botón de la ficha— porque la
 * pantalla la necesita instantánea y el comando la necesita sin navegador. Este test es el
 * que impide que las dos se separen sin que nadie lo note.
 *
 * **El porcentaje es del PRECIO de venta**, no del excedente: es la unidad en que lo lee un
 * vendedor y la que hace que el descuento sea la resta de dos porcentajes. La plata sigue
 * saliendo del excedente sobre el canal base.
 */
class SugerirComisionesTest extends TestCase
{
    private function servicio(): PreciosPorCanalService
    {
        // `sugerirComisiones` no consulta la base: recibe las filas ya armadas.
        return new PreciosPorCanalService(new CanalesPrecioService());
    }

    /** Los canales del caso real que destapó el error, con costo 927.935. */
    private function filas(): array
    {
        return [
            ['segmentacion_opcion_id' => 1, 'precio' => 1326000.0, 'es_canal_base' => true,  'es_precio_publico' => false],
            ['segmentacion_opcion_id' => 2, 'precio' => 1375000.0, 'es_canal_base' => false, 'es_precio_publico' => false],
            ['segmentacion_opcion_id' => 3, 'precio' => 1428000.0, 'es_canal_base' => false, 'es_precio_publico' => false],
            ['segmentacion_opcion_id' => 4, 'precio' => 1428000.0, 'es_canal_base' => false, 'es_precio_publico' => true],
        ];
    }

    public function test_el_canal_base_no_paga_comision_ni_descuenta(): void
    {
        $base = $this->servicio()->sugerirComisiones($this->filas())[0];

        $this->assertSame(0.0, $base['comision_min_pct']);
        $this->assertSame(0.0, $base['comision_max_pct']);
        $this->assertSame(0.0, $base['descuento_max_pct']);
    }

    public function test_el_tope_es_medio_excedente_dicho_en_porcentaje_del_precio(): void
    {
        [$base, $distribuidor, $especial, $publico] = $this->servicio()->sugerirComisiones($this->filas());

        // 49.000 de excedente, la mitad para el vendedor: 24.500, que sobre un precio de
        // 1.375.000 es el 1,78 %.
        $this->assertSame(1.78, $distribuidor['comision_max_pct']);
        $this->assertEqualsWithDelta(24500, 1375000 * $distribuidor['comision_max_pct'] / 100, 50);

        // 102.000 de excedente, la mitad: 51.000 sobre 1.428.000 es el 3,57 %.
        $this->assertSame(3.57, $especial['comision_max_pct']);
        $this->assertEqualsWithDelta(51000, 1428000 * $especial['comision_max_pct'] / 100, 50);
    }

    public function test_el_precio_publico_lleva_el_setenta_por_ciento_del_excedente(): void
    {
        $publico = $this->servicio()->sugerirComisiones($this->filas())[3];

        // 70 % de 102.000 son 71.400, que es exactamente el 5 % de 1.428.000.
        $this->assertSame(5.0, $publico['comision_max_pct']);
        $this->assertSame(71400.0, 1428000 * $publico['comision_max_pct'] / 100);
    }

    public function test_el_piso_de_cada_canal_es_la_plata_que_paga_el_de_abajo(): void
    {
        [$base, $distribuidor, $especial, $publico] = $this->servicio()->sugerirComisiones($this->filas());

        // Vender Especial con su descuento máximo deja el precio de Distribuidor sin
        // descuento: las dos ventas tienen que pagar la misma comisión. 24.500 sobre 1.428.000
        // es 1,72 %, y no 1,78 %, que es lo que daría comparar los porcentajes crudos.
        $this->assertSame(1.72, $especial['comision_min_pct']);
        $this->assertEqualsWithDelta(24500, 1428000 * $especial['comision_min_pct'] / 100, 100);

        $this->assertSame(3.57, $publico['comision_min_pct']);
    }

    public function test_lo_que_el_vendedor_puede_convertir_en_descuento(): void
    {
        $publico = $this->servicio()->sugerirComisiones($this->filas())[3];

        // El descuento que puede dar es lo que deja de ganar: la resta de los dos
        // porcentajes, porque los dos son del mismo precio.
        $this->assertSame(1.43, round($publico['comision_max_pct'] - $publico['comision_min_pct'], 2));
    }

    public function test_el_tope_nunca_pasa_del_excedente(): void
    {
        $filas = $this->servicio()->sugerirComisiones($this->filas());
        $base  = 1326000.0;

        foreach (array_slice($filas, 1) as $fila) {
            $comision  = $fila['precio'] * $fila['comision_max_pct'] / 100;
            $excedente = $fila['precio'] - $base;

            // Más que el excedente saldría de la utilidad garantizada de la empresa, y el
            // cliente podría terminar pagando menos que el precio del canal base.
            $this->assertLessThanOrEqual($excedente, $comision);
        }
    }

    public function test_la_plata_del_vendedor_sube_en_cada_escalon(): void
    {
        $filas = $this->servicio()->sugerirComisiones($this->filas());
        $antes = -1.0;

        foreach (array_slice($filas, 1) as $fila) {
            $tope = $fila['precio'] * $fila['comision_max_pct'] / 100;

            $this->assertGreaterThan($antes, $tope, 'Un canal más lejos del base no puede pagar menos.');
            $antes = $tope;
        }
    }

    public function test_el_descuento_maximo_llega_hasta_el_canal_de_abajo(): void
    {
        [$base, $distribuidor, $especial, $publico] = $this->servicio()->sugerirComisiones($this->filas());

        $this->assertSame(3.56, $distribuidor['descuento_max_pct']);
        $this->assertSame(3.71, $especial['descuento_max_pct']);
        $this->assertSame(0.0, $publico['descuento_max_pct']);
    }

    public function test_un_canal_que_vale_lo_mismo_que_el_base_no_reparte_nada(): void
    {
        $filas = [
            ['segmentacion_opcion_id' => 1, 'precio' => 100000.0, 'es_canal_base' => true,  'es_precio_publico' => false],
            ['segmentacion_opcion_id' => 2, 'precio' => 100000.0, 'es_canal_base' => false, 'es_precio_publico' => false],
            ['segmentacion_opcion_id' => 3, 'precio' => 120000.0, 'es_canal_base' => false, 'es_precio_publico' => true],
        ];

        [$base, $sinExcedente, $publico] = $this->servicio()->sugerirComisiones($filas);

        $this->assertSame(0.0, $sinExcedente['comision_max_pct']);

        // Y el canal de arriba no hereda piso de uno que no paga nada.
        $this->assertSame(0.0, $publico['comision_min_pct']);
        // 70 % de 20.000 son 14.000, que es el 11,67 % de 120.000.
        $this->assertSame(11.67, $publico['comision_max_pct']);
    }
}
