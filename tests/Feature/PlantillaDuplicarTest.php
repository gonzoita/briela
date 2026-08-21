<?php

namespace Tests\Feature;

use App\Models\ChecklistCalidad;
use App\Models\PlantillaComponente;
use App\Models\PlantillaEnsamble;
use App\Models\PlantillaSeccion;
use App\Models\Producto;
use App\Models\TemplateTrabajo;
use App\Models\TemplateTrabajoPaso;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Duplicar una plantilla tiene que traerse la plantilla entera.
 *
 * Copiaba solo campos y componentes, y los componentes se quedaban apuntando a las secciones
 * de la plantilla original: quedaban guardados pero invisibles, porque la pantalla los agrupa
 * por las secciones propias. Los pasos de producción, la lista de calidad y la configuración
 * de salida no se copiaban en absoluto.
 */
class PlantillaDuplicarTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'administrador']);
    }

    private function plantillaCompleta(): PlantillaEnsamble
    {
        $plantilla = PlantillaEnsamble::create([
            'nombre'        => 'Puerta abatible',
            'activo'        => true,
            'config_salida' => ['mostrar_desglose' => true, 'etiqueta_precio' => 'Desde'],
        ]);

        $plantilla->campos()->create([
            'nombre' => 'ancho_vano', 'etiqueta' => 'Ancho', 'tipo' => 'decimal',
            'tipo_campo' => 'entrada', 'valor_defecto' => '1.2', 'orden' => 0,
        ]);

        $seccion = PlantillaSeccion::create([
            'plantilla_id' => $plantilla->id, 'nombre' => 'Estructura', 'orden' => 0,
        ]);

        $producto = Producto::create([
            'nombre' => 'Lámina', 'referencia' => 'LAM-1', 'tipo' => 'producto',
            'unidad_medida' => 'UN', 'precio_costo' => 1000, 'activo' => true,
        ]);

        PlantillaComponente::create([
            'plantilla_id' => $plantilla->id, 'producto_id' => $producto->id,
            'etiqueta' => 'Lámina frontal', 'formula' => 'ancho_vano * 2',
            'seccion_id' => $seccion->id, 'orden' => 0,
        ]);

        $template = TemplateTrabajo::create([
            'plantilla_ensamble_id' => $plantilla->id, 'nombre' => 'Puerta abatible', 'activo' => true,
        ]);
        TemplateTrabajoPaso::create([
            'template_id' => $template->id, 'nombre' => 'Cortar', 'descripcion' => 'Cortar la lámina',
            'peso_porcentaje' => 60, 'orden' => 0, 'nivel_dificultad' => 2,
        ]);
        TemplateTrabajoPaso::create([
            'template_id' => $template->id, 'nombre' => 'Entregar', 'descripcion' => 'Entregar a bodega',
            'peso_porcentaje' => 40, 'orden' => 1, 'nivel_dificultad' => 1,
            'depende_de' => [0], 'es_paso_final' => true,
        ]);

        ChecklistCalidad::create([
            'checkeable_type' => PlantillaEnsamble::class, 'checkeable_id' => $plantilla->id,
            'titulo' => 'Escuadra', 'orden' => 0, 'exige_foto' => true, 'es_critico' => true, 'activo' => true,
        ]);

        return $plantilla;
    }

    public function test_la_copia_se_trae_secciones_componentes_pasos_calidad_y_salida(): void
    {
        $plantilla = $this->plantillaCompleta();

        $res = $this->actingAs($this->admin())
            ->postJson("/cotizadores/plantillas/{$plantilla->id}/duplicar")
            ->assertOk();

        $copia = PlantillaEnsamble::where('nombre', 'Puerta abatible (copia)')->firstOrFail();

        $this->assertFalse($copia->activo, 'La copia nace inactiva.');
        $this->assertEqualsCanonicalizing(['mostrar_desglose' => true, 'etiqueta_precio' => 'Desde'], $copia->config_salida);

        $this->assertCount(1, $copia->campos);
        $this->assertCount(1, $copia->secciones);
        $this->assertCount(1, $copia->componentes);
        $this->assertCount(2, $copia->obtenerOCrearTemplateTrabajo()->pasos);
        $this->assertCount(1, $copia->checksCalidad);

        // La respuesta le da a la pantalla todo lo que necesita para dibujar la copia.
        $res->assertJsonPath('nombre', 'Puerta abatible (copia)')
            ->assertJsonCount(1, 'secciones')
            ->assertJsonCount(1, 'componentes')
            ->assertJsonCount(2, 'template_trabajo.pasos');
    }

    public function test_los_componentes_de_la_copia_apuntan_a_las_secciones_de_la_copia(): void
    {
        $plantilla = $this->plantillaCompleta();

        $this->actingAs($this->admin())
            ->postJson("/cotizadores/plantillas/{$plantilla->id}/duplicar")
            ->assertOk();

        $copia = PlantillaEnsamble::where('nombre', 'Puerta abatible (copia)')->firstOrFail();

        $seccionCopia   = $copia->secciones->first();
        $componenteCopia = $copia->componentes->first();

        $this->assertNotSame($plantilla->secciones->first()->id, $seccionCopia->id);
        $this->assertSame($seccionCopia->id, $componenteCopia->seccion_id,
            'El componente copiado tiene que colgar de la sección de SU plantilla, o no se ve.');
    }

    public function test_un_componente_no_se_puede_guardar_en_una_seccion_de_otra_plantilla(): void
    {
        $plantilla = $this->plantillaCompleta();
        $otra      = PlantillaEnsamble::create(['nombre' => 'Otra', 'activo' => true]);
        $seccionAjena = PlantillaSeccion::create([
            'plantilla_id' => $otra->id, 'nombre' => 'Ajena', 'orden' => 0,
        ]);

        $this->actingAs($this->admin())
            ->postJson("/cotizadores/plantillas/{$plantilla->id}/componentes", [
                'producto_id' => Producto::first()->id,
                'formula'     => '1',
                'seccion_id'  => $seccionAjena->id,
            ])
            ->assertJsonValidationErrors('seccion_id');
    }
}
