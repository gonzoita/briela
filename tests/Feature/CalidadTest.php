<?php

namespace Tests\Feature;

use App\Models\Op;
use App\Models\OpItem;
use App\Models\OpItemTrabajo;
use App\Models\OpItemTrabajoCheck;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El módulo de Calidad: lo que se revisa, y lo que no se deja pasar.
 *
 * Las dos pruebas que importan son las dos negativas. Un punto que exige foto no se puede dar
 * por cumplido sin ella: es el que después se discute con el cliente. Y la orden no se puede
 * sellar con puntos sin resolver: ese sello es el candado del despacho, y si se puede poner de
 * cualquier manera deja de significar algo.
 */
class CalidadTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'administrador']);
    }

    /** Una unidad terminada con sus puntos de revisión, que es lo que le llega a calidad. */
    private function unidad(bool $exigeFoto = false): OpItemTrabajo
    {
        $op = Op::create([
            // La sede es la de siempre: el tablero filtra por la sede activa como todo el
            // resto, así que una orden sin sede no aparecería y la prueba mediría otra cosa.
            'sede_id'                => \App\Models\Sede::query()->value('id'),
            'estado'                 => 'calidad',
            'responsable_id'         => User::factory()->create(['rol' => 'jefe_produccion'])->id,
            'fecha_creacion'         => now()->subDays(5),
            'fecha_entrega_estimada' => now()->addDays(2),
        ]);

        $item = OpItem::create([
            'op_id'               => $op->id,
            'descripcion'         => 'Puerta de prueba',
            'cantidad'            => 1,
            'precio_unitario'     => 100000,
            'subtotal'            => 100000,
            'total_linea'         => 100000,
            'orden'               => 0,
            'variables_instancia' => ['ancho' => 220, 'alto' => 430],
        ]);

        $trabajo = OpItemTrabajo::create([
            'op_item_id'        => $item->id,
            'porcentaje_avance' => 100,
            'numero_unidad'     => 1,
            'total_unidades'    => 1,
        ]);

        OpItemTrabajoCheck::create([
            'op_item_trabajo_id' => $trabajo->id,
            'titulo'             => 'Escuadra y aplome',
            'orden'              => 0,
            'es_critico'         => true,
        ]);

        OpItemTrabajoCheck::create([
            'op_item_trabajo_id' => $trabajo->id,
            'titulo'             => 'Acabado de pintura',
            'orden'              => 1,
            'exige_foto'         => $exigeFoto,
            'es_critico'         => true,
        ]);

        return $trabajo;
    }

    public function test_el_tablero_lista_las_unidades_fabricadas_y_sin_despachar(): void
    {
        $trabajo = $this->unidad();

        $this->actingAs($this->admin())
            ->get('/calidad')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Calidad/Index')
                ->has('fichas', 1)
                ->where('fichas.0.id', $trabajo->id)
                ->where('fichas.0.total_checks', 2)
                ->where('fichas.0.resueltos', 0)
                // Las medidas de la instancia viajan con la ficha: sin ellas, cinco unidades
                // del mismo ensamble son cinco fichas idénticas.
                ->has('fichas.0.variables', 2)
                ->has('ops', 1)
            );
    }

    public function test_una_unidad_a_medio_fabricar_no_entra_al_tablero(): void
    {
        $trabajo = $this->unidad();
        $trabajo->update(['porcentaje_avance' => 60]);

        $this->actingAs($this->admin())
            ->get('/calidad')
            ->assertInertia(fn ($p) => $p->component('Calidad/Index')->has('fichas', 0));
    }

    public function test_terminar_la_unidad_deja_todo_en_cumple_y_sella_la_orden(): void
    {
        $trabajo = $this->unidad();

        $this->actingAs($this->admin())
            ->post("/calidad/unidades/{$trabajo->id}/terminar")
            ->assertOk()
            ->assertJsonPath('ficha.porcentaje', 100);

        $this->assertSame(0, $trabajo->checks()->where('resultado', 'pendiente')->count());
        // El sello se pone solo: nadie debería tener que apretar «aprobar» después de haber
        // revisado punto por punto cada unidad.
        $this->assertNotNull($trabajo->opItem->op->fresh()->calidad_aprobada_at);
    }

    public function test_un_punto_que_exige_foto_no_deja_cerrar_la_unidad(): void
    {
        $trabajo = $this->unidad(exigeFoto: true);

        $this->actingAs($this->admin())
            ->postJson("/calidad/unidades/{$trabajo->id}/terminar")
            ->assertStatus(422)
            ->assertJsonPath('exigen_foto.0.titulo', 'Acabado de pintura');

        // Y nada se marcó a medias: el punto sin foto sigue pendiente, y el otro también.
        $this->assertSame(2, $trabajo->checks()->where('resultado', 'pendiente')->count());
        $this->assertNull($trabajo->opItem->op->fresh()->calidad_aprobada_at);
    }

    public function test_con_la_foto_puesta_la_unidad_ya_cierra(): void
    {
        Storage::fake('public');

        $trabajo = $this->unidad(exigeFoto: true);
        $check   = $trabajo->checks()->where('exige_foto', true)->firstOrFail();

        $this->actingAs($this->admin())
            ->post("/calidad/checks/{$check->id}/fotos", [
                'fotos' => [UploadedFile::fake()->image('acabado.jpg')],
            ])
            ->assertOk();

        $this->actingAs($this->admin())
            ->post("/calidad/unidades/{$trabajo->id}/terminar")
            ->assertOk();

        $this->assertSame(0, $trabajo->checks()->where('resultado', 'pendiente')->count());
    }

    public function test_la_orden_no_se_sella_con_puntos_sin_resolver(): void
    {
        $trabajo = $this->unidad();
        $op      = $trabajo->opItem->op;

        $this->actingAs($this->admin())
            ->post("/calidad/ops/{$op->id}/terminar")
            ->assertSessionHasErrors('calidad');

        $this->assertNull($op->fresh()->calidad_aprobada_at);
    }

    public function test_una_falla_critica_tampoco_deja_sellar(): void
    {
        $trabajo = $this->unidad();
        $op      = $trabajo->opItem->op;

        foreach ($trabajo->checks as $i => $check) {
            $this->actingAs($this->admin())
                ->patchJson("/calidad/checks/{$check->id}", ['resultado' => $i === 0 ? 'falla' : 'cumple'])
                ->assertOk();
        }

        $this->actingAs($this->admin())
            ->post("/calidad/ops/{$op->id}/terminar")
            ->assertSessionHasErrors('calidad');

        $this->assertNull($op->fresh()->calidad_aprobada_at);
    }

    public function test_reabrir_una_unidad_le_quita_el_sello_a_la_orden(): void
    {
        $trabajo = $this->unidad();

        $this->actingAs($this->admin())->post("/calidad/unidades/{$trabajo->id}/terminar")->assertOk();
        $this->assertNotNull($trabajo->opItem->op->fresh()->calidad_aprobada_at);

        $this->actingAs($this->admin())->post("/calidad/unidades/{$trabajo->id}/reabrir")->assertOk();

        $this->assertNull($trabajo->opItem->op->fresh()->calidad_aprobada_at);
        $this->assertSame(2, $trabajo->checks()->where('resultado', 'pendiente')->count());
    }

    public function test_la_ficha_de_verificacion_trae_la_informacion_del_proyecto(): void
    {
        $trabajo = $this->unidad();

        $this->actingAs($this->admin())
            ->get("/calidad/unidades/{$trabajo->id}")
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->component('Calidad/Show')
                ->where('item.descripcion', 'Puerta de prueba')
                ->has('ficha.variables', 2)
                ->has('op')
                ->has('pasos')
            );
    }

    public function test_sin_el_permiso_de_calidad_no_se_entra(): void
    {
        $vendedor = User::factory()->create(['rol' => 'vendedor']);

        $this->actingAs($vendedor)->get('/calidad')->assertForbidden();
    }
}
