<?php

namespace Tests\Feature;

use App\Models\Archivo;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Producto;
use App\Models\SegmentacionOpcion;
use App\Models\Sede;
use App\Models\User;
use App\Services\CanalesPrecioService;
use App\Services\CatalogoPdfService;
use App\Services\PreciosPorCanalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Lo que la tienda en línea muestra de un producto: marca, origen, características, planos y
 * fichas técnicas, y si el precio se publica. Y el catálogo en PDF, que sale siempre sin precios.
 */
class ProductosTiendaTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-de-prueba-1234567890';

    protected function setUp(): void
    {
        parent::setUp();

        Sede::query()->first() ?? Sede::create(['nombre' => 'Principal', 'es_principal' => true]);
        $this->actingAs(User::factory()->create(['rol' => 'administrador']));
    }

    private function producto(array $extra = []): Producto
    {
        return Producto::create($extra + [
            'tipo' => 'producto', 'nombre' => 'Bisagra con rampa', 'referencia' => 'IC10102',
            'es_insumo' => false, 'es_vendible' => true, 'unidad_medida' => 'unidad', 'activo' => true,
        ]);
    }

    /** Lo mínimo que manda el formulario de editar un producto. */
    private function formulario(Producto $p, array $extra = []): array
    {
        return array_merge(['nombre' => $p->nombre, 'referencia' => $p->referencia, 'es_vendible' => 1], $extra);
    }

    /** Un canal público que le pone precio a todo, para ver la diferencia entre publicar o no. */
    private function conPrecioPublico(float $precio): void
    {
        $this->mock(CanalesPrecioService::class, fn ($m) => $m->shouldReceive('publico')->andReturn(new SegmentacionOpcion()));
        $this->mock(PreciosPorCanalService::class, fn ($m) => $m->shouldReceive('precioDe')->andReturn($precio));
    }

    public function test_guarda_marca_origen_caracteristicas_y_si_publica_el_precio(): void
    {
        $p = $this->producto();

        $this->put("/productos/{$p->id}", $this->formulario($p, [
            'marca' => ' Acme ', 'origen' => 'Italia', 'publicar_precio' => 0,
            'caracteristicas' => [
                ['nombre' => 'Material', 'valor' => 'Acero inoxidable'],
                ['nombre' => '', 'valor' => 'sin nombre, se descarta'],
            ],
        ]))->assertSessionHasNoErrors();

        $p->refresh();
        $this->assertSame('Acme', $p->marca);
        $this->assertSame('Italia', $p->origen);
        $this->assertSame([['nombre' => 'Material', 'valor' => 'Acero inoxidable']], $p->caracteristicas);
        $this->assertFalse($p->publicar_precio);
    }

    public function test_un_producto_nuevo_publica_el_precio_por_omision(): void
    {
        $this->assertTrue($this->producto()->fresh()->publicar_precio);
    }

    public function test_quitar_todas_las_caracteristicas_las_borra(): void
    {
        $p = $this->producto(['caracteristicas' => [['nombre' => 'Material', 'valor' => 'Acero']]]);

        // Un formulario con archivos no manda la lista vacía: la marca dice que vino.
        $this->put("/productos/{$p->id}", $this->formulario($p, ['caracteristicas_enviadas' => 1]))->assertSessionHasNoErrors();

        $this->assertNull($p->fresh()->caracteristicas);
    }

    public function test_un_guardado_que_no_trae_los_campos_no_los_borra(): void
    {
        $p = $this->producto(['marca' => 'Acme', 'caracteristicas' => [['nombre' => 'Material', 'valor' => 'Acero']]]);

        $this->put("/productos/{$p->id}", $this->formulario($p))->assertSessionHasNoErrors();

        $this->assertSame('Acme', $p->fresh()->marca);
        $this->assertCount(1, $p->fresh()->caracteristicas);
    }

    public function test_sube_un_plano_con_nombre_aleatorio_y_lo_borra(): void
    {
        Storage::fake('public');
        $p = $this->producto();

        $this->post("/productos/{$p->id}/documentos", [
            'archivo' => UploadedFile::fake()->create('plano bisagra.pdf', 120, 'application/pdf'),
            'categoria' => 'plano',
        ])->assertSessionHasNoErrors();

        $doc = $p->documentos()->first();
        $this->assertSame('plano', $doc->categoria);
        $this->assertSame('plano bisagra.pdf', $doc->nombre_original);
        $this->assertStringEndsWith('.pdf', $doc->ruta);
        $this->assertStringNotContainsString('plano bisagra', $doc->ruta, 'el nombre guardado no sale del archivo');
        Storage::disk('public')->assertExists($doc->ruta);

        $this->delete("/productos/documentos/{$doc->id}")->assertSessionHasNoErrors();
        $this->assertSame(0, $p->documentos()->count());
        Storage::disk('public')->assertMissing($doc->ruta);
    }

    public function test_un_archivo_que_no_es_documento_se_rechaza(): void
    {
        Storage::fake('public');
        $p = $this->producto();

        $this->post("/productos/{$p->id}/documentos", [
            'archivo' => UploadedFile::fake()->create('malo.php', 1, 'text/x-php'),
            'categoria' => 'ficha_tecnica',
        ])->assertSessionHasErrors('archivo');

        $this->assertSame(0, Archivo::count());
    }

    public function test_la_ruta_de_documentos_no_borra_archivos_de_otros_registros(): void
    {
        $cliente = Cliente::create(['tipo' => 'empresa', 'nombre' => 'Vidrios del Norte SAS', 'tipo_identificacion' => 'NIT']);
        $ajeno = Archivo::create([
            'nombre_original' => 'rut.pdf', 'nombre_archivo' => 'rut.pdf', 'ruta' => 'clientes/1/rut.pdf', 'tipo_mime' => 'application/pdf',
            'extension' => 'pdf', 'tamano' => 10, 'categoria' => 'plano', 'archivable_type' => Cliente::class, 'archivable_id' => $cliente->id,
        ]);

        $this->delete("/productos/documentos/{$ajeno->id}")->assertNotFound();
        $this->assertTrue(Archivo::whereKey($ajeno->id)->exists());
    }

    public function test_el_catalogo_para_la_tienda_trae_marca_documentos_y_respeta_el_precio_oculto(): void
    {
        Configuracion::set('integracion_wordpress_token', self::TOKEN);
        $this->conPrecioPublico(150000);

        $visible = $this->producto(['publicado_web' => true, 'marca' => 'Acme', 'origen' => 'Italia',
            'caracteristicas' => [['nombre' => 'Material', 'valor' => 'Acero']]]);
        $visible->documentos()->create(['nombre_original' => 'ficha.pdf', 'nombre_archivo' => 'x.pdf', 'ruta' => 'productos/1/documentos/x.pdf',
            'tipo_mime' => 'application/pdf', 'extension' => 'pdf', 'tamano' => 10, 'categoria' => 'ficha_tecnica']);
        $oculto = $this->producto(['referencia' => 'IC10103', 'nombre' => 'Bisagra 55 mm', 'publicado_web' => true, 'publicar_precio' => false]);

        $catalogo = collect($this->withHeader('Authorization', 'Bearer ' . self::TOKEN)->getJson('/api/wp/catalogo')
            ->assertOk()->json('catalogo'))->keyBy('id');

        $this->assertEquals(150000, $catalogo[$visible->id]['precio']);
        $this->assertSame('Acme', $catalogo[$visible->id]['marca']);
        $this->assertSame('Italia', $catalogo[$visible->id]['origen']);
        $this->assertSame('Material', $catalogo[$visible->id]['caracteristicas'][0]['nombre']);
        $this->assertSame('ficha_tecnica', $catalogo[$visible->id]['documentos'][0]['tipo']);
        $this->assertStringContainsString('productos/1/documentos/x.pdf', $catalogo[$visible->id]['documentos'][0]['url']);

        $this->assertNull($catalogo[$oculto->id]['precio'], 'con el precio oculto no viaja la cifra');
        $this->assertFalse($catalogo[$oculto->id]['publicar_precio']);
    }

    public function test_las_variantes_de_un_producto_con_precio_oculto_tampoco_lo_llevan(): void
    {
        Configuracion::set('integracion_wordpress_token', self::TOKEN);
        $this->conPrecioPublico(90000);

        $padre = $this->producto(['es_padre' => true, 'publicado_web' => true, 'publicar_precio' => false, 'atributo_variante' => 'Medida']);
        $this->producto(['referencia' => 'IC10102-45', 'nombre' => 'Bisagra', 'producto_padre_id' => $padre->id, 'valor_variante' => '45 mm']);

        $item = collect($this->withHeader('Authorization', 'Bearer ' . self::TOKEN)->getJson('/api/wp/catalogo')->json('catalogo'))->firstWhere('id', $padre->id);

        $this->assertSame('Medida', $item['atributo_variante']);
        $this->assertNull($item['variantes'][0]['precio']);
    }

    public function test_el_catalogo_en_pdf_no_lleva_precios_y_se_descarga(): void
    {
        $this->producto(['marca' => 'Acme', 'precio_cliente_final' => 150000]);

        $fichas = app(CatalogoPdfService::class)->fichas(false)->flatten(1);
        $this->assertCount(1, $fichas);
        $this->assertSame('Acme', $fichas->first()['marca']);
        foreach (array_keys($fichas->first()) as $clave) {
            $this->assertStringNotContainsString('precio', $clave, 'ninguna ficha lleva precio');
        }

        $this->get('/productos/catalogo-pdf')->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_el_catalogo_pdf_de_la_tienda_trae_solo_lo_publicado_y_pide_el_token(): void
    {
        Configuracion::set('integracion_wordpress_token', self::TOKEN);
        $this->producto(['publicado_web' => true]);
        $this->producto(['referencia' => 'IC10103', 'nombre' => 'No publicado']);

        $this->assertCount(1, app(CatalogoPdfService::class)->fichas(true)->flatten(1));

        $this->get('/api/wp/catalogo/pdf')->assertStatus(401);
        $this->withHeader('Authorization', 'Bearer ' . self::TOKEN)->get('/api/wp/catalogo/pdf')
            ->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_la_ficha_publica_no_muestra_ni_manda_el_precio_oculto(): void
    {
        $p = $this->producto(['publicar_precio' => false, 'precio_cliente_final' => 150000]);
        auth()->logout();

        $this->get("/catalogo/productos/{$p->id}?precio=1")->assertOk()->assertInertia(fn ($pagina) => $pagina
            ->where('mostrarPrecio', false)
            ->where('producto.precio_publico', null)
            ->where('producto.precio_cliente_final', null));
    }
}
