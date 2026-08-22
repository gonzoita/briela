<?php

namespace Tests\Unit;

use App\Services\CanalesPrecioService;
use App\Services\PreciosPorCanalService;
use PHPUnit\Framework\TestCase;

/**
 * Fija los números del precio de venta a partir del costo.
 *
 * La cuenta estuvo escrita tres veces con tres resultados distintos, y el mismo ensamble valía
 * tres precios según por qué pantalla se entrara:
 *
 * - la ficha del producto y el recálculo del ensamble: `/(1-m)` redondeando al millar,
 * - el configurador de la cotización: `/(1-m)` redondeando a **cinco mil**,
 * - el probador de plantillas: `*(1+m)`.
 *
 * Ahora hay una sola definición, y este test es el que impide que se vuelvan a separar. La
 * pantalla repite la misma cuenta en `resources/js/composables/usePreciosPorCanal.js` porque
 * la necesita instantánea mientras se teclea: si se cambia una, se cambia la otra.
 *
 * **El margen es un recargo sobre el costo**: con 30 %, el precio es el costo más un 30 % de
 * ese costo. Decidido el 21 ago 2026. En contabilidad la palabra «margen» significa porcentaje
 * del precio de venta, pero no es lo que la pantalla de Segmentación le promete a quien
 * escribe el número, y el número lo escribe quien pone los precios.
 */
class PrecioDesdeCostoTest extends TestCase
{
    private function servicio(): PreciosPorCanalService
    {
        // `precioDesdeCosto` no consulta la base: son dos números y una cuenta.
        return new PreciosPorCanalService(new CanalesPrecioService());
    }

    /**
     * El caso real que destapó el error: una puerta institucional de 0,80 × 1,90.
     *
     * Costo 1.209.954 con el canal Distribuidor al 30 %. Salía 1.795.000 por dos motivos
     * encadenados: el configurador ignoraba ese 30 % y aplicaba un 32,5 % escrito en el
     * código, y además calculaba el margen sobre la venta en vez de sobre el costo.
     *
     * 1.209.954 × 1,30 = 1.572.940,2, que sube al millar siguiente.
     */
    public function test_el_caso_de_la_puerta_institucional(): void
    {
        $this->assertSame(1573000.0, $this->servicio()->precioDesdeCosto(1209954, 30));
    }

    /** Ni el 32,5 % de antes ni el margen sobre la venta vuelven a aparecer. */
    public function test_los_numeros_viejos_ya_no_salen(): void
    {
        $precio = $this->servicio()->precioDesdeCosto(1209954, 30);

        $this->assertNotSame(1795000.0, $precio);   // 32,5 % sobre la venta, techo de 5.000
        $this->assertNotSame(1729000.0, $precio);   // 30 % sobre la venta, techo de 1.000
    }

    /** El margen es del costo: con 30 %, se cobra el costo y un 30 % más. */
    public function test_el_margen_es_un_recargo_sobre_el_costo(): void
    {
        // 1.000.000 × 1,30 = 1.300.000 exacto, así que el redondeo no lo mueve.
        $this->assertSame(1300000.0, $this->servicio()->precioDesdeCosto(1000000, 30));
    }

    /** Se redondea hacia arriba: hacia abajo se regala margen. */
    public function test_redondea_hacia_arriba_al_millar(): void
    {
        // 100.500 × 1,25 = 125.625 → 126.000
        $this->assertSame(126000.0, $this->servicio()->precioDesdeCosto(100500, 25));
    }

    /**
     * Un recargo mayor al 100 % es legítimo.
     *
     * Con la fórmula vieja era una división por un número negativo, así que estaba cortado en
     * 100 y un producto que se vende a dos veces y media su costo no se podía configurar.
     */
    public function test_un_recargo_mayor_a_cien_es_valido(): void
    {
        // 400.000 × 2,40 = 960.000
        $this->assertSame(960000.0, $this->servicio()->precioDesdeCosto(400000, 140));
    }

    /**
     * Sin costo o sin margen, el precio es cero.
     *
     * Cero es lo que la pantalla sabe mostrar como «falta el precio».
     */
    public function test_los_casos_que_no_tienen_precio(): void
    {
        $s = $this->servicio();

        $this->assertSame(0.0, $s->precioDesdeCosto(0, 30));
        $this->assertSame(0.0, $s->precioDesdeCosto(1209954, 0));
    }
}
