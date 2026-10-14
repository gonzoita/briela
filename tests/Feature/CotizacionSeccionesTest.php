<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionItem;
use App\Models\Producto;
use App\Services\PdfPlantillaRenderer;
use App\Services\PdfVariablesEngine;
use App\Models\Sede;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Las secciones de una cotización («Puerta corredera», «Cocina → Estufa») y lo que la plantilla PDF
 * hace con ellas: un bloque por sección con su subtotal, o la tabla de siempre si nadie puso sección.
 */
class CotizacionSeccionesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sede::query()->first() ?? Sede::create(['nombre' => 'Principal', 'es_principal' => true]);
        $this->actingAs(User::factory()->create(['rol' => 'administrador']));
    }

    /** @return array<int, array<string, mixed>> */
    private function lineas(): array
    {
        return [
            ['index' => 1, 'seccion' => 'Puerta corredera', 'descripcion' => 'Riel superior', 'cantidad' => 1, 'precio_unitario' => 100, 'total_linea' => 100, 'imagen_url' => null],
            ['index' => 2, 'seccion' => 'Puerta corredera', 'descripcion' => 'Chapa', 'cantidad' => 2, 'precio_unitario' => 50, 'total_linea' => 100, 'imagen_url' => null],
            ['index' => 3, 'seccion' => 'Puerta batiente', 'descripcion' => 'Bisagra', 'cantidad' => 3, 'precio_unitario' => 30, 'total_linea' => 90, 'imagen_url' => null],
            ['index' => 4, 'seccion' => 'Puerta corredera', 'descripcion' => 'Tornillería', 'cantidad' => 1, 'precio_unitario' => 10, 'total_linea' => 10, 'imagen_url' => null],
        ];
    }

    /** Una plantilla visual mínima: encabezado de la tabla, las líneas y los totales. */
    private function plantilla(array $opciones): array
    {
        return [
            'modo_editor'    => 'visual',
            'bloques_header' => [],
            'bloques_footer' => [],
            'bloques_body'   => [
                ['tipo' => 'tabla', 'props' => ['columnas' => [
                    ['etiqueta' => '#', 'variable' => 'index'],
                    ['etiqueta' => 'Descripción', 'variable' => 'descripcion'],
                    ['etiqueta' => 'Total', 'variable' => 'total_linea|moneda'],
                    ['etiqueta' => 'Foto', 'variable' => 'imagen_url'],
                ]]],
                ['tipo' => 'totales', 'props' => []],
            ],
            'opciones' => $opciones,
        ];
    }

    private function html(array $opciones, ?array $items = null): string
    {
        $items ??= $this->lineas();
        $datos = array_merge(['empresa' => []], ['items' => $items], PdfVariablesEngine::agruparSecciones($items));

        return PdfPlantillaRenderer::html($this->plantilla($opciones), $datos)['html'];
    }

    public function test_agrupa_por_seccion_en_el_orden_en_que_aparecen_y_suma_cada_una(): void
    {
        $g = PdfVariablesEngine::agruparSecciones($this->lineas());

        $this->assertSame(2, $g['secciones_n']);
        $this->assertSame('Puerta corredera', $g['seccion_1_nombre']);
        $this->assertCount(3, $g['seccion_1_items']);
        $this->assertEquals(210, $g['seccion_1_subtotal'], 'la suma de sus líneas');
        $this->assertSame('Puerta batiente', $g['seccion_2_nombre']);
        $this->assertEquals(90, $g['seccion_2_subtotal']);
    }

    public function test_sin_ninguna_seccion_no_hay_agrupacion_y_sale_la_tabla_de_siempre(): void
    {
        $sinSeccion = array_map(fn ($l) => array_diff_key($l, ['seccion' => true]), $this->lineas());

        $this->assertSame(['secciones_n' => 0], PdfVariablesEngine::agruparSecciones($sinSeccion));

        $html = $this->html(['agrupar_secciones' => true], $sinSeccion);
        $this->assertStringNotContainsString('seccion-titulo', $html);
        $this->assertStringNotContainsString('seccion-subtotal', $html, 'el total general de siempre sí puede salir');
    }

    public function test_con_agrupar_sale_un_titulo_y_un_subtotal_por_seccion(): void
    {
        $html = $this->html(['agrupar_secciones' => true]);

        $this->assertStringContainsString('Puerta corredera', $html);
        $this->assertStringContainsString('Puerta batiente', $html);
        $this->assertStringContainsString('Subtotal Puerta corredera', $html);
        $this->assertStringContainsString('Subtotal Puerta batiente', $html);
        $this->assertSame(2, substr_count($html, 'seccion-subtotal'));
    }

    public function test_sin_agrupar_sale_una_sola_tabla_aunque_haya_secciones(): void
    {
        $html = $this->html(['agrupar_secciones' => false]);

        $this->assertStringNotContainsString('seccion-titulo', $html);
        $this->assertStringContainsString('Riel superior', $html);
        $this->assertStringContainsString('Bisagra', $html);
    }

    public function test_sin_precios_no_hay_subtotales_ni_totales_ni_columna_de_precio(): void
    {
        $html = $this->html(['agrupar_secciones' => true, 'mostrar_precios' => false]);

        $this->assertStringNotContainsString('Subtotal', $html);
        $this->assertStringNotContainsString('seccion-subtotal', $html);
        $this->assertStringNotContainsString('total_linea', $html);
        $this->assertStringContainsString('Riel superior', $html, 'la descripción sí sale');
    }

    public function test_sin_foto_se_quita_la_columna_de_la_imagen(): void
    {
        $html = $this->html(['mostrar_fotos' => false]);

        $this->assertStringNotContainsString('imagen_url', $html);
        $this->assertStringNotContainsString('>Foto<', $html);
    }

    public function test_los_subtotales_salen_en_la_moneda_de_la_cotizacion(): void
    {
        // Una línea convertida a otra moneda: el subtotal de la sección tiene que sumar lo convertido.
        $items = $this->lineas();
        $items[0]['total_linea'] = 25;
        $items[1]['total_linea'] = 25;
        $items[3]['total_linea'] = 2.5;

        $g = PdfVariablesEngine::agruparSecciones($items);

        $this->assertEquals(52.5, $g['seccion_1_subtotal']);
    }

    private function datosDeCotizacion(array $items): array
    {
        $cliente = Cliente::create(['tipo' => 'empresa', 'nombre' => 'Vidrios del Norte SAS', 'tipo_identificacion' => 'NIT']);

        return [
            'cliente_id' => $cliente->id, 'moneda' => 'COP', 'tasa_cambio' => 1,
            'fecha_creacion' => today()->toDateString(), 'fecha_validez' => today()->addDays(30)->toDateString(),
            'responsable_id' => $this->admin()->id, 'items' => $items,
            // Lo que el formulario manda siempre, aunque venga vacío.
            'contacto_id' => null, 'nombre_contacto_override' => null, 'notas_internas' => null,
            'condiciones_comerciales' => 'Pago contra entrega',
        ];
    }

    private function admin(): User
    {
        return User::query()->first();
    }

    public function test_la_seccion_de_cada_linea_se_guarda_y_una_vacia_queda_sin_seccion(): void
    {
        $this->post('/cotizaciones', $this->datosDeCotizacion([
            ['tipo' => 'texto_libre', 'descripcion' => 'Riel superior', 'cantidad' => 1, 'precio_unitario' => 100, 'seccion' => 'Puerta corredera'],
            ['tipo' => 'texto_libre', 'descripcion' => 'Estufa', 'cantidad' => 1, 'precio_unitario' => 500, 'seccion' => '   '],
        ]))->assertSessionHasNoErrors();

        $cot = Cotizacion::latest('id')->first();
        $this->assertSame(['Puerta corredera', null], $cot->items()->orderBy('id')->pluck('seccion')->all());
    }

    public function test_la_seccion_se_puede_cambiar_al_editar_la_cotizacion(): void
    {
        $this->post('/cotizaciones', $this->datosDeCotizacion([
            ['tipo' => 'texto_libre', 'descripcion' => 'Riel superior', 'cantidad' => 1, 'precio_unitario' => 100, 'seccion' => 'Puerta corredera'],
        ]))->assertSessionHasNoErrors();
        $cot = Cotizacion::latest('id')->first();
        $item = $cot->items()->first();

        $datos = $this->datosDeCotizacion([
            ['id' => $item->id, 'tipo' => 'texto_libre', 'descripcion' => 'Riel superior', 'cantidad' => 1, 'precio_unitario' => 100, 'seccion' => 'Puerta batiente'],
        ]);
        unset($datos['cliente_id']);
        $datos['cliente_id'] = $cot->cliente_id;
        $this->put("/cotizaciones/{$cot->id}", $datos)->assertSessionHasNoErrors();

        $this->assertSame('Puerta batiente', CotizacionItem::find($item->id)->seccion);
    }

    public function test_una_seccion_demasiado_larga_se_rechaza(): void
    {
        $this->post('/cotizaciones', $this->datosDeCotizacion([
            ['tipo' => 'texto_libre', 'descripcion' => 'Riel', 'cantidad' => 1, 'precio_unitario' => 100, 'seccion' => str_repeat('a', 81)],
        ]))->assertSessionHasErrors('items.0.seccion');
    }

    public function test_editar_conserva_el_id_de_cada_linea_y_no_las_recrea(): void
    {
        $this->post('/cotizaciones', $this->datosDeCotizacion([
            ['tipo' => 'texto_libre', 'descripcion' => 'Riel', 'cantidad' => 1, 'precio_unitario' => 100, 'seccion' => 'Puerta corredera'],
        ]))->assertSessionHasNoErrors();
        $cot = Cotizacion::latest('id')->first();
        $item = $cot->items()->first();

        $this->put("/cotizaciones/{$cot->id}", array_merge($this->datosDeCotizacion([
            ['id' => $item->id, 'tipo' => 'texto_libre', 'descripcion' => 'Riel', 'cantidad' => 2, 'precio_unitario' => 100, 'seccion' => 'Puerta corredera'],
        ]), ['cliente_id' => $cot->cliente_id]))->assertSessionHasNoErrors();

        $this->assertTrue(CotizacionItem::whereKey($item->id)->exists(), 'la misma línea, no una nueva');
        $this->assertSame(1, $cot->fresh()->items()->count());
    }

    public function test_un_id_de_otra_cotizacion_no_se_toca(): void
    {
        $this->post('/cotizaciones', $this->datosDeCotizacion([
            ['tipo' => 'texto_libre', 'descripcion' => 'Riel ajeno', 'cantidad' => 1, 'precio_unitario' => 100, 'seccion' => 'Otra'],
        ]))->assertSessionHasNoErrors();
        $ajena = Cotizacion::latest('id')->first()->items()->first();

        $this->post('/cotizaciones', $this->datosDeCotizacion([
            ['tipo' => 'texto_libre', 'descripcion' => 'Mía', 'cantidad' => 1, 'precio_unitario' => 5, 'seccion' => 'Mía'],
        ]))->assertSessionHasNoErrors();
        $mia = Cotizacion::latest('id')->first();

        $this->put("/cotizaciones/{$mia->id}", array_merge($this->datosDeCotizacion([
            ['id' => $ajena->id, 'tipo' => 'texto_libre', 'descripcion' => 'Robada', 'cantidad' => 1, 'precio_unitario' => 1, 'seccion' => 'X'],
        ]), ['cliente_id' => $mia->cliente_id]));

        $this->assertSame('Riel ajeno', $ajena->fresh()->descripcion, 'la línea de la otra cotización queda igual');
    }

    /** Un producto con un plano en imagen y una ficha técnica en PDF; de la variante cuelga sin documentos propios. */
    private function productoConDocumentos(): array
    {
        Storage::fake('public');
        // Un PNG de 1×1: lo mínimo que se puede incrustar.
        Storage::disk('public')->put('productos/1/documentos/plano.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));
        Storage::disk('public')->put('productos/1/documentos/ficha.pdf', '%PDF-1.4');

        $base = ['tipo' => 'producto', 'es_insumo' => false, 'es_vendible' => true, 'unidad_medida' => 'unidad', 'activo' => true];
        $padre = Producto::create($base + ['nombre' => 'Bisagra con rampa', 'referencia' => 'IC10102', 'es_padre' => true]);
        $variante = Producto::create($base + ['nombre' => 'Bisagra con rampa', 'referencia' => 'IC10102-45', 'producto_padre_id' => $padre->id, 'valor_variante' => '45 mm']);

        foreach ([['plano', 'plano.png', 'png'], ['ficha_tecnica', 'ficha.pdf', 'pdf']] as [$categoria, $archivo, $ext]) {
            $padre->documentos()->create(['nombre_original' => "Bisagra {$archivo}", 'nombre_archivo' => $archivo,
                'ruta' => "productos/1/documentos/{$archivo}", 'tipo_mime' => $ext === 'pdf' ? 'application/pdf' : 'image/png',
                'extension' => $ext, 'tamano' => 10, 'categoria' => $categoria]);
        }

        return [$padre, $variante];
    }

    public function test_los_documentos_de_los_productos_salen_con_la_imagen_incrustada_y_el_pdf_enlazado(): void
    {
        [$padre, $variante] = $this->productoConDocumentos();

        // La línea es de la variante, que no tiene documentos: salen los de su producto, una sola vez.
        $html = PdfVariablesEngine::documentosAdjuntos([(object) ['producto_id' => $variante->id], (object) ['producto_id' => $padre->id]]);

        $this->assertStringContainsString('Planos y fichas técnicas', $html);
        $this->assertSame(1, substr_count($html, 'IC10102<'), 'el producto aparece una sola vez');
        $this->assertStringContainsString('data:image/png;base64,', $html, 'el plano en imagen se ve en la hoja');
        $this->assertStringContainsString('productos/1/documentos/ficha.pdf', $html, 'el PDF queda como enlace');
    }

    public function test_sin_productos_con_documentos_no_sale_la_seccion(): void
    {
        $this->assertSame('', PdfVariablesEngine::documentosAdjuntos([(object) ['producto_id' => null]]));
    }

    public function test_la_plantilla_adjunta_los_documentos_solo_si_la_opcion_esta_encendida(): void
    {
        $items = $this->lineas();
        $datos = array_merge(['empresa' => [], 'items' => $items, 'documentos.adjuntos' => '<p>PLANO-ADJUNTO</p>'], PdfVariablesEngine::agruparSecciones($items));

        $this->assertStringNotContainsString('PLANO-ADJUNTO', PdfPlantillaRenderer::html($this->plantilla([]), $datos)['html']);
        $this->assertStringContainsString('PLANO-ADJUNTO', PdfPlantillaRenderer::html($this->plantilla(['adjuntar_documentos' => true]), $datos)['html']);
    }
}
