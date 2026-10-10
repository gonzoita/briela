<?php

namespace Tests\Feature;

use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\ProductoProveedor;
use App\Models\ProductoProveedorPrecio;
use App\Models\Proveedor;
use App\Models\SolicitudCompra;
use App\Models\SolicitudCompraItem;
use App\Models\User;
use App\Services\IA\ConsultasDatosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La orden de compra lleva el código de QUIEN la recibe.
 *
 * La bisagra que la empresa llama IC5260 la venden varios proveedores con códigos distintos
 * (1256899P, R125458…). Si la orden manda el código interno, el proveedor despacha otra
 * cosa o llama a preguntar.
 */
class ComprasCodigoProveedorTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'administrador']);
    }

    private function proveedor(string $nombre): Proveedor
    {
        return Proveedor::create(['nombre' => $nombre, 'activo' => true]);
    }

    private function bisagra(): Producto
    {
        return Producto::create([
            'tipo' => 'producto', 'nombre' => 'Bisagra', 'referencia' => 'IC5260',
            'es_insumo' => true, 'unidad_medida' => 'unidad',
        ]);
    }

    private function conCodigo(Producto $producto, Proveedor $proveedor, string $codigo, float $precio = 0, ?string $fecha = null): ProductoProveedor
    {
        return ProductoProveedor::create([
            'producto_id' => $producto->id, 'proveedor_id' => $proveedor->id,
            'referencia_proveedor' => $codigo, 'precio' => $precio,
            'actualizado_el' => $fecha ?? ($precio > 0 ? today() : null),
        ]);
    }

    /** @param array<string, mixed> $linea */
    private function crearOrden(User $user, Proveedor $proveedor, array $linea)
    {
        return $this->actingAs($user)->post('/compras/ordenes', [
            'proveedor_id' => $proveedor->id,
            'items'        => [array_merge([
                'descripcion' => 'Bisagra', 'cantidad' => 10, 'unidad' => 'unidad',
                'precio_unitario' => 1000, 'impuesto_pct' => 19,
            ], $linea)],
        ]);
    }

    // ─── El código que se manda ──────────────────────────────────────────────

    public function test_la_orden_toma_el_codigo_que_ese_proveedor_ya_tiene_registrado(): void
    {
        $bisagra = $this->bisagra();
        $norte   = $this->proveedor('Herrajes del Norte');
        $sur     = $this->proveedor('Herrajes del Sur');
        $this->conCodigo($bisagra, $norte, '1256899P');
        $this->conCodigo($bisagra, $sur, 'R125458');

        $admin = $this->admin();

        $this->crearOrden($admin, $norte, ['item_id' => $bisagra->id])->assertRedirect();
        $this->crearOrden($admin, $sur, ['item_id' => $bisagra->id])->assertRedirect();

        // El mismo producto, dos órdenes, dos códigos: cada uno el de su proveedor.
        $codigos = OrdenCompra::with('items')->orderBy('id')->get()
            ->map(fn ($o) => $o->items->first()->referencia_proveedor)->all();

        $this->assertSame(['1256899P', 'R125458'], $codigos);
    }

    public function test_el_codigo_escrito_en_la_orden_manda_y_queda_como_equivalencia(): void
    {
        $bisagra = $this->bisagra();
        $norte   = $this->proveedor('Herrajes del Norte');

        $this->assertNull(ProductoProveedor::where('producto_id', $bisagra->id)->first(), 'Parte sin equivalencia.');

        $this->crearOrden($this->admin(), $norte, [
            'item_id' => $bisagra->id, 'referencia_proveedor' => '1256899P', 'precio_unitario' => 2500,
        ])->assertRedirect();

        $this->assertSame('1256899P', OrdenCompra::with('items')->first()->items->first()->referencia_proveedor);

        // Quedó guardada para la próxima, con el precio de la línea y como preferido.
        $fila = ProductoProveedor::where('producto_id', $bisagra->id)->where('proveedor_id', $norte->id)->firstOrFail();
        $this->assertSame('1256899P', $fila->referencia_proveedor);
        $this->assertEquals(2500, (float) $fila->precio);
        $this->assertTrue($fila->es_preferido);
        $this->assertSame($norte->id, $bisagra->fresh()->proveedor_id, 'productos.proveedor_id sigue al preferido.');
    }

    public function test_un_codigo_corregido_en_la_orden_reemplaza_al_registrado(): void
    {
        $bisagra = $this->bisagra();
        $norte   = $this->proveedor('Herrajes del Norte');
        $this->conCodigo($bisagra, $norte, 'VIEJO-1');

        $this->crearOrden($this->admin(), $norte, ['item_id' => $bisagra->id, 'referencia_proveedor' => 'NUEVO-9'])
            ->assertRedirect();

        $this->assertSame('NUEVO-9', ProductoProveedor::where('producto_id', $bisagra->id)->firstOrFail()->referencia_proveedor);
    }

    public function test_una_linea_manual_sin_producto_no_inventa_equivalencias(): void
    {
        $norte = $this->proveedor('Herrajes del Norte');

        $this->crearOrden($this->admin(), $norte, ['item_id' => null, 'referencia_proveedor' => 'X-1'])->assertRedirect();

        $this->assertSame('X-1', OrdenCompra::with('items')->first()->items->first()->referencia_proveedor);
        $this->assertSame(0, ProductoProveedor::count());
    }

    public function test_convertir_una_solicitud_usa_el_codigo_y_el_precio_del_proveedor(): void
    {
        $admin   = $this->admin();
        $bisagra = $this->bisagra();
        $norte   = $this->proveedor('Herrajes del Norte');
        $this->conCodigo($bisagra, $norte, '1256899P', 2300);

        $solicitud = SolicitudCompra::create([
            'numero' => 'SC-T1', 'estado' => 'aprobada', 'solicitado_por' => $admin->id,
        ]);
        SolicitudCompraItem::create([
            'solicitud_id' => $solicitud->id, 'item_id' => $bisagra->id, 'descripcion' => 'Bisagra',
            'cantidad' => 4, 'unidad' => 'unidad', 'precio_estimado' => 900,
        ]);

        $this->actingAs($admin)->post("/compras/solicitudes/{$solicitud->id}/convertir", ['proveedor_id' => $norte->id])
            ->assertRedirect();

        $linea = OrdenCompra::with('items')->firstOrFail()->items->first();

        $this->assertSame('1256899P', $linea->referencia_proveedor);
        $this->assertEquals(2300, (float) $linea->precio_unitario, 'Manda el precio del proveedor, no la estimación.');
        $this->assertEquals(9200, (float) $linea->total_linea);
    }

    public function test_si_el_proveedor_no_tiene_precio_se_usa_la_estimacion(): void
    {
        $admin   = $this->admin();
        $bisagra = $this->bisagra();
        $norte   = $this->proveedor('Herrajes del Norte');
        $this->conCodigo($bisagra, $norte, '1256899P', 0);

        $solicitud = SolicitudCompra::create(['numero' => 'SC-T2', 'estado' => 'aprobada', 'solicitado_por' => $admin->id]);
        SolicitudCompraItem::create([
            'solicitud_id' => $solicitud->id, 'item_id' => $bisagra->id, 'descripcion' => 'Bisagra',
            'cantidad' => 4, 'unidad' => 'unidad', 'precio_estimado' => 900,
        ]);

        $this->actingAs($admin)->post("/compras/solicitudes/{$solicitud->id}/convertir", ['proveedor_id' => $norte->id]);

        $this->assertEquals(900, (float) OrdenCompra::with('items')->firstOrFail()->items->first()->precio_unitario);
    }

    // ─── La pantalla y el PDF ────────────────────────────────────────────────

    public function test_la_pantalla_de_crear_trae_lo_que_cada_proveedor_sabe_de_cada_insumo(): void
    {
        $bisagra = $this->bisagra();
        $norte   = $this->proveedor('Herrajes del Norte');
        $this->conCodigo($bisagra, $norte, '1256899P', 2300);

        $this->actingAs($this->admin())->get('/compras/ordenes/crear')
            ->assertInertia(fn ($page) => $page
                ->component('Compras/Ordenes/Create')
                ->where("items.0.proveedores.{$norte->id}.referencia", '1256899P')
                ->where("items.0.proveedores.{$norte->id}.precio", 2300));
    }

    public function test_el_pdf_y_la_ficha_llevan_el_codigo_del_proveedor(): void
    {
        $bisagra = $this->bisagra();
        $norte   = $this->proveedor('Herrajes del Norte');
        $admin   = $this->admin();

        $this->crearOrden($admin, $norte, ['item_id' => $bisagra->id, 'referencia_proveedor' => '1256899P']);

        $orden = OrdenCompra::with(['proveedor', 'creadoPor:id,name', 'items.item:id,nombre,referencia'])->firstOrFail();

        $html = view('pdf.orden-compra', compact('orden'))->render();

        $this->assertStringContainsString('Cód. proveedor: 1256899P', $html);
        $this->assertStringContainsString('Ref. interna: IC5260', $html);

        $this->actingAs($admin)->get("/compras/ordenes/{$orden->id}")
            ->assertInertia(fn ($page) => $page->where('orden.items.0.referencia_proveedor', '1256899P'));
    }

    // ─── Los precios ─────────────────────────────────────────────────────────

    public function test_enviar_la_orden_actualiza_el_precio_y_deja_historia(): void
    {
        $bisagra = $this->bisagra();
        $norte   = $this->proveedor('Herrajes del Norte');
        $this->conCodigo($bisagra, $norte, '1256899P', 2000, today()->subDays(200)->toDateString());
        $admin = $this->admin();

        $this->crearOrden($admin, $norte, ['item_id' => $bisagra->id, 'precio_unitario' => 2600]);
        $orden = OrdenCompra::firstOrFail();

        // En borrador todavía no pasa nada: es una intención.
        $this->assertEquals(2000, (float) ProductoProveedor::firstOrFail()->precio);
        $this->assertSame(0, ProductoProveedorPrecio::count());

        $this->actingAs($admin)->post("/compras/ordenes/{$orden->id}/enviar")->assertRedirect();

        $fila = ProductoProveedor::firstOrFail();
        $this->assertEquals(2600, (float) $fila->precio);
        $this->assertSame(today()->toDateString(), $fila->actualizado_el->toDateString());

        $historia = ProductoProveedorPrecio::firstOrFail();
        $this->assertEquals(2600, (float) $historia->precio);
        $this->assertSame($orden->id, $historia->orden_compra_id);
    }

    // ─── Lo que lee el asistente ─────────────────────────────────────────────

    public function test_el_asistente_compara_proveedores_y_no_recomienda_un_precio_viejo(): void
    {
        $bisagra = $this->bisagra();
        $barato  = $this->proveedor('Muy Barato (precio viejo)');
        $vigente = $this->proveedor('Vigente');
        $caro    = $this->proveedor('Caro pero vigente');

        $this->conCodigo($bisagra, $barato, 'B-1', 500, today()->subDays(300)->toDateString());
        $this->conCodigo($bisagra, $vigente, 'V-1', 1200, today()->subDays(5)->toDateString());
        $this->conCodigo($bisagra, $caro, 'C-1', 1800, today()->subDays(5)->toDateString());

        $this->actingAs($this->admin());

        $resultado = app(ConsultasDatosService::class)->ejecutar('comparar_proveedores', ['texto' => 'IC5260']);

        $producto = $resultado['productos'][0];

        $this->assertSame('Vigente', $producto['mas_barato'], 'El de 300 días no es una oferta.');
        $this->assertSame(
            ['Vigente', 'Caro pero vigente', 'Muy Barato (precio viejo)'],
            array_column($producto['proveedores'], 'proveedor'),
            'Los vigentes primero, y entre ellos el más barato.',
        );
        $this->assertSame('V-1', $producto['proveedores'][0]['codigo_proveedor']);
    }

    public function test_si_ningun_precio_esta_vigente_el_asistente_lo_dice(): void
    {
        $bisagra = $this->bisagra();
        $this->conCodigo($bisagra, $this->proveedor('Viejo'), 'B-1', 500, today()->subDays(300)->toDateString());

        $this->actingAs($this->admin());

        $producto = app(ConsultasDatosService::class)->ejecutar('comparar_proveedores', ['texto' => 'IC5260'])['productos'][0];

        $this->assertNull($producto['mas_barato']);
        $this->assertStringContainsString('Ningún precio está vigente', $producto['aviso']);
    }

    public function test_comparar_proveedores_exige_poder_ver_costos(): void
    {
        $this->bisagra();

        // Un rol sin `costos.ver`: la consulta ni aparece en lo que se le ofrece a la IA.
        $this->actingAs(User::factory()->create(['rol' => 'vendedor']));

        $this->assertArrayNotHasKey('comparar_proveedores', app(ConsultasDatosService::class)->disponibles());
        $this->assertNull(app(ConsultasDatosService::class)->ejecutar('comparar_proveedores', ['texto' => 'IC5260']));
    }
}
