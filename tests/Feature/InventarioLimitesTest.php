<?php

namespace Tests\Feature;

use App\Models\Bodega;
use App\Models\Producto;
use App\Models\Sede;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mínimo y máximo de stock: se cambian en la misma lista y en el tablero, admiten decimales, y el
 * formulario de editar un material guarda aunque la descripción sea larga.
 */
class InventarioLimitesTest extends TestCase
{
    use RefreshDatabase;

    private Bodega $bodega;

    protected function setUp(): void
    {
        parent::setUp();

        Sede::query()->first() ?? Sede::create(['nombre' => 'Principal', 'es_principal' => true]);
        $this->bodega = Bodega::create(['nombre' => 'Principal', 'activa' => true, 'es_principal' => true]);
        $this->actingAs(User::factory()->create(['rol' => 'administrador']));
    }

    private function insumo(array $extra = [], float $stock = 0): Producto
    {
        $p = Producto::create($extra + [
            'tipo' => 'producto', 'nombre' => 'Lámina ' . uniqid(), 'referencia' => 'INS-' . uniqid(),
            'es_insumo' => true, 'inventariable' => true, 'unidad_medida' => 'm', 'activo' => true,
        ]);

        if ($stock > 0) {
            $p->registrarMovimiento('entrada', $stock, $this->bodega->id, auth()->id());
        }

        return $p;
    }

    public function test_cambia_el_minimo_y_el_maximo_con_decimales(): void
    {
        $p = $this->insumo();

        $this->patch("/inventario/{$p->id}/limites", ['stock_minimo' => 2.5, 'stock_maximo' => 40])->assertSessionHasNoErrors();

        $p->refresh();
        $this->assertEquals(2.5, (float) $p->stock_minimo, 'un mínimo de 2,5 no se trunca a 2');
        $this->assertEquals(40, (float) $p->stock_maximo);
    }

    public function test_cambiar_solo_uno_no_toca_el_otro_y_vacio_es_sin_limite(): void
    {
        $p = $this->insumo(['stock_minimo' => 5, 'stock_maximo' => 20]);

        $this->patch("/inventario/{$p->id}/limites", ['stock_minimo' => 8])->assertSessionHasNoErrors();
        $this->assertEquals([8, 20], [(float) $p->fresh()->stock_minimo, (float) $p->fresh()->stock_maximo]);

        $this->patch("/inventario/{$p->id}/limites", ['stock_maximo' => ''])->assertSessionHasNoErrors();
        $this->assertEquals(0, (float) $p->fresh()->stock_maximo, 'vacío es sin máximo');
    }

    public function test_el_maximo_no_puede_ser_menor_que_el_minimo(): void
    {
        $p = $this->insumo(['stock_minimo' => 10]);

        $this->patch("/inventario/{$p->id}/limites", ['stock_maximo' => 4])->assertSessionHasErrors('stock_maximo');
        $this->assertEquals(0, (float) $p->fresh()->stock_maximo);
    }

    public function test_sin_permiso_de_editar_no_cambia_los_limites(): void
    {
        $p = $this->insumo();
        $this->actingAs(User::factory()->create(['rol' => 'operario']));

        $this->patch("/inventario/{$p->id}/limites", ['stock_minimo' => 3])->assertForbidden();
        $this->assertEquals(0, (float) $p->fresh()->stock_minimo);
    }

    public function test_editar_un_material_con_descripcion_larga_guarda(): void
    {
        $p = $this->insumo(['descripcion_corta' => str_repeat('Lámina de acero inoxidable calibre 22. ', 10)]);

        $this->put("/inventario/{$p->id}", [
            'nombre' => 'Lámina renombrada', 'referencia' => $p->referencia, 'unidad_medida' => 'm',
            'descripcion_corta' => $p->descripcion_corta, 'stock_minimo' => 3.5, 'stock_maximo' => '', 'activo' => true,
        ])->assertSessionHasNoErrors();

        $p->refresh();
        $this->assertSame('Lámina renombrada', $p->nombre);
        $this->assertEquals(3.5, (float) $p->stock_minimo);
        $this->assertEquals(0, (float) $p->stock_maximo);
    }

    public function test_la_lista_trae_el_costo_vigente_aunque_no_haya_compras(): void
    {
        $p = $this->insumo(['precio_costo' => 45000], 5);

        $this->get('/inventario')->assertOk()->assertInertia(fn ($pg) => $pg
            ->where('items.data.0.costo_vigente', 45000));
    }

    public function test_el_tablero_no_cuenta_como_bajo_lo_que_no_tiene_minimo_y_lo_lista_aparte(): void
    {
        $sinMinimo = $this->insumo(['precio_costo' => 1000], 10);
        $bajo      = $this->insumo(['stock_minimo' => 20, 'precio_costo' => 1000], 5);

        $this->get('/inventario/dashboard')->assertOk()->assertInertia(fn ($pg) => $pg
            ->has('itemsBajoStock', 1)
            ->where('itemsBajoStock.0.id', $bajo->id)
            ->where('totalSinMinimo', 1)
            ->where('sinMinimo.0.id', $sinMinimo->id)
            ->where('valorTotal', 15000));
    }
}
