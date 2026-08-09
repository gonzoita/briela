<?php

namespace Tests\Feature;

use App\Models\SegmentacionOpcion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El cotizador decide el precio (y con él la comisión del vendedor) mirando el
 * `tipos_contacto` del cliente contra los textos «mayorista» y «distribuidor».
 *
 * Si alguien borrara esas opciones desde Listas de segmentación, los clientes
 * que las tuvieran pasarían a cotizarse como cliente final sin ningún aviso.
 * Estas pruebas fijan el candado.
 */
class SegmentacionCanalPrecioTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'administrador']);
    }

    public function test_no_se_puede_borrar_una_opcion_que_define_el_precio(): void
    {
        $opcion = SegmentacionOpcion::create([
            'tipo'     => 'tipo_contacto',
            'valor'    => 'mayorista',
            'etiqueta' => 'Mayorista',
        ]);

        $this->actingAs($this->admin())
            ->deleteJson("/api/segmentacion-opciones/{$opcion->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('segmentacion_opciones', ['id' => $opcion->id]);
    }

    public function test_las_demas_opciones_si_se_pueden_borrar(): void
    {
        $opcion = SegmentacionOpcion::create([
            'tipo'     => 'tipo_contacto',
            'valor'    => 'prospecto',
            'etiqueta' => 'Prospecto',
        ]);

        $this->actingAs($this->admin())
            ->deleteJson("/api/segmentacion-opciones/{$opcion->id}")
            ->assertOk();

        $this->assertDatabaseMissing('segmentacion_opciones', ['id' => $opcion->id]);
    }

    public function test_el_mismo_valor_en_otro_tipo_no_queda_bloqueado(): void
    {
        // «mayorista» solo manda sobre el precio cuando es un tipo de contacto.
        $opcion = SegmentacionOpcion::create([
            'tipo'     => 'industria',
            'valor'    => 'mayorista',
            'etiqueta' => 'Mayorista',
        ]);

        $this->actingAs($this->admin())
            ->deleteJson("/api/segmentacion-opciones/{$opcion->id}")
            ->assertOk();
    }

    public function test_la_pantalla_recibe_la_marca_de_atada_a_precios(): void
    {
        SegmentacionOpcion::create([
            'tipo'     => 'tipo_contacto',
            'valor'    => 'distribuidor',
            'etiqueta' => 'Distribuidor',
        ]);

        $respuesta = $this->actingAs($this->admin())
            ->getJson('/api/segmentacion-opciones')
            ->assertOk()
            ->json();

        $distribuidor = collect($respuesta['tipo_contacto'])
            ->firstWhere('valor', 'distribuidor');

        $this->assertTrue($distribuidor['atada_a_precios']);
    }
}
