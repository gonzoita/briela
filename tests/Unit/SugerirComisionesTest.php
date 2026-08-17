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
 * que impide que las dos se separen sin que nadie lo note: los valores esperados son los
 * mismos que produce el composable con las mismas entradas.
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

    public function test_el_tope_reparte_la_mitad_del_excedente_y_setenta_por_ciento_en_precio_publico(): void
    {
        [$base, $distribuidor, $especial, $publico] = $this->servicio()->sugerirComisiones($this->filas());

        // 49.000 de excedente sobre el canal base; antes el tope quedaba en 2,32 % —1.137—
        // porque el porcentaje salía de lo que el excedente representa del precio.
        $this->assertSame(0.0, $distribuidor['comision_min_pct']);
        $this->assertSame(50.0, $distribuidor['comision_max_pct']);
        $this->assertSame(24500.0, 49000 * $distribuidor['comision_max_pct'] / 100);

        $this->assertSame(50.0, $especial['comision_max_pct']);
        $this->assertSame(70.0, $publico['comision_max_pct']);
        $this->assertSame(71400.0, 102000 * $publico['comision_max_pct'] / 100);
    }

    public function test_el_piso_de_cada_canal_es_la_plata_que_paga_el_de_abajo(): void
    {
        [$base, $distribuidor, $especial, $publico] = $this->servicio()->sugerirComisiones($this->filas());

        // Vender Especial con su descuento máximo deja el precio de Distribuidor sin
        // descuento: las dos ventas tienen que pagar la misma comisión. 50 % de 49.000 son
        // 24.500, que sobre los 102.000 de Especial es 24,02 % — y no 50 %, que es lo que
        // daba comparar los porcentajes crudos.
        $this->assertSame(24.02, $especial['comision_min_pct']);
        $this->assertEqualsWithDelta(24500, 102000 * $especial['comision_min_pct'] / 100, 20);

        $this->assertSame(50.0, $publico['comision_min_pct']);
        $this->assertSame(51000.0, 102000 * $publico['comision_min_pct'] / 100);
    }

    public function test_la_plata_del_vendedor_sube_en_cada_escalon(): void
    {
        $filas = $this->servicio()->sugerirComisiones($this->filas());
        $base  = 1326000.0;
        $antes = -1.0;

        foreach (array_slice($filas, 1) as $fila) {
            $tope = ($fila['precio'] - $base) * $fila['comision_max_pct'] / 100;

            $this->assertGreaterThan($antes, $tope, 'Un canal más lejos del base no puede pagar menos.');
            $antes = $tope;
        }
    }

    public function test_el_descuento_maximo_llega_hasta_el_canal_de_abajo(): void
    {
        [$base, $distribuidor, $especial, $publico] = $this->servicio()->sugerirComisiones($this->filas());

        $this->assertSame(3.56, $distribuidor['descuento_max_pct']);
        $this->assertSame(3.71, $especial['descuento_max_pct']);

        // Precio Público vale lo mismo que Precio Especial, así que no tiene hacia dónde
        // descontar. Es el caso que en la cotización se ve como «sin espacio para descuento».
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
        $this->assertSame(70.0, $publico['comision_max_pct']);
    }
}
