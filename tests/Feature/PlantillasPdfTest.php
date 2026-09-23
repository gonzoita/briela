<?php

namespace Tests\Feature;

use App\Models\PdfPlantilla;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlantillasPdfTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'administrador']);
    }

    private function diseno(array $extra = []): array
    {
        return [
            'modulo'      => 'cotizacion',
            'modo_editor' => 'codigo',
            'html_header' => '<b>{{empresa.nombre}}</b>',
            'html'        => '<p>{{cotizacion.numero}}</p>{{#if a}}',
            'html_footer' => 'Página {{pagina}} de {{total_paginas}}',
            'papel'       => 'ticket-80',
            'orientacion' => 'portrait',
            ...$extra,
        ];
    }

    public function test_guarda_una_plantilla_visual_sin_html(): void
    {
        // `html` es NOT NULL: en modo visual llegaba vacío y el guardado reventaba.
        $this->actingAs($this->admin())->postJson('/configuracion/plantillas-pdf', [
            'modulo' => 'cotizacion', 'nombre' => 'Visual', 'modo_editor' => 'visual',
            'html' => '', 'papel' => 'a4', 'orientacion' => 'portrait',
            'bloques_body' => [['id' => 'b1', 'tipo' => 'texto', 'props' => ['texto' => 'Hola']]],
        ])->assertOk();

        $this->assertSame('', PdfPlantilla::first()->html);
    }

    public function test_guarda_encabezado_y_pie(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/configuracion/plantillas-pdf', $this->diseno(['nombre' => 'Con piezas', 'alto_footer_mm' => 10]))
            ->assertOk();

        $p = PdfPlantilla::first();
        $this->assertSame('<b>{{empresa.nombre}}</b>', $p->html_header);
        $this->assertSame(10, (int) $p->alto_footer_mm);
    }

    public function test_la_vista_previa_devuelve_un_pdf(): void
    {
        $res = $this->actingAs($this->admin())->post('/configuracion/plantillas-pdf/preview', $this->diseno());

        $res->assertOk();
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_validar_reporta_bloques_abiertos_y_variables_inexistentes(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/configuracion/plantillas-pdf/validar', $this->diseno(['html_header' => '{{empresa.nitt}}']))
            ->assertOk()
            ->assertJsonPath('con_datos', false)
            ->assertJsonFragment(['desconocidas' => ['empresa.nitt', 'cotizacion.numero', 'a']])
            ->assertJsonCount(1, 'errores');
    }

    public function test_el_meta_prompt_usa_las_claves_reales_del_modulo(): void
    {
        // El recibo publica sus campos como «pago.*»; el diccionario decía «recibo_pago.*».
        $prompt = $this->actingAs($this->admin())
            ->postJson('/configuracion/plantillas-pdf/meta-prompt', ['modulo' => 'recibo_pago'])
            ->assertOk()->json('prompt');

        $this->assertStringContainsString('{{pago.valor}}', $prompt);
        $this->assertStringNotContainsString('{{recibo_pago.', $prompt);
        $this->assertStringContainsString('<!-- PIE -->', $prompt);
    }

    public function test_separa_lo_que_se_pega(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/configuracion/plantillas-pdf/separar', ['texto' => "<!-- CUERPO -->\n<p>x</p>\n<!-- PIE -->\n{{pagina}}"])
            ->assertOk()
            ->assertExactJson(['header' => '', 'body' => '<p>x</p>', 'footer' => '{{pagina}}']);
    }
}
