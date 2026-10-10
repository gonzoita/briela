<?php

namespace Tests\Feature;

use App\Models\Bodega;
use App\Models\OrdenCompra;
use App\Models\OrdenCompraItem;
use App\Models\Producto;
use App\Models\ProductoMovimiento;
use App\Models\Proveedor;
use App\Models\Remision;
use App\Models\RemisionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El detalle de los movimientos de un producto: de dónde vino cada uno y con qué papel.
 *
 * Un movimiento decía «entrada, 10» y, a lo sumo, una nota. La pregunta de quien audita un
 * inventario es otra: ¿con qué factura o remisión entró esto?
 */
class ProductosMovimientosDetalleTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'administrador']);
    }

    private function bodega(): Bodega
    {
        return Bodega::create(['nombre' => 'Principal', 'activa' => true, 'es_principal' => true]);
    }

    private function insumo(string $nombre = 'Bisagra'): Producto
    {
        return Producto::create([
            'tipo' => 'producto', 'nombre' => $nombre, 'referencia' => 'REF-'.uniqid(),
            'es_insumo' => true, 'inventariable' => true, 'unidad_medida' => 'unidad',
        ]);
    }

    /** Una orden enviada con una línea de 10 unidades, lista para recibir. */
    private function ordenConLinea(Producto $producto, int $cantidad = 10): OrdenCompra
    {
        $proveedor = Proveedor::create(['nombre' => 'Herrajes del Norte', 'activo' => true]);

        $orden = OrdenCompra::create([
            'estado' => 'enviada', 'proveedor_id' => $proveedor->id, 'creado_por' => $this->admin()->id,
        ]);

        OrdenCompraItem::create([
            'orden_id' => $orden->id, 'item_id' => $producto->id, 'descripcion' => $producto->nombre,
            'cantidad' => $cantidad, 'unidad' => 'unidad', 'precio_unitario' => 1000, 'total_linea' => $cantidad * 1000,
        ]);

        return $orden->fresh('items');
    }

    private function recibir(User $user, OrdenCompra $orden, float $cantidad, array $papel = [])
    {
        return $this->actingAs($user)->post("/compras/ordenes/{$orden->id}/recibir", array_merge([
            'items' => [['id' => $orden->items->first()->id, 'cantidad_recibida' => $cantidad]],
        ], $papel));
    }

    // ─── La recepción trae su papel ──────────────────────────────────────────

    public function test_recibir_con_factura_deja_el_papel_en_la_recepcion_y_en_el_movimiento(): void
    {
        $this->bodega();
        $producto = $this->insumo();
        $orden    = $this->ordenConLinea($producto);

        $this->recibir($this->admin(), $orden, 10, [
            'factura_numero' => 'FE-1234', 'remision_numero' => 'R-77',
            'fecha_documento' => '2026-10-08', 'fecha_recepcion' => '2026-10-09',
            'observaciones' => 'Llegó una caja golpeada',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $recepcion = $orden->recepciones()->firstOrFail();
        $this->assertSame('FE-1234', $recepcion->factura_numero);
        $this->assertSame('R-77', $recepcion->remision_numero);
        $this->assertSame('2026-10-09', $recepcion->fecha_recepcion->toDateString());

        $mov = ProductoMovimiento::where('producto_id', $producto->id)->firstOrFail();
        $this->assertSame('factura', $mov->documento_tipo, 'Con factura manda la factura.');
        $this->assertSame('FE-1234', $mov->documento_numero);
        $this->assertSame('2026-10-08', $mov->documento_fecha->toDateString());
        $this->assertStringContainsString('Llegó una caja golpeada', $mov->notas);
    }

    public function test_sin_factura_el_papel_es_la_remision(): void
    {
        $this->bodega();
        $producto = $this->insumo();
        $orden    = $this->ordenConLinea($producto);

        $this->recibir($this->admin(), $orden, 10, ['remision_numero' => 'R-77'])->assertRedirect();

        $mov = ProductoMovimiento::firstOrFail();
        $this->assertSame('remision', $mov->documento_tipo);
        $this->assertSame('R-77', $mov->documento_numero);
    }

    public function test_sin_ningun_papel_no_se_inventa_uno(): void
    {
        $this->bodega();
        $producto = $this->insumo();
        $orden    = $this->ordenConLinea($producto);

        $this->recibir($this->admin(), $orden, 10)->assertRedirect()->assertSessionHasNoErrors();

        $mov = ProductoMovimiento::firstOrFail();
        $this->assertNull($mov->documento_tipo);
        $this->assertNull($mov->documento_numero);

        $this->assertFalse($orden->recepciones()->firstOrFail()->traePapel(), 'La entrega queda marcada: no se puede comprobar.');
    }

    public function test_una_orden_en_dos_entregas_guarda_el_papel_de_cada_una(): void
    {
        $this->bodega();
        $producto = $this->insumo();
        $orden    = $this->ordenConLinea($producto);
        $admin    = $this->admin();

        $this->recibir($admin, $orden, 4, ['remision_numero' => 'R-1'])->assertRedirect();
        $this->recibir($admin, $orden, 6, ['factura_numero' => 'FE-2'])->assertRedirect();

        $this->assertSame(
            ['FE-2', null],
            $orden->recepciones()->get()->pluck('factura_numero')->all(),
        );
        $this->assertSame(['R-1'], $orden->recepciones()->whereNotNull('remision_numero')->pluck('remision_numero')->all());
        $this->assertSame('recibida', $orden->fresh()->estado);
    }

    public function test_la_fecha_de_llegada_no_puede_ser_futura(): void
    {
        $this->bodega();
        $orden = $this->ordenConLinea($this->insumo());

        $this->recibir($this->admin(), $orden, 10, ['fecha_recepcion' => today()->addDays(3)->toDateString()])
            ->assertSessionHasErrors('fecha_recepcion');

        $this->assertSame(0, $orden->recepciones()->count());
    }

    // ─── El ajuste de stock ──────────────────────────────────────────────────

    public function test_un_ajuste_guarda_el_papel_que_lo_respalda(): void
    {
        $bodega   = $this->bodega();
        $producto = $this->insumo();

        $this->actingAs($this->admin())->post("/productos/{$producto->id}/ajuste-stock", [
            'bodega_id' => $bodega->id, 'tipo' => 'entrada', 'cantidad' => 5,
            'notas' => 'Inventario físico', 'documento_tipo' => 'factura',
            'documento_numero' => 'FE-9', 'documento_fecha' => '2026-10-01',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $mov = ProductoMovimiento::firstOrFail();
        $this->assertSame('factura', $mov->documento_tipo);
        $this->assertSame('FE-9', $mov->documento_numero);
        $this->assertSame('Inventario físico', $mov->notas);
    }

    public function test_el_tipo_de_papel_sin_numero_se_rechaza(): void
    {
        $bodega   = $this->bodega();
        $producto = $this->insumo();

        $this->actingAs($this->admin())->post("/productos/{$producto->id}/ajuste-stock", [
            'bodega_id' => $bodega->id, 'tipo' => 'entrada', 'cantidad' => 5, 'documento_tipo' => 'factura',
        ])->assertSessionHasErrors('documento_numero');

        $this->assertSame(0, ProductoMovimiento::count());
    }

    public function test_ajustar_el_stock_exige_el_permiso_de_stock(): void
    {
        $bodega   = $this->bodega();
        $producto = $this->insumo();

        // Un ajuste mueve el inventario. La ruta no pedía ningún permiso.
        $this->actingAs(User::factory()->create(['rol' => 'operario']))
            ->post("/productos/{$producto->id}/ajuste-stock", ['bodega_id' => $bodega->id, 'tipo' => 'entrada', 'cantidad' => 5])
            ->assertForbidden();

        $this->assertSame(0, ProductoMovimiento::count());
    }

    // ─── Lo que muestra la ficha ─────────────────────────────────────────────

    public function test_la_ficha_resuelve_el_origen_y_el_papel_de_cada_movimiento(): void
    {
        $this->bodega();
        $producto = $this->insumo();
        $orden    = $this->ordenConLinea($producto);
        $admin    = $this->admin();

        $this->recibir($admin, $orden, 10, ['factura_numero' => 'FE-1234', 'fecha_documento' => '2026-10-08']);

        $this->actingAs($admin)->get("/productos/{$producto->id}")
            ->assertInertia(fn ($page) => $page
                ->component('Productos/Show')
                ->where('producto.movimientos_recientes.0.origen.etiqueta', "Orden {$orden->numero} · Herrajes del Norte")
                ->where('producto.movimientos_recientes.0.origen.url', "/compras/ordenes/{$orden->id}")
                ->where('producto.movimientos_recientes.0.documento.etiqueta', 'Factura')
                ->where('producto.movimientos_recientes.0.documento.numero', 'FE-1234')
                ->where('producto.movimientos_recientes.0.stock_anterior', 0)
                ->where('producto.movimientos_recientes.0.stock_nuevo', 10)
                ->where('producto.movimientos_hay_mas', false));
    }

    public function test_un_movimiento_sin_papel_ni_origen_conocido_no_revienta_la_ficha(): void
    {
        $bodega   = $this->bodega();
        $producto = $this->insumo();
        $producto->registrarMovimiento('entrada', 3, $bodega->id, $this->admin()->id, origenTipo: 'algo_nuevo');
        $producto->registrarMovimiento('entrada', 3, $bodega->id, $this->admin()->id, origenTipo: 'orden_compra', origenId: 9999);

        $this->actingAs($this->admin())->get("/productos/{$producto->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('producto.movimientos_recientes.1.origen.etiqueta', 'Algo nuevo')
                ->where('producto.movimientos_recientes.0.origen.etiqueta', 'Orden de compra')
                ->where('producto.movimientos_recientes.0.origen.url', null)
                ->where('producto.movimientos_recientes.0.documento', null));
    }

    public function test_el_historial_se_pide_por_tandas_con_un_cursor(): void
    {
        $bodega   = $this->bodega();
        $producto = $this->insumo();
        $admin    = $this->admin();

        foreach (range(1, 35) as $i) {
            $producto->registrarMovimiento('entrada', 1, $bodega->id, $admin->id, origenTipo: 'ajuste_manual', notas: "mov {$i}");
        }

        $primera = $this->actingAs($admin)->get("/productos/{$producto->id}")->viewData('page')['props']['producto'];

        $this->assertCount(30, $primera['movimientos_recientes']);
        $this->assertTrue($primera['movimientos_hay_mas']);
        $this->assertSame('mov 35', $primera['movimientos_recientes'][0]['notas'], 'Lo más nuevo primero.');

        $ultimo = end($primera['movimientos_recientes'])['id'];

        $segunda = $this->actingAs($admin)->getJson("/productos/{$producto->id}/movimientos?antes={$ultimo}")
            ->assertOk()->json();

        $this->assertCount(5, $segunda['data']);
        $this->assertFalse($segunda['hay_mas']);
        $this->assertSame('mov 5', $segunda['data'][0]['notas'], 'Sigue donde terminó la primera: sin repetir ni saltar.');
        $this->assertSame('mov 1', end($segunda['data'])['notas']);
    }

    public function test_la_ficha_lista_las_remisiones_en_las_que_salio(): void
    {
        $producto = $this->insumo();
        $admin    = $this->admin();

        $remision = Remision::create([
            'numero' => 'REM-0001', 'tipo' => 'manual', 'estado' => 'entregada',
            'fecha_remision' => '2026-10-05', 'created_by' => $admin->id,
        ]);
        RemisionItem::create([
            'remision_id' => $remision->id, 'producto_id' => $producto->id,
            'descripcion' => 'Bisagra', 'cantidad' => 4, 'unidad' => 'unidad',
        ]);

        $this->actingAs($admin)->get("/productos/{$producto->id}")
            ->assertInertia(fn ($page) => $page
                ->where('producto.remisiones.0.numero', 'REM-0001')
                ->where('producto.remisiones.0.fecha', '2026-10-05')
                ->where('producto.remisiones.0.cantidad', 4));
    }
}
