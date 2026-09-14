<?php

namespace Tests\Feature;

use App\Models\Bodega;
use App\Models\Ensamble;
use App\Models\Op;
use App\Models\OpItem;
use App\Models\Producto;
use App\Models\Sede;
use App\Models\User;
use App\Services\CierrePasoService;
use App\Services\TrabajoAutoGeneratorService;
use App\Support\Modulos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Encender y apagar módulos.
 *
 * Lo que fijan: que las dependencias se arrastran en las dos direcciones, que un módulo apagado
 * se lleva sus permisos y sus rutas —también las públicas—, que gana el cambio más reciente al
 * sincronizar con el panel, y que el flujo de producción se salta los pasos de un módulo apagado
 * en vez de quedarse esperando algo que nadie puede hacer.
 */
class ModulosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Modulos::olvidar();
    }

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'administrador']);
    }

    // ── Dependencias y permisos ──────────────────────────────────────────────

    public function test_apagar_un_modulo_apaga_los_que_dependen_de_el(): void
    {
        Modulos::guardar(['cotizaciones'], 'instalacion');

        $this->assertFalse(Modulos::activo('cotizaciones'));
        $this->assertFalse(Modulos::activo('comisiones'));
        $this->assertTrue(Modulos::activo('crm'));
    }

    public function test_un_modulo_apagado_se_lleva_sus_permisos_y_nada_mas(): void
    {
        Modulos::guardar(['calidad', 'cotizaciones'], 'instalacion');

        $permisos = $this->admin()->permisos();

        $this->assertNotContains('ops.calidad', $permisos);
        $this->assertNotContains('cotizaciones.ver', $permisos);
        $this->assertNotContains('comisiones.ver', $permisos);
        $this->assertContains('ops.ver', $permisos);
        $this->assertContains('clientes.ver', $permisos);
    }

    public function test_encender_un_modulo_enciende_aquello_de_lo_que_depende(): void
    {
        Modulos::guardar(['cotizaciones'], 'instalacion');

        $this->actingAs($this->admin())
            ->post('/configuracion/modulos', ['clave' => 'comisiones', 'activo' => true])
            ->assertRedirect();

        Modulos::olvidar();

        $this->assertTrue(Modulos::activo('comisiones'));
        $this->assertTrue(Modulos::activo('cotizaciones'));
    }

    // ── Rutas ────────────────────────────────────────────────────────────────

    public function test_la_ruta_de_un_modulo_apagado_devuelve_al_tablero(): void
    {
        Modulos::guardar(['crm'], 'instalacion');

        $this->actingAs($this->admin())
            ->get('/crm')
            ->assertRedirect('/dashboard')
            ->assertSessionHas('error');
    }

    public function test_el_portal_publico_de_un_modulo_apagado_no_existe(): void
    {
        Modulos::guardar(['ops'], 'instalacion');

        $this->get('/seguimiento')->assertNotFound();
    }

    // ── Sincronización con el panel ──────────────────────────────────────────

    public function test_gana_el_cambio_mas_reciente(): void
    {
        Modulos::guardar(['rrss'], 'instalacion', now()->subHour()->toIso8601String());

        // Uno más viejo que lo de aquí no pisa nada.
        $this->assertFalse(Modulos::sincronizarDesde(['apagados' => [], 'cambiado_at' => now()->subDay()->toIso8601String()]));
        $this->assertFalse(Modulos::activo('rrss'));

        // Uno más nuevo, sí.
        $this->assertTrue(Modulos::sincronizarDesde(['apagados' => ['mantenimiento'], 'cambiado_at' => now()->toIso8601String()]));
        $this->assertTrue(Modulos::activo('rrss'));
        $this->assertFalse(Modulos::activo('mantenimiento'));
        $this->assertSame('superadmin', Modulos::estado()['origen']);
    }

    // ── El flujo se salta los pasos apagados ─────────────────────────────────

    /** Una OP con un ensamble directo de un solo paso, que es el final. */
    private function unidad(bool $conBodegas = true): array
    {
        $usuario = $this->admin();
        $sede    = Sede::query()->first() ?? Sede::create(['nombre' => 'Principal']);
        $bodega  = Bodega::create(['sede_id' => $sede->id, 'nombre' => 'Principal', 'activa' => true]);

        $ensamble = Ensamble::create([
            'nombre'                => 'Mesa de prueba',
            'tipo_armado'           => 'directo',
            'variables'             => [],
            'creado_por'            => $usuario->id,
            'unidad_medida'         => 'unidad',
            'precio_costo'          => 10000,
            'componentes_resultado' => [],
        ]);

        $ensamble->obtenerOCrearTemplateTrabajo()->sincronizarPasos([
            ['nombre' => 'Armar', 'peso_porcentaje' => 100, 'orden' => 0, 'es_paso_final' => true],
        ]);

        $op = Op::create([
            'sede_id'                => $sede->id,
            'estado'                 => 'en_produccion',
            'responsable_id'         => $usuario->id,
            'bodega_entrega_id'      => $conBodegas ? $bodega->id : null,
            'bodega_material_id'     => $conBodegas ? $bodega->id : null,
            'fecha_creacion'         => now(),
            'fecha_entrega_estimada' => now()->addDays(5),
        ]);

        $item = OpItem::create([
            'op_id'                => $op->id,
            'ensamble_id'          => $ensamble->id,
            'descripcion'          => 'Mesa de prueba',
            'cantidad'             => 1,
            'precio_unitario'      => 100000,
            'subtotal'             => 100000,
            'total_linea'          => 100000,
            'orden'                => 0,
            'componentes_snapshot' => [],
        ]);

        app(TrabajoAutoGeneratorService::class)->generarParaItem($item);

        $this->actingAs($usuario);

        return ['op' => $op, 'trabajo' => $item->trabajos()->firstOrFail(), 'ensamble' => $ensamble];
    }

    public function test_sin_calidad_la_unidad_terminada_queda_lista_para_remisionar(): void
    {
        Modulos::guardar(['calidad'], 'instalacion');

        $u = $this->unidad();

        app(CierrePasoService::class)->cerrar($u['trabajo']->pasos()->firstOrFail());

        $this->assertNotNull($u['trabajo']->fresh()->calidad_revisada_at);
        $this->assertNotNull($u['op']->fresh()->calidad_aprobada_at);
    }

    public function test_con_calidad_la_unidad_terminada_espera_la_firma(): void
    {
        $u = $this->unidad();

        app(CierrePasoService::class)->cerrar($u['trabajo']->pasos()->firstOrFail());

        $this->assertNull($u['trabajo']->fresh()->calidad_revisada_at);
    }

    public function test_sin_inventario_el_paso_final_cierra_sin_bodegas_ni_movimientos(): void
    {
        Modulos::guardar(['inventario'], 'instalacion');

        $u = $this->unidad(conBodegas: false);

        $bodega = app(CierrePasoService::class)->cerrar($u['trabajo']->pasos()->firstOrFail());

        $this->assertNull($bodega);
        $this->assertSame(100.0, (float) $u['trabajo']->fresh()->porcentaje_avance);
        $this->assertNull($u['trabajo']->fresh()->entregado_at);
        $this->assertFalse(Producto::where('ensamble_id', $u['ensamble']->id)->exists());
    }
}
