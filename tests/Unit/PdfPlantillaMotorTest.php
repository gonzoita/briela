<?php

namespace Tests\Unit;

use App\Services\PdfPlantillaIaService;
use App\Services\PdfPlantillaMotor;
use App\Services\PdfPlantillaRenderer;
use Tests\TestCase;

/**
 * Fija la sintaxis de las plantillas PDF. Cada caso es un error que existió:
 * ver el encabezado de PdfPlantillaMotor.
 */
class PdfPlantillaMotorTest extends TestCase
{
    private array $datos = [
        'cliente.nombre'           => 'Ana & Cía',
        'cotizacion.total'         => '1234567.00',
        'cotizacion.descuento_total' => '0.00',
        'cotizacion.created_at'    => '2026-09-23 10:00:00',
        'op.estado'                => 'despachada',
        'items' => [
            ['descripcion' => 'Puerta', 'cantidad' => '2.000', 'total_linea' => '500000', 'imagen_base64' => ''],
            ['descripcion' => 'Ventana', 'cantidad' => '1.000', 'total_linea' => '250000', 'imagen_base64' => 'data:x'],
        ],
    ];

    private function r(string $html): string
    {
        return PdfPlantillaMotor::render($html, $this->datos);
    }

    public function test_variables_filtros_y_escape(): void
    {
        $this->assertSame('Ana &amp; Cía', $this->r('{{cliente.nombre}}'));
        $this->assertSame('Ana & Cía', $this->r('{{!cliente.nombre}}'));
        $this->assertSame('Ana & Cía', $this->r('{{{cliente.nombre}}}'));
        $this->assertSame('$1.234.567', $this->r('{{cotizacion.total|moneda}}'));
        $this->assertSame('23/09/2026', $this->r('{{ cotizacion.created_at | fecha }}'));
        $this->assertSame('ANA &amp; CÍA', $this->r('{{cliente.nombre|upper}}'));
        $this->assertSame('2,25%', PdfPlantillaMotor::render('{{p|pct}}', ['p' => '2.25']));
    }

    public function test_variable_inexistente_sale_vacia_y_se_reporta(): void
    {
        $r = PdfPlantillaMotor::analizar('[{{cliente.nitt}}]', $this->datos);
        $this->assertSame('[]', $r['html']);
        $this->assertSame(['cliente.nitt'], $r['desconocidas']);
    }

    public function test_un_valor_no_se_vuelve_a_interpretar(): void
    {
        $html = PdfPlantillaMotor::render('{{a}}', ['a' => '{{b}}', 'b' => 'secreto']);
        $this->assertSame('{{b}}', $html);
    }

    public function test_each_con_filas_raiz_y_metadatos(): void
    {
        $html = $this->r('{{#each items}}{{@numero}}.{{descripcion}}-{{cliente.nombre|upper}}{{#unless @last}},{{/unless}}{{/each}}');
        $this->assertSame('1.Puerta-ANA &amp; CÍA,2.Ventana-ANA &amp; CÍA', $html);
    }

    public function test_bloque_legado_items_sigue_funcionando(): void
    {
        $this->assertSame('1Puerta2Ventana', $this->r('{{#items}}{{index}}{{descripcion}}{{/items}}'));
    }

    public function test_if_dentro_de_each_mira_la_fila(): void
    {
        $html = $this->r('{{#each items}}{{#if imagen_base64}}[img]{{else}}[sin]{{/if}}{{/each}}');
        $this->assertSame('[sin][img]', $html);
    }

    public function test_if_else_anidado_y_comparaciones(): void
    {
        $this->assertSame('no', $this->r('{{#if cotizacion.descuento_total}}si{{else}}no{{/if}}'));
        $this->assertSame('D', $this->r('{{#if op.estado == "despachada"}}{{#if cotizacion.total > 1000}}D{{/if}}{{else}}X{{/if}}'));
        $this->assertSame('', $this->r('{{#if op.estado != "despachada"}}X{{/if}}'));
    }

    public function test_each_vacio_usa_else(): void
    {
        $this->assertSame('nada', PdfPlantillaMotor::render('{{#each items}}x{{else}}nada{{/each}}', ['items' => []]));
    }

    public function test_etiquetas_mal_cerradas_se_reportan(): void
    {
        $r = PdfPlantillaMotor::analizar('{{#if a}}x{{/each}}', ['a' => 1]);
        $this->assertNotEmpty($r['errores']);
    }

    public function test_estructura_de_pagina(): void
    {
        $this->assertStringContainsString('salto-pagina', $this->r('{{salto_pagina}}'));
        $this->assertStringContainsString('pdf-pagina', $this->r('{{pagina}}'));
        $this->assertStringContainsString('pdf-total-paginas', $this->r('{{total_paginas}}'));
    }

    public function test_compone_encabezado_y_pie_fijos(): void
    {
        $html = PdfPlantillaRenderer::componer('<b>ENC</b>', '<html><head><style>.x{}</style></head><body>CUERPO</body></html>', 'Página {{pagina}}');
        $this->assertMatchesRegularExpression('/id="pdf-encabezado"><b>ENC<\/b>.*id="pdf-pie">.*CUERPO/s', $html);
        $this->assertStringContainsString('.x{}', $html);
        $this->assertStringContainsString('@page { margin: 37mm 12mm 27mm 12mm; }', $html);
    }

    public function test_separa_la_respuesta_de_una_ia(): void
    {
        $texto = "```html\n<!-- ENCABEZADO -->\n<h1>A</h1>\n<!-- CUERPO -->\n<p>B</p>\n<!-- PIE -->\n<small>C</small>\n```";
        $this->assertSame(['header' => '<h1>A</h1>', 'body' => '<p>B</p>', 'footer' => '<small>C</small>'], PdfPlantillaIaService::separar($texto));
        $this->assertSame('<p>solo</p>', PdfPlantillaIaService::separar('<p>solo</p>')['body']);
    }
}
