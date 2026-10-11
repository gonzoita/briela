<?php

namespace Tests\Feature;

use App\Models\Bodega;
use App\Models\Ensamble;
use App\Models\Op;
use App\Models\OpItem;
use App\Models\Producto;
use App\Models\Remision;
use App\Models\Sede;
use App\Models\User;
use App\Services\CierrePasoService;
use App\Services\TrabajoAutoGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El flujo después de la cotización, de punta a punta: alistar, fabricar, calidad, remisionar y
 * despachar — y que la orden cambie de estado sola con cada paso real.
 *
 * El estado se decide en un solo sitio (`Op::sincronizarEstado`). Antes cada pantalla hacía su
 * parte: cerrar un paso pasaba la orden a calidad, pero alistar un producto no la movía.
 */
class FlujoOpRemisionTest extends TestCase
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
        $this->bodega = Bodega::create(['sede_id' => $this->sede->id, 'nombre' => 'Terminado', 'activa' => true, 'es_principal' => true]);
        $this->actingAs($this->admin);
    }

    private function op(string $estado = 'confirmada'): Op
    {
        return Op::create([
            'sede_id' => $this->sede->id, 'estado' => $estado, 'responsable_id' => $this->admin->id,
            'bodega_entrega_id' => $this->bodega->id, 'bodega_material_id' => $this->bodega->id,
            'fecha_creacion' => now(), 'fecha_entrega_estimada' => now()->addDays(5),
        ]);
    }

    /** Un producto de bodega en la orden, con stock. */
    private function producto(Op $op, int $cantidad = 3, int $stock = 10): OpItem
    {
        $p = Producto::create([
            'tipo' => 'producto', 'nombre' => 'Bisagra '.uniqid(), 'referencia' => 'B-'.uniqid(),
            'unidad_medida' => 'unidad', 'inventariable' => true, 'activo' => true,
        ]);
        $p->registrarMovimiento('entrada', $stock, $this->bodega->id, $this->admin->id);

        return OpItem::create([
            'op_id' => $op->id, 'tipo' => 'producto', 'producto_id' => $p->id, 'descripcion' => $p->nombre,
            'cantidad' => $cantidad, 'precio_unitario' => 1000, 'subtotal' => 1000 * $cantidad, 'total_linea' => 1000 * $cantidad,
            'orden' => 0, 'estado_item' => 'pendiente',
        ]);
    }

    private function servicio(Op $op): OpItem
    {
        return OpItem::create([
            'op_id' => $op->id, 'tipo' => 'servicio', 'descripcion' => 'Instalación', 'cantidad' => 1,
            'precio_unitario' => 500, 'subtotal' => 500, 'total_linea' => 500, 'orden' => 9, 'estado_item' => 'pendiente',
        ]);
    }

    /** Un ensamble con unidades (dos pasos cada una), como lo genera la orden real. */
    private function ensamble(Op $op, int $cantidad = 2): OpItem
    {
        $ensamble = Ensamble::create([
            'nombre' => 'Puerta', 'tipo_armado' => 'directo', 'variables' => [], 'creado_por' => $this->admin->id,
            'unidad_medida' => 'unidad', 'precio_costo' => 1000, 'componentes_resultado' => [],
        ]);
        $ensamble->obtenerOCrearTemplateTrabajo()->sincronizarPasos([
            ['nombre' => 'Cortar', 'peso_porcentaje' => 50, 'orden' => 0, 'es_paso_final' => false],
            ['nombre' => 'Armar',  'peso_porcentaje' => 50, 'orden' => 1, 'es_paso_final' => true],
        ]);

        $item = OpItem::create([
            'op_id' => $op->id, 'ensamble_id' => $ensamble->id, 'tipo' => 'ensamble', 'descripcion' => 'Puerta',
            'cantidad' => $cantidad, 'precio_unitario' => 1000, 'subtotal' => 1000, 'total_linea' => 1000, 'orden' => 1,
        ]);
        app(TrabajoAutoGeneratorService::class)->generarParaItem($item);

        return $item->fresh();
    }

    private function fabricar(OpItem $item, ?int $unidades = null): void
    {
        $svc = app(CierrePasoService::class);
        foreach ($item->trabajos()->orderBy('numero_unidad')->take($unidades ?? 99)->get() as $t) {
            $svc->cerrar($t->pasos()->where('es_paso_final', false)->firstOrFail());
            $svc->cerrar($t->pasos()->where('es_paso_final', true)->firstOrFail());
        }
    }

    private function alistar(OpItem $item, bool $alistado = true)
    {
        return $this->patch("/produccion/alistamiento/{$item->id}", ['alistado' => $alistado]);
    }

    private function remisionar(Op $op, OpItem $item, int $unidades): Remision
    {
        $this->post('/logistica/remisiones', [
            'tipo' => 'op', 'op_id' => $op->id,
            'items' => [['descripcion' => $item->descripcion, 'cantidad' => $unidades, 'cantidad_unidades' => $unidades, 'op_item_id' => $item->id]],
        ])->assertSessionHasNoErrors();

        return Remision::latest('id')->firstOrFail();
    }

    private function estado(Remision $r, string $estado)
    {
        return $this->patch("/logistica/remisiones/{$r->id}/estado", ['estado' => $estado]);
    }

    // ─── Alistar mueve la orden ──────────────────────────────────────────────

    public function test_alistar_productos_mueve_la_orden_hasta_calidad_y_la_sella_sola(): void
    {
        $op = $this->op();
        $a  = $this->producto($op);
        $b  = $this->producto($op);

        $this->alistar($a)->assertSessionHas('success');
        $this->assertSame('en_produccion', $op->fresh()->estado); // algo listo: arrancó

        $this->alistar($b);
        $op->refresh();
        $this->assertSame('calidad', $op->estado);               // todo listo
        $this->assertNotNull($op->calidad_aprobada_at);           // sin unidades no hay qué firmar
    }

    public function test_desalistar_un_producto_devuelve_la_orden_a_produccion(): void
    {
        $op = $this->op();
        $a  = $this->producto($op);
        $this->alistar($a);
        $this->assertSame('calidad', $op->fresh()->estado);

        $this->alistar($a, false);

        $op->refresh();
        $this->assertSame('en_produccion', $op->estado);
        $this->assertNull($op->calidad_aprobada_at);
    }

    public function test_el_boton_de_la_orden_alista_igual_que_alistamiento(): void
    {
        $op = $this->op();
        $a  = $this->producto($op);

        $this->patch("/produccion/ops/{$op->id}/items/{$a->id}/terminar")->assertSessionHas('success');

        $this->assertSame('calidad', $op->fresh()->estado);
    }

    public function test_un_ensamble_con_unidades_no_se_alista_a_mano(): void
    {
        $op   = $this->op();
        $item = $this->ensamble($op);

        $this->alistar($item)->assertSessionHas('error');

        $this->assertSame('pendiente', $item->fresh()->estado_item ?? 'pendiente');
        $this->assertSame('confirmada', $op->fresh()->estado);
    }

    // ─── Trabajos y calidad ──────────────────────────────────────────────────

    public function test_terminar_las_unidades_lleva_la_orden_a_calidad_y_el_ensamble_queda_listo_solo(): void
    {
        $op   = $this->op();
        $item = $this->ensamble($op);
        $prod = $this->producto($op);

        $this->alistar($prod);
        $this->fabricar($item);

        $this->assertSame('terminado', $item->fresh()->estado_item);
        $this->assertSame('calidad', $op->fresh()->estado);
        // Las unidades todavía no tienen firma: la orden no está sellada.
        $this->assertNull($op->fresh()->calidad_aprobada_at);
    }

    public function test_la_orden_no_se_sella_con_la_primera_unidad_revisada(): void
    {
        $op   = $this->op();
        $item = $this->ensamble($op, 2);
        $this->fabricar($item);

        $primera = $item->trabajos()->orderBy('numero_unidad')->first();
        $this->post("/calidad/unidades/{$primera->id}/terminar")->assertOk();

        $this->assertNull($op->fresh()->calidad_aprobada_at); // falta la segunda

        $segunda = $item->trabajos()->orderBy('numero_unidad')->skip(1)->first();
        $this->post("/calidad/unidades/{$segunda->id}/terminar")->assertOk();

        $this->assertNotNull($op->fresh()->calidad_aprobada_at);
    }

    public function test_una_unidad_reabierta_deja_el_item_pendiente_otra_vez(): void
    {
        $op   = $this->op();
        $item = $this->ensamble($op, 1);
        $this->fabricar($item);
        $this->assertSame('terminado', $item->fresh()->estado_item);

        $final = $item->trabajos()->first()->pasos()->where('es_paso_final', true)->first();
        app(CierrePasoService::class)->reabrir($final);

        $this->assertSame('pendiente', $item->fresh()->estado_item);
        $this->assertSame('en_produccion', $op->fresh()->estado);
    }

    // ─── Remisiones ──────────────────────────────────────────────────────────

    public function test_una_orden_de_productos_alistados_se_puede_remisionar_desde_la_orden(): void
    {
        $op = $this->op();
        $a  = $this->producto($op);
        $this->alistar($a);

        $this->get("/produccion/ops/{$op->id}")
            ->assertInertia(fn ($page) => $page->where('op.por_remisionar', 3));

        $this->get("/logistica/remisiones/crear?op_id={$op->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Logistica/Remisiones/Create')->has('op.items', 1));
    }

    public function test_la_lista_de_ordenes_solo_trae_lo_que_de_verdad_se_puede_remisionar(): void
    {
        $sinCalidad = $this->op();
        $item = $this->ensamble($sinCalidad, 1);
        $this->fabricar($item); // fabricada, sin firmar por calidad

        $lista = $this->op();
        $this->alistar($this->producto($lista));

        $ids = collect($this->getJson('/logistica/api/ops/buscar')->json())->pluck('id');

        $this->assertTrue($ids->contains($lista->id));
        $this->assertFalse($ids->contains($sinCalidad->id));
    }

    public function test_crear_la_remision_no_despacha_salir_si_y_descuenta_el_producto(): void
    {
        $op   = $this->op();
        $prod = $this->producto($op, 3, 10);
        $this->servicio($op);
        $this->alistar($prod);
        $this->alistar($op->items()->where('tipo', 'servicio')->first());

        $r = $this->remisionar($op, $prod, 3);

        $this->assertSame('calidad', $op->fresh()->estado); // en borrador sigue en la bodega
        $this->assertEquals(10, $prod->producto->fresh()->stockTotal());

        $this->estado($r, 'en_camino')->assertSessionHas('success');

        // El servicio no viaja y no impide despachar.
        $this->assertSame('despachada', $op->fresh()->estado);
        $this->assertEquals(7, $prod->producto->fresh()->stockTotal());
    }

    public function test_anular_una_remision_que_salio_devuelve_el_stock_y_la_orden_a_calidad(): void
    {
        $op   = $this->op();
        $prod = $this->producto($op, 3, 10);
        $this->alistar($prod);
        $r = $this->remisionar($op, $prod, 3);
        $this->estado($r, 'en_camino');

        $this->estado($r, 'anulada')->assertSessionHas('success');

        $this->assertSame('calidad', $op->fresh()->estado);
        $this->assertEquals(10, $prod->producto->fresh()->stockTotal());
        $this->assertSame(3, $prod->fresh()->cantidadDisponible()); // se puede volver a despachar
    }

    public function test_despachar_dos_veces_no_descuenta_dos_veces(): void
    {
        $op   = $this->op();
        $prod = $this->producto($op, 3, 10);
        $this->alistar($prod);

        $r1 = $this->remisionar($op, $prod, 3);
        $this->estado($r1, 'en_camino');
        $this->estado($r1, 'anulada');

        $r2 = $this->remisionar($op, $prod, 3);
        $this->estado($r2, 'en_camino');

        $this->assertEquals(7, $prod->producto->fresh()->stockTotal());
        $this->assertNotNull($op->fresh()->inventario_consumido_at);
    }

    public function test_un_producto_en_dos_remisiones_se_anula_solo_lo_suyo(): void
    {
        $op   = $this->op();
        $prod = $this->producto($op, 4, 10);
        $this->alistar($prod);

        $this->remisionar($op, $prod, 1);
        $b = $this->remisionar($op, $prod, 3);

        $this->estado($b, 'anulada');

        // Antes volvía entero: se recontaba por unidades, y un producto no tiene.
        $this->assertEquals(1, (float) $prod->fresh()->cantidad_remisionada);
        $this->assertSame(3, $prod->fresh()->cantidadDisponible());
    }

    public function test_eliminar_una_remision_anulada_no_libera_las_unidades_de_otra(): void
    {
        $op   = $this->op();
        $item = $this->ensamble($op, 2);
        $this->fabricar($item);
        $this->post("/calidad/ops/{$op->id}/terminar")->assertSessionHasNoErrors();

        $a = $this->remisionar($op, $item, 1);
        $b = $this->remisionar($op, $item, 1);

        $this->estado($b, 'anulada');
        $this->delete("/logistica/remisiones/{$b->id}");

        // La unidad de A sigue remisionada: liberarla otra vez la soltaba.
        $this->assertSame(1, $item->trabajos()->where('remisionado', true)->count());
        $this->assertNotNull($a->fresh());
    }

    public function test_una_remision_anulada_no_revive_y_una_que_salio_no_se_elimina(): void
    {
        $op   = $this->op();
        $prod = $this->producto($op, 2, 10);
        $this->alistar($prod);
        $r = $this->remisionar($op, $prod, 1);

        $this->estado($r, 'en_camino');
        $this->delete("/logistica/remisiones/{$r->id}")->assertSessionHas('error');
        $this->assertNotNull($r->fresh());

        $this->estado($r, 'anulada');
        $this->estado($r, 'en_camino')->assertSessionHas('error');
        $this->assertSame('anulada', $r->fresh()->estado);
    }

    public function test_la_firma_de_recibido_tambien_despacha(): void
    {
        $op   = $this->op();
        $prod = $this->producto($op, 1, 5);
        $this->alistar($prod);
        $r = $this->remisionar($op, $prod, 1);
        $this->estado($r, 'en_camino');
        // (ya despachada al salir; la firma solo cierra la entrega)
        $this->post("/logistica/remisiones/{$r->id}/firma", ['tipo' => 'recibido', 'firma' => 'data:image/png;base64,AAA']);

        $this->assertSame('entregada', $r->fresh()->estado);
        $this->assertSame('despachada', $op->fresh()->estado);
        $this->assertEquals(4, $prod->producto->fresh()->stockTotal()); // una sola salida
    }

    // ─── Pantalla de remisiones ──────────────────────────────────────────────

    public function test_remisiones_muestra_lo_pendiente_por_remisionar_y_lo_creado(): void
    {
        $op   = $this->op();
        $prod = $this->producto($op, 3, 10);
        $this->alistar($prod);

        $this->get('/logistica/remisiones')
            ->assertInertia(fn ($page) => $page
                ->where('vista', 'pendientes')
                ->has('pendientes', 1)
                ->where('pendientes.0.id', $op->id)
                ->where('pendientes.0.items.0.disponibles', 3));

        $this->remisionar($op, $prod, 3);

        $this->get('/logistica/remisiones')
            ->assertInertia(fn ($page) => $page
                ->where('vista', 'creadas')
                ->has('pendientes', 0)
                ->has('remisiones.data', 1));
    }

    // ─── Órdenes ya atascadas ────────────────────────────────────────────────

    public function test_el_comando_pone_al_dia_las_ordenes_atascadas(): void
    {
        // Como quedaban antes: todo alistado y la orden en producción.
        $op   = $this->op('en_produccion');
        $prod = $this->producto($op);
        $prod->update(['estado_item' => 'terminado']);

        $this->artisan('ops:sincronizar-estados')->assertSuccessful();

        $this->assertSame('calidad', $op->fresh()->estado);
    }

    public function test_abrir_la_orden_tambien_la_pone_al_dia(): void
    {
        $op   = $this->op('en_produccion');
        $prod = $this->producto($op);
        $prod->update(['estado_item' => 'terminado']);

        $this->get("/produccion/ops/{$op->id}")->assertOk();

        $this->assertSame('calidad', $op->fresh()->estado);
    }
}
