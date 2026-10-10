<?php

namespace Tests\Feature;

use App\Models\Bodega;
use App\Models\CanalPrecio;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\SegmentacionOpcion;
use App\Models\User;
use App\Services\PreciosPorCanalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Crear un producto padre con sus variantes.
 *
 * Son los casos que hacían que la pantalla respondiera un 500 sin decir qué pasó: una
 * referencia generada más larga que su columna, una fila de proveedor sin todas sus
 * claves, y el contador de referencias tropezando con las variantes que él mismo creó.
 */
class ProductosVariantesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'administrador']);
    }

    /** Lo que manda el formulario de «Nuevo producto» con las variantes activadas. */
    private function formulario(array $extra = []): array
    {
        return array_merge([
            'tipo'                   => 'producto',
            'categoria_id'           => '',
            'proveedor_id'           => '',
            'nombre'                 => 'Perfil de aluminio',
            'referencia'             => '',
            'unidad_medida'          => 'unidad',
            'descripcion_corta'      => '',
            'descripcion_cotizacion' => '',
            'descripcion_larga'      => '',
            'inventariable'          => true,
            'es_vendible'            => true,
            'es_insumo'              => false,
            'atributo_variante'      => 'Longitud',
            'stock_minimo'           => 0,
            'stock_maximo'           => 0,
            'precio_costo'           => 10000,
            'moneda_costo'           => 'COP',
            'canales'                => app(PreciosPorCanalService::class)->paraFormulario(null),
            'es_padre'               => true,
            'stock_inicial'          => [],
        ], $extra);
    }

    // ─── El caso de todos los días ───────────────────────────────────────────

    public function test_el_padre_nace_con_sus_variantes_y_su_stock(): void
    {
        $bodega = Bodega::create(['nombre' => 'Principal', 'activa' => true, 'es_principal' => true]);

        $this->actingAs($this->admin())->post('/productos', $this->formulario([
            'variantes' => [
                ['valor_variante' => '3m', 'stock_inicial' => [$bodega->id => 5]],
                ['valor_variante' => '6m', 'stock_inicial' => []],
            ],
        ]))->assertRedirect();

        $padre = Producto::where('es_padre', true)->firstOrFail();

        $this->assertSame('Longitud', $padre->atributo_variante);
        $this->assertFalse((bool) $padre->inventariable, 'El padre no lleva stock propio.');
        $this->assertSame(2, $padre->variantes()->count());

        $tresMetros = $padre->variantes()->where('valor_variante', '3m')->firstOrFail();

        $this->assertSame($padre->referencia . '-3M', $tresMetros->referencia);
        $this->assertSame(5.0, $tresMetros->stockTotal());
        $this->assertSame(5.0, $padre->stockTotal(), 'El stock del padre es el de sus variantes.');
    }

    // ─── Lo que respondía 500 ────────────────────────────────────────────────

    public function test_un_valor_largo_no_desborda_la_columna_de_referencia(): void
    {
        $this->actingAs($this->admin())->post('/productos', $this->formulario([
            'variantes' => [
                ['valor_variante' => str_repeat('A', 55)],
                ['valor_variante' => str_repeat('A', 55) . ' bis'],
            ],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $referencias = Producto::whereNotNull('producto_padre_id')->pluck('referencia');

        $this->assertCount(2, $referencias);
        $this->assertCount(2, $referencias->unique(), 'Dos variantes nunca comparten referencia.');

        foreach ($referencias as $referencia) {
            $this->assertLessThanOrEqual(Producto::REFERENCIA_MAX, strlen($referencia));
        }
    }

    public function test_una_fila_de_proveedor_sin_todas_sus_claves_no_revienta(): void
    {
        $proveedor = Proveedor::create(['nombre' => 'Aceros del Norte', 'activo' => true]);

        $this->actingAs($this->admin())->post('/productos', $this->formulario([
            // Sin `dias_entrega` ni `minimo_compra`: así las manda la importación.
            'proveedores_precios' => [['proveedor_id' => $proveedor->id, 'precio' => 1000]],
            'variantes'           => [['valor_variante' => '3m']],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $padre = Producto::where('es_padre', true)->firstOrFail();

        $this->assertSame($proveedor->id, $padre->proveedor_id);
    }

    public function test_la_referencia_del_siguiente_producto_no_cuenta_las_variantes(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/productos', $this->formulario([
            'variantes' => [['valor_variante' => '3m'], ['valor_variante' => '6m']],
        ]))->assertRedirect();

        $this->actingAs($admin)->post('/productos', $this->formulario([
            'nombre'    => 'Tubo redondo',
            'variantes' => [['valor_variante' => '3m']],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            ['PROD-0001', 'PROD-0002'],
            Producto::where('es_padre', true)->orderBy('id')->pluck('referencia')->all(),
        );
    }

    public function test_una_referencia_escrita_a_mano_no_bloquea_la_siguiente_automatica(): void
    {
        $admin = $this->admin();

        // Alguien escribe a mano la referencia que al contador le tocaría dar después.
        $this->actingAs($admin)->post('/productos', $this->formulario([
            'referencia' => 'PROD-0001',
            'variantes'  => [['valor_variante' => '3m']],
        ]))->assertRedirect();

        $this->actingAs($admin)->post('/productos', $this->formulario([
            'nombre'    => 'Tubo redondo',
            'variantes' => [['valor_variante' => '3m']],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(2, Producto::where('es_padre', true)->count());
    }

    // ─── La referencia de algo eliminado queda libre ─────────────────────────

    private function eliminado(string $nombre, string $referencia): Producto
    {
        $producto = Producto::create(['tipo' => 'producto', 'nombre' => $nombre, 'referencia' => $referencia]);
        $producto->delete();

        return $producto;
    }

    public function test_la_referencia_de_una_variante_eliminada_se_puede_volver_a_usar(): void
    {
        // Lo que queda de un intento fallido que la persona limpió a mano.
        $viejo = $this->eliminado('Barra vieja', 'BARRA-3M');

        $this->actingAs($this->admin())->post('/productos', $this->formulario([
            'variantes' => [['valor_variante' => '3m', 'referencia' => 'BARRA-3M']],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $nueva = Producto::where('referencia', 'BARRA-3M')->firstOrFail();

        $this->assertNotSame($viejo->id, $nueva->id);
        $this->assertSame('3m', $nueva->valor_variante);

        // El eliminado sigue ahí, con su historial, solo que con otra referencia.
        $viejo = Producto::withTrashed()->findOrFail($viejo->id);

        $this->assertTrue($viejo->trashed());
        $this->assertSame('BARRA-3M~elim'.$viejo->id, $viejo->referencia);
    }

    public function test_la_referencia_del_producto_tambien_se_libera(): void
    {
        $viejo = $this->eliminado('Perfil viejo', 'PROD-9000');

        $this->actingAs($this->admin())->post('/productos', $this->formulario([
            'referencia' => 'PROD-9000',
            'variantes'  => [['valor_variante' => '3m']],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Perfil de aluminio', Producto::where('referencia', 'PROD-9000')->firstOrFail()->nombre);
        $this->assertTrue(Producto::withTrashed()->findOrFail($viejo->id)->trashed());
    }

    public function test_una_referencia_larga_liberada_no_desborda_la_columna(): void
    {
        $larga = str_repeat('R', 60);
        $viejo = $this->eliminado('Larga', $larga);

        $this->actingAs($this->admin())->post('/productos', $this->formulario([
            'variantes' => [['valor_variante' => '3m', 'referencia' => $larga]],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertLessThanOrEqual(60, strlen(Producto::withTrashed()->findOrFail($viejo->id)->referencia));
    }

    public function test_una_referencia_de_un_producto_vivo_sigue_bloqueada_y_dice_cual(): void
    {
        Producto::create(['tipo' => 'producto', 'nombre' => 'Barra viva', 'referencia' => 'BARRA-6M']);

        $this->actingAs($this->admin())->post('/productos', $this->formulario([
            'variantes' => [['valor_variante' => '6m', 'referencia' => 'BARRA-6M']],
        ]))->assertSessionHasErrors('variantes.0.referencia');

        $mensaje = session('errors')->first('variantes.0.referencia');

        $this->assertStringContainsString('variante 1', $mensaje);
        $this->assertStringContainsString('Barra viva', $mensaje);
        $this->assertSame(0, Producto::where('es_padre', true)->count(), 'No se crea nada a medias.');
    }

    public function test_editar_un_producto_para_tomar_la_referencia_de_uno_eliminado(): void
    {
        $this->eliminado('Tubo viejo', 'TUBO-1');
        $vivo = Producto::create(['tipo' => 'producto', 'nombre' => 'Tubo', 'referencia' => 'TUBO-2']);

        $this->actingAs($this->admin())->put("/productos/{$vivo->id}", $this->formulario([
            'nombre'     => 'Tubo',
            'referencia' => 'TUBO-1',
            'es_padre'   => false,
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('TUBO-1', $vivo->fresh()->referencia);
    }

    public function test_dejar_la_referencia_vacia_evita_el_choque(): void
    {
        $this->eliminado('Barra vieja', 'BARRA-3M');

        $this->actingAs($this->admin())->post('/productos', $this->formulario([
            'variantes' => [['valor_variante' => '3m', 'referencia' => null]],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(1, Producto::where('es_padre', true)->count());
    }

    // ─── Lo que se cotiza es la variante ─────────────────────────────────────

    public function test_cada_variante_se_queda_con_los_precios_por_canal(): void
    {
        // Un canal propio de la empresa: no tiene columna vieja de respaldo.
        $nuevo = SegmentacionOpcion::create([
            'tipo'            => 'tipo_contacto',
            'valor'           => 'institucional',
            'etiqueta'        => 'Institucional',
            'margen_sugerido' => 40,
            'define_precio'   => true,
            'activo'          => true,
            'orden'           => 99,
        ]);

        $canales = app(PreciosPorCanalService::class)->paraFormulario(null);

        foreach ($canales as $i => $canal) {
            $canales[$i]['precio'] = 50000;
        }

        $this->actingAs($this->admin())->post('/productos', $this->formulario([
            'canales'   => $canales,
            'variantes' => [['valor_variante' => '3m'], ['valor_variante' => '6m']],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        foreach (Producto::whereNotNull('producto_padre_id')->get() as $variante) {
            $fila = CanalPrecio::where('precionable_type', $variante->getMorphClass())
                ->where('precionable_id', $variante->id)
                ->where('segmentacion_opcion_id', $nuevo->id)
                ->first();

            $this->assertNotNull($fila, "La variante {$variante->referencia} se quedó sin precio del canal nuevo.");
            $this->assertSame('50000.00', $fila->precio);
        }
    }

    public function test_agregar_una_variante_desde_editar_no_deja_los_precios_en_cero(): void
    {
        $admin   = $this->admin();
        $canales = app(PreciosPorCanalService::class)->paraFormulario(null);

        foreach ($canales as $i => $canal) {
            $canales[$i]['precio'] = 50000;
        }

        $this->actingAs($admin)->post('/productos', $this->formulario([
            'canales'   => $canales,
            'variantes' => [['valor_variante' => '3m']],
        ]))->assertRedirect();

        $padre = Producto::where('es_padre', true)->firstOrFail();

        // La pantalla de editar un padre manda solo esto: ni precios ni canales.
        $this->actingAs($admin)->put("/productos/{$padre->id}", [
            'nombre'            => $padre->nombre,
            'categoria_id'      => '',
            'atributo_variante' => 'Longitud',
            'variantes'         => [['valor_variante' => '6m']],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $nueva = $padre->variantes()->where('valor_variante', '6m')->firstOrFail();

        $this->assertSame('50000.00', $nueva->precio_mayorista);
        $this->assertSame(
            $padre->preciosPorCanal()->count(),
            $nueva->preciosPorCanal()->count(),
            'La variante nueva hereda los canales del padre.',
        );
    }
}
