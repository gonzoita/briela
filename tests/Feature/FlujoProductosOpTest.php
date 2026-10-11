<?php

namespace Tests\Feature;

use App\Models\Bodega;
use App\Models\Op;
use App\Models\OpItem;
use App\Models\Producto;
use App\Models\Remision;
use App\Models\Sede;
use App\Models\TemplateTrabajo;
use App\Models\User;
use App\Services\TrabajoAutoGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Una orden de productos, de punta a punta, sin trabas.
 *
 * Tres cosas que fallaban: una orden en borrador no se movía al alistar un producto (y
 * Alistamiento y el remisionador ni la mostraban), un producto no se podía remisionar sin pasar
 * antes por Alistamiento, y un producto que había que cortar o pintar no tenía cómo pasar por
 * planta: solo los ensambles tenían trabajos.
 */
class FlujoProductosOpTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Bodega $bodega;
    private Sede $sede;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin  = User::factory()->create(['rol' => 'administrador']);
        $this->sede   = Sede::query()->first() ?? Sede::create(['nombre' => 'Principal']);
        $this->bodega = Bodega::create(['sede_id' => $this->sede->id, 'nombre' => 'Principal', 'activa' => true, 'es_principal' => true]);
        $this->actingAs($this->admin);
    }

    /** Una orden recién nacida de la cotización: en borrador y sin bodegas. */
    private function borrador(): Op
    {
        return Op::create([
            'sede_id' => $this->sede->id, 'estado' => 'borrador', 'responsable_id' => $this->admin->id,
            'fecha_creacion' => now(), 'fecha_entrega_estimada' => now()->addDays(5),
        ]);
    }

    private function productoBase(int $stock = 10): Producto
    {
        $p = Producto::create([
            'tipo' => 'producto', 'nombre' => 'Vidrio '.uniqid(), 'referencia' => 'V-'.uniqid(),
            'unidad_medida' => 'unidad', 'inventariable' => true, 'activo' => true,
        ]);
        $p->registrarMovimiento('entrada', $stock, $this->bodega->id, $this->admin->id);

        return $p;
    }

    /** El producto en la orden, como lo crean la orden y la cotización: con el generador. */
    private function item(Op $op, Producto $p, int $cantidad = 2): OpItem
    {
        $item = OpItem::create([
            'op_id' => $op->id, 'tipo' => 'producto', 'producto_id' => $p->id, 'descripcion' => $p->nombre,
            'cantidad' => $cantidad, 'precio_unitario' => 1000, 'subtotal' => 1000 * $cantidad, 'total_linea' => 1000 * $cantidad,
            'orden' => 0, 'estado_item' => 'pendiente',
        ]);
        app(TrabajoAutoGeneratorService::class)->generarParaItem($item);

        return $item->fresh();
    }

    private function remisionar(Op $op, OpItem $item, int $unidades)
    {
        return $this->post('/logistica/remisiones', [
            'tipo' => 'op', 'op_id' => $op->id,
            'items' => [['descripcion' => $item->descripcion, 'cantidad' => $unidades, 'cantidad_unidades' => $unidades, 'op_item_id' => $item->id]],
        ]);
    }

    private function stock(Producto $p): float
    {
        return (float) $p->fresh()->stocks()->where('bodega_id', $this->bodega->id)->sum('cantidad');
    }

    // ─── Borrador ────────────────────────────────────────────────────────────

    public function test_alistar_un_producto_en_borrador_arranca_la_orden_con_bodegas(): void
    {
        $op   = $this->borrador();
        $item = $this->item($op, $this->productoBase());
        $this->item($op, $this->productoBase());

        $this->patch("/produccion/ops/{$op->id}/items/{$item->id}/terminar")->assertSessionHas('success');

        $op->refresh();
        $this->assertSame('en_produccion', $op->estado);
        // Nunca pasó por «Confirmar»: las bodegas se precargan con la principal.
        $this->assertSame($this->bodega->id, (int) $op->bodega_entrega_id);
        $this->assertSame($this->bodega->id, (int) $op->bodega_material_id);
    }

    public function test_un_borrador_sin_nada_listo_se_sigue_armando(): void
    {
        $op = $this->borrador();
        $this->item($op, $this->productoBase());

        $op->sincronizarEstado();

        $this->assertSame('borrador', $op->fresh()->estado);
    }

    public function test_alistamiento_muestra_los_items_de_un_borrador(): void
    {
        $op = $this->borrador();
        $this->item($op, $this->productoBase());

        $this->get('/produccion/alistamiento')
            ->assertInertia(fn ($page) => $page->where('items.total', 1));
    }

    // ─── Remisionar sin alistar ──────────────────────────────────────────────

    public function test_se_remisiona_un_producto_sin_alistar_y_queda_alistado(): void
    {
        $op   = $this->borrador();
        $prod = $this->productoBase(10);
        $item = $this->item($op, $prod, 3);

        $this->remisionar($op, $item, 3)->assertSessionHasNoErrors();

        $this->assertSame('terminado', $item->fresh()->estado_item);
        $op->refresh();
        $this->assertSame('calidad', $op->estado);          // todo listo, y arrancó desde borrador
        $this->assertNotNull($op->calidad_aprobada_at);       // sin unidades: se sella sola

        $remision = Remision::latest('id')->firstOrFail();
        $this->patch("/logistica/remisiones/{$remision->id}/estado", ['estado' => 'en_camino'])->assertSessionHasNoErrors();

        $this->assertSame('despachada', $op->fresh()->estado);
        $this->assertSame(7.0, $this->stock($prod));
    }

    public function test_el_remisionador_ofrece_la_orden_aunque_no_haya_nada_alistado(): void
    {
        $op = $this->borrador();
        $this->item($op, $this->productoBase(), 2);

        $this->get("/logistica/remisiones/crear?op_id={$op->id}")
            ->assertInertia(fn ($page) => $page
                ->where('op.items.0.unidades_disponibles', 2)
                ->where('op.items.0.sin_alistar', true));

        // La pestaña «Por remisionar» sigue siendo la de lo que ya está listo.
        $this->get('/logistica/remisiones')
            ->assertInertia(fn ($page) => $page->where('pendientes', []));
    }

    public function test_no_se_remisiona_mas_de_lo_que_pide_la_orden(): void
    {
        $op   = $this->borrador();
        $item = $this->item($op, $this->productoBase(), 2);

        $this->remisionar($op, $item, 3)->assertSessionHasErrors();
    }

    // ─── Un producto no pasa por planta (14 oct 2026) ─────────────────────────

    public function test_un_producto_nunca_nace_con_unidades_aunque_tenga_un_flujo_viejo(): void
    {
        $prod = $this->productoBase();
        // Un flujo que la 1.5.0 dejó colgar de un producto.
        $t = TemplateTrabajo::create(['producto_id' => $prod->id, 'nombre' => $prod->nombre, 'activo' => true]);
        $t->sincronizarPasos([['nombre' => 'Cortar', 'peso_porcentaje' => 100, 'orden' => 0, 'es_paso_final' => true]]);

        $item = $this->item($this->borrador(), $prod, 3);

        $this->assertSame(0, $item->trabajos()->count());
        $this->assertNull($item->alistar());
        $this->assertSame('terminado', $item->fresh()->estado_item);
    }

    public function test_un_producto_no_se_puede_mandar_a_planta_desde_la_orden(): void
    {
        $ensamble = \App\Models\Ensamble::create([
            'nombre' => 'Marco', 'tipo_armado' => 'directo', 'variables' => [], 'creado_por' => $this->admin->id,
            'unidad_medida' => 'unidad', 'precio_costo' => 1000, 'componentes_resultado' => [],
        ]);
        $template = $ensamble->obtenerOCrearTemplateTrabajo();
        $op       = $this->borrador();
        $item     = $this->item($op, $this->productoBase(), 2);

        $this->post("/produccion/ops/{$op->id}/items/{$item->id}/trabajo/iniciar", ['template_id' => $template->id])
            ->assertSessionHas('error');

        $this->assertSame(0, $item->trabajos()->count());
    }

    public function test_los_productos_que_estaban_en_planta_vuelven_a_alistar(): void
    {
        $op        = $this->borrador();
        $terminado = $this->item($op, $this->productoBase(), 2);
        $amedias   = $this->item($op, $this->productoBase(), 1);

        // Unidades como las que dejó crear la 1.5.0.
        foreach ([[$terminado, 100], [$terminado, 100], [$amedias, 50]] as $i => [$it, $avance]) {
            \App\Models\OpItemTrabajo::create([
                'op_item_id' => $it->id, 'porcentaje_avance' => $avance, 'numero_unidad' => $i + 1, 'total_unidades' => 1,
            ]);
        }

        $this->artisan('ops:sincronizar-estados')->assertSuccessful();

        $this->assertSame(0, \App\Models\OpItemTrabajo::count());
        $this->assertSame('terminado', $terminado->fresh()->estado_item);
        $this->assertSame('pendiente', $amedias->fresh()->estado_item);
        $this->assertSame('en_produccion', $op->fresh()->estado);
    }

    public function test_remisionar_un_producto_lo_saca_del_almacen_al_crear_la_remision(): void
    {
        $op   = $this->borrador();
        $prod = $this->productoBase(10);
        $item = $this->item($op, $prod, 4);
        $item->alistar();

        $this->remisionar($op, $item, 4)->assertSessionHasNoErrors();

        $this->assertSame(6.0, $this->stock($prod));
        $mov = $prod->movimientos()->latest('id')->firstOrFail();
        $this->assertSame('remision', $mov->origen_tipo);
        $this->assertSame('salida', $mov->tipo);
        $this->assertEquals(4, (float) $mov->cantidad);
    }
}
