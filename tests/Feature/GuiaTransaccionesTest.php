<?php

namespace Tests\Feature;

use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CotizacionItem;
use App\Models\Ensamble;
use App\Models\Op;
use App\Models\OpCuota;
use App\Models\OpItem;
use App\Models\Producto;
use App\Models\Remision;
use App\Models\Sede;
use App\Models\User;
use App\Services\IA\TransaccionesIaService;
use App\Services\TrabajoAutoGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La guía hace transacciones, pero solo con confirmación y con los mismos servicios que las
 * pantallas: proponer no escribe nada; confirmar revisa permiso y estado otra vez.
 */
class GuiaTransaccionesTest extends TestCase
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

    private function svc(): TransaccionesIaService
    {
        return app(TransaccionesIaService::class);
    }

    private function opConProducto(int $cantidad = 4, int $stock = 10): array
    {
        $op = Op::create([
            'sede_id' => $this->sede->id, 'estado' => 'confirmada', 'responsable_id' => $this->admin->id,
            'bodega_entrega_id' => $this->bodega->id, 'bodega_material_id' => $this->bodega->id, 'fecha_creacion' => now(),
        ]);
        $p = Producto::create(['tipo' => 'producto', 'nombre' => 'Bisagra IC5260', 'referencia' => 'B-'.uniqid(), 'unidad_medida' => 'unidad', 'inventariable' => true, 'activo' => true]);
        $p->registrarMovimiento('entrada', $stock, $this->bodega->id, $this->admin->id);
        $item = OpItem::create([
            'op_id' => $op->id, 'tipo' => 'producto', 'producto_id' => $p->id, 'descripcion' => 'Bisagra IC5260',
            'cantidad' => $cantidad, 'precio_unitario' => 1000, 'subtotal' => 1000, 'total_linea' => 1000, 'orden' => 0, 'estado_item' => 'pendiente',
        ]);

        return [$op->fresh(), $item, $p];
    }

    /** Propone, comprueba que no escribió, y confirma por el endpoint como lo haría la tarjeta. */
    private function proponerYConfirmar(string $accion, array $params): array
    {
        $propuesta = $this->svc()->proponer($accion, $params);
        $this->assertTrue($propuesta['exito'] ?? false, $propuesta['falta'] ?? 'sin propuesta');

        return $this->postJson('/api/asistente/confirmar', [
            'accion' => $accion, 'parametros' => $propuesta['propuesta']['parametros'],
        ])->assertOk()->json();
    }

    public function test_proponer_no_escribe_nada(): void
    {
        [$op, $item] = $this->opConProducto();

        $r = $this->svc()->proponer('alistar', ['op' => $op->numero]);

        $this->assertTrue($r['exito']);
        $this->assertStringContainsString('Bisagra', $r['propuesta']['resumen']);
        $this->assertSame('pendiente', $item->fresh()->estado_item);
    }

    public function test_alistar_y_remisionar_desde_la_guia(): void
    {
        [$op, $item, $p] = $this->opConProducto(4, 10);
        $numero = (int) preg_replace('/\D/', '', $op->numero); // «la OP 1» también sirve

        $this->proponerYConfirmar('alistar', ['op' => (string) $numero]);
        $this->assertSame('terminado', $item->fresh()->estado_item);

        $r = $this->proponerYConfirmar('remisionar', ['op' => $op->numero]);
        $this->assertTrue($r['exito']);
        $this->assertSame(6.0, (float) $p->fresh()->stockTotal());

        $remision = Remision::latest('id')->firstOrFail();
        $this->proponerYConfirmar('mover_remision', ['remision' => $remision->numero, 'estado' => 'en camino']);
        $this->assertSame('despachada', $op->fresh()->estado);
    }

    public function test_registrar_un_pago_abona_a_la_cuota(): void
    {
        [$op] = $this->opConProducto();
        $cuota = OpCuota::create(['op_id' => $op->id, 'numero_cuota' => 1, 'concepto' => 'Anticipo', 'valor' => 500000, 'estado' => 'pendiente', 'valor_pagado' => 0]);

        $this->proponerYConfirmar('registrar_pago', ['op' => $op->numero, 'valor' => '$250.000', 'medio_pago' => 'transferencia']);

        $this->assertEquals(250000, (float) $cuota->fresh()->valor_pagado);
        $this->assertSame('parcial', $cuota->fresh()->estado);
    }

    public function test_generar_la_op_de_una_cotizacion_aprobada(): void
    {
        $cliente = Cliente::create(['tipo' => 'empresa', 'nombre' => 'Frío Andino', 'tipo_identificacion' => 'NIT']);
        $cot = Cotizacion::create(['cliente_id' => $cliente->id, 'moneda' => 'COP', 'tasa_cambio' => 1, 'estado' => 'aprobada', 'responsable_id' => $this->admin->id, 'dias_entrega' => 5]);
        CotizacionItem::create(['cotizacion_id' => $cot->id, 'tipo' => 'texto_libre', 'descripcion' => 'Instalación', 'orden' => 0, 'cantidad' => 1, 'precio_unitario' => 1000, 'descuento_pct' => 0, 'impuesto_pct' => 0, 'subtotal' => 1000, 'total_linea' => 1000]);

        $r = $this->proponerYConfirmar('generar_op', ['cotizacion' => $cot->numero]);

        $op = Op::where('cotizacion_id', $cot->id)->firstOrFail();
        $this->assertStringContainsString($op->numero, $r['mensaje']);
        $this->assertSame(today()->addWeekdays(5)->toDateString(), $op->fecha_entrega_estimada->toDateString());
    }

    public function test_cerrar_los_pasos_de_una_unidad_y_aprobar_calidad(): void
    {
        $op = Op::create(['sede_id' => $this->sede->id, 'estado' => 'confirmada', 'responsable_id' => $this->admin->id, 'fecha_creacion' => now()]);
        $ens = Ensamble::create(['nombre' => 'Marco', 'tipo_armado' => 'directo', 'variables' => [], 'creado_por' => $this->admin->id, 'unidad_medida' => 'unidad', 'precio_costo' => 1000, 'componentes_resultado' => []]);
        $ens->obtenerOCrearTemplateTrabajo()->sincronizarPasos([
            ['nombre' => 'Cortar', 'peso_porcentaje' => 50, 'orden' => 0, 'es_paso_final' => false],
            ['nombre' => 'Armar',  'peso_porcentaje' => 50, 'orden' => 1, 'es_paso_final' => true],
        ]);
        $item = OpItem::create(['op_id' => $op->id, 'ensamble_id' => $ens->id, 'tipo' => 'ensamble', 'descripcion' => 'Marco', 'cantidad' => 1, 'precio_unitario' => 1, 'subtotal' => 1, 'total_linea' => 1, 'orden' => 0]);
        app(TrabajoAutoGeneratorService::class)->generarParaItem($item);

        $this->proponerYConfirmar('cerrar_paso', ['op' => $op->numero, 'paso' => 'cortar']);
        $this->assertSame('en_produccion', $op->fresh()->estado);

        $this->proponerYConfirmar('cerrar_paso', ['op' => $op->numero]); // el resto
        $this->assertSame('calidad', $op->fresh()->estado);

        $this->proponerYConfirmar('aprobar_calidad', ['op' => $op->numero]);
        $this->assertNotNull($op->fresh()->calidad_aprobada_at);
        $this->assertSame(1, $item->fresh()->cantidadDisponible());
    }

    public function test_sin_permiso_no_propone_ni_ejecuta(): void
    {
        [$op] = $this->opConProducto();
        $this->actingAs(User::factory()->create(['rol' => 'operario']));

        $this->assertNull($this->svc()->proponer('remisionar', ['op' => $op->numero]));
        $this->postJson('/api/asistente/confirmar', ['accion' => 'remisionar', 'parametros' => ['entrada' => ['op' => $op->numero]]])
            ->assertStatus(422)->assertJsonPath('exito', false);
        $this->assertSame(0, Remision::count());
    }

    public function test_lo_que_falta_se_pregunta(): void
    {
        $this->assertFalse($this->svc()->proponer('remisionar', [])['exito']);
        $this->assertStringContainsString('No encontré', $this->svc()->proponer('remisionar', ['op' => 'OP-9999'])['falta']);
    }

    public function test_si_algo_cambio_entre_la_propuesta_y_el_clic_no_se_hace(): void
    {
        [$op, $item] = $this->opConProducto();
        $propuesta = $this->svc()->proponer('alistar', ['op' => $op->numero])['propuesta'];

        $item->alistar(); // alguien lo alistó por la pantalla mientras tanto

        $this->postJson('/api/asistente/confirmar', ['accion' => 'alistar', 'parametros' => $propuesta['parametros']])
            ->assertStatus(422)->assertJsonPath('exito', false);
    }
}
