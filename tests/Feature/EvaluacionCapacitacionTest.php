<?php

namespace Tests\Feature;

use App\Models\Curso;
use App\Models\CursoEvaluacion;
use App\Models\CursoLeccion;
use App\Models\CursoModulo;
use App\Models\EvaluacionOpcion;
use App\Models\EvaluacionPregunta;
use App\Models\Inscripcion;
use App\Models\Operario;
use App\Models\ProgresoLeccion;
use App\Models\PuntoColaborador;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EvaluacionCapacitacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_flujo_completo_evaluacion_certificado_y_puntos(): void
    {
        Storage::fake('public');

        $colaborador = User::factory()->create(['rol' => 'operario']);
        $operario    = Operario::create([
            'user_id'   => $colaborador->id,
            'nombre'    => $colaborador->name,
            'documento' => '123456789',
        ]);

        $admin = User::factory()->create(['rol' => 'administrador']);

        $curso   = Curso::create([
            'titulo'           => 'Curso de prueba',
            'publico_objetivo' => 'colaborador',
            'obligatorio'      => false,
            'activo'           => true,
            'puntos_otorga'    => 50,
        ]);
        $modulo  = CursoModulo::create(['curso_id' => $curso->id, 'nombre' => 'Módulo 1', 'orden' => 1]);
        $leccion = CursoLeccion::create(['curso_modulo_id' => $modulo->id, 'nombre' => 'Lección 1', 'tipo' => 'texto', 'contenido' => 'contenido', 'orden' => 1]);

        $evaluacion = CursoEvaluacion::create([
            'curso_id'                 => $curso->id,
            'nombre'                   => 'Evaluación final',
            'nota_minima_aprobacion'   => 70,
            'requiere_revision_manual' => true,
        ]);

        $p1  = EvaluacionPregunta::create(['curso_evaluacion_id' => $evaluacion->id, 'enunciado' => '2 + 2', 'tipo' => 'opcion_multiple', 'orden' => 1]);
        EvaluacionOpcion::create(['evaluacion_pregunta_id' => $p1->id, 'texto' => '3', 'es_correcta' => false, 'orden' => 1]);
        $p1c = EvaluacionOpcion::create(['evaluacion_pregunta_id' => $p1->id, 'texto' => '4', 'es_correcta' => true, 'orden' => 2]);

        $p2  = EvaluacionPregunta::create(['curso_evaluacion_id' => $evaluacion->id, 'enunciado' => 'Color del cielo', 'tipo' => 'opcion_multiple', 'orden' => 2]);
        $p2c = EvaluacionOpcion::create(['evaluacion_pregunta_id' => $p2->id, 'texto' => 'Azul', 'es_correcta' => true, 'orden' => 1]);
        EvaluacionOpcion::create(['evaluacion_pregunta_id' => $p2->id, 'texto' => 'Verde', 'es_correcta' => false, 'orden' => 2]);

        $p3 = EvaluacionPregunta::create(['curso_evaluacion_id' => $evaluacion->id, 'enunciado' => 'Describe el proceso', 'tipo' => 'abierta', 'orden' => 3]);

        $inscripcion = Inscripcion::create([
            'inscribible_id'   => $colaborador->id,
            'inscribible_type' => User::class,
            'curso_id'         => $curso->id,
            'obligatorio'      => false,
            'estado'           => 'pendiente',
        ]);

        ProgresoLeccion::create(['inscripcion_id' => $inscripcion->id, 'curso_leccion_id' => $leccion->id, 'completado' => true, 'completado_at' => now()]);
        $inscripcion->marcarCompletado();

        $this->assertSame(100, $inscripcion->porcentajeAvance());

        // ── El colaborador ve la evaluación ──────────────────────────────────
        $this->actingAs($colaborador)
            ->get("/mi-capacitacion/{$curso->id}/evaluacion")
            ->assertOk();

        // ── El colaborador envía sus respuestas (con la abierta pendiente) ───
        $respuesta = $this->actingAs($colaborador)
            ->postJson("/mi-capacitacion/{$curso->id}/evaluacion", [
                'respuestas' => [
                    ['pregunta_id' => $p1->id, 'opcion_id' => $p1c->id],
                    ['pregunta_id' => $p2->id, 'opcion_id' => $p2c->id],
                    ['pregunta_id' => $p3->id, 'texto_respuesta' => 'El proceso consiste en...'],
                ],
            ]);

        $respuesta->assertOk();
        $respuesta->assertJson(['estado' => 'pendiente_revision']);

        $intento = $inscripcion->intentos()->latest('id')->first();
        $this->assertSame('pendiente_revision', $intento->estado);
        $this->assertNull($intento->aprobado);

        $inscripcion->refresh();
        $this->assertNotSame('aprobado', $inscripcion->estado);

        // ── El administrador califica la pregunta abierta ────────────────────
        $this->actingAs($admin)
            ->post("/capacitacion/revision-evaluaciones/{$intento->id}/calificar", [
                'notas' => [
                    ['pregunta_id' => $p3->id, 'correcta' => true],
                ],
            ])
            ->assertRedirect('/capacitacion/revision-evaluaciones');

        $intento->refresh();
        $this->assertSame('calificado', $intento->estado);
        $this->assertTrue((bool) $intento->aprobado);
        $this->assertEquals(100.0, (float) $intento->nota);

        // ── La inscripción queda aprobada y se genera el certificado ────────
        $inscripcion->refresh();
        $this->assertSame('aprobado', $inscripcion->estado);

        $certificado = $inscripcion->certificado;
        $this->assertNotNull($certificado);
        $this->assertNotNull($certificado->pdf_path);
        Storage::disk('public')->assertExists($certificado->pdf_path);

        // ── El colaborador puede descargar su certificado ────────────────────
        $this->actingAs($colaborador)
            ->get("/mi-capacitacion/{$curso->id}/certificado")
            ->assertOk();

        // ── Se otorgaron los puntos_otorga configurados en el curso ──────────
        $puntoRegistrado = PuntoColaborador::where('concepto', 'like', '%Curso completado%')->latest()->first();
        $this->assertNotNull($puntoRegistrado);
        $this->assertSame($operario->id, $puntoRegistrado->operario_id);
        $this->assertSame(50, $puntoRegistrado->puntos);

        $operario->refresh();
        $this->assertSame(50, $operario->puntos_totales);
    }

    public function test_candado_de_avance_entre_modulos_con_evaluacion_de_modulo(): void
    {
        Storage::fake('public');

        $colaborador = User::factory()->create(['rol' => 'operario']);
        $operario    = Operario::create([
            'user_id'   => $colaborador->id,
            'nombre'    => $colaborador->name,
            'documento' => '987654321',
        ]);

        $curso = Curso::create([
            'titulo'           => 'Curso con módulos',
            'publico_objetivo' => 'colaborador',
            'obligatorio'      => false,
            'activo'           => true,
            'puntos_otorga'    => 30,
        ]);

        $modulo1 = CursoModulo::create(['curso_id' => $curso->id, 'nombre' => 'Módulo 1', 'orden' => 1]);
        $modulo2 = CursoModulo::create(['curso_id' => $curso->id, 'nombre' => 'Módulo 2', 'orden' => 2]);

        $leccion1 = CursoLeccion::create(['curso_modulo_id' => $modulo1->id, 'nombre' => 'Lección 1', 'tipo' => 'texto', 'contenido' => 'x', 'orden' => 1]);
        $leccion2 = CursoLeccion::create(['curso_modulo_id' => $modulo2->id, 'nombre' => 'Lección 2', 'tipo' => 'texto', 'contenido' => 'y', 'orden' => 1]);

        // Módulo 1 tiene evaluación (candado); módulo 2 no tiene evaluación propia.
        $evaluacionModulo1 = CursoEvaluacion::create([
            'curso_modulo_id'        => $modulo1->id,
            'nombre'                 => 'Evaluación módulo 1',
            'nota_minima_aprobacion' => 70,
        ]);

        $pm1  = EvaluacionPregunta::create(['curso_evaluacion_id' => $evaluacionModulo1->id, 'enunciado' => '1 + 1', 'tipo' => 'opcion_multiple', 'orden' => 1]);
        $pm1c = EvaluacionOpcion::create(['evaluacion_pregunta_id' => $pm1->id, 'texto' => '2', 'es_correcta' => true, 'orden' => 1]);
        EvaluacionOpcion::create(['evaluacion_pregunta_id' => $pm1->id, 'texto' => '3', 'es_correcta' => false, 'orden' => 2]);

        // El curso conserva su evaluación final (requisito para el certificado).
        $evaluacionFinal = CursoEvaluacion::create([
            'curso_id'               => $curso->id,
            'nombre'                 => 'Evaluación final',
            'nota_minima_aprobacion' => 70,
        ]);

        $pf  = EvaluacionPregunta::create(['curso_evaluacion_id' => $evaluacionFinal->id, 'enunciado' => '3 + 3', 'tipo' => 'opcion_multiple', 'orden' => 1]);
        $pfc = EvaluacionOpcion::create(['evaluacion_pregunta_id' => $pf->id, 'texto' => '6', 'es_correcta' => true, 'orden' => 1]);
        EvaluacionOpcion::create(['evaluacion_pregunta_id' => $pf->id, 'texto' => '5', 'es_correcta' => false, 'orden' => 2]);

        $inscripcion = Inscripcion::create([
            'inscribible_id'   => $colaborador->id,
            'inscribible_type' => User::class,
            'curso_id'         => $curso->id,
            'obligatorio'      => false,
            'estado'           => 'pendiente',
        ]);

        // (a) módulo 2 bloqueado antes de aprobar la evaluación del módulo 1
        $this->assertTrue($inscripcion->moduloDesbloqueado($modulo1));
        $this->assertFalse($inscripcion->moduloDesbloqueado($modulo2));

        $this->actingAs($colaborador)
            ->postJson("/mi-capacitacion/{$curso->id}/lecciones/{$leccion2->id}")
            ->assertForbidden();

        // Completar el módulo 1 y aprobar su evaluación
        $this->actingAs($colaborador)
            ->postJson("/mi-capacitacion/{$curso->id}/lecciones/{$leccion1->id}")
            ->assertOk();

        $respuestaModulo = $this->actingAs($colaborador)
            ->postJson("/mi-capacitacion/{$curso->id}/modulos/{$modulo1->id}/evaluacion", [
                'respuestas' => [
                    ['pregunta_id' => $pm1->id, 'opcion_id' => $pm1c->id],
                ],
            ]);

        $respuestaModulo->assertOk();
        $respuestaModulo->assertJson(['aprobado' => true]);

        // (b) módulo 2 desbloqueado después de aprobarla
        $inscripcion->refresh();
        $this->assertTrue($inscripcion->moduloDesbloqueado($modulo2));

        // (c) aprobar la evaluación de módulo NO genera certificado ni puntos ni cambia el estado de la inscripción
        $this->assertNotSame('aprobado', $inscripcion->estado);
        $this->assertNull($inscripcion->certificado);
        $this->assertNull(PuntoColaborador::where('concepto', 'like', '%Curso con módulos%')->first());

        // Completar el módulo 2 y aprobar la evaluación final
        $this->actingAs($colaborador)
            ->postJson("/mi-capacitacion/{$curso->id}/lecciones/{$leccion2->id}")
            ->assertOk();

        $respuestaFinal = $this->actingAs($colaborador)
            ->postJson("/mi-capacitacion/{$curso->id}/evaluacion", [
                'respuestas' => [
                    ['pregunta_id' => $pf->id, 'opcion_id' => $pfc->id],
                ],
            ]);

        $respuestaFinal->assertOk();
        $respuestaFinal->assertJson(['aprobado' => true]);

        // (d) completar módulo 2 + aprobar evaluación final SÍ genera certificado y puntos, igual que antes
        $inscripcion->refresh();
        $this->assertSame('aprobado', $inscripcion->estado);
        $this->assertNotNull($inscripcion->certificado);

        $puntoRegistrado = PuntoColaborador::where('concepto', 'like', '%Curso con módulos%')->latest()->first();
        $this->assertNotNull($puntoRegistrado);
        $this->assertSame(30, $puntoRegistrado->puntos);
    }
}
