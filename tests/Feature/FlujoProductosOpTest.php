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
use App\Services\CierrePasoService;
use App\Services\PreciosPorCanalService;
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

    /** Un producto que se procesa en planta: dos pasos y un punto de revisión. */
    private function conProceso(Producto $p): TemplateTrabajo
    {
        $template = TemplateTrabajo::create(['producto_id' => $p->id, 'nombre' => $p->nombre, 'activo' => true]);
        $template->sincronizarPasos([
            ['nombre' => 'Cortar',  'peso_porcentaje' => 50, 'orden' => 0, 'es_paso_final' => false],
            ['nombre' => 'Empacar', 'peso_porcentaje' => 50, 'orden' => 1, 'es_paso_final' => true],
        ]);
        $p->checksCalidad()->create(['titulo' => 'Sin despicados', 'orden' => 0, 'activo' => true]);

        return $template;
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

    // ─── Producto que pasa por planta ────────────────────────────────────────

    public function test_un_producto_con_proceso_nace_con_unidades_en_planta(): void
    {
        $prod = $this->productoBase();
        $this->conProceso($prod);

        $item = $this->item($this->borrador(), $prod, 3);

        $this->assertSame(3, $item->trabajos()->count());
        $this->assertSame(2, $item->trabajos()->first()->pasos()->count());
        $this->assertSame(1, $item->trabajos()->first()->checks()->count());
        // Lo alista la planta, no la mano.
        $this->assertNotNull($item->alistar());
    }

    public function test_un_producto_procesado_queda_listo_al_terminar_y_sale_del_inventario_al_despachar(): void
    {
        $prod = $this->productoBase(10);
        $this->conProceso($prod);
        $op   = $this->borrador();
        $item = $this->item($op, $prod, 2);
        $svc  = app(CierrePasoService::class);

        foreach ($item->trabajos as $t) {
            $svc->cerrar($t->pasos()->where('es_paso_final', false)->firstOrFail());
            // El paso final de un producto no pide bodegas: no entra a bodega como terminado.
            $svc->cerrar($t->pasos()->where('es_paso_final', true)->firstOrFail());
        }

        $item->refresh();
        $op->refresh();
        $this->assertSame('terminado', $item->estado_item);
        $this->assertSame('calidad', $op->estado);
        $this->assertNull($item->trabajos()->first()->entregado_at);
        $this->assertSame(10.0, $this->stock($prod)); // procesarlo no lo movió

        // Calidad firma las unidades: ahí se pueden remisionar.
        $this->assertSame(0, $item->cantidadDisponible());
        foreach ($item->trabajos as $t) {
            $t->checks()->update(['resultado' => 'cumple']);
            $t->firmarCalidad();
        }
        $op->sincronizarEstado();
        $this->assertSame(2, $item->fresh()->cantidadDisponible());

        $this->remisionar($op, $item, 2)->assertSessionHasNoErrors();
        $remision = Remision::latest('id')->firstOrFail();
        $this->patch("/logistica/remisiones/{$remision->id}/estado", ['estado' => 'en_camino'])->assertSessionHasNoErrors();

        $this->assertSame('despachada', $op->fresh()->estado);
        $this->assertSame(8.0, $this->stock($prod));
    }

    public function test_desde_la_orden_se_manda_a_planta_un_producto_sin_proceso(): void
    {
        $otro     = $this->productoBase();
        $template = $this->conProceso($otro);
        $op       = $this->borrador();
        $item     = $this->item($op, $this->productoBase(), 2);
        $this->assertSame(0, $item->trabajos()->count());

        $this->post("/produccion/ops/{$op->id}/items/{$item->id}/trabajo/iniciar", ['template_id' => $template->id])
            ->assertSessionHas('success');

        $this->assertSame(2, $item->trabajos()->count());

        // Con avance registrado ya no se le cambia el flujo ni se saca de planta.
        app(CierrePasoService::class)->cerrar($item->trabajos()->first()->pasos()->where('es_paso_final', false)->firstOrFail());

        $this->post("/produccion/ops/{$op->id}/items/{$item->id}/trabajo/iniciar", ['template_id' => $template->id])
            ->assertSessionHas('error');
        $this->delete("/produccion/ops/{$op->id}/items/{$item->id}/trabajo/pasos-todos")
            ->assertSessionHas('error');
        $this->assertSame(2, $item->trabajos()->count());
        // Y avanzar un paso arrancó la orden.
        $this->assertSame('en_produccion', $op->fresh()->estado);
    }

    public function test_sacar_de_planta_un_producto_que_nadie_toco_lo_deja_para_alistar(): void
    {
        $prod = $this->productoBase();
        $this->conProceso($prod);
        $op   = $this->borrador();
        $item = $this->item($op, $prod, 2);

        $this->delete("/produccion/ops/{$op->id}/items/{$item->id}/trabajo/pasos-todos")->assertSessionHas('success');

        $item->refresh();
        $this->assertSame(0, $item->trabajos()->count());
        $this->assertNull($item->alistar());
        $this->assertSame('terminado', $item->fresh()->estado_item);
    }

    public function test_la_ficha_del_producto_guarda_y_apaga_su_proceso_en_planta(): void
    {
        $prod = $this->productoBase();

        $form = fn (array $proceso) => [
            'tipo' => 'producto', 'nombre' => $prod->nombre, 'referencia' => $prod->referencia,
            'unidad_medida' => 'unidad', 'inventariable' => true, 'es_vendible' => true, 'es_insumo' => false,
            'precio_costo' => 1000, 'canales' => app(PreciosPorCanalService::class)->paraFormulario($prod),
            'proceso_planta' => json_encode($proceso),
        ];

        $this->put("/productos/{$prod->id}", $form([
            'activo' => true,
            'pasos'  => [
                ['nombre' => 'Cortar', 'peso_porcentaje' => 60, 'es_paso_final' => true],
                ['nombre' => 'Pulir',  'peso_porcentaje' => 40, 'es_paso_final' => false],
            ],
            'checks' => [['titulo' => 'Medidas correctas']],
        ]))->assertSessionHasNoErrors();

        $template = $prod->fresh()->procesoEnPlanta();
        $this->assertNotNull($template);
        $this->assertSame(['Cortar', 'Pulir'], $template->pasos()->pluck('nombre')->all());
        $this->assertSame(1, $template->pasos()->where('es_paso_final', true)->count());
        $this->assertSame(1, $prod->checksCalidad()->count());

        $this->put("/productos/{$prod->id}", $form(['activo' => false, 'pasos' => [], 'checks' => []]))
            ->assertSessionHasNoErrors();

        $this->assertNull($prod->fresh()->procesoEnPlanta());
        $this->assertSame(0, $this->item($this->borrador(), $prod->fresh())->trabajos()->count());
    }
}
