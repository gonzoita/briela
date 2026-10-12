<?php

namespace Tests\Feature;

use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionItem;
use App\Models\Op;
use App\Models\OpItem;
use App\Models\OpPago;
use App\Models\Producto;
use App\Models\Remision;
use App\Models\Sede;
use App\Models\User;
use App\Services\PdfVariablesEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lo que pidió la planta el 11 oct 2026:
 *  - ninguna cotización ni orden se procesa sin cliente;
 *  - de 15 vendidas se alistan 10, se remisionan esas 10 y quedan 5 pendientes, dicho en la remisión;
 *  - la remisión entregada se cierra sola cuando la orden queda pagada con pagos verificados;
 *  - la firma de la remisión funciona y sale en el PDF.
 */
class AlistamientoParcialYCierreTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Sede $sede;
    private Bodega $bodega;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin  = User::factory()->create(['rol' => 'administrador']);
        $this->sede   = Sede::query()->first() ?? Sede::create(['nombre' => 'Principal']);
        $this->sede->update(['tiene_produccion' => true, 'activa' => true]);
        $this->bodega = Bodega::create(['sede_id' => $this->sede->id, 'nombre' => 'Principal', 'activa' => true, 'es_principal' => true]);
        $this->actingAs($this->admin);
    }

    /** Una orden de 15 bisagras, con 20 en la bodega. */
    private function orden(int $cantidad = 15, ?int $clienteId = -1, float $total = 150000): array
    {
        $op = Op::create([
            'cliente_id' => $clienteId === -1 ? $this->clientePrueba()->id : $clienteId,
            'sede_id' => $this->sede->id, 'estado' => 'borrador', 'responsable_id' => $this->admin->id,
            'fecha_creacion' => now(), 'total' => $total,
        ]);
        $p = Producto::create(['tipo' => 'producto', 'nombre' => 'Bisagra', 'referencia' => 'B-'.uniqid(), 'unidad_medida' => 'unidad', 'inventariable' => true, 'activo' => true]);
        $p->registrarMovimiento('entrada', 20, $this->bodega->id, $this->admin->id);
        $item = OpItem::create([
            'op_id' => $op->id, 'tipo' => 'producto', 'producto_id' => $p->id, 'descripcion' => 'Bisagra',
            'cantidad' => $cantidad, 'precio_unitario' => 10000, 'subtotal' => 10000 * $cantidad, 'total_linea' => 10000 * $cantidad,
            'orden' => 0, 'estado_item' => 'pendiente',
        ]);

        return [$op, $item, $p];
    }

    private function remisionar(Op $op, OpItem $item, int $unidades)
    {
        return $this->post('/logistica/remisiones', [
            'tipo' => 'op', 'op_id' => $op->id,
            'items' => [['descripcion' => $item->descripcion, 'cantidad' => $unidades, 'cantidad_unidades' => $unidades, 'op_item_id' => $item->id]],
        ]);
    }

    // ─── Cliente obligatorio ─────────────────────────────────────────────────

    public function test_no_se_guarda_una_cotizacion_sin_cliente(): void
    {
        $this->post('/cotizaciones', [
            'moneda' => 'COP', 'tasa_cambio' => 1, 'fecha_creacion' => today()->toDateString(),
            'fecha_validez' => today()->addDays(30)->toDateString(), 'responsable_id' => $this->admin->id, 'items' => [],
        ])->assertSessionHasErrors('cliente_id');

        $this->assertSame(0, Cotizacion::count());
    }

    public function test_una_cotizacion_vieja_sin_cliente_no_se_envia_ni_genera_orden(): void
    {
        $cot = Cotizacion::create(['moneda' => 'COP', 'tasa_cambio' => 1, 'estado' => 'borrador', 'responsable_id' => $this->admin->id]);

        $this->post("/cotizaciones/{$cot->id}/estado", ['estado' => 'enviada'])->assertSessionHas('error');
        $this->assertSame('borrador', $cot->fresh()->estado);

        $cot->update(['estado' => 'aprobada']);
        $this->post("/produccion/ops/desde-cotizacion/{$cot->id}")->assertSessionHas('error');
        $this->assertSame(0, Op::count());
    }

    public function test_no_se_guarda_una_orden_sin_cliente(): void
    {
        $this->post('/produccion/ops', [
            'responsable_id' => $this->admin->id, 'fecha_creacion' => today()->toDateString(), 'items' => [],
        ])->assertSessionHasErrors('cliente_id');
    }

    public function test_una_orden_vieja_sin_cliente_no_se_alista_ni_remisiona(): void
    {
        [$op, $item] = $this->orden(15, null);

        $this->assertSame(Op::SIN_CLIENTE, $item->alistar());
        $this->remisionar($op, $item, 5)->assertSessionHasErrors('op_id');
        $this->assertSame(0, Remision::count());
    }

    // ─── Alistamiento parcial ────────────────────────────────────────────────

    public function test_de_15_se_alistan_10_y_se_remisionan_esas_10_con_5_pendientes(): void
    {
        [$op, $item, $p] = $this->orden();

        $this->patch("/produccion/alistamiento/{$item->id}", ['alistado' => true, 'cantidad' => 10])->assertSessionHas('success');

        $item->refresh();
        $this->assertSame('en_proceso', $item->estado_item);
        $this->assertSame(10, $item->cantidadAlistada());
        $this->assertSame(10, $item->cantidadLista());
        // Alistar en parte también es trabajo real: la orden arranca.
        $this->assertSame('en_produccion', $op->fresh()->estado);

        $this->remisionar($op, $item, 10)->assertSessionHasNoErrors();

        $linea = Remision::latest('id')->firstOrFail()->items()->firstOrFail();
        $this->assertEquals(5, (float) $linea->cantidad_pendiente);
        // Al crear la remisión, lo que lleva salió de la empresa.
        $this->assertSame(10.0, (float) $p->fresh()->stockTotal());

        $item->refresh();
        $this->assertSame(0, $item->cantidadLista());
        $this->assertSame(5, $item->cantidadDisponible());
        $this->assertSame('en_proceso', $item->estado_item);
        $this->assertNotSame('calidad', $op->fresh()->estado); // faltan 5: no está todo listo

        // Las otras 5, después.
        $item->alistar();
        $this->remisionar($op, $item, 5)->assertSessionHasNoErrors();
        $this->assertEquals(0, (float) Remision::latest('id')->firstOrFail()->items()->firstOrFail()->cantidad_pendiente);
        $this->assertSame('terminado', $item->fresh()->estado_item);
    }

    public function test_la_remision_de_una_parte_lo_dice_en_su_pdf(): void
    {
        [$op, $item] = $this->orden();
        $item->alistar(true, 10);
        $this->remisionar($op, $item, 10);

        $datos = PdfVariablesEngine::prepararDatos('remision', Remision::latest('id')->firstOrFail());

        $this->assertSame('Pendiente por entregar: 5', $datos['items'][0]['pendiente']);
    }

    public function test_desalistar_no_baja_de_lo_que_ya_salio(): void
    {
        [$op, $item] = $this->orden();
        $item->alistar(true, 10);
        $this->remisionar($op, $item, 6);

        $this->assertNull($item->fresh()->alistar(false));
        $this->assertSame(6, $item->fresh()->cantidadAlistada());
        $this->assertNotNull($item->fresh()->alistar(false)); // ya no queda nada que devolver
    }

    // ─── Cierre por pago ─────────────────────────────────────────────────────

    private function entregada(Op $op, OpItem $item): Remision
    {
        $item->alistar();
        $this->remisionar($op, $item, 15)->assertSessionHasNoErrors();
        $r = Remision::latest('id')->firstOrFail();
        $this->patch("/logistica/remisiones/{$r->id}/estado", ['estado' => 'en_camino']);
        $this->patch("/logistica/remisiones/{$r->id}/estado", ['estado' => 'entregada', 'nombre_receptor' => 'Laura']);

        return $r->fresh();
    }

    public function test_la_remision_entregada_se_cierra_sola_al_quedar_pagada_y_verificada(): void
    {
        [$op, $item] = $this->orden();
        $r = $this->entregada($op, $item);
        $this->assertSame('entregada', $r->estado);

        // Anotado pero sin verificar: todavía no.
        $pago = OpPago::registrar($op, ['valor' => 150000, 'medio_pago' => 'efectivo', 'fecha_pago' => today()->toDateString()]);
        $this->assertSame('entregada', $r->fresh()->estado);

        $this->postJson("/financiero/pagos/{$pago->id}/verificar")->assertOk();
        $this->assertSame('cerrada', $r->fresh()->estado);
        $this->assertNotNull($r->fresh()->cerrada_at);

        // Deshacer la verificación la reabre: la orden vuelve a deber.
        $this->deleteJson("/financiero/pagos/{$pago->id}/verificar")->assertOk();
        $this->assertSame('entregada', $r->fresh()->estado);
    }

    public function test_un_abono_parcial_verificado_no_cierra(): void
    {
        [$op, $item] = $this->orden();
        $r = $this->entregada($op, $item);

        OpPago::registrar($op, ['valor' => 100000, 'medio_pago' => 'transferencia', 'fecha_pago' => today()->toDateString(), 'verificado_at' => now()]);

        $this->assertSame('entregada', $r->fresh()->estado);
    }

    public function test_si_ya_estaba_pagada_se_cierra_al_entregarse(): void
    {
        [$op, $item] = $this->orden();
        OpPago::registrar($op, ['valor' => 150000, 'medio_pago' => 'transferencia', 'fecha_pago' => today()->toDateString(), 'verificado_at' => now()]);

        $this->assertSame('cerrada', $this->entregada($op, $item)->estado);
    }

    public function test_una_remision_cerrada_no_se_anula_ni_se_elige_a_mano(): void
    {
        [$op, $item] = $this->orden(15, -1, 0);
        $r = $this->entregada($op, $item);
        $this->assertSame('cerrada', $r->estado); // orden sin valor: nada por cobrar

        $this->patch("/logistica/remisiones/{$r->id}/estado", ['estado' => 'anulada'])->assertSessionHas('error');
        $this->assertSame('cerrada', $r->fresh()->estado);
    }

    // ─── Firma de la remisión ────────────────────────────────────────────────

    public function test_la_firma_de_despacho_se_guarda_con_quien_firmo_y_sale_en_el_pdf(): void
    {
        [$op, $item] = $this->orden();
        $item->alistar();
        $this->remisionar($op, $item, 15);
        $r = Remision::latest('id')->firstOrFail();
        $this->patch("/logistica/remisiones/{$r->id}/estado", ['estado' => 'confirmada']);

        $firma = 'data:image/png;base64,iVBORw0KGgo=';
        $this->post("/logistica/remisiones/{$r->id}/firma", ['tipo' => 'despacho', 'firma' => $firma])->assertSessionHas('success');

        $r->refresh();
        $this->assertSame($firma, $r->firma_despacho);
        $this->assertSame($this->admin->id, (int) $r->firma_despacho_por);

        $datos = PdfVariablesEngine::prepararDatos('remision', $r);
        $this->assertStringContainsString('<img src="'.$firma, $datos['firma.despacho']);
        $this->assertSame($this->admin->name, $datos['despachado_por.nombre']);
    }

    public function test_una_firma_que_no_es_imagen_se_rechaza(): void
    {
        [$op, $item] = $this->orden();
        $item->alistar();
        $this->remisionar($op, $item, 15);
        $r = Remision::latest('id')->firstOrFail();
        $this->patch("/logistica/remisiones/{$r->id}/estado", ['estado' => 'confirmada']);

        $this->post("/logistica/remisiones/{$r->id}/firma", ['tipo' => 'despacho', 'firma' => '<script>alert(1)</script>'])
            ->assertSessionHasErrors('firma');
        $this->assertNull($r->fresh()->firma_despacho);
    }

    public function test_sin_firmar_la_remision_sale_con_la_firma_del_perfil_de_quien_la_hizo(): void
    {
        $firma = 'data:image/png;base64,QUJD';
        $this->admin->update(['firma' => $firma]);

        [$op, $item] = $this->orden();
        $item->alistar();
        $this->remisionar($op, $item, 15);

        $datos = PdfVariablesEngine::prepararDatos('remision', Remision::latest('id')->firstOrFail());
        $this->assertStringContainsString($firma, $datos['firma.despacho']);
    }

    public function test_el_pdf_de_la_remision_se_genera_con_firmas(): void
    {
        $this->seed(\Database\Seeders\PdfPlantillasSeeder::class);
        [$op, $item] = $this->orden();
        $item->alistar();
        $this->remisionar($op, $item, 15);
        $r = Remision::latest('id')->firstOrFail();

        $this->get("/logistica/remisiones/{$r->id}/pdf")->assertOk();
    }
}
