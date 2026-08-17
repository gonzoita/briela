<?php

namespace Tests\Feature;

use App\Models\Ensamble;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Un ensamble no se puede guardar sin su flujo de producción.
 *
 * Sin pasos, la OP nace con el trabajo vacío: el operario escanea su QR y no tiene nada que
 * marcar, el avance se queda en cero y la orden quieta en «confirmada» sin que nada lo
 * explique. Estas pruebas fijan el candado y el guardado.
 */
class EnsamblePasosProduccionTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'administrador']);
    }

    /** Un ensamble directo mínimo, con los pasos que se le pasen. */
    private function payload(array $pasos): array
    {
        return [
            'tipo_armado' => 'directo',
            'nombre'      => 'Puerta de prueba',
            'lineas'      => [
                ['concepto' => 'Lámina', 'cantidad' => 2, 'precio_unit' => 15000, 'unidad' => 'unidad'],
            ],
            'pasos_trabajo' => $pasos,
        ];
    }

    public function test_no_se_puede_crear_un_ensamble_sin_pasos_de_produccion(): void
    {
        $this->actingAs($this->admin())
            ->post('/ensambles', $this->payload([]))
            ->assertSessionHasErrors('pasos_trabajo');

        $this->assertDatabaseCount('ensambles', 0);
    }

    public function test_un_paso_sin_nombre_tampoco_pasa(): void
    {
        $this->actingAs($this->admin())
            ->post('/ensambles', $this->payload([['nombre' => '', 'peso_porcentaje' => 100]]))
            ->assertSessionHasErrors('pasos_trabajo.0.nombre');
    }

    public function test_al_crear_el_ensamble_queda_con_su_flujo_de_produccion(): void
    {
        $this->actingAs($this->admin())
            ->post('/ensambles', $this->payload([
                ['nombre' => 'Cortar',  'peso_porcentaje' => 40, 'orden' => 0, 'es_paso_final' => false],
                ['nombre' => 'Armar',   'peso_porcentaje' => 35, 'orden' => 1, 'es_paso_final' => false],
                ['nombre' => 'Empacar', 'peso_porcentaje' => 25, 'orden' => 2, 'es_paso_final' => true],
            ]))
            ->assertRedirect();

        $ensamble = Ensamble::firstOrFail();
        $pasos    = $ensamble->templateTrabajo->pasos()->orderBy('orden')->get();

        $this->assertCount(3, $pasos);
        $this->assertSame(['Cortar', 'Armar', 'Empacar'], $pasos->pluck('nombre')->all());
        $this->assertSame(100.0, (float) $pasos->sum('peso_porcentaje'));
    }

    public function test_el_paso_final_es_uno_solo(): void
    {
        $this->actingAs($this->admin())
            ->post('/ensambles', $this->payload([
                ['nombre' => 'Cortar', 'peso_porcentaje' => 50, 'es_paso_final' => true],
                ['nombre' => 'Armar',  'peso_porcentaje' => 50, 'es_paso_final' => true],
            ]))
            ->assertRedirect();

        $pasos = Ensamble::firstOrFail()->templateTrabajo->pasos()->orderBy('orden')->get();

        // El último marcado gana: es el que entrega la unidad a bodega, y con dos marcados
        // `EntregaAlmacenService` haría la entrega dos veces.
        $this->assertSame([false, true], $pasos->pluck('es_paso_final')->all());
    }

    public function test_guardar_sin_tocar_los_pasos_no_los_vuelve_a_crear(): void
    {
        $pasos = [
            ['nombre' => 'Cortar', 'peso_porcentaje' => 50, 'orden' => 0, 'es_paso_final' => false],
            ['nombre' => 'Armar',  'peso_porcentaje' => 50, 'orden' => 1, 'es_paso_final' => true],
        ];

        $this->actingAs($this->admin())->post('/ensambles', $this->payload($pasos))->assertRedirect();

        $ensamble = Ensamble::firstOrFail();
        $idsAntes = $ensamble->templateTrabajo->pasos()->orderBy('orden')->pluck('id')->all();

        $this->actingAs($this->admin())
            ->put("/ensambles/{$ensamble->id}", $this->payload($pasos))
            ->assertRedirect();

        $idsDespues = $ensamble->fresh()->templateTrabajo->pasos()->orderBy('orden')->pluck('id')->all();

        // Reescribirlos borra y recrea las filas, y eso deja en null el `template_paso_id` de
        // los trabajos en curso. Guardar el precio de un ensamble no puede hacer eso.
        $this->assertSame($idsAntes, $idsDespues);
    }
}
