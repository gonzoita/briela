<?php

namespace Tests\Feature;

use App\Models\CanalPrecio;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionItem;
use App\Models\Producto;
use App\Models\SegmentacionOpcion;
use App\Models\Sede;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Duplicar una cotización —o cambiarle el cliente— lleva sus precios al canal que le corresponde al
 * cliente por su segmento. Lo negociado se recorta al rango del canal nuevo, no se pierde.
 */
class CotizacionRepreciaPorSegmentoTest extends TestCase
{
    use RefreshDatabase;

    private SegmentacionOpcion $mayorista;
    private SegmentacionOpcion $directo;
    private Producto $producto;

    protected function setUp(): void
    {
        parent::setUp();

        Sede::query()->first() ?? Sede::create(['nombre' => 'Principal', 'es_principal' => true]);
        $this->actingAs(User::factory()->create(['rol' => 'administrador']));

        // La instalación trae canales de fábrica con estos mismos nombres: se parte de cero.
        SegmentacionOpcion::where('tipo', 'tipo_contacto')->delete();

        $this->mayorista = SegmentacionOpcion::create(['tipo' => 'tipo_contacto', 'valor' => 'mayorista', 'etiqueta' => 'Mayorista', 'define_precio' => true, 'es_canal_base' => true, 'orden' => 1, 'activo' => true]);
        $this->directo   = SegmentacionOpcion::create(['tipo' => 'tipo_contacto', 'valor' => 'directo', 'etiqueta' => 'Cliente directo', 'define_precio' => true, 'es_precio_publico' => true, 'orden' => 2, 'activo' => true]);

        $this->producto = Producto::create(['tipo' => 'producto', 'nombre' => 'Bisagra', 'referencia' => 'B1', 'precio_costo' => 50000, 'unidad_medida' => 'unidad', 'activo' => true]);
        $fila = fn ($canal, $precio, $extra = []) => CanalPrecio::create($extra + ['precionable_type' => $this->producto->getMorphClass(),
            'precionable_id' => $this->producto->id, 'segmentacion_opcion_id' => $canal->id, 'margen_pct' => 20, 'precio' => $precio]);
        $fila($this->mayorista, 60000);
        $fila($this->directo, 75000, ['comision_min_pct' => 2, 'comision_max_pct' => 5, 'descuento_max_pct' => 8]);
    }

    private function cotizacion(Cliente $cliente, float $precio, array $linea = []): Cotizacion
    {
        $cot = Cotizacion::create(['cliente_id' => $cliente->id, 'moneda' => 'COP', 'tasa_cambio' => 1, 'estado' => 'borrador', 'responsable_id' => auth()->id()]);
        CotizacionItem::create($linea + [
            'cotizacion_id' => $cot->id, 'tipo' => 'producto', 'producto_id' => $this->producto->id, 'descripcion' => 'Bisagra', 'orden' => 0,
            'cantidad' => 2, 'precio_unitario' => $precio, 'descuento_pct' => 0, 'impuesto_pct' => 19,
            'subtotal' => 2 * $precio, 'total_linea' => 2 * $precio * 1.19,
        ]);
        $cot->load('items')->recalcularTotales();

        return $cot->fresh('items');
    }

    private function cliente(string $nombre, array $tipos): Cliente
    {
        return Cliente::create(['nombre' => $nombre, 'tipo' => 'empresa', 'tipo_identificacion' => 'NIT', 'tipos_contacto' => $tipos]);
    }

    public function test_duplicar_con_el_cliente_en_otro_segmento_reprecia_la_copia(): void
    {
        $directo = $this->cliente('Cliente directo SAS', ['directo']);
        $original = $this->cotizacion($directo, 60000); // se cotizó con el precio del mayorista

        $this->post("/cotizaciones/{$original->id}/duplicar")->assertRedirect();

        $copia = Cotizacion::where('id', '!=', $original->id)->latest('id')->first();
        $linea = $copia->items->first();

        $this->assertEquals(75000, (float) $linea->precio_unitario, 'el precio del canal de su cliente');
        $this->assertEquals(60000, (float) $linea->precio_mayorista_base);
        $this->assertEquals(5, (float) $linea->comision_pct_aplicada, 'sin nada negociado arranca en el máximo del canal');
        $this->assertEquals(2 * 75000 * 1.19, (float) $linea->total_linea);
        $this->assertEquals(150000, (float) $copia->subtotal);
        $this->assertEquals(60000, (float) $original->fresh()->items->first()->precio_unitario, 'la original no se toca');
    }

    public function test_el_descuento_negociado_se_recorta_al_tope_del_canal(): void
    {
        $mayorista = $this->cliente('Mayorista SAS', ['mayorista']);
        $original  = $this->cotizacion($mayorista, 60000, ['descuento_pct' => 12]);

        // Se le cambia el cliente a uno directo, cuyo canal admite hasta 8 %.
        $directo = $this->cliente('Cliente directo SAS', ['directo']);
        $original->update(['cliente_id' => $directo->id]);

        $r = app(\App\Services\RepreciarCotizacionService::class)->aplicar($original->fresh());

        $linea = $original->fresh('items')->items->first();
        $this->assertSame(1, $r['lineas']);
        $this->assertSame('Cliente directo', $r['canal']);
        $this->assertEquals(8, (float) $linea->descuento_pct);
    }

    public function test_el_canal_base_no_paga_comision_ni_admite_descuento(): void
    {
        $directo   = $this->cliente('Cliente directo SAS', ['directo']);
        $original  = $this->cotizacion($directo, 75000, ['descuento_pct' => 5, 'comision_pct_aplicada' => 4]);
        $original->update(['cliente_id' => $this->cliente('Mayorista SAS', ['mayorista'])->id]);

        app(\App\Services\RepreciarCotizacionService::class)->aplicar($original->fresh());

        $linea = $original->fresh('items')->items->first();
        $this->assertEquals([60000.0, 0.0, 0.0], [(float) $linea->precio_unitario, (float) $linea->descuento_pct, (float) $linea->comision_pct_aplicada]);
    }

    public function test_un_cliente_sin_segmento_se_cotiza_al_precio_publico(): void
    {
        $sinTipo  = $this->cliente('Sin segmento SAS', []);
        $original = $this->cotizacion($sinTipo, 60000);

        app(\App\Services\RepreciarCotizacionService::class)->aplicar($original);

        $this->assertEquals(75000, (float) $original->fresh('items')->items->first()->precio_unitario);
    }

    public function test_la_pantalla_de_editar_marca_las_lineas_que_se_pueden_repreciar(): void
    {
        $original = $this->cotizacion($this->cliente('Cliente directo SAS', ['directo']), 75000);

        $this->get("/cotizaciones/{$original->id}/editar")->assertOk()->assertInertia(fn ($p) => $p
            ->where('cotizacion.items.0.repreciable', true));
    }
}
