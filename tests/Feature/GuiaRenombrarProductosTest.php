<?php

namespace Tests\Feature;

use App\Models\Configuracion;
use App\Models\Producto;
use App\Models\RegistroActividad;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Services\IA\ConsultasDatosService;
use App\Services\IA\TransaccionesIaService;
use App\Support\Modulos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * La guía diferencia productos que quedaron con el mismo nombre: los encuentra agrupados y los
 * renombra en lote, con confirmación, sin dejar un nombre repetido nuevo.
 */
class GuiaRenombrarProductosTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Modulos::guardar([], 'instalacion');
        Cache::flush();
        Configuracion::set('ia_api_key', 'llave-de-prueba');
        Sede::query()->first() ?? Sede::create(['nombre' => 'Principal', 'es_principal' => true]);
        $this->actingAs(User::factory()->create(['rol' => 'administrador']));

        foreach ([['TPP-01', 'Tirador pasante palanca', 'Plana 121-140mm'], ['TPP-02', 'Tirador pasante palanca', 'Redonda 101-120mm'], ['TPP-03', 'tirador pasante  palanca ', 'Redonda 141-160mm'], ['BIS-01', 'Bisagra cazoleta', '35mm']] as [$ref, $nombre, $desc]) {
            $this->producto($ref, $nombre, $desc);
        }
    }

    private function producto(string $referencia, string $nombre, string $descripcion = ''): Producto
    {
        return Producto::create([
            'tipo' => 'producto', 'nombre' => $nombre, 'referencia' => $referencia, 'descripcion_corta' => $descripcion,
            'es_insumo' => false, 'es_vendible' => true, 'unidad_medida' => 'unidad', 'activo' => true,
        ]);
    }

    private function svc(): TransaccionesIaService
    {
        return app(TransaccionesIaService::class);
    }

    public function test_la_consulta_agrupa_los_que_se_llaman_igual_aunque_cambien_mayusculas_y_espacios(): void
    {
        $r = app(ConsultasDatosService::class)->ejecutar('productos_nombre_repetido', ['texto' => 'tirador']);

        $this->assertCount(1, $r['grupos']);
        $this->assertSame(3, $r['grupos'][0]['cuantos']);
        $refs = collect($r['grupos'][0]['productos'])->pluck('referencia')->sort()->values()->all();
        $this->assertSame(['TPP-01', 'TPP-02', 'TPP-03'], $refs);
        $this->assertSame('Plana 121-140mm', collect($r['grupos'][0]['productos'])->firstWhere('referencia', 'TPP-01')['descripcion']);
    }

    public function test_proponer_no_cambia_nada_y_confirmar_renombra_todos_juntos(): void
    {
        $cambios = [
            ['referencia' => 'TPP-01', 'nombre' => 'Tirador pasante palanca plana 121-140mm'],
            ['referencia' => 'TPP-02', 'nombre' => 'Tirador pasante palanca redonda 101-120mm'],
            ['referencia' => 'TPP-03', 'nombre' => 'Tirador pasante palanca redonda 141-160mm'],
        ];

        $propuesta = $this->svc()->proponer('renombrar_productos', ['cambios' => $cambios]);

        $this->assertTrue($propuesta['exito'], json_encode($propuesta));
        $this->assertStringContainsString('TPP-02: «Tirador pasante palanca» → «Tirador pasante palanca redonda 101-120mm»', $propuesta['propuesta']['resumen']);
        $this->assertSame('Tirador pasante palanca', Producto::where('referencia', 'TPP-01')->value('nombre'), 'proponer no escribe');

        $r = $this->svc()->ejecutar('renombrar_productos', $propuesta['propuesta']['parametros']);

        $this->assertTrue($r['exito'], $r['mensaje']);
        $this->assertSame('Tirador pasante palanca plana 121-140mm', Producto::where('referencia', 'TPP-01')->value('nombre'));
        $this->assertSame('Tirador pasante palanca redonda 141-160mm', Producto::where('referencia', 'TPP-03')->value('nombre'));
        $this->assertSame('35mm', Producto::where('referencia', 'BIS-01')->value('descripcion_corta'), 'lo que no se pidió queda igual');
        $id = Producto::where('referencia', 'TPP-01')->value('id');
        $this->assertTrue(RegistroActividad::where('modelo_id', $id)->where('accion', 'like', '%actualiz%')->exists()
            || RegistroActividad::where('modelo_id', $id)->where('accion', '!=', 'creado')->exists(), 'queda en la auditoría');
    }

    public function test_no_deja_un_nombre_repetido_nuevo(): void
    {
        $r = $this->svc()->proponer('renombrar_productos', ['cambios' => [
            ['referencia' => 'TPP-01', 'nombre' => 'Tirador redondo'],
            ['referencia' => 'TPP-02', 'nombre' => 'tirador  redondo'],
        ]]);
        $this->assertFalse($r['exito']);
        $this->assertStringContainsString('dales nombres distintos', $r['falta']);

        $r = $this->svc()->proponer('renombrar_productos', ['cambios' => [['referencia' => 'TPP-01', 'nombre' => 'Bisagra cazoleta']]]);
        $this->assertFalse($r['exito']);
        $this->assertStringContainsString('BIS-01', $r['falta']);
    }

    public function test_una_referencia_que_no_existe_se_pregunta(): void
    {
        $r = $this->svc()->proponer('renombrar_productos', ['cambios' => [['referencia' => 'NO-EXISTE', 'nombre' => 'Algo']]]);

        $this->assertFalse($r['exito']);
        $this->assertStringContainsString('NO-EXISTE', $r['falta']);
    }

    public function test_si_alguien_lo_renombro_entre_tanto_no_se_hace_a_ciegas(): void
    {
        $propuesta = $this->svc()->proponer('renombrar_productos', ['cambios' => [['referencia' => 'TPP-01', 'nombre' => 'Tirador plano']]]);
        Producto::where('referencia', 'TPP-01')->update(['nombre' => 'Otro nombre puesto a mano']);

        $r = $this->svc()->ejecutar('renombrar_productos', $propuesta['propuesta']['parametros']);

        $this->assertFalse($r['exito']);
        $this->assertSame('Otro nombre puesto a mano', Producto::where('referencia', 'TPP-01')->value('nombre'));
    }

    public function test_sin_permiso_de_editar_productos_no_se_ofrece(): void
    {
        $rol = Rol::create(['nombre' => 'Solo ve', 'rol_base' => 'vendedor', 'activo' => true, 'todas_las_sedes' => true]);
        $rol->sincronizarPermisos(['productos.ver']);
        $this->actingAs(User::factory()->create(['rol' => 'vendedor', 'rol_id' => $rol->id]));

        $this->assertNull($this->svc()->proponer('renombrar_productos', ['cambios' => [['referencia' => 'TPP-01', 'nombre' => 'X']]]));
        $this->assertNotNull(app(ConsultasDatosService::class)->ejecutar('productos_nombre_repetido'), 'consultar sí puede');
    }
}
