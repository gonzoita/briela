<?php

namespace Tests\Feature;

use App\Models\CanalPrecio;
use App\Models\Configuracion;
use App\Models\Importacion;
use App\Models\Producto;
use App\Models\SegmentacionOpcion;
use App\Models\User;
use App\Services\LiquidacionImportacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La liquidación de una importación: lo que cuesta de verdad lo que llegó de afuera.
 *
 * Los números están hechos a mano en los comentarios para que la prueba también explique la cuenta.
 */
class ImportacionesTest extends TestCase
{
    use RefreshDatabase;

    private function servicio(): LiquidacionImportacionService
    {
        return app(LiquidacionImportacionService::class);
    }

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'administrador']);
    }

    /**
     * Dos líneas de 500 USD a $4.000 (2.000.000 de pesos cada una).
     * Flete 200 USD + seguro 50 USD = 1.000.000 de pesos, y 300.000 de gastos locales.
     */
    private function embarque(array $extra = []): array
    {
        return $extra + [
            'tasa'   => 4000,
            'metodo' => 'valor',
            'iva_descontable' => true,
            'items'  => [
                ['cantidad' => 10, 'valor_unitario' => 50,  'peso_kg' => 30, 'arancel_pct' => 10, 'iva_pct' => 19],
                ['cantidad' => 5,  'valor_unitario' => 100, 'peso_kg' => 10, 'arancel_pct' => 0,  'iva_pct' => 19],
            ],
            'costos' => [
                ['tipo' => 'flete',  'valor' => 200,    'en_moneda_base' => false],
                ['tipo' => 'seguro', 'valor' => 50,     'en_moneda_base' => false],
                ['tipo' => 'gasto',  'valor' => 300000, 'en_moneda_base' => true],
            ],
        ];
    }

    // ─── La cuenta ───────────────────────────────────────────────────────────

    public function test_el_costo_puesto_en_bodega_suma_flete_arancel_y_gastos_y_deja_fuera_el_iva_que_se_recupera(): void
    {
        $r = $this->servicio()->calcular($this->embarque());

        [$a, $b] = $r['items'];

        // A: factura 2.000.000 + mitad del flete y seguro 500.000 = CIF 2.500.000.
        $this->assertEquals(2000000, $a['fob']);
        $this->assertEquals(500000, $a['flete_seguro']);
        $this->assertEquals(2500000, $a['cif']);
        // Arancel 10 % del CIF; IVA 19 % de (CIF + arancel), que se recupera y no entra al costo.
        $this->assertEquals(250000, $a['arancel']);
        $this->assertEquals(522500, $a['iva']);
        // 2.500.000 + 250.000 + 150.000 de gastos = 2.900.000, entre 10 unidades.
        $this->assertEquals(2900000, $a['costo_total']);
        $this->assertEquals(290000, $a['costo_unitario']);

        $this->assertEquals(2650000, $b['costo_total']);
        $this->assertEquals(530000, $b['costo_unitario']);

        $this->assertEquals(4000000, $r['totales']['fob']);
        $this->assertEquals(5550000, $r['totales']['costo_total']);
        $this->assertEquals(1.3875, $r['factor']);
    }

    public function test_si_el_iva_no_se_recupera_se_suma_al_costo(): void
    {
        $r = $this->servicio()->calcular($this->embarque(['iva_descontable' => false]));

        $this->assertEquals(3422500, $r['items'][0]['costo_total']);   // 2.900.000 + 522.500
        $this->assertEquals(342250, $r['items'][0]['costo_unitario']);
    }

    public function test_repartir_por_peso_carga_mas_al_que_pesa_mas(): void
    {
        $r = $this->servicio()->calcular($this->embarque(['metodo' => 'peso']));

        // 30 kg y 10 kg: tres cuartos y un cuarto del flete y seguro (1.000.000).
        $this->assertEquals(750000, $r['items'][0]['flete_seguro']);
        $this->assertEquals(250000, $r['items'][1]['flete_seguro']);
    }

    public function test_sin_pesos_se_reparte_por_valor_y_lo_dice(): void
    {
        $e = $this->embarque(['metodo' => 'peso']);
        $e['items'][0]['peso_kg'] = null;
        $e['items'][1]['peso_kg'] = null;

        $r = $this->servicio()->calcular($e);

        $this->assertEquals(500000, $r['items'][0]['flete_seguro']);
        $this->assertNotEmpty($r['avisos']);
    }

    public function test_las_partes_suman_exactamente_el_total_aunque_no_se_pueda_dividir(): void
    {
        $e = [
            'tasa' => 1, 'metodo' => 'cantidad', 'iva_descontable' => true,
            'items' => array_fill(0, 3, ['cantidad' => 1, 'valor_unitario' => 100, 'arancel_pct' => 0, 'iva_pct' => 0]),
            'costos' => [['tipo' => 'flete', 'valor' => 100000, 'en_moneda_base' => true]],
        ];

        $r = $this->servicio()->calcular($e);

        $this->assertEquals(100000, array_sum(array_column($r['items'], 'flete_seguro')));
        $this->assertEquals(100000, $r['totales']['flete_seguro']);
    }

    public function test_sin_tasa_avisa_y_no_inventa_valores(): void
    {
        $r = $this->servicio()->calcular($this->embarque(['tasa' => 0]));

        $this->assertNotEmpty($r['avisos']);
        $this->assertEquals(0, $r['items'][0]['fob']);
    }

    // ─── Guardar, liquidar y llevar el costo al producto ─────────────────────

    private function canal(string $valor): SegmentacionOpcion
    {
        return SegmentacionOpcion::where('tipo', 'tipo_contacto')->where('valor', $valor)->firstOrFail();
    }

    private function producto(): Producto
    {
        $p = Producto::create([
            'tipo' => 'producto', 'nombre' => 'Cerradura china', 'referencia' => 'CER-1',
            'precio_costo' => 100000, 'moneda_costo' => 'USD', 'costo_moneda' => 25,
        ]);

        // Una fila calculada (25 % sobre 100.000) y una escrita a mano.
        CanalPrecio::create(['precionable_type' => $p->getMorphClass(), 'precionable_id' => $p->id,
            'segmentacion_opcion_id' => $this->canal('mayorista')->id, 'margen_pct' => 25, 'precio' => 125000]);
        CanalPrecio::create(['precionable_type' => $p->getMorphClass(), 'precionable_id' => $p->id,
            'segmentacion_opcion_id' => $this->canal('cliente_directo')->id, 'margen_pct' => 35, 'precio' => 999000]);

        return $p;
    }

    private function cuerpo(?int $productoId, array $extra = []): array
    {
        return $extra + [
            'fecha' => now()->toDateString(), 'moneda' => 'USD', 'tasa' => 4000, 'metodo_prorrateo' => 'valor',
            'iva_descontable' => true, 'pais_origen' => 'CN', 'incoterm' => 'FOB', 'referencia' => 'INV-1',
            'items' => [[
                'producto_id' => $productoId, 'descripcion' => 'Cerradura china', 'cantidad' => 10, 'valor_unitario' => 50,
                'arancel_pct' => 10, 'iva_pct' => 19, 'codigo_hs' => '8301.40',
            ]],
            'costos' => [
                ['tipo' => 'flete', 'concepto' => 'Flete marítimo', 'valor' => 100, 'en_moneda_base' => false],
                ['tipo' => 'gasto', 'concepto' => 'Agente de aduana', 'valor' => 200000, 'en_moneda_base' => true],
            ],
        ];
    }

    public function test_guarda_un_borrador_con_su_numero_y_no_toca_el_producto(): void
    {
        $p = $this->producto();

        $this->actingAs($this->admin())->post('/compras/importaciones', $this->cuerpo($p->id))->assertSessionHasNoErrors();

        $imp = Importacion::with(['items', 'costos'])->firstOrFail();
        $this->assertSame('borrador', $imp->estado);
        $this->assertNotEmpty($imp->numero);
        $this->assertCount(1, $imp->items);
        $this->assertCount(2, $imp->costos);
        $this->assertEquals(100000, (float) $p->fresh()->precio_costo, 'Un borrador no cambia el costo.');
    }

    public function test_liquidar_lleva_el_costo_al_producto_y_reprecia_solo_lo_calculado(): void
    {
        $p = $this->producto();
        $this->actingAs($this->admin())->post('/compras/importaciones', $this->cuerpo($p->id));
        $imp = Importacion::firstOrFail();

        $this->post("/compras/importaciones/{$imp->id}/liquidar")->assertSessionHas('success');

        // Factura 10 × 50 USD × 4.000 = 2.000.000; flete 100 USD = 400.000 → CIF 2.400.000;
        // arancel 240.000; gastos 200.000 → 2.840.000 entre 10 = 284.000 por unidad.
        $imp->refresh();
        $this->assertSame('liquidada', $imp->estado);
        $this->assertEquals(2840000, (float) $imp->total_costo);

        $p->refresh();
        $this->assertEquals(284000, (float) $p->precio_costo);
        $this->assertSame('COP', $p->moneda_costo);
        $this->assertNull($p->costo_moneda, 'Deja de seguir la tasa diaria: el costo es el del embarque.');
        $this->assertSame('8301.40', $p->codigo_hs);
        $this->assertEquals(10, (float) $p->arancel_pct);

        $precio = fn ($canal) => (float) CanalPrecio::where('precionable_id', $p->id)
            ->where('segmentacion_opcion_id', $this->canal($canal)->id)->value('precio');

        // 284.000 con 25 % de recargo = 355.000, redondeado a los mil.
        $this->assertEquals(355000, $precio('mayorista'));
        $this->assertEquals(999000, $precio('cliente_directo'), 'Un precio escrito a mano no se reprecia.');
    }

    public function test_una_liquidada_no_se_edita_ni_se_elimina_ni_se_liquida_dos_veces(): void
    {
        $this->actingAs($this->admin())->post('/compras/importaciones', $this->cuerpo(null));
        $imp = Importacion::firstOrFail();
        $this->post("/compras/importaciones/{$imp->id}/liquidar");

        $this->put("/compras/importaciones/{$imp->id}", $this->cuerpo(null, ['referencia' => 'OTRA']))->assertSessionHas('error');
        $this->delete("/compras/importaciones/{$imp->id}")->assertSessionHas('error');
        $this->post("/compras/importaciones/{$imp->id}/liquidar")->assertSessionHas('error');

        $this->assertSame('INV-1', $imp->fresh()->referencia);
        $this->assertNotNull(Importacion::find($imp->id));
    }

    public function test_el_promedio_pondera_por_cantidad_todos_los_embarques(): void
    {
        Configuracion::set('importacion_costo_vigente', 'promedio');
        $p = $this->producto();
        $this->actingAs($this->admin());

        // Primer embarque: 10 unidades a 284.000.
        $this->post('/compras/importaciones', $this->cuerpo($p->id));
        $this->post('/compras/importaciones/' . Importacion::firstOrFail()->id . '/liquidar');

        // Segundo: 30 unidades a 100 USD, sin flete ni gastos, sin arancel ni IVA → 400.000 cada una.
        $this->post('/compras/importaciones', $this->cuerpo($p->id, [
            'items' => [['producto_id' => $p->id, 'descripcion' => 'Cerradura china', 'cantidad' => 30, 'valor_unitario' => 100, 'arancel_pct' => 0, 'iva_pct' => 0]],
            'costos' => [],
        ]));
        $this->post('/compras/importaciones/' . Importacion::orderByDesc('id')->first()->id . '/liquidar');

        // (10 × 284.000 + 30 × 400.000) ÷ 40 = 371.000.
        $this->assertEquals(371000, (float) $p->fresh()->precio_costo);
    }

    public function test_una_importacion_en_la_moneda_base_no_convierte_nada(): void
    {
        $this->actingAs($this->admin())->post('/compras/importaciones', $this->cuerpo(null, [
            'moneda' => 'COP', 'tasa' => 4000,   // la tasa que mande la pantalla no cuenta
            'items' => [['producto_id' => null, 'descripcion' => 'Local', 'cantidad' => 2, 'valor_unitario' => 1000, 'arancel_pct' => 0, 'iva_pct' => 0]],
            'costos' => [],
        ]));

        $imp = Importacion::firstOrFail();
        $this->assertEquals(1, (float) $imp->tasa);

        $this->post("/compras/importaciones/{$imp->id}/liquidar");
        $this->assertEquals(2000, (float) $imp->fresh()->total_costo);
    }

    public function test_exige_al_menos_un_producto_y_una_tasa_positiva(): void
    {
        $this->actingAs($this->admin())
            ->post('/compras/importaciones', $this->cuerpo(null, ['items' => []]))
            ->assertSessionHasErrors('items');

        $this->post('/compras/importaciones', $this->cuerpo(null, ['tasa' => 0]))->assertSessionHasErrors('tasa');
        $this->assertSame(0, Importacion::count());
    }

    // ─── Pantallas y permisos ────────────────────────────────────────────────

    public function test_la_cuenta_en_vivo_la_calcula_el_servidor(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/importaciones/calcular', $this->embarque())
            ->assertOk()
            ->assertJsonPath('totales.costo_total', 5550000);
    }

    public function test_las_pantallas_abren(): void
    {
        $this->actingAs($this->admin())->post('/compras/importaciones', $this->cuerpo($this->producto()->id));
        $imp = Importacion::firstOrFail();

        foreach (['/compras/importaciones', '/compras/importaciones/crear', "/compras/importaciones/{$imp->id}", "/compras/importaciones/{$imp->id}/editar"] as $ruta) {
            $this->get($ruta)->assertOk();
        }

        $this->get("/compras/importaciones/{$imp->id}")->assertInertia(fn ($p) => $p
            ->where('totales.costo_total', 2840000)
            ->where('items.0.costo_unitario', 284000));
    }

    public function test_quien_no_tiene_permiso_no_entra(): void
    {
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']))->get('/compras/importaciones')->assertForbidden();
    }

    public function test_busca_productos_con_su_arancel_habitual(): void
    {
        Producto::create(['tipo' => 'producto', 'nombre' => 'Bisagra', 'referencia' => 'BIS', 'codigo_hs' => '8302.10', 'arancel_pct' => 15]);

        $this->actingAs($this->admin())->getJson('/api/importaciones/productos?q=Bisag')
            ->assertOk()->assertJsonPath('0.codigo_hs', '8302.10')->assertJsonPath('0.arancel_pct', 15);
    }
}
