<?php

namespace Tests\Feature;

use App\Models\Configuracion;
use App\Models\OrdenCompra;
use App\Models\OrdenCompraItem;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\RetencionesService;
use App\Support\Fiscal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lo que la empresa le retiene a un proveedor al pagar una orden de compra.
 *
 * Es el espejo de las retenciones de venta: aquí quien paga —y por eso retiene— es la empresa, y
 * es su RUT el que decide si es agente de retención.
 */
class ComprasRetencionesTest extends TestCase
{
    use RefreshDatabase;

    private function proveedor(array $codigos = []): Proveedor
    {
        return Proveedor::create(['nombre' => 'Herrajes SAS', 'activo' => true, 'responsabilidades_fiscales' => $codigos ?: null]);
    }

    private function orden(Proveedor $proveedor, float $precio, float $iva = 19, string $tipo = 'producto'): OrdenCompra
    {
        $producto = Producto::create([
            'tipo' => $tipo, 'nombre' => 'Ítem '.uniqid(), 'referencia' => 'REF-'.uniqid(), 'unidad_medida' => 'unidad',
        ]);

        $orden = OrdenCompra::create([
            'estado' => 'borrador', 'proveedor_id' => $proveedor->id,
            'creado_por' => User::factory()->create(['rol' => 'administrador'])->id,
        ]);

        OrdenCompraItem::create([
            'orden_id' => $orden->id, 'item_id' => $producto->id, 'descripcion' => $producto->nombre,
            'cantidad' => 1, 'unidad' => 'unidad', 'precio_unitario' => $precio,
            'impuesto_pct' => $iva, 'total_linea' => $precio,
        ]);

        $orden->load('items')->recalcularTotales();

        return $orden->fresh();
    }

    private function empresa(array $codigos): void
    {
        Fiscal::guardarJson('fiscal_responsabilidades', $codigos);
        Configuracion::set('fiscal_uvt', '50000');
    }

    public function test_una_empresa_agente_retiene_renta_e_iva_al_proveedor(): void
    {
        $this->empresa(['07', '09', '48']);

        $r = app(RetencionesService::class)->paraCompra($this->orden($this->proveedor(['48']), 10000000));
        $valores = collect($r['lineas'])->pluck('valor', 'clave');

        $this->assertEquals(250000, $valores['retefuente_compras']); // 2,5 % de 10 millones
        $this->assertEquals(285000, $valores['reteiva']);             // 15 % de un IVA de 1.900.000
    }

    public function test_un_servicio_usa_el_concepto_de_servicios_y_su_base_minima(): void
    {
        $this->empresa(['07']);

        $r = app(RetencionesService::class)->paraCompra($this->orden($this->proveedor(['48']), 1000000, tipo: 'servicio'));

        $this->assertEquals(40000, collect($r['lineas'])->pluck('valor', 'clave')['retefuente_servicios']); // 4 %
    }

    public function test_si_la_empresa_no_es_agente_no_retiene_y_lo_dice(): void
    {
        $this->empresa(['48']);

        $r = app(RetencionesService::class)->paraCompra($this->orden($this->proveedor(['48']), 10000000));

        $this->assertSame([], $r['lineas']);
        $this->assertNotEmpty(collect($r['motivos'])->filter(fn ($m) => str_contains($m, 'no es agente de retención')));
    }

    public function test_al_autorretenedor_y_al_regimen_simple_no_se_les_retiene_renta(): void
    {
        $this->empresa(['07']);

        foreach (['15', '47'] as $codigo) {
            $r = app(RetencionesService::class)->paraCompra($this->orden($this->proveedor([$codigo, '48']), 10000000));

            $this->assertArrayNotHasKey('retefuente_compras', collect($r['lineas'])->pluck('valor', 'clave')->all(), $codigo);
        }
    }

    public function test_a_un_proveedor_no_responsable_de_iva_no_se_le_retiene_iva(): void
    {
        $this->empresa(['07', '09']);

        $r = app(RetencionesService::class)->paraCompra($this->orden($this->proveedor(['49']), 10000000, iva: 0));

        $this->assertArrayNotHasKey('reteiva', collect($r['lineas'])->pluck('valor', 'clave')->all());
    }

    public function test_sin_rut_del_proveedor_se_calcula_pero_se_avisa(): void
    {
        $this->empresa(['07']);

        $r = app(RetencionesService::class)->paraCompra($this->orden($this->proveedor(), 10000000));

        $this->assertEquals(250000, collect($r['lineas'])->pluck('valor', 'clave')['retefuente_compras']);
        $this->assertNotEmpty(collect($r['avisos'])->filter(fn ($m) => str_contains($m, 'no tiene cargado su RUT')));
    }

    public function test_sin_perfil_fiscal_de_la_empresa_no_se_inventa_nada(): void
    {
        $r = app(RetencionesService::class)->paraCompra($this->orden($this->proveedor(['48']), 10000000));

        $this->assertFalse($r['aplica']);
        $this->assertSame([], $r['lineas']);
    }

    public function test_la_ficha_de_la_orden_las_manda_a_la_pantalla(): void
    {
        $this->empresa(['07']);
        $orden = $this->orden($this->proveedor(['48']), 10000000);

        $this->actingAs(User::factory()->create(['rol' => 'administrador']))
            ->get("/compras/ordenes/{$orden->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('retenciones.total', 250000));
    }
}
