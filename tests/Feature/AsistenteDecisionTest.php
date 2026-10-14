<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\Sede;
use App\Models\User;
use App\Services\IA\ConsultasDatosService;
use App\Services\IA\IaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La decisión del asistente: qué consulta o acción elige antes de responder. Cuando el modelo
 * escribe una lista larga y el JSON sale cortado, el asistente tiene que decirlo —no inventar que
 * la herramienta no existe—, y el límite de la decisión tiene que caber una lista de 20 productos.
 */
class AsistenteDecisionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['rol' => 'administrador', 'name' => 'Diego González']);
        Sede::query()->first() ?? Sede::create(['nombre' => 'Principal']);
        $this->actingAs($this->admin);
    }

    private function producto(string $referencia, string $nombre, array $extra = []): Producto
    {
        return Producto::create($extra + [
            'tipo' => 'producto', 'nombre' => $nombre, 'referencia' => $referencia,
            'es_insumo' => false, 'es_vendible' => true, 'unidad_medida' => 'unidad', 'activo' => true,
        ]);
    }

    public function test_una_decision_cortada_avisa_a_la_redaccion_y_no_inventa_herramientas(): void
    {
        // Lo que devuelve el modelo cuando la lista de 20 cambios se corta a mitad.
        $cortada = '{"consultas": [], "accion": {"accion": "renombrar_productos", "parametros": {"cambios": [{"referencia": "IC10057", "nombre": "Distanciador de aluminio anodizado 40x20x2 mm Coldtech"}, {"referencia": "IC10058", "nombre": "Distanci';

        $prompts = [];
        $this->mock(IaService::class, function ($m) use (&$prompts, $cortada) {
            $m->shouldReceive('maxTokensRespuesta')->andReturn(1500);
            $m->shouldReceive('texto')->andReturnUsing(function ($prompt, $instr = '', $max = 700, $rapido = false) use (&$prompts, $cortada) {
                $prompts[] = $prompt;

                return $rapido ? $cortada : 'Te ayudo con eso.';
            });
        });

        $this->postJson('/api/asistente', ['mensaje' => 'Renombra estos 20 productos'])->assertOk();

        $redaccion = end($prompts);
        $this->assertStringContainsString('no_pude_preparar_la_accion', $redaccion, 'la redacción tiene que saber que la decisión se cortó');
        $this->assertStringContainsString('lotes de 10', $redaccion);
    }

    public function test_una_respuesta_en_prosa_no_se_marca_como_cortada(): void
    {
        $prompts = [];
        $this->mock(IaService::class, function ($m) use (&$prompts) {
            $m->shouldReceive('maxTokensRespuesta')->andReturn(1500);
            $m->shouldReceive('texto')->andReturnUsing(function ($prompt, $instr = '', $max = 700, $rapido = false) use (&$prompts) {
                $prompts[] = $prompt;

                return $rapido ? 'Hola, no sé qué consultar.' : 'Hola.';
            });
        });

        $this->postJson('/api/asistente', ['mensaje' => 'hola'])->assertOk();

        $this->assertStringNotContainsString('no_pude_preparar_la_accion', end($prompts));
    }

    public function test_la_decision_tiene_espacio_para_una_lista_de_20_cambios(): void
    {
        $maximos = [];
        $this->mock(IaService::class, function ($m) use (&$maximos) {
            $m->shouldReceive('maxTokensRespuesta')->andReturn(1500);
            $m->shouldReceive('texto')->andReturnUsing(function ($prompt, $instr = '', $max = 700, $rapido = false) use (&$maximos) {
                if ($rapido) {
                    $maximos[] = $max;
                }

                return $rapido ? '{"consultas": []}' : 'Listo.';
            });
        });

        $this->postJson('/api/asistente', ['mensaje' => 'hola'])->assertOk();

        $this->assertNotEmpty($maximos);
        $this->assertGreaterThanOrEqual(1500, $maximos[0], 'una lista de 20 pares no cabe en 700 tokens');
    }

    public function test_cuantos_productos_tenemos_responde_con_el_resumen_del_catalogo(): void
    {
        $this->producto('TPP-01', 'Tirador pasante palanca', ['descripcion_corta' => 'Plana']);
        $this->producto('TPP-02', 'tirador pasante  palanca');
        $this->producto('BIS-01', 'Bisagra cazoleta');
        $this->producto('INA-01', 'Producto viejo', ['activo' => false]);

        $r = app(ConsultasDatosService::class)->ejecutar('productos_resumen');

        $this->assertSame(3, $r['activos']);
        $this->assertSame(1, $r['inactivos']);
        $this->assertSame(['grupos' => 1, 'productos' => 2], $r['nombres_repetidos'], 'mayúsculas y espacios de más cuentan igual');
        $this->assertSame(3, collect($r['categorias_top'])->sum('productos'));
    }

    public function test_el_resumen_de_productos_sale_en_la_lista_de_consultas(): void
    {
        $this->assertArrayHasKey('productos_resumen', app(ConsultasDatosService::class)->disponibles());
    }
}
