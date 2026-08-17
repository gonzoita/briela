<?php

namespace Tests\Feature;

use App\Models\SegmentacionOpcion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Qué canal de precio se puede borrar desde Listas de segmentación.
 *
 * El sistema necesita saber cuál es el **canal base** —el piso de utilidad contra el que se
 * calcula la comisión— y cuál es el **precio público**, que es el que ve un desconocido. Esos
 * dos están atados; los demás son de la empresa y se borran cuando quiera.
 *
 * Estas pruebas se escribieron cuando el canal se decidía comparando los textos «mayorista» y
 * «distribuidor», y por eso fallaban desde que los canales son configurables: creaban la
 * opción sin ninguna marca y esperaban que el candado igual la protegiera. El candado mira el
 * papel, no el nombre — ver `SegmentacionOpcion::getAtadaAPreciosAttribute()`.
 */
class SegmentacionCanalPrecioTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'administrador']);
    }

    public function test_no_se_puede_borrar_el_canal_base(): void
    {
        $opcion = SegmentacionOpcion::create([
            'tipo'          => 'tipo_contacto',
            'valor'         => 'mayorista',
            'etiqueta'      => 'Mayorista',
            'define_precio' => true,
            'es_canal_base' => true,
        ]);

        $this->actingAs($this->admin())
            ->deleteJson("/api/segmentacion-opciones/{$opcion->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('segmentacion_opciones', ['id' => $opcion->id]);
    }

    public function test_tampoco_se_puede_borrar_el_precio_publico(): void
    {
        $opcion = SegmentacionOpcion::create([
            'tipo'              => 'tipo_contacto',
            'valor'             => 'cliente_directo',
            'etiqueta'          => 'Cliente directo',
            'define_precio'     => true,
            'es_precio_publico' => true,
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
        // El papel de canal solo existe entre los tipos de contacto: una industria llamada
        // «Mayorista» no tiene nada que ver con el precio.
        $opcion = SegmentacionOpcion::create([
            'tipo'          => 'industria',
            'valor'         => 'mayorista',
            'etiqueta'      => 'Mayorista',
            'es_canal_base' => true,
        ]);

        $this->actingAs($this->admin())
            ->deleteJson("/api/segmentacion-opciones/{$opcion->id}")
            ->assertOk();
    }

    public function test_la_pantalla_recibe_la_marca_de_atada_a_precios(): void
    {
        SegmentacionOpcion::create([
            'tipo'          => 'tipo_contacto',
            'valor'         => 'distribuidor',
            'etiqueta'      => 'Distribuidor',
            'define_precio' => true,
            'es_canal_base' => true,
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
