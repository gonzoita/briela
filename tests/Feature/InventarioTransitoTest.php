<?php

namespace Tests\Feature;

use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionItem;
use App\Models\Notificacion;
use App\Models\OrdenCompra;
use App\Models\OrdenCompraItem;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\InventarioTransitoService;
use App\Services\ReservaStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El inventario en tránsito: lo pedido a un proveedor y todavía sin recibir. Se puede cotizar
 * contra él, avisando; lo apartado se come primero el real; y al llegar se avisa a quien contaba con él.
 */
class InventarioTransitoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $vendedor;
    private Bodega $bodega;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin    = User::factory()->create(['rol' => 'administrador']);
        $this->vendedor = User::factory()->create(['rol' => 'vendedor']);
        $this->bodega   = Bodega::create(['nombre' => 'Principal', 'activa' => true, 'es_principal' => true]);
        $this->actingAs($this->admin);
    }

    private function producto(float $stock): Producto
    {
        $p = Producto::create([
            'tipo' => 'producto', 'nombre' => 'Bisagra ' . uniqid(), 'referencia' => 'REF-' . uniqid(),
            'inventariable' => true, 'unidad_medida' => 'unidad', 'es_vendible' => true, 'activo' => true,
        ]);

        if ($stock > 0) {
            $p->registrarMovimiento('entrada', $stock, $this->bodega->id, $this->admin->id);
        }

        return $p;
    }

    private function orden(Producto $p, float $cantidad, string $estado = 'enviada', ?string $fecha = '2030-01-20', float $recibida = 0): OrdenCompra
    {
        $orden = OrdenCompra::create([
            'estado' => $estado, 'creado_por' => $this->admin->id, 'fecha_entrega_esperada' => $fecha,
            'proveedor_id' => Proveedor::create(['nombre' => 'Herrajes del Norte', 'activo' => true])->id,
        ]);
        OrdenCompraItem::create([
            'orden_id' => $orden->id, 'item_id' => $p->id, 'descripcion' => $p->nombre, 'cantidad' => $cantidad,
            'cantidad_recibida' => $recibida, 'unidad' => 'unidad', 'precio_unitario' => 1000, 'total_linea' => $cantidad * 1000,
        ]);

        return $orden->fresh('items');
    }

    private function cotizacion(Producto $p, float $cantidad, string $estado = 'borrador'): Cotizacion
    {
        $cliente = Cliente::firstOrCreate(['nombre' => 'Cliente SAS'], ['tipo' => 'empresa', 'tipo_identificacion' => 'NIT']);
        $cot = Cotizacion::create([
            'cliente_id' => $cliente->id, 'moneda' => 'COP', 'tasa_cambio' => 1, 'estado' => 'borrador',
            'responsable_id' => $this->vendedor->id,
        ]);
        CotizacionItem::create([
            'cotizacion_id' => $cot->id, 'tipo' => 'producto', 'producto_id' => $p->id, 'descripcion' => $p->nombre,
            'orden' => 0, 'cantidad' => $cantidad, 'precio_unitario' => 1000, 'descuento_pct' => 0, 'impuesto_pct' => 0,
            'subtotal' => 1000 * $cantidad, 'total_linea' => 1000 * $cantidad,
        ]);

        if ($estado !== 'borrador') {
            $cot->update(['estado' => $estado]);
        }

        return $cot->fresh('items');
    }

    private function transito(): InventarioTransitoService
    {
        return app(InventarioTransitoService::class);
    }

    public function test_cuenta_lo_pedido_y_no_recibido_de_las_ordenes_en_camino(): void
    {
        $p = $this->producto(0);
        $this->orden($p, 10);                                   // enviada: 10
        $this->orden($p, 6, 'recibida_parcial', '2030-01-10', 2); // le faltan 4
        $this->orden($p, 50, 'borrador');                       // todavía no se pidió
        $this->orden($p, 50, 'cancelada');                      // ya no viene
        $this->orden($p, 5, 'recibida', null, 5);               // ya llegó

        $t = $this->transito()->porProducto([$p->id])[$p->id];

        $this->assertEquals(14, $t['cantidad']);
        $this->assertSame('2030-01-10', $t['llega'], 'la primera fecha que todavía no pasó');
        $this->assertCount(2, $t['ordenes']);
    }

    public function test_una_orden_atrasada_se_marca_y_no_dice_cuando_llega(): void
    {
        $p = $this->producto(0);
        $this->orden($p, 3, 'enviada', now()->subDays(4)->toDateString());

        $t = $this->transito()->porProducto([$p->id])[$p->id];
        $this->assertTrue($t['atrasada']);
        $this->assertNull($t['llega']);

        $r = $this->transito()->resumen();
        $this->assertCount(1, $r['atrasadas']);
        $this->assertSame(4, $r['atrasadas'][0]['dias']);
        $this->assertEquals(3000, $r['valor']);
    }

    public function test_la_cobertura_usa_primero_lo_real_y_despues_el_transito(): void
    {
        // 8 en bodega, 10 en camino, nada apartado: pedir 12 son 8 + 4.
        $this->assertSame(['de_real' => 8.0, 'de_transito' => 4.0, 'faltan' => 0.0, 'libre_real' => 8.0, 'libre_transito' => 10.0],
            InventarioTransitoService::cobertura(12, 8, 0, 10));

        // Otras cotizaciones apartan 11: se comen las 8 reales y 3 del tránsito.
        $c = InventarioTransitoService::cobertura(12, 8, 11, 10);
        $this->assertEquals([0, 7, 5], [$c['de_real'], $c['de_transito'], $c['faltan']]);
    }

    public function test_la_disponibilidad_de_la_cotizacion_trae_el_transito_libre(): void
    {
        $p = $this->producto(5);
        $this->orden($p, 10);
        $this->cotizacion($p, 7, 'enviada'); // aparta 7: 5 reales y 2 en camino

        $d = app(ReservaStockService::class)->disponibilidad([$p->id], [], null)[$p->id];

        $this->assertEquals(10, $d['transito']);
        $this->assertEquals(8, $d['transito_libre'], 'la otra cotización ya tomó 2 de lo que viene');
        $this->assertEquals(0, $d['disponible']);
    }

    public function test_el_pdf_dice_cuando_llega_lo_que_viene_en_transito(): void
    {
        $p = $this->producto(4);
        $this->orden($p, 10, 'enviada', '2030-01-20');
        $cot = $this->cotizacion($p, 6);

        $textos = $this->transito()->entregaDeCotizacion($cot);

        $this->assertSame('Entrega: 4 de inmediato y 2 a partir del 20 de enero de 2030.', $textos[$cot->items->first()->id]);
    }

    public function test_si_alcanza_con_lo_real_el_pdf_no_dice_nada(): void
    {
        $p = $this->producto(10);
        $this->orden($p, 10);

        $this->assertSame([], $this->transito()->entregaDeCotizacion($this->cotizacion($p, 6)));
    }

    public function test_al_recibir_sale_del_transito_entra_a_bodega_y_avisa_a_quien_lo_esperaba(): void
    {
        $p = $this->producto(2);
        $orden = $this->orden($p, 10);
        $espera   = $this->cotizacion($p, 6, 'enviada');   // pedía más de las 2 que había
        $noEspera = $this->cotizacion($p, 1, 'enviada');   // le alcanzaba con lo real
        $vieja    = $this->cotizacion($p, 9, 'rechazada'); // ya no está viva

        $this->post("/compras/ordenes/{$orden->id}/recibir", [
            'items' => [['id' => $orden->items->first()->id, 'cantidad_recibida' => 10]],
            'remision_numero' => 'REM-55',
        ])->assertSessionHasNoErrors();

        $this->assertEquals(12, $p->fresh()->stockTotal());
        $this->assertSame([], $this->transito()->porProducto([$p->id]), 'lo recibido ya no está en camino');

        $avisos = Notificacion::where('tipo', 'transito_recibido')->get();
        $this->assertCount(1, $avisos);
        $this->assertSame($this->vendedor->id, $avisos->first()->user_id);
        $this->assertStringContainsString($espera->numero, $avisos->first()->mensaje);
        $this->assertStringNotContainsString($noEspera->numero . ' ', $avisos->first()->mensaje);
    }

    public function test_la_lista_la_ficha_y_el_tablero_muestran_el_transito(): void
    {
        $p = $this->producto(1);
        $this->orden($p, 7, 'enviada', '2030-01-20');

        $this->get('/productos?buscar=' . urlencode($p->referencia))->assertOk()->assertInertia(fn ($pg) => $pg
            ->where('productos.data.0.stock_transito', 7)
            ->where('productos.data.0.transito_llega', '2030-01-20'));

        $this->get("/productos/{$p->id}")->assertOk()->assertInertia(fn ($pg) => $pg
            ->where('producto.transito.cantidad', 7)
            ->has('producto.transito.ordenes', 1));

        $this->get('/inventario/dashboard')->assertOk()->assertInertia(fn ($pg) => $pg
            ->where('transito.unidades', 7)
            ->where('transito.ordenes', 1));
    }

    public function test_la_busqueda_de_la_cotizacion_trae_el_transito(): void
    {
        $p = $this->producto(0);
        $this->orden($p, 3);

        $r = $this->getJson('/api/cotizaciones/productos?q=' . urlencode($p->referencia))->assertOk()->json();

        $this->assertEquals(3, $r[0]['stock_transito']);
        $this->assertSame('2030-01-20', $r[0]['transito_llega']);
    }

    public function test_con_una_sede_activa_cuentan_sus_ordenes_y_las_que_no_tienen_sede(): void
    {
        $norte = \App\Models\Sede::create(['nombre' => 'Norte', 'codigo' => 'NOR', 'activa' => true, 'es_principal' => true]);
        $sur   = \App\Models\Sede::create(['nombre' => 'Sur', 'codigo' => 'SUR', 'activa' => true]);
        $p = $this->producto(0);

        $this->orden($p, 2);                                     // sin sede: cuenta en todas
        $this->orden($p, 3)->update(['sede_id' => $norte->id]);   // de la sede activa
        $this->orden($p, 50)->update(['sede_id' => $sur->id]);    // de otra sede

        session(['sede_activa_id' => $norte->id]);

        $this->assertEquals(5, $this->transito()->porProducto([$p->id])[$p->id]['cantidad']);
    }
}
