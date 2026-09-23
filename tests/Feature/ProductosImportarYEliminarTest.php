<?php

namespace Tests\Feature;

use App\Models\CanalPrecio;
use App\Models\Producto;
use App\Models\SegmentacionOpcion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * La plantilla de importación sigue a los canales de Segmentación, la importación escribe los
 * precios donde la cotización los lee, y el borrado en bloque pide su permiso y se lleva las
 * variantes con el padre.
 *
 * Los tres canales de fábrica —mayorista (base), distribuidor, cliente directo (público)— los
 * dejan puestos las migraciones.
 */
class ProductosImportarYEliminarTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'administrador']);
    }

    private function canal(string $valor): SegmentacionOpcion
    {
        return SegmentacionOpcion::where('tipo', 'tipo_contacto')->where('valor', $valor)->firstOrFail();
    }

    /** @return array{0: list<string>, 1: list<list<string>>} */
    private function leerCsv(string $csv): array
    {
        $lineas = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', preg_replace('/^\xEF\xBB\xBF/', '', $csv)),
            fn ($l) => trim($l) !== ''
        ));

        $filas = array_map(fn ($l) => str_getcsv($l, ';'), $lineas);

        return [array_shift($filas), $filas];
    }

    private function importar(User $user, string $csv)
    {
        $archivo = UploadedFile::fake()->createWithContent('productos.csv', $csv);

        return $this->actingAs($user)
            ->post('/productos/importar', ['archivo' => $archivo], ['Accept' => 'application/json'])
            ->assertOk();
    }

    private function producto(string $nombre, array $extra = []): Producto
    {
        static $n = 0;

        return Producto::create(array_merge([
            'tipo'       => 'producto',
            'nombre'     => $nombre,
            'referencia' => 'PRUEBA-' . (++$n) . '-' . uniqid(),
        ], $extra));
    }

    // ─── Plantilla ───────────────────────────────────────────────────────────

    public function test_la_plantilla_trae_columnas_por_canal_incluido_uno_nuevo(): void
    {
        SegmentacionOpcion::create([
            'tipo' => 'tipo_contacto', 'valor' => 'constructora', 'etiqueta' => 'Constructora',
            'define_precio' => true, 'activo' => true, 'orden' => 9, 'margen_sugerido' => 28,
        ]);

        $csv = $this->actingAs($this->admin())->get('/productos/importar/plantilla')->assertOk()->getContent();

        [$encabezados, $filas] = $this->leerCsv($csv);

        foreach (['margen_mayorista', 'precio_mayorista', 'precio_distribuidor', 'comision_min_distribuidor',
                  'descuento_max_distribuidor', 'precio_cliente_directo', 'margen_constructora',
                  'precio_constructora', 'comision_max_constructora', 'descripcion_cotizacion',
                  'referencia_proveedor', 'precio_proveedor'] as $col) {
            $this->assertContains($col, $encabezados, "Falta la columna {$col}");
        }

        // El canal base es el piso de utilidad: no lleva comisión ni descuento.
        $this->assertNotContains('comision_min_mayorista', $encabezados);
        $this->assertNotContains('descuento_max_mayorista', $encabezados);

        // Cada fila de ejemplo con tantas celdas como encabezados: si no, sale corrida.
        foreach ($filas as $i => $fila) {
            $this->assertCount(count($encabezados), $fila, "La fila de ejemplo {$i} está corrida");
        }
    }

    // ─── Importar ────────────────────────────────────────────────────────────

    public function test_importar_escribe_los_precios_donde_cotiza_el_sistema(): void
    {
        $this->importar($this->admin(), implode("\n", [
            'nombre;referencia;precio_costo;margen_distribuidor;precio_cliente_directo',
            'Panel 100 mm;IMP-001;1000000;40;1500000',
        ]));

        $producto = Producto::where('referencia', 'IMP-001')->firstOrFail();
        $fila = fn (string $valor) => CanalPrecio::where('precionable_id', $producto->id)
            ->where('precionable_type', $producto->getMorphClass())
            ->where('segmentacion_opcion_id', $this->canal($valor)->id)->firstOrFail();

        // Margen dado: el precio sale del costo, como en la ficha.
        $this->assertEquals(1400000, (float) $fila('distribuidor')->precio);

        // Sin margen ni precio: el margen sugerido de Segmentación (25 % en el canal base).
        $this->assertEquals(25, (float) $fila('mayorista')->margen_pct);
        $this->assertEquals(1250000, (float) $fila('mayorista')->precio);

        // Precio dado: manda, y el margen se deduce de él para que la ficha no lo cambie.
        $this->assertEquals(1500000, (float) $fila('cliente_directo')->precio);
        $this->assertEquals(50, (float) $fila('cliente_directo')->margen_pct);

        // Producto nuevo sin comisiones en el archivo: salen sugeridas, no en cero.
        $this->assertGreaterThan(0, (float) $fila('cliente_directo')->comision_max_pct);

        // Y el espejo a las columnas viejas, que la ficha todavía muestra.
        $this->assertEquals(1400000, (float) $producto->fresh()->precio_distribuidor);
    }

    public function test_un_csv_de_la_plantilla_vieja_sigue_importando(): void
    {
        $this->importar($this->admin(), implode("\n", [
            'nombre;referencia;precio_cliente_final',
            'Instalación;IMP-VIEJO;900000',
        ]));

        $producto = Producto::where('referencia', 'IMP-VIEJO')->firstOrFail();

        $this->assertEquals(900000, (float) CanalPrecio::where('precionable_id', $producto->id)
            ->where('segmentacion_opcion_id', $this->canal('cliente_directo')->id)->value('precio'));
    }

    public function test_reimportar_sin_tocar_precios_no_los_cambia(): void
    {
        $admin = $this->admin();
        $this->importar($admin, "nombre;referencia;precio_costo;precio_distribuidor\nTubo;IMP-002;100000;175000");

        // Otra pasada que solo cambia la descripción: los precios se quedan como estaban.
        $this->importar($admin, "nombre;referencia;descripcion_corta\nTubo;IMP-002;Tubo galvanizado");

        $producto = Producto::where('referencia', 'IMP-002')->firstOrFail();
        $this->assertSame('Tubo galvanizado', $producto->descripcion_corta);
        $this->assertEquals(175000, (float) CanalPrecio::where('precionable_id', $producto->id)
            ->where('segmentacion_opcion_id', $this->canal('distribuidor')->id)->value('precio'));
    }

    public function test_el_proveedor_entra_a_la_lista_de_proveedores(): void
    {
        $this->importar($this->admin(), implode("\n", [
            'nombre;referencia;precio_costo;proveedor;referencia_proveedor;precio_proveedor',
            'Bisagra;IMP-003;45000;Aceros SA;BS-9;43000',
        ]));

        $producto = Producto::where('referencia', 'IMP-003')->firstOrFail();

        $this->assertDatabaseHas('producto_proveedor', [
            'producto_id'          => $producto->id,
            'proveedor_id'         => $producto->proveedor_id,
            'referencia_proveedor' => 'BS-9',
            'precio'               => 43000,
            'es_preferido'         => true,
        ]);
    }

    // ─── Eliminar en bloque ──────────────────────────────────────────────────

    public function test_eliminar_varios_se_lleva_las_variantes_del_padre(): void
    {
        $padre    = $this->producto('Puerta', ['es_padre' => true]);
        $variante = $this->producto('Puerta — Blanco', ['producto_padre_id' => $padre->id]);
        $otro     = $this->producto('Manija');

        $this->actingAs($this->admin())
            ->post('/productos/eliminar', ['ids' => [$padre->id]])
            ->assertRedirect();

        $this->assertSoftDeleted('productos', ['id' => $padre->id]);
        $this->assertSoftDeleted('productos', ['id' => $variante->id]);
        $this->assertNotSoftDeleted('productos', ['id' => $otro->id]);
    }

    public function test_eliminar_todos_los_del_filtro_respeta_el_filtro(): void
    {
        $a = $this->producto('Puerta corrediza');
        $b = $this->producto('Puerta vaivén');
        $c = $this->producto('Panel');

        $this->actingAs($this->admin())
            ->post('/productos/eliminar', ['todos_del_filtro' => true, 'buscar' => 'Puerta'])
            ->assertRedirect();

        $this->assertSoftDeleted('productos', ['id' => $a->id]);
        $this->assertSoftDeleted('productos', ['id' => $b->id]);
        $this->assertNotSoftDeleted('productos', ['id' => $c->id]);
    }

    public function test_sin_el_permiso_de_eliminar_no_se_borra_nada(): void
    {
        $producto = $this->producto('Panel');
        $vendedor = User::factory()->create(['rol' => 'vendedor']);

        $this->actingAs($vendedor)
            ->post('/productos/eliminar', ['ids' => [$producto->id]])
            ->assertForbidden();

        // El borrado de uno solo tampoco: el permiso existía y ninguna ruta lo pedía.
        $this->actingAs($vendedor)
            ->delete("/productos/{$producto->id}")
            ->assertForbidden();

        $this->assertNotSoftDeleted('productos', ['id' => $producto->id]);
    }
}
