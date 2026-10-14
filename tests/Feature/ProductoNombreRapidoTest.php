<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\RegistroActividad;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cambiar el nombre desde la lista de productos: solo el nombre, con el permiso de editar, dejando
 * rastro en la auditoría, y sin impedir que dos productos se llamen igual (se distinguen por código).
 */
class ProductoNombreRapidoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Sede::query()->first() ?? Sede::create(['nombre' => 'Principal', 'es_principal' => true]);
        $this->actingAs(User::factory()->create(['rol' => 'administrador']));
    }

    private function producto(string $referencia, string $nombre): Producto
    {
        return Producto::create([
            'tipo' => 'producto', 'nombre' => $nombre, 'referencia' => $referencia, 'descripcion_corta' => 'Plana',
            'es_insumo' => false, 'es_vendible' => true, 'unidad_medida' => 'unidad', 'activo' => true,
        ]);
    }

    public function test_cambia_solo_el_nombre_y_limpia_los_espacios(): void
    {
        $p = $this->producto('TPP-01', 'Tirador pasante palanca');

        $r = $this->patchJson("/productos/{$p->id}/nombre", ['nombre' => '  Tirador   pasante palanca plana 121-140mm  '])
            ->assertOk()
            ->assertJson(['id' => $p->id, 'nombre' => 'Tirador pasante palanca plana 121-140mm']);

        $p->refresh();
        $this->assertSame('Tirador pasante palanca plana 121-140mm', $p->nombre);
        $this->assertSame('TPP-01', $p->referencia, 'el código no se toca');
        $this->assertSame('Plana', $p->descripcion_corta, 'lo demás del producto queda igual');
    }

    public function test_dos_productos_pueden_quedar_con_el_mismo_nombre(): void
    {
        $a = $this->producto('TPP-01', 'Tirador pasante palanca');
        $b = $this->producto('TPP-02', 'Bisagra cazoleta');

        $this->patchJson("/productos/{$b->id}/nombre", ['nombre' => 'Tirador pasante palanca'])->assertOk();

        $this->assertSame(2, Producto::where('nombre', 'Tirador pasante palanca')->count());
        $this->assertSame('Tirador pasante palanca', $a->fresh()->nombre);
    }

    public function test_un_nombre_vacio_se_rechaza_y_no_cambia_nada(): void
    {
        $p = $this->producto('TPP-01', 'Tirador pasante palanca');

        $this->patchJson("/productos/{$p->id}/nombre", ['nombre' => '   '])->assertStatus(422);

        $this->assertSame('Tirador pasante palanca', $p->fresh()->nombre);
    }

    public function test_el_cambio_queda_en_la_auditoria(): void
    {
        $p = $this->producto('TPP-01', 'Tirador pasante palanca');
        $antes = RegistroActividad::where('modelo_id', $p->id)->count();

        $this->patchJson("/productos/{$p->id}/nombre", ['nombre' => 'Tirador plano'])->assertOk();

        $this->assertGreaterThan($antes, RegistroActividad::where('modelo_id', $p->id)->count());
    }

    public function test_sin_permiso_de_editar_productos_no_cambia_el_nombre(): void
    {
        $p = $this->producto('TPP-01', 'Tirador pasante palanca');

        $rol = Rol::create(['nombre' => 'Solo ve', 'rol_base' => 'vendedor', 'activo' => true, 'todas_las_sedes' => true]);
        $rol->sincronizarPermisos(['productos.ver']);
        $this->actingAs(User::factory()->create(['rol' => 'vendedor', 'rol_id' => $rol->id]));

        $this->patchJson("/productos/{$p->id}/nombre", ['nombre' => 'Cambiado'])->assertForbidden();

        $this->assertSame('Tirador pasante palanca', $p->fresh()->nombre);
    }
}
