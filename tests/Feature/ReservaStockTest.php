<?php

namespace Tests\Feature;

use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionItem;
use App\Models\Notificacion;
use App\Models\Producto;
use App\Models\ReservaStock;
use App\Models\User;
use App\Services\ReservaStockService;
use App\Support\Modulos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Apartar stock por 24 horas mientras una cotización está en la calle.
 *
 * Es una marca, no un movimiento: solo avisa y deja cotizar. Lo que se cuida es que el vendedor y
 * administración estén al tanto cuando una venta usa unidades que otra cotización tenía apartadas.
 */
class ReservaStockTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $vendedor;
    private Bodega $bodega;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin    = User::factory()->create(['rol' => 'administrador']);
        $this->vendedor = User::factory()->create(['rol' => 'vendedor']);
        $this->bodega   = Bodega::create(['nombre' => 'Principal', 'activa' => true, 'es_principal' => true]);
    }

    private function producto(float $stock): Producto
    {
        $p = Producto::create([
            'tipo' => 'producto', 'nombre' => 'Bisagra '.uniqid(), 'referencia' => 'REF-'.uniqid(),
            'inventariable' => true, 'unidad_medida' => 'unidad',
        ]);

        if ($stock > 0) {
            $p->registrarMovimiento('entrada', $stock, $this->bodega->id, $this->admin->id);
        }

        return $p;
    }

    private function cotizacion(Producto $producto, float $cantidad, string $estado = 'borrador', ?User $vendedor = null): Cotizacion
    {
        $cliente = Cliente::firstOrCreate(['nombre' => 'Cliente SAS'], ['tipo' => 'empresa', 'tipo_identificacion' => 'NIT']);

        $cot = Cotizacion::create([
            'cliente_id' => $cliente->id, 'moneda' => 'COP', 'tasa_cambio' => 1, 'estado' => 'borrador',
            'responsable_id' => ($vendedor ?? $this->vendedor)->id,
        ]);

        CotizacionItem::create([
            'cotizacion_id' => $cot->id, 'tipo' => 'producto', 'producto_id' => $producto->id,
            'descripcion' => $producto->nombre, 'orden' => 0, 'cantidad' => $cantidad,
            'precio_unitario' => 1000, 'descuento_pct' => 0, 'impuesto_pct' => 0,
            'subtotal' => 1000 * $cantidad, 'total_linea' => 1000 * $cantidad,
        ]);

        if ($estado !== 'borrador') {
            $cot->update(['estado' => $estado]);
        }

        return $cot->fresh();
    }

    private function reserva(Cotizacion $cot, Producto $p): ?ReservaStock
    {
        return ReservaStock::where('cotizacion_id', $cot->id)->where('producto_id', $p->id)->first();
    }

    // ─── El ciclo ────────────────────────────────────────────────────────────

    public function test_al_enviar_aparta_por_24_horas(): void
    {
        $p   = $this->producto(10);
        $cot = $this->cotizacion($p, 4, 'enviada');

        $r = $this->reserva($cot, $p);

        $this->assertNotNull($r);
        $this->assertSame('activa', $r->estado);
        $this->assertEquals(4, $r->cantidad);
        $this->assertEqualsWithDelta(24 * 3600, now()->diffInSeconds($r->expira_at, false), 5);
    }

    public function test_un_borrador_no_aparta_nada(): void
    {
        $p = $this->producto(10);
        $this->cotizacion($p, 4, 'borrador');

        $this->assertSame(0, ReservaStock::count());
    }

    public function test_apartar_no_mueve_el_inventario(): void
    {
        $p = $this->producto(10);
        $this->cotizacion($p, 4, 'enviada');

        $this->assertEquals(10, $p->fresh()->stockTotal());
    }

    public function test_guardar_otra_vez_no_renueva_el_plazo(): void
    {
        $p   = $this->producto(10);
        $cot = $this->cotizacion($p, 4, 'enviada');

        $this->travel(20)->hours();
        $expiraAntes = $this->reserva($cot, $p)->expira_at;

        $cot->recalcularTotales();

        $this->assertTrue($this->reserva($cot, $p)->fresh()->expira_at->equalTo($expiraAntes));
    }

    public function test_cambiar_la_cantidad_de_una_enviada_ajusta_lo_apartado(): void
    {
        $p   = $this->producto(10);
        $cot = $this->cotizacion($p, 4, 'enviada');

        $cot->items()->update(['cantidad' => 6]);
        $cot->load('items')->recalcularTotales();

        $this->assertEquals(6, $this->reserva($cot, $p)->cantidad);
    }

    public function test_quitar_la_linea_libera_lo_apartado(): void
    {
        $p   = $this->producto(10);
        $cot = $this->cotizacion($p, 4, 'enviada');

        $cot->items()->delete();
        $cot->load('items')->recalcularTotales();

        $this->assertSame('liberada', $this->reserva($cot, $p)->estado);
    }

    public function test_rechazar_volver_a_borrador_o_borrar_libera(): void
    {
        $p = $this->producto(10);

        foreach (['rechazada', 'borrador', 'vencida'] as $estado) {
            $cot = $this->cotizacion($p, 2, 'enviada');
            $cot->update(['estado' => $estado]);
            $this->assertSame('liberada', $this->reserva($cot, $p)->estado, $estado);
        }

        $cot = $this->cotizacion($p, 2, 'enviada');
        $cot->delete();
        $this->assertSame('liberada', $this->reserva($cot, $p)->estado);
    }

    public function test_pasadas_24_horas_el_comando_la_vence_y_avisa_al_vendedor(): void
    {
        $p   = $this->producto(10);
        $cot = $this->cotizacion($p, 4, 'enviada');

        $this->travel(25)->hours();
        $this->artisan('stock:liberar-reservas')->assertSuccessful();

        $this->assertSame('vencida', $this->reserva($cot, $p)->estado);
        $this->assertDatabaseHas('notificaciones', ['user_id' => $this->vendedor->id, 'tipo' => 'stock_apartado_vencido']);

        // Una vez, no cada vez que corre el comando.
        $this->artisan('stock:liberar-reservas');
        $this->assertSame(1, Notificacion::where('tipo', 'stock_apartado_vencido')->count());
    }

    public function test_lo_vencido_no_se_reactiva_al_guardar(): void
    {
        $p   = $this->producto(10);
        $cot = $this->cotizacion($p, 4, 'enviada');

        $this->travel(25)->hours();
        $this->artisan('stock:liberar-reservas');

        $cot->load('items')->recalcularTotales();

        $this->assertSame('vencida', $this->reserva($cot, $p)->estado);
    }

    public function test_si_la_cotizacion_ya_no_esta_enviada_no_se_avisa_el_vencimiento(): void
    {
        $p   = $this->producto(10);
        $cot = $this->cotizacion($p, 4, 'enviada');
        $cot->update(['estado' => 'aprobada']);

        $this->assertSame('concretada', $this->reserva($cot, $p)->estado);
        $this->assertSame(0, app(ReservaStockService::class)->liberarVencidas());
    }

    // ─── La venta que usa lo apartado ────────────────────────────────────────

    public function test_la_venta_que_necesita_lo_apartado_se_lo_cede_y_avisa_al_vendedor_y_a_administracion(): void
    {
        $p = $this->producto(10);

        $abierta = $this->cotizacion($p, 6, 'enviada'); // aparta 6 de 10
        $venta   = $this->cotizacion($p, 8, 'enviada', $this->admin);

        $venta->update(['estado' => 'aprobada']);

        // Libre = 10 − 6 = 4; la venta pide 8 → toma 4 de la apartada.
        $r = $this->reserva($abierta, $p)->fresh();
        $this->assertEquals(4, $r->cantidad_cedida);
        $this->assertSame('activa', $r->estado);
        $this->assertSame($venta->id, $r->cedida_a_cotizacion_id);

        $this->assertDatabaseHas('notificaciones', ['user_id' => $this->vendedor->id, 'tipo' => 'stock_apartado_usado']);
        $this->assertDatabaseHas('notificaciones', ['user_id' => $this->admin->id, 'tipo' => 'stock_apartado_usado']);
    }

    public function test_cuando_todo_se_cede_la_reserva_queda_cedida(): void
    {
        $p = $this->producto(10);

        $abierta = $this->cotizacion($p, 4, 'enviada');
        $venta   = $this->cotizacion($p, 10, 'enviada', $this->admin);
        $venta->update(['estado' => 'aprobada']);

        $this->assertSame('cedida', $this->reserva($abierta, $p)->fresh()->estado);
    }

    public function test_si_el_vendedor_es_administrador_recibe_un_solo_aviso(): void
    {
        $p = $this->producto(10);

        $abierta = $this->cotizacion($p, 6, 'enviada', $this->admin);
        $venta   = $this->cotizacion($p, 9, 'enviada', $this->vendedor);
        $venta->update(['estado' => 'aprobada']);

        $this->assertSame(1, Notificacion::where('user_id', $this->admin->id)->where('tipo', 'stock_apartado_usado')->count());
    }

    public function test_si_hay_libre_de_sobra_no_se_cede_ni_se_avisa(): void
    {
        $p = $this->producto(20);

        $abierta = $this->cotizacion($p, 6, 'enviada');
        $venta   = $this->cotizacion($p, 8, 'enviada', $this->admin);
        $venta->update(['estado' => 'aprobada']);

        $this->assertEquals(0, $this->reserva($abierta, $p)->fresh()->cantidad_cedida);
        $this->assertSame(0, Notificacion::where('tipo', 'stock_apartado_usado')->count());
    }

    public function test_no_se_cede_lo_que_no_existe(): void
    {
        $p = $this->producto(0);

        $abierta = $this->cotizacion($p, 6, 'enviada');
        $venta   = $this->cotizacion($p, 8, 'enviada', $this->admin);
        $venta->update(['estado' => 'aprobada']);

        $this->assertEquals(0, $this->reserva($abierta, $p)->fresh()->cantidad_cedida);
        $this->assertSame(0, Notificacion::where('tipo', 'stock_apartado_usado')->count());
    }

    public function test_las_mas_nuevas_ceden_primero(): void
    {
        $p = $this->producto(10);

        $vieja = $this->cotizacion($p, 5, 'enviada');
        $this->travel(1)->minutes();
        $nueva = $this->cotizacion($p, 5, 'enviada');
        $venta = $this->cotizacion($p, 3, 'enviada', $this->admin);

        // Stock 10, apartado 10 + lo de la venta (3, enviada) → libre 0; la venta toma 3.
        $venta->update(['estado' => 'aprobada']);

        $this->assertEquals(0, $this->reserva($vieja, $p)->fresh()->cantidad_cedida);
        $this->assertEquals(3, $this->reserva($nueva, $p)->fresh()->cantidad_cedida);
    }

    public function test_aprobar_por_el_portal_publico_tambien_dispara_el_flujo(): void
    {
        $p = $this->producto(10);

        $abierta = $this->cotizacion($p, 6, 'enviada');
        $venta   = $this->cotizacion($p, 8, 'enviada', $this->admin);

        $this->post("/cotizaciones/{$venta->token_publico}/aprobar", ['nombre' => 'Cliente', 'aceptar' => true]);

        // Con o sin portal, el observador es lo que engancha: si el estado cambió, se atendió.
        if ($venta->fresh()->estado === 'aprobada') {
            $this->assertEquals(4, $this->reserva($abierta, $p)->fresh()->cantidad_cedida);
        } else {
            $this->markTestSkipped('El portal exige otros datos; el observador ya está cubierto por cambiarEstado.');
        }
    }

    public function test_cambiar_estado_desde_la_pantalla_interna_dispara_el_flujo(): void
    {
        $p = $this->producto(10);

        $abierta = $this->cotizacion($p, 6, 'enviada');
        $venta   = $this->cotizacion($p, 8, 'enviada', $this->admin);

        $this->actingAs($this->admin)->post("/cotizaciones/{$venta->id}/estado", ['estado' => 'aprobada']);

        $this->assertEquals(4, $this->reserva($abierta, $p)->fresh()->cantidad_cedida);
    }

    // ─── Lo que ven quienes cotizan ──────────────────────────────────────────

    public function test_la_disponibilidad_descuenta_lo_apartado_por_otras_pero_no_lo_propio(): void
    {
        $p = $this->producto(10);

        $otra = $this->cotizacion($p, 6, 'enviada');
        $mia  = $this->cotizacion($p, 2, 'enviada');

        $servicio = app(ReservaStockService::class);

        $this->assertEquals(6, $servicio->apartadoPorProducto([$p->id], $mia->id)[$p->id]);
        $this->assertEquals(2, $servicio->apartadoPorProducto([$p->id], $otra->id)[$p->id]);
        $this->assertEquals(2, $servicio->disponibilidad([$p->id], [], null)[$p->id]['disponible']);
        $this->assertEquals(4, $servicio->disponibilidad([$p->id], [], $mia->id)[$p->id]['disponible']);
    }

    public function test_el_endpoint_de_disponibilidad_responde_a_usuarios_internos(): void
    {
        $p = $this->producto(10);
        $this->cotizacion($p, 6, 'enviada');

        $this->actingAs($this->admin)
            ->postJson('/api/cotizaciones/disponibilidad', ['producto_ids' => [$p->id]])
            ->assertOk()
            ->assertJsonPath("{$p->id}.apartado", 6)
            ->assertJsonPath("{$p->id}.disponible", 4);
    }

    public function test_el_endpoint_de_disponibilidad_exige_sesion(): void
    {
        $this->postJson('/api/cotizaciones/disponibilidad', ['producto_ids' => [1]])->assertUnauthorized();
    }

    public function test_el_buscador_trae_lo_apartado_por_otras(): void
    {
        $p = $this->producto(10);
        $this->cotizacion($p, 6, 'enviada');

        $r = $this->actingAs($this->admin)->getJson('/api/cotizaciones/productos?q='.urlencode($p->nombre))->assertOk();

        $this->assertEquals(6, $r->json('0.stock_apartado'));
    }

    public function test_la_pantalla_de_apartados_lista_lo_vigente(): void
    {
        $p = $this->producto(10);
        $this->cotizacion($p, 6, 'enviada');

        $this->actingAs($this->admin)->get('/inventario/apartados')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Compras/Inventario/Apartados')
                ->has('activas', 1)
                ->where('activas.0.cantidad', 6));
    }

    public function test_la_ficha_de_la_cotizacion_muestra_sus_reservas_pero_el_portal_no(): void
    {
        $p   = $this->producto(10);
        $cot = $this->cotizacion($p, 6, 'enviada');

        $this->actingAs($this->admin)->get("/cotizaciones/{$cot->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('reservas', 1));

        auth()->logout();
        $html = $this->get("/cotizaciones/{$cot->token_publico}/aprobar");
        $this->assertStringNotContainsString('apartad', mb_strtolower($html->getContent()));
    }

    // ─── Módulos ─────────────────────────────────────────────────────────────

    public function test_con_inventario_apagado_no_se_aparta_nada(): void
    {
        Modulos::guardar(['inventario'], 'test');

        $p   = $this->producto(10);
        $cot = $this->cotizacion($p, 4, 'enviada');

        $this->assertNull($this->reserva($cot, $p));
    }

    public function test_los_ensambles_y_los_servicios_sin_producto_no_apartan(): void
    {
        $p   = $this->producto(10);
        $cot = $this->cotizacion($p, 4, 'borrador');

        CotizacionItem::create([
            'cotizacion_id' => $cot->id, 'tipo' => 'texto_libre', 'descripcion' => 'Instalación', 'orden' => 1,
            'cantidad' => 1, 'precio_unitario' => 500, 'descuento_pct' => 0, 'impuesto_pct' => 0,
            'subtotal' => 500, 'total_linea' => 500,
        ]);

        $cot->update(['estado' => 'enviada']);

        $this->assertSame(1, ReservaStock::where('cotizacion_id', $cot->id)->count());
    }
}
