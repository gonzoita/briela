<?php

namespace Tests\Unit;

use App\Services\FormulaEvaluatorService;
use Tests\TestCase;

/**
 * La coma decimal de las fórmulas: decimal donde no puede separar nada, separador donde sí.
 *
 * El reemplazo era un `(\d),(\d)` a ciegas, y convertía en decimal también las comas que
 * separan argumentos y elementos de una lista: `max(1,5)` daba 1,5 en vez de 5, y `[1,2,3]`
 * quedaba como `[1.2.3]`, que es un error de sintaxis — y el evaluador lo devuelve como un cero
 * silencioso.
 *
 * Usa el TestCase de Laravel solo porque el evaluador anota en el registro cuando una fórmula
 * falla: sin aplicación, una prueba rota reventaría por la fachada y no por la fórmula.
 */
class FormulaComaDecimalTest extends TestCase
{
    private function evaluar(string $formula, array $vars = []): float
    {
        return app(FormulaEvaluatorService::class)->evaluar($formula, $vars);
    }

    // ── Donde la coma es decimal ────────────────────────────────────────────

    public function test_un_numero_suelto_con_coma_es_decimal(): void
    {
        $this->assertEqualsWithDelta(5.0, $this->evaluar('2,5 * 2'), 1e-9);
        $this->assertEqualsWithDelta(2.24, $this->evaluar('alto + 0,04', ['alto' => 2.2]), 1e-9);
    }

    public function test_dentro_de_un_parentesis_de_agrupar_tambien(): void
    {
        // El paréntesis interno agrupa: la coma no puede separar nada ahí.
        $this->assertEqualsWithDelta(2.3, $this->evaluar('ceil((2 * ancho + 0,3) * 10) / 10', ['ancho' => 1]), 1e-9);
        $this->assertEqualsWithDelta(2.3, $this->evaluar('(2 * ancho + 0,3)', ['ancho' => 1]), 1e-9);
    }

    public function test_en_un_ternario_suelto(): void
    {
        $this->assertSame(1.0, $this->evaluar('ancho <= 1,1 ? 1 : 2', ['ancho' => 1.05]));
        $this->assertSame(2.0, $this->evaluar('ancho <= 1,1 ? 1 : 2', ['ancho' => 1.2]));
    }

    public function test_un_grupo_despues_de_and_o_not_no_es_una_funcion(): void
    {
        $this->assertSame(1.0, $this->evaluar('x > 1 and (y + 0,5) > 2', ['x' => 2, 'y' => 2]));
        $this->assertSame(0.0, $this->evaluar('not (y > 2,5)', ['y' => 3]));
    }

    // ── Donde la coma separa ────────────────────────────────────────────────

    public function test_los_argumentos_de_una_funcion_no_se_tocan(): void
    {
        $this->assertSame(5.0, $this->evaluar('max(1,5)'));
        $this->assertSame(1.0, $this->evaluar('min(1,5)'));
        $this->assertSame(7.0, $this->evaluar('max(1,5,7)'));
        $this->assertSame(8.0, $this->evaluar('pow(2,3)'));
        $this->assertSame(4.0, $this->evaluar('iif(ancho > 1,4,2)', ['ancho' => 2]));
    }

    public function test_un_decimal_dentro_de_una_funcion_se_escribe_con_punto_o_agrupado(): void
    {
        $this->assertSame(2.0, $this->evaluar('iif(ancho > 1.5, 4, 2)', ['ancho' => 1.4]));
        $this->assertSame(2.0, $this->evaluar('iif(ancho > (1,5), 4, 2)', ['ancho' => 1.4]));
        $this->assertSame(4.0, $this->evaluar('iif(ancho > (1,5), 4, 2)', ['ancho' => 1.6]));
    }

    public function test_los_elementos_de_una_lista_no_se_tocan(): void
    {
        $this->assertSame(1.0, $this->evaluar('3 in [1,2,3] ? 1 : 0'));
        $this->assertSame(0.0, $this->evaluar('4 in [1,2,3] ? 1 : 0'));
        // Antes, [1,2,3] quedaba como [1.2.3]: error de sintaxis y un cero silencioso.
        $this->assertSame(1.0, $this->evaluar('2 in [1,2,3] ? 1 : 0'));
    }

    public function test_una_funcion_dentro_de_un_grupo_dentro_de_una_funcion(): void
    {
        // max( … ) separa; ( … ) agrupa; min( … ) vuelve a separar.
        $this->assertEqualsWithDelta(2.5, $this->evaluar('max((2,5), min(1,2))'), 1e-9);
    }

    public function test_lo_que_va_entre_comillas_no_se_toca(): void
    {
        $this->assertSame(1.0, $this->evaluar('tipo == "1,5" ? 1 : 0', ['tipo' => '1,5']));
        $this->assertSame(1.0, $this->evaluar("tipo == '2,0' ? 1 : 0", ['tipo' => '2,0']));
    }

    public function test_el_final_de_un_nombre_no_es_un_numero(): void
    {
        // `x1,2` no es «x1.2»: es el nombre x1 seguido de una coma, y fuera de una llamada no
        // se puede leer. Que siga fallando —un cero, con el error en el registro— en vez de
        // inventarse una variable que no existe.
        $this->assertSame(0.0, $this->evaluar('x1,2', ['x1' => 7]));
    }

    public function test_el_probador_usa_la_misma_regla(): void
    {
        $res = app(FormulaEvaluatorService::class)->testFormula(
            'max(area, 1,5)',
            ['ancho' => 1, 'alto' => 2],
            [['nombre' => 'area', 'formula' => 'ancho * (alto + 0,04)']]
        );

        $this->assertNull($res['error']);
        $this->assertEqualsWithDelta(5.0, $res['resultado'], 1e-9);
    }
}
