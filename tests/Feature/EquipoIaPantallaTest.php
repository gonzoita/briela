<?php

namespace Tests\Feature;

use App\Models\AgenteEjecucion;
use App\Models\AgenteInterno;
use App\Models\CargoIa;
use App\Models\Configuracion;
use App\Models\IaConsumo;
use App\Models\PromptVersionIa;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Services\Agentes\AgentesDeFabrica;
use App\Services\IA\IaService;
use App\Support\Modulos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La pestaña «Equipo interno»: permisos, módulo apagado, validaciones y los endpoints que la
 * alimentan. La IA va simulada.
 */
class EquipoIaPantallaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Modulos::guardar([], 'instalacion');
        Cache::flush();
        Configuracion::set('ia_api_key', 'llave-de-prueba');

        $this->admin = User::factory()->create(['rol' => 'administrador']);
        Sede::query()->first() ?? Sede::create(['nombre' => 'Principal', 'es_principal' => true]);
        $this->actingAs($this->admin);
    }

    private function conPermisos(array $permisos): User
    {
        $rol = Rol::create(['nombre' => 'Rol ' . uniqid(), 'rol_base' => 'vendedor', 'activo' => true, 'todas_las_sedes' => true]);
        $rol->sincronizarPermisos($permisos);

        return User::factory()->create(['rol' => 'vendedor', 'rol_id' => $rol->id]);
    }

    private function cargoPropio(array $extra = []): CargoIa
    {
        $rol = Rol::create(['nombre' => 'Rol agente ' . uniqid(), 'rol_base' => 'operario', 'activo' => true, 'todas_las_sedes' => true]);
        $rol->sincronizarPermisos(['ops.ver', 'cotizaciones.ver']);

        return CargoIa::create($extra + [
            'nombre' => 'Cargo de prueba', 'rol_id' => $rol->id, 'nivel_modelo' => 'economico', 'max_pasos' => 6, 'max_tokens' => 1200,
            'herramientas' => ['produccion_resumen', 'ops_por_entregar'], 'instrucciones' => 'Sé breve.',
        ]);
    }

    private function datosAgente(CargoIa $cargo, array $extra = []): array
    {
        return $extra + [
            'nombre' => 'Agente de prueba', 'cargo_id' => $cargo->id, 'modo' => 'solo_lectura',
            'herramientas' => ['produccion_resumen'], 'frecuencia' => ['tipo' => 'manual'],
        ];
    }

    // ─── Acceso ──────────────────────────────────────────────────────────────

    public function test_con_el_modulo_apagado_la_api_no_existe(): void
    {
        Modulos::guardar(['equipo_ia'], 'instalacion');

        $this->getJson('/api/equipo-ia/oficina')->assertNotFound();
        $this->getJson('/api/equipo-ia/pulso')->assertNotFound();
    }

    public function test_sin_permiso_no_se_entra(): void
    {
        $this->actingAs($this->conPermisos(['agentes.ver']));

        $this->getJson('/api/equipo-ia/oficina')->assertForbidden();
        $this->postJson('/api/equipo-ia/agentes', [])->assertForbidden();
    }

    public function test_cada_permiso_abre_solo_lo_suyo(): void
    {
        $this->actingAs($this->conPermisos(['equipo_ia.ver']));

        $this->getJson('/api/equipo-ia/oficina')->assertOk();
        $this->getJson('/api/equipo-ia/catalogos')->assertForbidden();
        $this->getJson('/api/equipo-ia/consumo')->assertForbidden();
        $a = AgenteInterno::first() ?? AgenteInterno::create(['nombre' => 'X', 'modo' => 'propone']);
        $p = \App\Models\AgentePropuesta::create(['agente_id' => $a->id, 'accion' => 'tarea_crm', 'parametros' => [], 'resumen' => 'x']);
        $this->postJson("/api/equipo-ia/propuestas/{$p->id}/aprobar")->assertForbidden();
    }

    public function test_la_pantalla_agentes_solo_ofrece_la_pestana_a_quien_puede_y_con_el_modulo_encendido(): void
    {
        $this->get('/configuracion/agentes')->assertInertia(fn ($p) => $p->where('equipo.gestionar', true)->where('equipo.aprobar', true));

        $this->actingAs($this->conPermisos(['agentes.ver']));
        $this->get('/configuracion/agentes')->assertInertia(fn ($p) => $p->where('equipo', null));

        $this->actingAs($this->admin);
        Modulos::guardar(['equipo_ia'], 'instalacion');
        $this->get('/configuracion/agentes')->assertInertia(fn ($p) => $p->where('equipo', null));
    }

    // ─── La oficina ──────────────────────────────────────────────────────────

    public function test_la_primera_vez_llegan_los_agentes_de_fabrica_apagados(): void
    {
        $r = $this->getJson('/api/equipo-ia/oficina')->assertOk();

        $this->assertCount(3, $r->json('agentes'));
        $this->assertSame(['apagado'], collect($r->json('agentes'))->pluck('estado')->unique()->all());
        $this->assertSame(0, $r->json('pendientes'));
    }

    public function test_el_estado_de_la_oficina_se_deriva_de_lo_que_pasa(): void
    {
        app(AgentesDeFabrica::class)->sembrar();
        $a = AgenteInterno::where('clave', 'analista_del_dia')->first();
        $a->update(['activo' => true]);

        $estado = fn () => collect($this->getJson('/api/equipo-ia/oficina')->json('agentes'))->firstWhere('id', $a->id)['estado'];

        $this->assertSame('libre', $estado());

        $e = AgenteEjecucion::create(['agente_id' => $a->id, 'estado' => 'corriendo', 'origen' => 'manual']);
        $this->assertSame('trabajando', $estado());

        $e->update(['estado' => 'terminada', 'terminada_at' => now(), 'resumen' => 'Todo bien.']);
        $this->assertSame('terminado', $estado());

        \App\Models\AgentePropuesta::create(['agente_id' => $a->id, 'accion' => 'tarea_crm', 'parametros' => [], 'resumen' => 'x', 'estado' => 'pendiente']);
        $this->assertSame('aprobacion', $estado());

        $e->update(['estado' => 'fallida', 'error' => 'sin saldo']);
        $this->assertSame('error', $estado());
    }

    public function test_el_pulso_cambia_cuando_algo_cambia(): void
    {
        app(AgentesDeFabrica::class)->sembrar();
        $a = AgenteInterno::first();

        $antes = $this->getJson('/api/equipo-ia/pulso')->json('firma');
        $this->assertSame($antes, $this->getJson('/api/equipo-ia/pulso')->json('firma'), 'sin cambios, la misma firma');

        AgenteEjecucion::create(['agente_id' => $a->id, 'estado' => 'en_cola', 'origen' => 'manual']);

        $this->assertNotSame($antes, $this->getJson('/api/equipo-ia/pulso')->json('firma'));
        $this->assertSame(1, $this->getJson('/api/equipo-ia/pulso')->json('en_curso'));
    }

    // ─── La oficina en vivo ──────────────────────────────────────────────────

    public function test_la_oficina_dice_que_esta_haciendo_cada_agente_que_corre(): void
    {
        app(AgentesDeFabrica::class)->sembrar();
        $a = AgenteInterno::where('clave', 'seguimiento_comercial')->first() ?? AgenteInterno::first();
        $a->update(['activo' => true]);
        $actual = fn () => collect($this->getJson('/api/equipo-ia/oficina')->json('agentes'))->firstWhere('id', $a->id)['actual'];

        $this->assertNull($actual(), 'sin corrida no hay nada en curso');

        $e = AgenteEjecucion::create(['agente_id' => $a->id, 'estado' => 'en_cola', 'origen' => 'manual']);
        $this->assertSame('Esperando turno', $actual()['accion']);

        $e->update(['estado' => 'corriendo', 'iniciada_at' => now()]);
        \App\Models\AgentePaso::create(['ejecucion_id' => $e->id, 'orden' => 1, 'tipo' => 'mensaje', 'resumen' => 'Pidió 1 herramienta(s).', 'created_at' => now()]);
        $this->assertSame('Pensando', $actual()['accion']);

        \App\Models\AgentePaso::create(['ejecucion_id' => $e->id, 'orden' => 2, 'tipo' => 'herramienta', 'herramienta' => 'cotizaciones_sin_respuesta', 'resumen' => '3 cotizaciones', 'created_at' => now()]);
        $ahora = $actual();
        $this->assertSame('Revisando cotizaciones sin respuesta', $ahora['accion']);
        $this->assertSame('3 cotizaciones', $ahora['detalle']);
        $this->assertSame(2, $ahora['pasos']);
        $this->assertSame($e->id, $ahora['ejecucion_id']);

        \App\Models\AgentePaso::create(['ejecucion_id' => $e->id, 'orden' => 3, 'tipo' => 'herramienta', 'herramienta' => 'proponer_nota_cotizacion', 'resumen' => 'Propuesta lista', 'created_at' => now()]);
        $this->assertSame('Redactando una propuesta: nota cotizacion', $actual()['accion']);

        $e->update(['estado' => 'terminada', 'terminada_at' => now()]);
        $this->assertNull($actual(), 'al terminar ya no hay nada en curso');
    }

    public function test_el_pulso_se_entera_de_cada_paso_nuevo(): void
    {
        app(AgentesDeFabrica::class)->sembrar();
        $e = AgenteEjecucion::create(['agente_id' => AgenteInterno::first()->id, 'estado' => 'corriendo', 'origen' => 'manual']);

        $antes = $this->getJson('/api/equipo-ia/pulso')->json('firma');

        // Un paso nuevo no toca la corrida, pero la oficina en vivo tiene que cambiar el globo.
        \App\Models\AgentePaso::create(['ejecucion_id' => $e->id, 'orden' => 1, 'tipo' => 'herramienta', 'herramienta' => 'leads_quietos', 'created_at' => now()]);

        $this->assertNotSame($antes, $this->getJson('/api/equipo-ia/pulso')->json('firma'));
    }

    public function test_el_tablero_trae_las_cifras_del_equipo_y_del_negocio(): void
    {
        app(AgentesDeFabrica::class)->sembrar();
        $a = AgenteInterno::first();
        $a->update(['activo' => true]);
        AgenteEjecucion::create(['agente_id' => $a->id, 'estado' => 'corriendo', 'origen' => 'manual']);
        AgenteEjecucion::create(['agente_id' => $a->id, 'estado' => 'terminada', 'origen' => 'manual', 'prueba' => true]);
        IaConsumo::create(['fecha' => today(), 'origen' => 'agentes', 'tipo' => 'chat', 'modelo' => 'x', 'tokens_entrada' => 1200, 'tokens_salida' => 300, 'created_at' => now()]);

        $t = $this->getJson('/api/equipo-ia/oficina')->json('tablero');

        $this->assertSame(1, $t['agentes_activos']);
        $this->assertSame(1, $t['trabajando']);
        $this->assertSame(1, $t['corridas_hoy'], 'las pruebas en seco no cuentan como corridas');
        $this->assertSame(1500, $t['tokens_hoy']);
        $this->assertSame('operativo', $t['estado']);
        // El administrador ve las órdenes y lo vendido hoy.
        $this->assertSame(0, $t['ops_en_curso']);
        $this->assertEquals(0, $t['ventas_hoy']);
    }

    public function test_sin_permiso_de_ordenes_el_tablero_no_muestra_ventas(): void
    {
        $usuario = $this->conPermisos(['equipo_ia.ver']);
        $this->actingAs($usuario);

        $t = $this->getJson('/api/equipo-ia/oficina')->assertOk()->json('tablero');

        $this->assertNull($t['ventas_hoy'], 'el tablero no puede ser la puerta trasera a las ventas');
        $this->assertNull($t['ops_en_curso']);
        $this->assertArrayHasKey('agentes_activos', $t);
    }

    // ─── Crear y configurar ──────────────────────────────────────────────────

    public function test_crear_un_agente_recorta_las_herramientas_al_techo_del_cargo(): void
    {
        $cargo = $this->cargoPropio();

        $r = $this->postJson('/api/equipo-ia/agentes', $this->datosAgente($cargo, [
            'herramientas' => ['produccion_resumen', 'cartera_pendiente'], // la segunda no es del cargo
        ]))->assertCreated();

        $this->assertSame(['produccion_resumen'], $r->json('herramientas_efectivas'));
        $this->assertFalse($r->json('activo'), 'un agente nuevo nace apagado');

        // Su usuario de servicio queda listo, con el rol del cargo.
        $a = AgenteInterno::findOrFail($r->json('id'));
        $this->assertSame($cargo->rol_id, User::withoutGlobalScopes()->find($a->usuario_id)->rol_id);
        $this->assertSame(1, PromptVersionIa::where('origen_tipo', 'agente')->where('origen_id', $a->id)->count());
    }

    public function test_un_modelo_sin_herramientas_no_se_acepta_para_un_agente_que_las_usa(): void
    {
        Http::fake(['*/models' => Http::response(['data' => [
            ['id' => 'sin/tools',  'name' => 'Sin tools',  'architecture' => ['output_modalities' => ['text']], 'supported_parameters' => ['temperature'], 'context_length' => 64000],
            ['id' => 'con/tools',  'name' => 'Con tools',  'architecture' => ['output_modalities' => ['text']], 'supported_parameters' => ['tools', 'tool_choice'], 'context_length' => 64000],
        ]])]);

        $cargo = $this->cargoPropio();

        $this->postJson('/api/equipo-ia/agentes', $this->datosAgente($cargo, ['modelo' => 'sin/tools']))
            ->assertStatus(422)->assertJsonPath('errors.modelo.0', 'No soporta herramientas.');

        $this->postJson('/api/equipo-ia/agentes', $this->datosAgente($cargo, ['modelo' => 'con/tools']))->assertCreated();
    }

    public function test_no_se_activa_un_agente_sin_cargo_con_rol_activo_ni_sin_herramientas(): void
    {
        $cargo = $this->cargoPropio();
        $id = $this->postJson('/api/equipo-ia/agentes', $this->datosAgente($cargo))->json('id');

        // Con rol inactivo no se enciende.
        $cargo->rol->update(['activo' => false]);
        $this->putJson("/api/equipo-ia/agentes/{$id}", $this->datosAgente($cargo, ['activo' => true]))->assertStatus(422);
        $this->assertFalse(AgenteInterno::find($id)->activo);

        // Con rol activo y herramientas, sí.
        $cargo->rol->update(['activo' => true]);
        $this->putJson("/api/equipo-ia/agentes/{$id}", $this->datosAgente($cargo, ['activo' => true]))->assertOk()->assertJsonPath('activo', true);
    }

    public function test_un_agente_programado_exige_hora(): void
    {
        $this->postJson('/api/equipo-ia/agentes', $this->datosAgente($this->cargoPropio(), ['frecuencia' => ['tipo' => 'diaria']]))->assertStatus(422);
    }

    public function test_los_cargos_de_fabrica_no_se_editan_pero_se_copian(): void
    {
        app(AgentesDeFabrica::class)->sembrar();
        $fabrica = CargoIa::where('clave', 'vigia_produccion')->first();

        $this->putJson("/api/equipo-ia/cargos/{$fabrica->id}", [
            'nombre' => 'Cambiado', 'nivel_modelo' => 'economico', 'max_pasos' => 5, 'max_tokens' => 1000,
        ])->assertStatus(422);
        $this->deleteJson("/api/equipo-ia/cargos/{$fabrica->id}")->assertStatus(422);

        $id = $this->postJson("/api/equipo-ia/cargos/{$fabrica->id}/copiar")->assertCreated()->json('id');
        $copia = CargoIa::find($id);

        $this->assertFalse($copia->de_fabrica);
        $this->assertNull($copia->clave);
        $this->assertSame($fabrica->instrucciones, $copia->instrucciones);

        $this->putJson("/api/equipo-ia/cargos/{$id}", [
            'nombre' => 'Mi vigía', 'nivel_modelo' => 'potente', 'max_pasos' => 5, 'max_tokens' => 1000, 'herramientas' => ['ops_por_entregar'],
        ])->assertOk();
        $this->assertSame('Mi vigía', $copia->fresh()->nombre);
    }

    public function test_un_cargo_con_agentes_no_se_elimina(): void
    {
        $cargo = $this->cargoPropio();
        $this->postJson('/api/equipo-ia/agentes', $this->datosAgente($cargo));

        $this->deleteJson("/api/equipo-ia/cargos/{$cargo->id}")->assertStatus(422);
    }

    public function test_bajar_las_herramientas_de_un_cargo_se_las_quita_a_sus_agentes(): void
    {
        $cargo = $this->cargoPropio();
        $id    = $this->postJson('/api/equipo-ia/agentes', $this->datosAgente($cargo, ['herramientas' => ['produccion_resumen', 'ops_por_entregar']]))->json('id');

        $this->putJson("/api/equipo-ia/cargos/{$cargo->id}", [
            'nombre' => $cargo->nombre, 'nivel_modelo' => 'economico', 'max_pasos' => 6, 'max_tokens' => 1200, 'herramientas' => ['produccion_resumen'],
        ])->assertOk();

        $this->assertSame(['produccion_resumen'], AgenteInterno::find($id)->herramientasEfectivas());
    }

    public function test_eliminar_un_agente_conserva_su_historial(): void
    {
        $id = $this->postJson('/api/equipo-ia/agentes', $this->datosAgente($this->cargoPropio()))->json('id');
        $e  = AgenteEjecucion::create(['agente_id' => $id, 'estado' => 'terminada', 'origen' => 'manual']);

        $this->deleteJson("/api/equipo-ia/agentes/{$id}")->assertOk();

        $this->assertNull(AgenteInterno::find($id));
        $this->assertNotNull(AgenteEjecucion::find($e->id));
    }

    // ─── Prompt, versiones y modelo ──────────────────────────────────────────

    public function test_la_vista_previa_muestra_las_capas_y_la_primera_no_es_editable(): void
    {
        $cargo = $this->cargoPropio(['instrucciones' => 'Eres el vigía de {{empresa}}.']);

        $r = $this->postJson('/api/equipo-ia/prompt/vista-previa', [
            'cargo_id' => $cargo->id, 'nombre' => 'Rex', 'instrucciones' => 'Habla de {{agente}}.',
        ])->assertOk();

        $capas = collect($r->json('capas'));
        $this->assertFalse($capas->firstWhere('clave', 'sistema')['editable']);
        $this->assertStringContainsString('Nunca uses voseo', $capas->firstWhere('clave', 'sistema')['texto']);
        $this->assertStringContainsString('Habla de Rex.', $capas->firstWhere('clave', 'agente')['texto'], 'las variables se rellenan');
        $this->assertGreaterThan(100, $r->json('tokens'));
    }

    public function test_las_versiones_se_guardan_y_se_pueden_restaurar(): void
    {
        $cargo = $this->cargoPropio();
        $id = $this->postJson('/api/equipo-ia/agentes', $this->datosAgente($cargo, ['instrucciones' => 'Versión uno']))->json('id');

        $this->putJson("/api/equipo-ia/agentes/{$id}", $this->datosAgente($cargo, ['instrucciones' => 'Versión dos']))->assertOk();
        // Guardar lo mismo no crea otra versión.
        $this->putJson("/api/equipo-ia/agentes/{$id}", $this->datosAgente($cargo, ['instrucciones' => 'Versión dos']))->assertOk();

        $v = $this->getJson("/api/equipo-ia/versiones/agente/{$id}")->assertOk()->json();
        $this->assertCount(2, $v);
        $this->assertSame('Versión dos', $v[0]['instrucciones']);

        $this->postJson("/api/equipo-ia/agentes/{$id}/restaurar/1")->assertOk()->assertJsonPath('instrucciones', 'Versión uno');
        $this->assertCount(3, $this->getJson("/api/equipo-ia/versiones/agente/{$id}")->json(), 'restaurar también queda en el historial');
    }

    public function test_la_recomendacion_de_modelo_explica_sus_razones_y_filtra_por_herramientas(): void
    {
        Http::fake(['*/models' => Http::response(['data' => [
            ['id' => 'a/barato',  'name' => 'Barato',  'architecture' => ['output_modalities' => ['text']], 'supported_parameters' => ['tools'], 'context_length' => 128000, 'pricing' => ['prompt' => '0.0000001', 'completion' => '0.0000004']],
            ['id' => 'b/sintools', 'name' => 'Sin tools', 'architecture' => ['output_modalities' => ['text']], 'supported_parameters' => [], 'context_length' => 128000, 'pricing' => ['prompt' => '0.00000001', 'completion' => '0.00000004']],
            ['id' => 'c/corto',   'name' => 'Corto',   'architecture' => ['output_modalities' => ['text']], 'supported_parameters' => ['tools'], 'context_length' => 8000, 'pricing' => ['prompt' => '0.00000001', 'completion' => '0.00000004']],
        ]])]);

        $r = $this->postJson('/api/equipo-ia/recomendar-modelo', [
            'herramientas' => ['produccion_resumen'], 'instrucciones' => 'Resume la producción.',
        ])->assertOk();

        $this->assertSame('economico', $r->json('nivel'));
        $this->assertNotEmpty($r->json('razones'));
        // Solo sirven los que soportan herramientas Y tienen contexto suficiente.
        $this->assertSame(['a/barato'], collect($r->json('candidatos'))->pluck('id')->all());
        $this->assertStringContainsString('no una garantía', $r->json('nota'));

        $potente = $this->postJson('/api/equipo-ia/recomendar-modelo', [
            'herramientas' => ['produccion_resumen'], 'instrucciones' => 'Compara y prioriza; diagnostica la causa y analiza el riesgo.',
        ])->json();
        $this->assertSame('potente', $potente['nivel']);
    }

    public function test_la_lista_de_modelos_dice_cuales_soportan_herramientas(): void
    {
        Http::fake(['*/models' => Http::response(['data' => [
            ['id' => 'con/tools', 'name' => 'Con', 'architecture' => ['output_modalities' => ['text']], 'supported_parameters' => ['tools'], 'context_length' => 64000],
            ['id' => 'sin/tools', 'name' => 'Sin', 'architecture' => ['output_modalities' => ['text']], 'supported_parameters' => [], 'context_length' => 64000],
        ]])]);

        $m = collect($this->getJson('/api/equipo-ia/modelos')->assertOk()->json('modelos'))->keyBy('id');

        $this->assertTrue($m['con/tools']['herramientas']);
        $this->assertFalse($m['sin/tools']['herramientas']);
    }

    // ─── Corridas ────────────────────────────────────────────────────────────

    public function test_probar_corre_sin_guardar_nada_y_con_el_agente_apagado(): void
    {
        $cargo = $this->cargoPropio();
        $id = $this->postJson('/api/equipo-ia/agentes', $this->datosAgente($cargo))->json('id');

        Http::fake(['*' => Http::response([
            'model' => 'm', 'choices' => [['message' => ['role' => 'assistant', 'content' => 'Todo en orden.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 50, 'completion_tokens' => 5],
        ])]);

        $r = $this->postJson("/api/equipo-ia/agentes/{$id}/probar", ['tarea' => 'Cuenta las OP.'])->assertStatus(202);

        $e = AgenteEjecucion::findOrFail($r->json('ejecucion_id'));
        $this->assertSame('terminada', $e->estado, (string) $e->error);
        $this->assertTrue($e->prueba);
        $this->assertNull(AgenteInterno::find($id)->ultima_ejecucion_at, 'una prueba no cuenta como la última corrida');

        $detalle = $this->getJson("/api/equipo-ia/ejecuciones/{$e->id}")->assertOk();
        $this->assertSame('Todo en orden.', $detalle->json('resumen'));
        $this->assertNotEmpty($detalle->json('pasos_log'));
    }

    public function test_ejecutar_exige_agente_activo_y_respeta_la_pausa_general(): void
    {
        $cargo = $this->cargoPropio();
        $id = $this->postJson('/api/equipo-ia/agentes', $this->datosAgente($cargo))->json('id');

        $this->postJson("/api/equipo-ia/agentes/{$id}/ejecutar")->assertStatus(422);

        AgenteInterno::find($id)->update(['activo' => true]);
        $this->postJson('/api/equipo-ia/pausar', ['pausado' => true])->assertOk();
        $this->postJson("/api/equipo-ia/agentes/{$id}/ejecutar")->assertStatus(422);
        $this->assertTrue($this->getJson('/api/equipo-ia/oficina')->json('pausado'));
    }

    public function test_la_pausa_general_detiene_tambien_al_cron(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        app(AgentesDeFabrica::class)->sembrar();
        AgenteInterno::where('clave', 'analista_del_dia')->update(['activo' => true]);
        Configuracion::set('equipo_ia_pausado', '1');

        $this->assertSame(0, app(\App\Services\Agentes\LanzadorAgentes::class)->programadas(\Illuminate\Support\Carbon::parse('2026-10-13 06:35')));
    }

    public function test_el_consumo_del_asistente_y_el_de_los_agentes_se_anotan_por_separado(): void
    {
        Http::fake(['*' => Http::response([
            'model' => 'm', 'choices' => [['message' => ['role' => 'assistant', 'content' => 'hola'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 2],
        ])]);

        app(IaService::class)->texto('hola');

        $this->assertSame(1, IaConsumo::where('origen', 'asistente')->count());
        $this->assertSame(10, (int) IaConsumo::where('origen', 'asistente')->value('tokens_entrada'));
        $this->assertSame(0, IaConsumo::where('origen', 'agentes')->count());

        $r = $this->getJson('/api/equipo-ia/consumo')->assertOk();
        $this->assertSame([], $r->json('por_agente'), 'el asistente no aparece como agente');
        $this->assertSame('asistente', $r->json('por_dia.0.origen'));
    }

    public function test_un_error_de_la_ia_tambien_queda_en_el_libro(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        try {
            app(IaService::class)->texto('hola');
        } catch (\Throwable) {
        }

        $this->assertSame(1, IaConsumo::where('ok', false)->count());
    }
}
