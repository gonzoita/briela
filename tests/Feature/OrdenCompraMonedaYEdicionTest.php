<?php

namespace Tests\Feature;

use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La orden de compra se escribe en la moneda del proveedor y se puede editar.
 * Por dentro sigue en la moneda base: lo recibido, el costo y los informes suman una sola.
 */
class OrdenCompraMonedaYEdicionTest extends TestCase
{
    use RefreshDatabase;

    private Proveedor $proveedor;
    private Producto $bisagra;

    protected function setUp(): void
    {
        parent::setUp();

        Sede::query()->first() ?? Sede::create(['nombre' => 'Principal', 'es_principal' => true]);
        $this->actingAs(User::factory()->create(['rol' => 'administrador']));

        $this->proveedor = Proveedor::create(['nombre' => 'Hettich', 'activo' => true]);
        $this->bisagra   = Producto::create([
            'tipo' => 'producto', 'nombre' => 'Bisagra', 'referencia' => 'IC5260',
            'es_insumo' => true, 'unidad_medida' => 'unidad', 'activo' => true,
        ]);
    }

    private function cuerpo(array $extra = [], array $linea = []): array
    {
        return $extra + [
            'proveedor_id' => $this->proveedor->id,
            'items' => [array_merge([
                'item_id' => $this->bisagra->id, 'descripcion' => 'Bisagra', 'cantidad' => 10,
                'unidad' => 'unidad', 'precio_unitario' => 2.5, 'impuesto_pct' => 19,
            ], $linea)],
        ];
    }

    public function test_una_orden_en_dolares_guarda_pesos_y_lo_tecleado(): void
    {
        $this->post('/compras/ordenes', $this->cuerpo(['moneda' => 'USD', 'tasa_cambio' => 4000]))->assertSessionHasNoErrors();

        $orden = OrdenCompra::first();
        $linea = $orden->items->first();

        $this->assertSame('USD', $orden->moneda);
        $this->assertEquals(10000.00, $linea->precio_unitario, 'pesos por unidad');
        $this->assertEquals(2.5, $linea->precio_moneda, 'lo que se tecleó');
        $this->assertEquals(100000.00, $linea->total_linea);
        $this->assertEquals(100000.00, $orden->subtotal);
        $this->assertEquals(119000.00, $orden->total);
    }

    public function test_en_otra_moneda_la_tasa_es_obligatoria(): void
    {
        $this->post('/compras/ordenes', $this->cuerpo(['moneda' => 'USD']))->assertSessionHasErrors('tasa_cambio');
        $this->assertSame(0, OrdenCompra::count());
    }

    public function test_sin_moneda_es_la_base_y_no_guarda_tasa(): void
    {
        $this->post('/compras/ordenes', $this->cuerpo([], ['precio_unitario' => 10000]));

        $orden = OrdenCompra::first();
        $this->assertNull($orden->moneda);
        $this->assertNull($orden->items->first()->precio_moneda);
        $this->assertEquals(100000.00, $orden->subtotal);
    }

    public function test_editar_un_borrador_cambia_moneda_lineas_y_totales(): void
    {
        $this->post('/compras/ordenes', $this->cuerpo());
        $orden = OrdenCompra::first();
        $linea = $orden->items->first();

        $this->get("/compras/ordenes/{$orden->id}/editar")->assertOk()->assertInertia(fn ($p) => $p
            ->component('Compras/Ordenes/Create')
            ->where('orden.id', $orden->id)
            ->where('orden.items.0.id', $linea->id));

        $this->put("/compras/ordenes/{$orden->id}", $this->cuerpo(['moneda' => 'USD', 'tasa_cambio' => 4000], ['id' => $linea->id, 'cantidad' => 20, 'precio_unitario' => 3]))
            ->assertSessionHasNoErrors();

        $orden->refresh();
        $this->assertSame('USD', $orden->moneda);
        $this->assertSame(1, $orden->items()->count(), 'la línea se edita, no se recrea');
        $this->assertSame($linea->id, $orden->items->first()->id);
        $this->assertEquals(240000.00, $orden->subtotal);
    }

    public function test_con_mercancia_recibida_no_se_quita_ni_se_baja_de_lo_recibido(): void
    {
        $this->post('/compras/ordenes', $this->cuerpo());
        $orden = OrdenCompra::first();
        $linea = $orden->items->first();
        $this->post("/compras/ordenes/{$orden->id}/enviar");
        $this->post("/compras/ordenes/{$orden->id}/recibir", ['items' => [['id' => $linea->id, 'cantidad_recibida' => 4]]]);

        $orden->refresh();
        $this->assertSame('recibida_parcial', $orden->estado);

        // No baja de lo recibido.
        $this->put("/compras/ordenes/{$orden->id}", $this->cuerpo([], ['id' => $linea->id, 'cantidad' => 3]))
            ->assertSessionHasErrors('items.0.cantidad');

        // No se quita la línea que ya entró.
        $this->put("/compras/ordenes/{$orden->id}", $this->cuerpo([], ['descripcion' => 'Otra', 'item_id' => null]))
            ->assertSessionHasErrors('items');

        // El proveedor y la moneda no cambian, pero sí la cantidad y el precio.
        $otro = Proveedor::create(['nombre' => 'Otro', 'activo' => true]);
        $this->put("/compras/ordenes/{$orden->id}", $this->cuerpo(['proveedor_id' => $otro->id, 'moneda' => 'USD', 'tasa_cambio' => 4000], ['id' => $linea->id, 'cantidad' => 12, 'precio_unitario' => 11000]))
            ->assertSessionHasNoErrors();

        $orden->refresh();
        $this->assertSame($this->proveedor->id, $orden->proveedor_id);
        $this->assertNull($orden->moneda);
        $this->assertEquals(4, $orden->items->first()->cantidad_recibida, 'lo recibido se conserva');
        $this->assertEquals(12, $orden->items->first()->cantidad);
        $this->assertEquals(132000.00, $orden->subtotal);
    }

    public function test_bajar_la_cantidad_hasta_lo_recibido_completa_la_orden(): void
    {
        $this->post('/compras/ordenes', $this->cuerpo());
        $orden = OrdenCompra::first();
        $linea = $orden->items->first();
        $this->post("/compras/ordenes/{$orden->id}/enviar");
        $this->post("/compras/ordenes/{$orden->id}/recibir", ['items' => [['id' => $linea->id, 'cantidad_recibida' => 4]]]);

        $this->put("/compras/ordenes/{$orden->id}", $this->cuerpo([], ['id' => $linea->id, 'cantidad' => 4]));

        $this->assertSame('recibida', $orden->fresh()->estado);
        $this->get("/compras/ordenes/{$orden->id}/editar")->assertRedirect("/compras/ordenes/{$orden->id}");
    }

    public function test_una_orden_ajena_no_se_cuela_en_la_edicion(): void
    {
        $this->post('/compras/ordenes', $this->cuerpo());
        $a = OrdenCompra::first();
        $this->post('/compras/ordenes', $this->cuerpo());
        $b = OrdenCompra::where('id', '!=', $a->id)->first();

        $this->put("/compras/ordenes/{$a->id}", $this->cuerpo([], ['id' => $b->items->first()->id]))
            ->assertSessionHasErrors('items.0.id');
    }
}
