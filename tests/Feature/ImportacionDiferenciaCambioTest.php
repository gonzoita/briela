<?php

namespace Tests\Feature;

use App\Models\Importacion;
use App\Models\ImportacionPago;
use App\Models\User;
use App\Services\DiferenciaCambioService;
use App\Services\LiquidacionImportacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las tres tasas de una importación: la de la factura, la de la declaración (con la que sale el
 * costo) y la de cada pago al proveedor. La distancia entre las dos últimas es la diferencia en
 * cambio, y no mueve el costo.
 */
class ImportacionDiferenciaCambioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['rol' => 'administrador']));
    }

    /** Una factura de 1.000 USD (10 × 100) a 4.000, nacionalizada a 4.100. */
    private function cuerpo(array $extra = []): array
    {
        return $extra + [
            'fecha' => '2026-10-01', 'moneda' => 'USD', 'tasa' => 4000, 'tasa_fecha' => '2026-09-20',
            'tasa_nacionalizacion' => 4100, 'tasa_nacionalizacion_fecha' => '2026-10-01',
            'metodo_prorrateo' => 'valor', 'iva_descontable' => true,
            'items' => [[
                'descripcion' => 'Bisagra', 'cantidad' => 10, 'valor_unitario' => 100, 'arancel_pct' => 0, 'iva_pct' => 19,
            ]],
            'costos' => [],
        ];
    }

    private function importacion(array $extra = []): Importacion
    {
        $this->post('/compras/importaciones', $this->cuerpo($extra))->assertSessionHasNoErrors();

        return Importacion::latest('id')->firstOrFail();
    }

    public function test_el_costo_sale_con_la_tasa_de_nacionalizacion(): void
    {
        $imp = $this->importacion();

        $r = app(LiquidacionImportacionService::class)->calcular(app(LiquidacionImportacionService::class)->entradaDe($imp->load(['items', 'costos'])));

        // 1.000 USD × 4.100, no × 4.000.
        $this->assertEquals(4100000, $r['totales']['fob']);
        $this->assertEquals(4100000, $r['totales']['costo_total']);
    }

    public function test_sin_tasa_de_nacionalizacion_el_costo_sale_con_la_de_la_factura(): void
    {
        $imp = $this->importacion(['tasa_nacionalizacion' => null, 'tasa_nacionalizacion_fecha' => null]);

        $this->assertEquals(4000, $imp->tasaDelCosto());
        $r = app(LiquidacionImportacionService::class)->calcular(app(LiquidacionImportacionService::class)->entradaDe($imp->load(['items', 'costos'])));
        $this->assertEquals(4000000, $r['totales']['costo_total']);
    }

    public function test_cada_pago_deja_su_diferencia_contra_la_tasa_del_costo(): void
    {
        $imp = $this->importacion();

        // Anticipo de 300 a 4.000: se pagó menos que el costo → ganancia de 30.000.
        $this->post("/compras/importaciones/{$imp->id}/pagos", ['fecha' => '2026-09-20', 'concepto' => 'anticipo', 'valor' => 300, 'tasa' => 4000])
            ->assertSessionHasNoErrors();
        // Saldo de 700 a 4.250: se pagó más → pérdida de 105.000.
        $this->post("/compras/importaciones/{$imp->id}/pagos", ['fecha' => '2026-10-20', 'concepto' => 'saldo', 'valor' => 700, 'tasa' => 4250])
            ->assertSessionHasNoErrors();

        $r = app(DiferenciaCambioService::class)->resumen($imp->fresh());

        $this->assertTrue($r['aplica']);
        $this->assertEquals(1000, $r['factura']);
        $this->assertEquals(1000, $r['pagado']);
        $this->assertEquals(0, $r['saldo']);
        $this->assertEquals(-30000, $r['pagos'][0]['diferencia']);
        $this->assertEquals(105000, $r['pagos'][1]['diferencia']);
        // 1.200.000 + 2.975.000 pagados, contra 4.100.000 del costo: 75.000 de pérdida.
        $this->assertEquals(4175000, $r['pagado_base']);
        $this->assertEquals(4100000, $r['al_costo_base']);
        $this->assertEquals(75000, $r['diferencia']);
        $this->assertSame('perdida', $r['resultado']);
    }

    public function test_los_pagos_no_mueven_el_costo_y_se_registran_aun_liquidada(): void
    {
        $imp = $this->importacion();
        app(LiquidacionImportacionService::class)->liquidar($imp);

        $this->post("/compras/importaciones/{$imp->id}/pagos", ['fecha' => '2026-10-20', 'concepto' => 'saldo', 'valor' => 1000, 'tasa' => 4500])
            ->assertSessionHasNoErrors();

        $this->assertEquals(4100000, (float) $imp->fresh()->total_costo);
        $this->assertSame('perdida', app(DiferenciaCambioService::class)->resumen($imp->fresh())['resultado']);
    }

    public function test_pagar_de_mas_avisa_y_sin_tasa_de_nacionalizacion_tambien(): void
    {
        $imp = $this->importacion(['tasa_nacionalizacion' => null]);
        $imp->pagos()->create(['fecha' => '2026-10-20', 'concepto' => 'pago', 'valor' => 1200, 'tasa' => 4000]);

        $r = app(DiferenciaCambioService::class)->resumen($imp->fresh());

        $this->assertEquals(-200, $r['saldo']);
        $this->assertCount(2, $r['avisos']);
        $this->assertNull($r['resultado'], 'misma tasa que la factura: sin diferencia');
    }

    public function test_en_la_moneda_base_no_hay_pagos_con_diferencia(): void
    {
        $imp = $this->importacion(['moneda' => 'COP', 'tasa' => 1]);

        $this->post("/compras/importaciones/{$imp->id}/pagos", ['fecha' => '2026-10-20', 'concepto' => 'pago', 'valor' => 100, 'tasa' => 1])
            ->assertSessionHas('error');
        $this->assertNull($imp->fresh()->tasa_nacionalizacion, 'en la moneda base no se guarda tasa de nacionalización');
        $this->assertSame(0, ImportacionPago::count());
    }

    public function test_un_pago_se_elimina(): void
    {
        $imp = $this->importacion();
        $pago = $imp->pagos()->create(['fecha' => '2026-10-20', 'concepto' => 'pago', 'valor' => 100, 'tasa' => 4000]);

        $this->delete("/compras/importaciones/pagos/{$pago->id}")->assertSessionHasNoErrors();

        $this->assertSame(0, ImportacionPago::count());
    }

    public function test_la_ficha_trae_el_resumen_de_cambio(): void
    {
        $imp = $this->importacion();

        $this->get("/compras/importaciones/{$imp->id}")->assertOk()->assertInertia(fn ($p) => $p
            ->where('cambio.aplica', true)
            ->where('cambio.tasa_costo', 4100)
            ->where('importacion.tasa_nacionalizacion', 4100));
    }
}
