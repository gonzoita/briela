<?php

namespace Tests\Feature;

use App\Models\CanalPrecio;
use App\Models\Producto;
use App\Models\SegmentacionOpcion;
use App\Models\Sede;
use App\Models\User;
use App\Services\PreciosPorCanalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El canal base puede tener margen 0 %: el costo ya trae la utilidad mínima de la empresa, así que
 * su precio es el costo, tal cual. En los demás canales, cero sigue siendo «sin margen puesto».
 */
class MargenCeroCanalBaseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sede::query()->first() ?? Sede::create(['nombre' => 'Principal', 'es_principal' => true]);
        $this->actingAs(User::factory()->create(['rol' => 'administrador']));
    }

    private function canal(string $etiqueta, array $extra = []): SegmentacionOpcion
    {
        SegmentacionOpcion::where('tipo', 'tipo_contacto')->where('valor', strtolower($etiqueta))->delete();

        return SegmentacionOpcion::create($extra + [
            'tipo' => 'tipo_contacto', 'valor' => strtolower($etiqueta), 'etiqueta' => $etiqueta,
            'define_precio' => true, 'margen_sugerido' => 30, 'activo' => true,
        ]);
    }

    public function test_el_canal_base_con_margen_cero_vale_el_costo_sin_redondear(): void
    {
        $precios = app(PreciosPorCanalService::class);

        $this->assertEquals(45230.55, $precios->precioDesdeCosto(45230.55, 0, true));
        $this->assertEquals(0.0, $precios->precioDesdeCosto(45230.55, 0, false), 'en otro canal, cero es «sin margen»');
        $this->assertEquals(0.0, $precios->precioDesdeCosto(0, 0, true), 'sin costo no hay precio');
        $this->assertEquals(59000.0, $precios->precioDesdeCosto(45230.55, 30, true), 'con margen sigue subiendo al millar');
    }

    public function test_un_cero_guardado_en_el_canal_base_manda_sobre_el_margen_de_segmentacion(): void
    {
        $base = $this->canal('Mayorista', ['es_canal_base' => true, 'margen_sugerido' => 25]);
        $otro = $this->canal('Distribuidor', ['margen_sugerido' => 25]);
        $p = Producto::create(['tipo' => 'producto', 'nombre' => 'Bisagra', 'referencia' => 'B1', 'precio_costo' => 1000, 'unidad_medida' => 'unidad', 'activo' => true]);
        CanalPrecio::create(['precionable_type' => $p->getMorphClass(), 'precionable_id' => $p->id, 'segmentacion_opcion_id' => $base->id, 'margen_pct' => 0, 'precio' => 1000]);
        CanalPrecio::create(['precionable_type' => $p->getMorphClass(), 'precionable_id' => $p->id, 'segmentacion_opcion_id' => $otro->id, 'margen_pct' => 0, 'precio' => 0]);

        $servicio = app(PreciosPorCanalService::class);
        $this->assertEquals(0.0, $servicio->margenDe($p, $base), 'el cero del canal base es una decisión');
        $this->assertEquals(25.0, $servicio->margenDe($p, $otro), 'en otro canal, cero cae al margen de Segmentación');
    }

    public function test_aplicar_el_margen_del_canal_reprecia_lo_existente_y_deja_en_paz_los_otros_canales(): void
    {
        $base = $this->canal('Mayorista', ['es_canal_base' => true, 'margen_sugerido' => 0]);
        $otro = $this->canal('Distribuidor', ['margen_sugerido' => 30]);
        $p = Producto::create(['tipo' => 'producto', 'nombre' => 'Bisagra', 'referencia' => 'B1', 'precio_costo' => 45230.55, 'unidad_medida' => 'unidad', 'activo' => true]);
        $mk = fn ($canal, $margen, $precio) => CanalPrecio::create(['precionable_type' => $p->getMorphClass(), 'precionable_id' => $p->id,
            'segmentacion_opcion_id' => $canal->id, 'margen_pct' => $margen, 'precio' => $precio]);
        $filaBase = $mk($base, 30, 58000);   // tenía 30 % y un precio calculado
        $filaOtro = $mk($otro, 40, 66000);   // este canal no se toca

        $this->postJson("/api/segmentacion-opciones/{$base->id}/aplicar-margen")->assertOk()->assertJsonPath('filas', 1);

        $this->assertEquals(0, (float) $filaBase->fresh()->margen_pct);
        $this->assertEquals(45230.55, (float) $filaBase->fresh()->precio, 'el precio base es el costo');
        $this->assertEquals([40.0, 66000.0], [(float) $filaOtro->fresh()->margen_pct, (float) $filaOtro->fresh()->precio]);
    }

    public function test_un_canal_que_no_define_precio_no_se_aplica(): void
    {
        $industria = SegmentacionOpcion::create(['tipo' => 'industria', 'valor' => 'x', 'etiqueta' => 'Industria X', 'define_precio' => false, 'activo' => true]);

        $this->postJson("/api/segmentacion-opciones/{$industria->id}/aplicar-margen")->assertStatus(422);
    }
}
