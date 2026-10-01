<?php

namespace Tests\Feature;

use App\Models\Cotizacion;
use App\Models\Ensamble;
use App\Models\PlantillaComponente;
use App\Models\PlantillaEnsamble;
use App\Models\Producto;
use App\Models\User;
use App\Services\FormulaEvaluatorService;
use App\Support\CostosReceta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * El costo de una receta no viaja a quien no tiene `costos.ver`.
 *
 * Las respuestas del cálculo y del buscador de ensambles anulaban el total, pero mandaban igual
 * la receta con el precio unitario y el subtotal de cada componente: sumarlos daba el costo
 * que la pantalla escondía. Estas pruebas miran la respuesta cruda, no la pantalla: lo que
 * importa es que el dato no salga del servidor.
 */
class CostosRecetaTest extends TestCase
{
    use RefreshDatabase;

    private Producto $lamina;
    private PlantillaEnsamble $plantilla;
    private Ensamble $ensamble;

    protected function setUp(): void
    {
        parent::setUp();

        $this->lamina = Producto::create([
            'nombre' => 'Lámina', 'referencia' => 'LAM-COSTO', 'tipo' => 'producto',
            'unidad_medida' => 'M2', 'precio_costo' => 1000, 'activo' => true,
        ]);

        $this->plantilla = PlantillaEnsamble::create(['nombre' => 'Puerta de prueba', 'activo' => true]);

        $this->plantilla->campos()->create([
            'nombre' => 'ancho', 'etiqueta' => 'Ancho', 'tipo' => 'decimal',
            'tipo_campo' => 'entrada', 'valor_defecto' => '1', 'orden' => 0,
        ]);
        $this->plantilla->campos()->create([
            'nombre' => 'area', 'etiqueta' => 'area', 'tipo' => 'decimal',
            'tipo_campo' => 'calculado', 'formula_calculo' => 'ancho * 2', 'orden' => 1,
        ]);

        PlantillaComponente::create([
            'plantilla_id' => $this->plantilla->id, 'producto_id' => $this->lamina->id,
            'etiqueta' => 'Lámina frontal', 'formula' => 'area', 'orden' => 0, 'activo' => true,
        ]);

        $receta = app(FormulaEvaluatorService::class)->calcularPlantilla($this->plantilla->id, ['ancho' => 1]);

        $this->ensamble = Ensamble::create([
            'nombre'                => 'Puerta 1 m',
            'tipo_armado'           => 'plantilla',
            'plantilla_id'          => $this->plantilla->id,
            'variables'             => ['ancho' => 1],
            'unidad_medida'         => 'unidad',
            'precio_costo'          => 2000,
            'componentes_resultado' => $receta,
            'creado_por'            => User::factory()->create(['rol' => 'administrador'])->id,
        ]);
    }

    private function vendedor(): User
    {
        // El vendedor cotiza (`cotizaciones.ver`) pero no tiene `costos.ver`.
        $vendedor = User::factory()->create(['rol' => 'vendedor']);
        $this->assertFalse($vendedor->tienePermiso('costos.ver'));
        $this->assertTrue($vendedor->tienePermiso('cotizaciones.ver'));

        return $vendedor;
    }

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'administrador']);
    }

    /** Ningún componente de la lista trae columnas de costo, y el JSON crudo tampoco las nombra. */
    private function assertSinCostos(TestResponse $res, array $componentes): void
    {
        $this->assertNotEmpty($componentes, 'La receta tiene que seguir viajando: sin ella no se arma el ítem.');

        foreach ($componentes as $c) {
            foreach (['precio_unit', 'subtotal', 'subtotal_real'] as $clave) {
                $this->assertArrayNotHasKey($clave, $c, "El componente «{$c['nombre']}» trae «{$clave}».");
            }
            // Lo que sí necesita quien cotiza o fabrica.
            $this->assertArrayHasKey('cantidad', $c);
            $this->assertArrayHasKey('producto_id', $c);
        }

        // Y en ninguna otra parte de la respuesta: el dato no sale del servidor.
        foreach (['"precio_unit"', '"subtotal"', '"subtotal_real"'] as $clave) {
            $this->assertStringNotContainsString($clave, $res->getContent());
        }
    }

    // ── /api/ensambles/calcular ─────────────────────────────────────────────

    public function test_calcular_no_manda_costos_sin_permiso(): void
    {
        $res = $this->actingAs($this->vendedor())
            ->postJson('/api/ensambles/calcular', [
                'plantilla_id' => $this->plantilla->id,
                'variables'    => ['ancho' => 1.5],
            ])->assertOk();

        $this->assertNull($res->json('total_costo'));
        $this->assertSinCostos($res, $res->json('componentes'));
        $this->assertEquals(3, $res->json('componentes.0.cantidad'));
    }

    public function test_calcular_si_manda_costos_con_permiso(): void
    {
        $res = $this->actingAs($this->admin())
            ->postJson('/api/ensambles/calcular', [
                'plantilla_id' => $this->plantilla->id,
                'variables'    => ['ancho' => 1.5],
            ])->assertOk();

        $this->assertEquals(3000, $res->json('total_costo'));
        $this->assertEquals(1000, $res->json('componentes.0.precio_unit'));
        $this->assertEquals(3000, $res->json('componentes.0.subtotal'));
    }

    // ── /api/ensambles/buscar ───────────────────────────────────────────────

    public function test_buscar_no_manda_la_receta_con_precios_sin_permiso(): void
    {
        $res = $this->actingAs($this->vendedor())
            ->getJson('/api/ensambles/buscar?q=Puerta')->assertOk();

        $this->assertNull($res->json('0.precio_costo'));
        $this->assertSinCostos($res, $res->json('0.componentes_resultado'));
    }

    public function test_buscar_si_manda_la_receta_con_precios_con_permiso(): void
    {
        $res = $this->actingAs($this->admin())
            ->getJson('/api/ensambles/buscar?q=Puerta')->assertOk();

        $this->assertEquals(2000, $res->json('0.precio_costo'));
        $this->assertEquals(1000, $res->json('0.componentes_resultado.0.precio_unit'));
    }

    // ── /api/cotizaciones/calcular-ensamble ─────────────────────────────────

    public function test_calcular_ensamble_de_la_cotizacion_no_manda_costos_sin_permiso(): void
    {
        $res = $this->actingAs($this->vendedor())
            ->postJson('/api/cotizaciones/calcular-ensamble', [
                'ensamble_id'         => $this->ensamble->id,
                'variables_instancia' => ['ancho' => 2],
            ])->assertOk();

        $this->assertNull($res->json('total_costo'));
        $this->assertNull($res->json('precio_costo'));
        $this->assertSinCostos($res, $res->json('componentes'));
        $this->assertEquals(4, $res->json('componentes.0.cantidad'));
    }

    public function test_calcular_ensamble_en_modo_plantilla_tampoco_manda_costos(): void
    {
        $res = $this->actingAs($this->vendedor())
            ->postJson('/api/cotizaciones/calcular-ensamble', [
                'plantilla_id' => $this->plantilla->id,
                'variables'    => ['ancho' => 1],
            ])->assertOk();

        $this->assertNull($res->json('total_costo'));
        $this->assertSinCostos($res, $res->json('componentes'));
    }

    public function test_calcular_ensamble_si_manda_costos_con_permiso(): void
    {
        $res = $this->actingAs($this->admin())
            ->postJson('/api/cotizaciones/calcular-ensamble', [
                'ensamble_id'         => $this->ensamble->id,
                'variables_instancia' => ['ancho' => 2],
            ])->assertOk();

        $this->assertEquals(4000, $res->json('total_costo'));
        $this->assertEquals(4000, $res->json('componentes.0.subtotal'));
    }

    // ── /api/plantillas-ensamble/probar (abierto a quien cotiza) ────────────

    public function test_el_probador_del_cotizador_no_manda_costos_sin_permiso(): void
    {
        $res = $this->actingAs($this->vendedor())
            ->postJson('/api/plantillas-ensamble/probar', [
                'plantilla_id' => $this->plantilla->id,
                'valores'      => ['ancho' => 1],
            ])->assertOk();

        $this->assertNull($res->json('total_costo'));
        $this->assertNull($res->json('total_costo_real'));
        $this->assertSinCostos($res, $res->json('componentes'));
    }

    // ── Al guardar, el servidor completa lo que no viajó ────────────────────

    public function test_la_cotizacion_del_vendedor_se_guarda_con_la_receta_completa(): void
    {
        $vendedor = $this->vendedor();

        // Lo que recibió su pantalla: la receta sin precios.
        $receta = $this->actingAs($vendedor)
            ->postJson('/api/cotizaciones/calcular-ensamble', [
                'ensamble_id'         => $this->ensamble->id,
                'variables_instancia' => ['ancho' => 2],
            ])->json('componentes');
        $this->assertArrayNotHasKey('precio_unit', $receta[0]);

        $this->actingAs($vendedor)->post('/cotizaciones', [
            'lead_id' => null, 'cliente_id' => null, 'contacto_id' => null,
            'nombre_contacto_override' => null, 'condiciones_comerciales' => null, 'notas_internas' => null,
            'moneda' => 'COP', 'tasa_cambio' => 1,
            'fecha_creacion' => now()->toDateString(), 'fecha_validez' => now()->addDays(30)->toDateString(),
            'responsable_id' => $vendedor->id,
            'items' => [[
                'tipo' => 'ensamble', 'ensamble_id' => $this->ensamble->id, 'descripcion' => 'Puerta 2 m',
                'cantidad' => 1, 'precio_unitario' => 9000,
                'variables_instancia' => ['ancho' => 2],
                'componentes_snapshot' => $receta,
            ]],
        ])->assertRedirect();

        $snapshot = Cotizacion::latest('id')->first()->items->first()->componentes_snapshot;

        $this->assertEquals(4, $snapshot[0]['cantidad']);
        $this->assertEquals(1000, $snapshot[0]['precio_unit']);
        $this->assertEquals(4000, $snapshot[0]['subtotal']);
        $this->assertEquals(4000, $snapshot[0]['subtotal_real']);
    }

    public function test_la_receta_del_buscador_se_completa_con_la_guardada_del_ensamble(): void
    {
        // Es lo que manda la OP: la receta guardada del ensamble, tal como la dio el buscador.
        $sinPrecios = CostosReceta::ocultar($this->ensamble->componentes_resultado);

        // El costo del producto cambió después de guardar el ensamble: manda el de la receta.
        $this->lamina->update(['precio_costo' => 5000]);

        $completa = CostosReceta::completar($sinPrecios, $this->ensamble->id, null);

        $this->assertEquals(1000, $completa[0]['precio_unit']);
        $this->assertEquals(2000, $completa[0]['subtotal']);

        // Un snapshot que ya trae precios no se toca.
        $this->assertSame($this->ensamble->componentes_resultado,
            CostosReceta::completar($this->ensamble->componentes_resultado, $this->ensamble->id, null));
    }
}
