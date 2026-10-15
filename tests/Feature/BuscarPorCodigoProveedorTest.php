<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\ProductoProveedor;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\User;
use App\Services\BuscadorGlobalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un producto se encuentra por el código con que lo llama su proveedor, en todos los buscadores:
 * la lista, la cotización, la orden de compra, la importación y Ctrl+K. Y se dice cuál coincidió.
 */
class BuscarPorCodigoProveedorTest extends TestCase
{
    use RefreshDatabase;

    private Producto $bisagra;

    protected function setUp(): void
    {
        parent::setUp();

        Sede::query()->first() ?? Sede::create(['nombre' => 'Principal', 'es_principal' => true]);
        $this->actingAs(User::factory()->create(['rol' => 'administrador']));

        $this->bisagra = $this->producto('Bisagra cazoleta 35 mm', 'BIS-35');
        $this->producto('Tirador pasante', 'TIR-01');

        ProductoProveedor::create([
            'producto_id' => $this->bisagra->id, 'referencia_proveedor' => 'HT-4410', 'precio' => 1000,
            'proveedor_id' => Proveedor::create(['nombre' => 'Herrajes del Norte', 'activo' => true])->id,
        ]);
    }

    private function producto(string $nombre, string $referencia, array $extra = []): Producto
    {
        return Producto::create($extra + [
            'tipo' => 'producto', 'nombre' => $nombre, 'referencia' => $referencia,
            'es_insumo' => true, 'es_vendible' => true, 'unidad_medida' => 'unidad', 'activo' => true,
        ]);
    }

    public function test_la_regla_unica_encuentra_por_nombre_referencia_y_codigo_de_proveedor(): void
    {
        $this->assertSame([$this->bisagra->id], Producto::buscarTexto('HT-44')->pluck('id')->all());
        $this->assertSame([$this->bisagra->id], Producto::buscarTexto('BIS-35')->pluck('id')->all());
        $this->assertCount(2, Producto::buscarTexto('')->get(), 'sin texto no filtra');
        $this->assertCount(0, Producto::buscarTexto('%')->get(), 'el comodín se busca como texto');
    }

    public function test_la_lista_de_productos_lo_encuentra_y_dice_por_que_codigo(): void
    {
        $this->get('/productos?buscar=HT-4410')->assertOk()->assertInertia(fn ($p) => $p
            ->has('productos.data', 1)
            ->where('productos.data.0.id', $this->bisagra->id)
            ->where('productos.data.0.codigo_proveedor.codigo', 'HT-4410')
            ->where('productos.data.0.codigo_proveedor.proveedor', 'Herrajes del Norte'));
    }

    public function test_la_lista_encuentra_al_padre_por_el_codigo_de_una_variante(): void
    {
        $padre    = $this->producto('Lámina', 'LAM', ['es_padre' => true, 'atributo_variante' => 'Espesor']);
        $variante = $this->producto('Lámina', 'LAM-40', ['producto_padre_id' => $padre->id, 'valor_variante' => '40 mm']);
        ProductoProveedor::create(['producto_id' => $variante->id, 'referencia_proveedor' => 'ZX-900', 'precio' => 5,
            'proveedor_id' => Proveedor::create(['nombre' => 'Aceros', 'activo' => true])->id]);

        $this->get('/productos?buscar=ZX-900')->assertOk()->assertInertia(fn ($p) => $p
            ->has('productos.data', 1)
            ->where('productos.data.0.id', $padre->id)
            ->where('productos.data.0.codigo_proveedor.codigo', 'ZX-900'));
    }

    public function test_la_cotizacion_y_la_importacion_lo_encuentran(): void
    {
        $cot = $this->getJson('/api/cotizaciones/productos?q=HT-4410')->assertOk()->json();
        $this->assertSame($this->bisagra->id, $cot[0]['id']);
        $this->assertSame('HT-4410', $cot[0]['codigo_proveedor']['codigo']);

        $imp = $this->getJson('/api/importaciones/productos?q=HT-4410')->assertOk()->json();
        $this->assertSame($this->bisagra->id, $imp[0]['id']);

        $sel = $this->getJson('/api/productos/buscar?q=HT-4410')->assertOk()->json();
        $this->assertSame($this->bisagra->id, $sel[0]['id']);
    }

    public function test_la_orden_de_compra_lo_encuentra(): void
    {
        $oc = $this->getJson('/compras/ordenes/buscar-productos?q=HT-4410')->assertOk()->json();

        $this->assertSame($this->bisagra->id, $oc[0]['id']);
    }

    public function test_ctrl_k_lo_encuentra_y_lo_dice(): void
    {
        $r = app(BuscadorGlobalService::class)->buscar('HT-4410', ['producto']);

        $producto = collect($r['grupos'] ?? $r)->firstWhere('tipo', 'producto');
        $this->assertNotNull($producto);
        $this->assertSame('Bisagra cazoleta 35 mm', $producto['resultados'][0]['titulo']);
        $this->assertStringContainsString('código de Herrajes del Norte: HT-4410', $producto['resultados'][0]['detalle']);
    }

    /** Cada resultado de Ctrl+K tiene que abrir una pantalla que exista: dos llevaban a un 405 y un 404. */
    public function test_los_enlaces_de_proveedores_y_solicitudes_abren_su_lista_filtrada(): void
    {
        \App\Models\SolicitudCompra::create(['numero' => 'SC-9001', 'estado' => 'aprobada', 'solicitado_por' => User::first()->id, 'sede_id' => \App\Models\Sede::first()->id]);

        $buscar = fn (string $t) => collect(app(BuscadorGlobalService::class)->buscar($t));

        $url = $buscar('Herrajes')->firstWhere('tipo', 'proveedor')['resultados'][0]['url'];
        $this->assertStringStartsWith('/compras/proveedores?buscar=', $url);
        $this->get($url)->assertOk();

        $url = $buscar(\App\Models\SolicitudCompra::first()->numero)->first(fn ($g) => str_contains($g['resultados'][0]['url'], 'solicitudes'))['resultados'][0]['url'];
        $this->get($url)->assertOk();
    }
}
