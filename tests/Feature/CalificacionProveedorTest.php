<?php

namespace Tests\Feature;

use App\Models\OrdenCompra;
use App\Models\OrdenCompraItem;
use App\Models\OrdenCompraRecepcion;
use App\Models\Producto;
use App\Models\ProductoProveedor;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\CalificacionProveedorService;
use App\Services\IA\ConsultasDatosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La calificación de un proveedor sale de lo que de verdad pasó, no de lo que alguien escribe.
 */
class CalificacionProveedorTest extends TestCase
{
    use RefreshDatabase;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuario = User::factory()->create(['rol' => 'administrador']);
    }

    private function proveedor(string $nombre = 'Herrajes del Norte'): Proveedor
    {
        return Proveedor::create(['nombre' => $nombre, 'activo' => true]);
    }

    private function dias(int $n): string
    {
        return today()->addDays($n)->toDateString();
    }

    /**
     * @param  list<array{fecha: string, factura?: string, remision?: string}>  $entregas
     */
    private function orden(Proveedor $proveedor, string $estado, ?string $esperada, array $entregas = [], float $pedido = 10, ?float $recibido = null): OrdenCompra
    {
        $orden = OrdenCompra::create([
            'estado' => $estado, 'proveedor_id' => $proveedor->id, 'creado_por' => $this->usuario->id,
            'fecha_entrega_esperada' => $esperada,
        ]);

        OrdenCompraItem::create([
            'orden_id' => $orden->id, 'descripcion' => 'Bisagra', 'cantidad' => $pedido,
            'cantidad_recibida' => $recibido ?? ($estado === 'recibida' ? $pedido : 0),
            'unidad' => 'unidad', 'precio_unitario' => 1, 'total_linea' => $pedido,
        ]);

        foreach ($entregas as $e) {
            OrdenCompraRecepcion::create([
                'orden_compra_id' => $orden->id, 'fecha_recepcion' => $e['fecha'],
                'factura_numero' => $e['factura'] ?? null, 'remision_numero' => $e['remision'] ?? null,
            ]);
        }

        return $orden;
    }

    /** Tres órdenes iguales: el mínimo para que haya nota. */
    private function tresOrdenes(Proveedor $proveedor, int $diasDePlazo, int $diasDeLlegada, array $papel = []): void
    {
        foreach (range(1, 3) as $_) {
            $this->orden($proveedor, 'recibida', $this->dias($diasDePlazo), [array_merge(['fecha' => $this->dias($diasDeLlegada)], $papel)]);
        }
    }

    private function nota(Proveedor $proveedor): array
    {
        return app(CalificacionProveedorService::class)->paraProveedor($proveedor->id);
    }

    // ─── Sin datos no hay nota ───────────────────────────────────────────────

    public function test_sin_ordenes_no_hay_nota_y_dice_cuantas_faltan(): void
    {
        $nota = $this->nota($this->proveedor());

        $this->assertNull($nota['puntaje']);
        $this->assertNull($nota['nivel']);
        $this->assertSame(0, $nota['muestras']);
        $this->assertStringContainsString('0 de 3', $nota['mensaje']);
    }

    public function test_dos_ordenes_no_alcanzan_para_calificar(): void
    {
        $proveedor = $this->proveedor();

        foreach (range(1, 2) as $_) {
            $this->orden($proveedor, 'recibida', $this->dias(-20), [['fecha' => $this->dias(-21), 'factura' => 'F']]);
        }

        $nota = $this->nota($proveedor);

        $this->assertNull($nota['puntaje'], 'Una nota con dos órdenes es una anécdota con cara de estadística.');
        $this->assertSame(2, $nota['muestras']);
    }

    public function test_una_orden_que_aun_tiene_plazo_no_es_ni_buena_ni_mala(): void
    {
        $proveedor = $this->proveedor();
        $this->orden($proveedor, 'enviada', $this->dias(+10));
        $this->orden($proveedor, 'enviada', null);

        $this->assertSame(0, $this->nota($proveedor)['muestras']);
    }

    // ─── El proveedor ejemplar y el que no ───────────────────────────────────

    public function test_el_que_llega_a_tiempo_completo_y_con_papel_saca_cien(): void
    {
        $proveedor = $this->proveedor();
        $this->tresOrdenes($proveedor, -20, -21, ['factura' => 'FE-1']);

        $nota = $this->nota($proveedor);

        $this->assertSame(100, $nota['puntaje']);
        $this->assertSame('excelente', $nota['nivel']);
        $this->assertSame(3, $nota['muestras']);
        $this->assertSame(100, $nota['componentes']['puntualidad']['valor']);
        $this->assertSame(100, $nota['componentes']['cumplimiento']['valor']);
        $this->assertSame(100, $nota['componentes']['en_regla']['valor']);
        $this->assertNull($nota['componentes']['precio']['valor'], 'Nadie le ha comparado el precio.');
    }

    public function test_un_componente_sin_datos_no_cuenta_como_cero(): void
    {
        // Sin precio comparable, el 100 de los otros tres no se baja a 80 por un dato que no existe.
        $proveedor = $this->proveedor();
        $this->tresOrdenes($proveedor, -20, -21, ['remision' => 'R-1']);

        $this->assertSame(100, $this->nota($proveedor)['puntaje']);
    }

    public function test_el_que_llega_tarde_y_sin_papel_queda_deficiente(): void
    {
        $proveedor = $this->proveedor();
        $this->tresOrdenes($proveedor, -30, -20);   // 10 días tarde, sin factura ni remisión

        $nota = $this->nota($proveedor);

        $this->assertSame(0, $nota['componentes']['puntualidad']['valor']);
        $this->assertSame(100, $nota['componentes']['cumplimiento']['valor'], 'Completó lo pedido, aunque tarde.');
        $this->assertSame(0, $nota['componentes']['en_regla']['valor']);
        // (0·30 + 100·25 + 0·25) / 80
        $this->assertSame(31, $nota['puntaje']);
        $this->assertSame('deficiente', $nota['nivel']);
    }

    public function test_llegar_con_papel_pero_tarde_no_cuenta_como_en_regla(): void
    {
        $proveedor = $this->proveedor();
        $this->tresOrdenes($proveedor, -30, -20, ['factura' => 'FE-1']);

        $this->assertSame(0, $this->nota($proveedor)['componentes']['en_regla']['valor']);
    }

    public function test_llegar_a_tiempo_pero_sin_papel_tampoco(): void
    {
        $proveedor = $this->proveedor();
        $this->tresOrdenes($proveedor, -20, -21);

        $nota = $this->nota($proveedor);

        $this->assertSame(100, $nota['componentes']['puntualidad']['valor']);
        $this->assertSame(0, $nota['componentes']['en_regla']['valor'], 'El mismo día, pero sin nada que lo respalde.');
    }

    // ─── Puntualidad ─────────────────────────────────────────────────────────

    public function test_hasta_tres_dias_tarde_vale_la_mitad(): void
    {
        $proveedor = $this->proveedor();
        $this->tresOrdenes($proveedor, -30, -28, ['factura' => 'F']);   // 2 días tarde

        $this->assertSame(50, $this->nota($proveedor)['componentes']['puntualidad']['valor']);
    }

    public function test_una_orden_vencida_sin_llegar_completa_cuenta_como_incumplida(): void
    {
        $proveedor = $this->proveedor();

        foreach (range(1, 3) as $_) {
            // Pasó la fecha y llegó la mitad.
            $this->orden($proveedor, 'recibida_parcial', $this->dias(-5), [['fecha' => $this->dias(-6), 'factura' => 'F']], 10, 5);
        }

        $nota = $this->nota($proveedor);

        $this->assertSame(3, $nota['muestras']);
        $this->assertSame(0, $nota['componentes']['puntualidad']['valor']);
        $this->assertSame(50, $nota['componentes']['cumplimiento']['valor']);
    }

    public function test_lo_que_sobra_en_una_linea_no_compensa_lo_que_falta_en_otra(): void
    {
        $proveedor = $this->proveedor();

        foreach (range(1, 3) as $_) {
            $orden = $this->orden($proveedor, 'recibida', $this->dias(-20), [['fecha' => $this->dias(-21), 'factura' => 'F']], 10, 10);
            OrdenCompraItem::create([
                'orden_id' => $orden->id, 'descripcion' => 'Tornillo', 'cantidad' => 10, 'cantidad_recibida' => 4,
                'unidad' => 'unidad', 'precio_unitario' => 1, 'total_linea' => 10,
            ]);
        }

        // 10 de 10 + 4 de 10 = 14 de 20.
        $this->assertSame(70, $this->nota($proveedor)['componentes']['cumplimiento']['valor']);
    }

    // ─── Entregas en regla: por entrega, no por orden ────────────────────────

    public function test_se_juega_por_entrega_una_con_papel_y_una_sin(): void
    {
        $proveedor = $this->proveedor();

        foreach (range(1, 3) as $_) {
            $this->orden($proveedor, 'recibida', $this->dias(-20), [
                ['fecha' => $this->dias(-22), 'remision' => 'R-1'],
                ['fecha' => $this->dias(-21)],
            ]);
        }

        $this->assertSame(50, $this->nota($proveedor)['componentes']['en_regla']['valor']);
    }

    public function test_lo_anterior_al_registro_por_entregas_se_juzga_en_puntualidad_pero_no_en_papel(): void
    {
        $proveedor = $this->proveedor();

        foreach (range(1, 3) as $_) {
            $orden = $this->orden($proveedor, 'recibida', $this->dias(-20));
            $orden->update(['fecha_recepcion' => $this->dias(-21)]);   // la fecha de la orden, sin filas de entrega
        }

        $nota = $this->nota($proveedor);

        $this->assertSame(100, $nota['componentes']['puntualidad']['valor']);
        $this->assertNull($nota['componentes']['en_regla']['valor'], 'No se sabe qué papel traía: no se juzga.');
        $this->assertSame(100, $nota['puntaje']);
    }

    // ─── Ventana ─────────────────────────────────────────────────────────────

    public function test_solo_cuenta_el_ultimo_anio(): void
    {
        $proveedor = $this->proveedor();

        foreach (range(1, 3) as $_) {
            $orden = $this->orden($proveedor, 'recibida', $this->dias(-800), [['fecha' => $this->dias(-780)]]);
            DB::table('ordenes_compra')->where('id', $orden->id)->update(['created_at' => now()->subDays(800)]);
        }

        $this->assertSame(0, $this->nota($proveedor)['muestras'], 'Un proveedor que mejoró no carga con lo de hace dos años.');
    }

    public function test_las_ordenes_en_borrador_o_canceladas_no_cuentan(): void
    {
        $proveedor = $this->proveedor();

        foreach (['borrador', 'cancelada'] as $estado) {
            $this->orden($proveedor, $estado, $this->dias(-20));
        }

        $this->assertSame(0, $this->nota($proveedor)['muestras']);
    }

    // ─── Precio ──────────────────────────────────────────────────────────────

    private function precio(Producto $producto, Proveedor $proveedor, float $precio, ?string $fecha = null): void
    {
        ProductoProveedor::create([
            'producto_id' => $producto->id, 'proveedor_id' => $proveedor->id,
            'precio' => $precio, 'actualizado_el' => $fecha ?? today()->toDateString(),
        ]);
    }

    private function producto(string $nombre): Producto
    {
        return Producto::create(['tipo' => 'producto', 'nombre' => $nombre, 'referencia' => 'R-'.uniqid(), 'es_insumo' => true]);
    }

    public function test_el_precio_se_mide_contra_lo_mas_barato_vigente(): void
    {
        $barato = $this->proveedor('Barato');
        $caro   = $this->proveedor('Caro');
        $bisagra = $this->producto('Bisagra');

        $this->precio($bisagra, $barato, 1000);
        $this->precio($bisagra, $caro, 1250);

        $notas = app(CalificacionProveedorService::class)->paraProveedores([$barato->id, $caro->id]);

        $this->assertSame(100, $notas[$barato->id]['componentes']['precio']['valor']);
        $this->assertSame(80, $notas[$caro->id]['componentes']['precio']['valor']);
        $this->assertStringContainsString('1 de 1', $notas[$barato->id]['componentes']['precio']['texto']);
    }

    public function test_un_producto_que_vende_uno_solo_no_se_puede_comparar(): void
    {
        $unico = $this->proveedor('Único');
        $this->precio($this->producto('Exclusivo'), $unico, 5000);

        $this->assertNull($this->nota($unico)['componentes']['precio']['valor'], 'Ser el más barato entre uno es no decir nada.');
    }

    public function test_un_precio_viejo_no_entra_a_la_comparacion(): void
    {
        $viejo = $this->proveedor('Viejo');
        $nuevo = $this->proveedor('Nuevo');
        $bisagra = $this->producto('Bisagra');

        $this->precio($bisagra, $viejo, 500, today()->subDays(300)->toDateString());   // el «más barato», pero de hace diez meses
        $this->precio($bisagra, $nuevo, 1000);

        // Con el viejo fuera no hay con quién comparar al nuevo.
        $this->assertNull($this->nota($nuevo)['componentes']['precio']['valor']);
    }

    // ─── Donde se ve ─────────────────────────────────────────────────────────

    public function test_la_lista_de_proveedores_trae_la_nota_de_cada_uno(): void
    {
        $proveedor = $this->proveedor();
        $this->tresOrdenes($proveedor, -20, -21, ['factura' => 'F']);

        $this->actingAs($this->usuario)->get('/compras/proveedores')
            ->assertInertia(fn ($page) => $page
                ->where('proveedores.data.0.calificacion.puntaje', 100)
                ->where('proveedores.data.0.calificacion.nivel', 'excelente'));
    }

    public function test_la_orden_de_compra_recibe_la_nota_de_cada_proveedor(): void
    {
        $this->proveedor('Sin historia');

        $this->actingAs($this->usuario)->get('/compras/ordenes/crear')
            ->assertInertia(fn ($page) => $page
                ->where('proveedores.0.calificacion.puntaje', null)
                ->where('proveedores.0.calificacion.muestras', 0));
    }

    public function test_el_asistente_ve_la_nota_junto_al_precio(): void
    {
        $bueno = $this->proveedor('Cumplido');
        $malo  = $this->proveedor('Barato pero tarde');
        $bisagra = $this->producto('Bisagra IC5260');

        $this->precio($bisagra, $bueno, 1200);
        $this->precio($bisagra, $malo, 900);

        $this->tresOrdenes($bueno, -20, -21, ['factura' => 'F']);
        $this->tresOrdenes($malo, -30, -10);

        $this->actingAs($this->usuario);

        $producto = app(ConsultasDatosService::class)->ejecutar('comparar_proveedores', ['texto' => 'IC5260'])['productos'][0];
        $porNombre = collect($producto['proveedores'])->keyBy('proveedor');

        // El más barato es el que llega tarde y sin papel: el asistente tiene las dos cosas a la vista.
        $this->assertSame('Barato pero tarde', $producto['mas_barato']);
        $this->assertSame('excelente', $porNombre['Cumplido']['calificacion']['nivel']);
        $this->assertSame('deficiente', $porNombre['Barato pero tarde']['calificacion']['nivel']);
        $this->assertSame(0, $porNombre['Barato pero tarde']['calificacion']['detalle']['puntualidad']);
    }
}
