<?php

namespace Tests\Feature;

use App\Models\Automatizacion;
use App\Models\AutomatizacionEjecucion;
use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CrmEtapa;
use App\Models\CrmLead;
use App\Models\CrmTarea;
use App\Models\Notificacion;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Services\Agentes\AgentesDeFabrica;
use App\Services\Automatizaciones\GrafoAutomatizacion;
use App\Services\Automatizaciones\LanzadorAutomatizaciones;
use App\Models\AgenteInterno;
use App\Support\Modulos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Automatizaciones: eventos → corridas, esperas que se retoman con datos frescos, frenos y permisos.
 */
class AutomatizacionesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow();
        Cache::flush();
        // El módulo nace apagado (migración): cada prueba lo enciende.
        Modulos::guardar([], 'instalacion');

        $this->admin = User::factory()->create(['rol' => 'administrador']);
        Sede::query()->first() ?? Sede::create(['nombre' => 'Principal', 'es_principal' => true]);
        $this->actingAs($this->admin);
    }

    // ─── Ayudas ──────────────────────────────────────────────────────────────

    private function disparo(string $evento = 'cotizacion.estado'): array
    {
        return ['id' => 'disparo', 'tipo' => 'disparador', 'config' => ['tipo' => 'evento', 'evento' => $evento]];
    }

    private function cadena(array ...$nodos): array
    {
        $aristas = [];
        for ($i = 0; $i < count($nodos) - 1; $i++) {
            $aristas[] = ['de' => $nodos[$i]['id'], 'a' => $nodos[$i + 1]['id']] + ($nodos[$i]['tipo'] === 'condicion' ? ['rama' => 'si'] : []);
        }

        return ['nodos' => $nodos, 'aristas' => $aristas];
    }

    private function condicion(string $id, string $campo, string $op, mixed $valor): array
    {
        return ['id' => $id, 'tipo' => 'condicion', 'config' => ['combinador' => 'y', 'reglas' => [['campo' => $campo, 'operador' => $op, 'valor' => $valor]]]];
    }

    private function avisoAlResponsable(string $id = 'avisa', string $titulo = 'Cotización {{numero}} de {{cliente}}'): array
    {
        return ['id' => $id, 'tipo' => 'accion', 'accion' => 'notificar', 'config' => ['destinatario' => 'responsable', 'titulo' => $titulo, 'mensaje' => 'Total {{total}}']];
    }

    private function armar(array $grafo, array $extra = []): Automatizacion
    {
        $d = GrafoAutomatizacion::disparadorDe($grafo);

        return Automatizacion::create($extra + [
            'nombre' => 'Prueba', 'grafo' => $grafo, 'activa' => true, 'creado_por' => $this->admin->id,
            'disparador_tipo' => $d['tipo'], 'disparador_clave' => $d['clave'],
        ]);
    }

    private function cotizacion(string $estado = 'borrador', ?User $responsable = null): Cotizacion
    {
        $cliente = Cliente::create(['tipo' => 'empresa', 'nombre' => 'Frío Andino SAS', 'tipo_identificacion' => 'NIT', 'numero_identificacion' => '900' . random_int(100000, 999999)]);

        return Cotizacion::create([
            'cliente_id' => $cliente->id, 'moneda' => 'COP', 'tasa_cambio' => 1, 'estado' => $estado,
            'responsable_id' => ($responsable ?? $this->admin)->id, 'total' => 4500000,
        ]);
    }

    private function avisos(User $u, string $tipo = 'automatizacion_aviso')
    {
        return Notificacion::where('user_id', $u->id)->where('tipo', $tipo)->get();
    }

    // ─── Validación ──────────────────────────────────────────────────────────

    public function test_un_grafo_sin_disparador_o_con_ciclos_no_se_guarda(): void
    {
        $this->assertNotEmpty(GrafoAutomatizacion::validar(['nodos' => [], 'aristas' => []], $this->admin));

        $sinDisparo = $this->cadena($this->avisoAlResponsable());
        $this->assertStringContainsString('disparador', implode(' ', GrafoAutomatizacion::validar($sinDisparo, $this->admin)));

        $ciclo = $this->cadena($this->disparo(), $this->avisoAlResponsable('a'), $this->avisoAlResponsable('b'));
        $ciclo['aristas'][] = ['de' => 'b', 'a' => 'a'];
        $this->assertStringContainsString('vueltas', implode(' ', GrafoAutomatizacion::validar($ciclo, $this->admin)));
    }

    public function test_una_automatizacion_no_puede_hacer_lo_que_su_autor_no_podria(): void
    {
        $rol = Rol::create(['nombre' => 'Solo automatizaciones', 'rol_base' => 'vendedor', 'activo' => true, 'todas_las_sedes' => true]);
        $rol->sincronizarPermisos(['automatizaciones.ver', 'automatizaciones.gestionar']);
        $persona = User::factory()->create(['rol' => 'vendedor', 'rol_id' => $rol->id]);

        $tarea = $this->cadena($this->disparo('lead.nuevo'), ['id' => 'tarea', 'tipo' => 'accion', 'accion' => 'crear_tarea_crm', 'config' => ['titulo' => 'Contactar']]);

        $this->assertStringContainsString('crm.editar', implode(' ', GrafoAutomatizacion::validar($tarea, $persona)));
        $this->assertSame([], GrafoAutomatizacion::validar($tarea, $this->admin));
    }

    public function test_las_reglas_de_sentido_comun(): void
    {
        $v = fn (array $g) => implode(' | ', GrafoAutomatizacion::validar($g, $this->admin));

        // Sin documento, las condiciones solo miran la hora, el día o lo que dejó la IA; y no hay «responsable».
        $manual = ['id' => 'disparo', 'tipo' => 'disparador', 'config' => ['tipo' => 'manual']];
        $this->assertStringContainsString('Sin un documento', $v($this->cadena($manual, $this->condicion('c', 'estado', 'igual', 'x'), $this->avisoAlResponsable())));
        $this->assertStringNotContainsString('Sin un documento', $v($this->cadena($manual, $this->condicion('c', 'hora', 'mayor', '6'), ['id' => 'a', 'tipo' => 'accion', 'accion' => 'notificar', 'config' => ['destinatario' => 'autor', 'titulo' => 'Hola']])));
        $this->assertStringContainsString('responsable', $v($this->cadena($manual, $this->avisoAlResponsable())));

        // Una acción de cotización no cuelga de un lead.
        $this->assertStringContainsString('trabaja sobre: cotización', $v($this->cadena($this->disparo('lead.nuevo'), ['id' => 'n', 'tipo' => 'accion', 'accion' => 'nota_cotizacion', 'config' => ['texto' => 'x']])));

        // Un campo que el evento no tiene, y una espera de más de 90 días.
        $this->assertStringContainsString('no tiene', $v($this->cadena($this->disparo(), $this->condicion('c', 'color', 'igual', 'x'))));
        $this->assertStringContainsString('90 días', $v($this->cadena($this->disparo(), ['id' => 'e', 'tipo' => 'espera', 'config' => ['cantidad' => 100, 'unidad' => 'dias']])));

        // Un evento de un módulo apagado no se ofrece.
        Modulos::guardar(['cotizaciones'], 'instalacion');
        $this->assertStringContainsString('apagado', $v($this->cadena($this->disparo())));
    }

    // ─── Eventos → corridas ──────────────────────────────────────────────────

    public function test_un_evento_dispara_la_automatizacion_y_avisa_con_los_datos_del_documento(): void
    {
        $vendedora = User::factory()->create(['rol' => 'vendedor']);
        $this->armar($this->cadena($this->disparo(), $this->condicion('es_enviada', 'estado', 'igual', 'enviada'), $this->avisoAlResponsable()));

        $cot = $this->cotizacion('borrador', $vendedora);
        $this->assertSame(0, $this->avisos($vendedora)->count(), 'en borrador la condición no se cumple');

        $cot->update(['estado' => 'enviada']);

        $aviso = $this->avisos($vendedora)->first();
        $this->assertNotNull($aviso);
        $this->assertSame("Cotización {$cot->numero} de Frío Andino SAS", $aviso->titulo);
        $this->assertSame('Total 4500000', $aviso->mensaje);
        $this->assertSame("/cotizaciones/{$cot->id}", $aviso->url);

        $e = AutomatizacionEjecucion::where('estado', 'terminada')->latest('id')->first();
        $this->assertSame(['disparador', 'condicion', 'accion'], $e->pasos->pluck('tipo')->all());
    }

    public function test_con_el_modulo_apagado_no_pasa_nada(): void
    {
        $this->armar($this->cadena($this->disparo(), $this->avisoAlResponsable()));
        Modulos::guardar(['automatizaciones'], 'instalacion');

        $this->cotizacion('enviada');

        $this->assertSame(0, AutomatizacionEjecucion::count());
    }

    public function test_una_automatizacion_pausada_no_escucha(): void
    {
        $this->armar($this->cadena($this->disparo(), $this->avisoAlResponsable()), ['activa' => false]);

        $this->cotizacion('enviada');

        $this->assertSame(0, AutomatizacionEjecucion::count());
    }

    public function test_el_mismo_cambio_en_el_mismo_minuto_es_una_sola_corrida(): void
    {
        $this->armar($this->cadena($this->disparo(), $this->avisoAlResponsable()));
        $cot = $this->cotizacion('enviada');
        $antes = AutomatizacionEjecucion::count();

        // El evento se vuelve a emitir (observadores encadenados): no debe duplicar el aviso.
        \App\Services\Automatizaciones\EventosAutomatizacion::emitir('cotizacion.estado', $cot->fresh());

        $this->assertSame($antes, AutomatizacionEjecucion::count());
    }

    public function test_un_fallo_en_las_automatizaciones_no_rompe_el_guardado_del_documento(): void
    {
        // Un grafo corrupto en la base: el documento se guarda igual.
        Automatizacion::create(['nombre' => 'Rota', 'grafo' => ['nodos' => 'basura'], 'activa' => true, 'creado_por' => $this->admin->id, 'disparador_tipo' => 'evento', 'disparador_clave' => 'cotizacion.estado']);

        $cot = $this->cotizacion('enviada');

        $this->assertSame('enviada', $cot->fresh()->estado);
    }

    public function test_el_tope_de_generaciones_corta_las_cadenas(): void
    {
        $this->armar($this->cadena($this->disparo(), $this->avisoAlResponsable()));
        app()->instance('briela.automatizacion.profundidad', LanzadorAutomatizaciones::PROFUNDIDAD_MAXIMA);

        $this->cotizacion('enviada');

        $this->assertSame(0, AutomatizacionEjecucion::count());
    }

    public function test_el_limite_por_hora_descarta_corridas_y_avisa_una_sola_vez(): void
    {
        $this->armar($this->cadena($this->disparo(), $this->avisoAlResponsable()), ['max_por_hora' => 2]);

        foreach (range(1, 5) as $_) {
            $this->cotizacion('enviada');
        }

        $this->assertSame(2, AutomatizacionEjecucion::count());
        $this->assertSame(1, $this->avisos($this->admin, 'automatizacion_fallo')->count(), 'un aviso por hora, no uno por corrida descartada');
    }

    // ─── Esperas ─────────────────────────────────────────────────────────────

    private function seguimientoACincoDias(): Automatizacion
    {
        return $this->armar($this->cadena(
            $this->disparo(),
            $this->condicion('enviada', 'estado', 'igual', 'enviada'),
            ['id' => 'espera', 'tipo' => 'espera', 'config' => ['cantidad' => 5, 'unidad' => 'dias']],
            $this->condicion('sigue', 'estado', 'igual', 'enviada'),
            $this->avisoAlResponsable('avisa', 'Sin respuesta: {{numero}}'),
        ));
    }

    public function test_una_espera_deja_la_corrida_guardada_y_el_cron_la_retoma(): void
    {
        $this->seguimientoACincoDias();
        $cot = $this->cotizacion('enviada');

        $e = AutomatizacionEjecucion::firstOrFail();
        $this->assertSame('esperando', $e->estado);
        $this->assertEqualsWithDelta(now()->addDays(5)->timestamp, $e->reanudar_at->timestamp, 5);
        $this->assertSame(0, $this->avisos($this->admin)->count());

        // Todavía no es la hora: el cron no hace nada.
        $this->assertSame(0, app(LanzadorAutomatizaciones::class)->reanudar());

        Carbon::setTestNow(now()->addDays(6));
        $this->assertSame(1, app(LanzadorAutomatizaciones::class)->reanudar());

        $this->assertSame('terminada', $e->fresh()->estado);
        $this->assertSame("Sin respuesta: {$cot->numero}", $this->avisos($this->admin)->first()->titulo);
    }

    public function test_al_volver_de_una_espera_la_condicion_mira_el_documento_de_hoy(): void
    {
        $this->seguimientoACincoDias();
        $cot = $this->cotizacion('enviada');

        // Mientras esperaba, el cliente la aprobó.
        $cot->update(['estado' => 'aprobada']);

        Carbon::setTestNow(now()->addDays(6));
        app(LanzadorAutomatizaciones::class)->reanudar();

        $this->assertSame(0, $this->avisos($this->admin)->count(), 'ya no está «enviada»: no hay nada que recordar');
        $this->assertSame('terminada', AutomatizacionEjecucion::where('evento', 'cotizacion.estado')->orderBy('id')->first()->estado);
    }

    public function test_editar_la_automatizacion_no_cambia_lo_que_ya_esperaba(): void
    {
        $a = $this->seguimientoACincoDias();
        $this->cotizacion('enviada');

        // Se cambia el aviso mientras la corrida espera: la corrida conserva SU copia.
        $grafo = $a->grafo;
        $grafo['nodos'][4]['config']['titulo'] = 'Texto nuevo';
        $a->update(['grafo' => $grafo]);

        Carbon::setTestNow(now()->addDays(6));
        app(LanzadorAutomatizaciones::class)->reanudar();

        $this->assertStringStartsWith('Sin respuesta', $this->avisos($this->admin)->first()->titulo);
    }

    public function test_pausar_o_eliminar_cancela_lo_que_esperaba(): void
    {
        $a = $this->seguimientoACincoDias();
        $this->cotizacion('enviada');

        $this->postJson("/api/automatizaciones/{$a->id}/activar", ['activa' => false])->assertOk();
        $this->assertSame('cancelada', AutomatizacionEjecucion::firstOrFail()->estado);

        $this->deleteJson("/api/automatizaciones/{$a->id}")->assertOk();
        $this->assertNull(Automatizacion::find($a->id));
    }

    // ─── Acciones ────────────────────────────────────────────────────────────

    public function test_un_lead_nuevo_recibe_su_tarea_de_contacto(): void
    {
        $this->armar($this->cadena($this->disparo('lead.nuevo'), ['id' => 'tarea', 'tipo' => 'accion', 'accion' => 'crear_tarea_crm', 'config' => [
            'titulo' => 'Contactar a {{contacto}} ({{empresa}})', 'dias' => 2,
        ]]));
        $etapa = CrmEtapa::create(['nombre' => 'Nuevo', 'orden' => 1, 'activa' => true]);

        $lead = CrmLead::create(['etapa_id' => $etapa->id, 'titulo' => 'Puertas', 'estado' => 'activo', 'nombre_contacto' => 'Laura', 'empresa_contacto' => 'Frío Andino', 'responsable_id' => $this->admin->id]);

        $t = CrmTarea::firstOrFail();
        $this->assertSame($lead->id, $t->lead_id);
        $this->assertSame('Contactar a Laura (Frío Andino)', $t->titulo);
        $this->assertSame(now()->addDays(2)->toDateString(), $t->fecha_vencimiento->toDateString());
    }

    public function test_avisar_a_quienes_tienen_un_permiso(): void
    {
        $rol = Rol::create(['nombre' => 'Calidad', 'rol_base' => 'jefe_produccion', 'activo' => true, 'todas_las_sedes' => true]);
        $rol->sincronizarPermisos(['ops.calidad', 'ops.ver']);
        $revisor = User::factory()->create(['rol' => 'jefe_produccion', 'rol_id' => $rol->id]);
        $otro    = User::factory()->create(['rol' => 'vendedor']);

        $this->armar($this->cadena($this->disparo('op.estado'), $this->condicion('c', 'estado', 'igual', 'calidad'), ['id' => 'a', 'tipo' => 'accion', 'accion' => 'notificar', 'config' => [
            'destinatario' => 'permiso', 'permiso' => 'ops.calidad', 'titulo' => 'Orden {{numero}} lista',
        ]]));

        $op = \App\Models\Op::create(['cliente_id' => $this->clientePrueba()->id, 'sede_id' => Sede::first()->id, 'estado' => 'en_produccion', 'fecha_creacion' => now(), 'responsable_id' => $this->admin->id]);
        $op->update(['estado' => 'calidad']);

        $this->assertSame(1, $this->avisos($revisor)->count());
        $this->assertSame(0, $this->avisos($otro)->count());
    }

    // ─── Horarios ────────────────────────────────────────────────────────────

    public function test_una_automatizacion_por_horario_corre_dentro_de_su_ventana_y_una_vez(): void
    {
        Queue::fake();
        $this->armar($this->cadena(
            ['id' => 'disparo', 'tipo' => 'disparador', 'config' => ['tipo' => 'horario', 'frecuencia' => ['tipo' => 'diaria', 'hora' => '06:00', 'dias' => []]]],
            ['id' => 'a', 'tipo' => 'accion', 'accion' => 'notificar', 'config' => ['destinatario' => 'usuarios', 'usuarios' => [$this->admin->id], 'titulo' => 'Buenos días']],
        ));
        $l = app(LanzadorAutomatizaciones::class);

        $this->assertSame(0, $l->programadas(Carbon::parse('2026-10-14 05:55')));
        $this->assertSame(0, $l->programadas(Carbon::parse('2026-10-14 10:00')), 'pasó la ventana de 3 horas');
        $this->assertSame(1, $l->programadas(Carbon::parse('2026-10-14 06:05')));
        $this->assertSame(0, $l->programadas(Carbon::parse('2026-10-14 06:06')), 'la misma ventana no se repite');
        $this->assertSame(1, $l->programadas(Carbon::parse('2026-10-15 06:01')));
    }

    // ─── Fallos ──────────────────────────────────────────────────────────────

    public function test_cinco_fallas_seguidas_pausan_la_automatizacion_y_avisan(): void
    {
        app(AgentesDeFabrica::class)->sembrar();
        $agente = AgenteInterno::where('clave', 'analista_del_dia')->first(); // apagado: la acción falla
        $a = $this->armar($this->cadena(
            ['id' => 'disparo', 'tipo' => 'disparador', 'config' => ['tipo' => 'manual']],
            ['id' => 'ag', 'tipo' => 'accion', 'accion' => 'ejecutar_agente', 'config' => ['agente_id' => $agente->id]],
        ));

        foreach (range(1, 5) as $_) {
            app(LanzadorAutomatizaciones::class)->manual($a, $this->admin);
        }

        $this->assertSame('fallida', AutomatizacionEjecucion::latest('id')->first()->estado);
        $this->assertStringContainsString('apagado', AutomatizacionEjecucion::latest('id')->first()->error);
        $this->assertFalse($a->fresh()->activa, 'se pausó sola');
        $this->assertSame(1, $this->avisos($this->admin, 'automatizacion_fallo')->count());
    }

    public function test_si_el_autor_pierde_el_permiso_la_automatizacion_deja_de_poder_usarlo(): void
    {
        $rol = Rol::create(['nombre' => 'Comercial', 'rol_base' => 'vendedor', 'activo' => true, 'todas_las_sedes' => true]);
        $rol->sincronizarPermisos(['crm.editar', 'crm.ver']);
        $autor = User::factory()->create(['rol' => 'vendedor', 'rol_id' => $rol->id]);

        $a = $this->armar($this->cadena($this->disparo('lead.nuevo'), ['id' => 't', 'tipo' => 'accion', 'accion' => 'crear_tarea_crm', 'config' => ['titulo' => 'Contactar']]), ['creado_por' => $autor->id]);

        $rol->permisosAsignados()->where('permiso', 'crm.editar')->delete();
        $etapa = CrmEtapa::create(['nombre' => 'Nuevo', 'orden' => 1, 'activa' => true]);
        CrmLead::create(['etapa_id' => $etapa->id, 'titulo' => 'Puertas', 'estado' => 'activo', 'nombre_contacto' => 'Laura']);

        $e = AutomatizacionEjecucion::firstOrFail();
        $this->assertSame('fallida', $e->estado);
        $this->assertStringContainsString('crm.editar', $e->error);
        $this->assertSame(0, CrmTarea::count());
        $this->assertTrue($a->fresh()->fallas_seguidas === 1);
    }

    // ─── La API ──────────────────────────────────────────────────────────────

    public function test_probar_muestra_el_camino_sin_ejecutar_nada(): void
    {
        $grafo = $this->cadena($this->disparo(), $this->condicion('c', 'total', 'mayor', 1000000), ['id' => 'e', 'tipo' => 'espera', 'config' => ['cantidad' => 2, 'unidad' => 'horas']], $this->avisoAlResponsable());
        $this->admin->forceFill([])->save();

        $r = $this->postJson('/api/automatizaciones/probar', ['grafo' => $grafo, 'contexto' => ['total' => 5000000, 'responsable_id' => $this->admin->id]])->assertOk();

        $this->assertTrue($r->json('termino'));
        $this->assertSame(['disparador', 'condicion', 'espera', 'accion'], collect($r->json('pasos'))->pluck('tipo')->all());
        $this->assertStringContainsString('Esperaría 2 horas', $r->json('pasos.2.resumen'));
        $this->assertStringContainsString('Avisaría', $r->json('pasos.3.resumen'));
        $this->assertSame(0, Notificacion::count(), 'una prueba no avisa a nadie');
        $this->assertSame(0, AutomatizacionEjecucion::count());

        // Con un total chico la condición no se cumple y el flujo termina ahí.
        $corta = $this->postJson('/api/automatizaciones/probar', ['grafo' => $grafo, 'contexto' => ['total' => 10]])->assertOk();
        $this->assertSame(['disparador', 'condicion'], collect($corta->json('pasos'))->pluck('tipo')->all());
    }

    public function test_crear_actualizar_y_activar_por_la_api(): void
    {
        $grafo = $this->cadena($this->disparo(), $this->avisoAlResponsable());

        $id = $this->postJson('/api/automatizaciones', ['nombre' => 'Aviso', 'grafo' => $grafo])->assertCreated()->json('id');
        $this->assertFalse(Automatizacion::find($id)->activa, 'nace pausada');
        $this->assertSame('cotizacion.estado', Automatizacion::find($id)->disparador_clave);

        $this->postJson('/api/automatizaciones', ['nombre' => 'Mala', 'grafo' => ['nodos' => [], 'aristas' => []]])->assertStatus(422);

        $this->putJson("/api/automatizaciones/{$id}", ['nombre' => 'Aviso 2', 'grafo' => $grafo])->assertOk();
        $this->assertSame(2, Automatizacion::find($id)->version);

        $this->postJson("/api/automatizaciones/{$id}/activar", ['activa' => true])->assertOk()->assertJsonPath('activa', true);
        $this->getJson('/api/automatizaciones')->assertOk()->assertJsonPath('0.nombre', 'Aviso 2');
    }

    public function test_cada_permiso_abre_solo_lo_suyo_y_el_modulo_apagado_la_corta(): void
    {
        $rol = Rol::create(['nombre' => 'Solo ver', 'rol_base' => 'vendedor', 'activo' => true, 'todas_las_sedes' => true]);
        $rol->sincronizarPermisos(['automatizaciones.ver']);
        $this->actingAs(User::factory()->create(['rol' => 'vendedor', 'rol_id' => $rol->id]));

        $this->getJson('/api/automatizaciones')->assertOk();
        $this->postJson('/api/automatizaciones', [])->assertForbidden();
        $this->postJson('/api/automatizaciones/probar', [])->assertForbidden();

        $a = $this->armar($this->cadena(['id' => 'disparo', 'tipo' => 'disparador', 'config' => ['tipo' => 'manual']], ['id' => 'a', 'tipo' => 'accion', 'accion' => 'notificar', 'config' => ['destinatario' => 'usuarios', 'usuarios' => [$this->admin->id], 'titulo' => 'Hola']]));
        $this->postJson("/api/automatizaciones/{$a->id}/ejecutar")->assertForbidden();

        $this->actingAs($this->admin);
        Modulos::guardar(['automatizaciones'], 'instalacion');
        $this->getJson('/api/automatizaciones')->assertNotFound();
    }

    public function test_ejecutar_a_mano_solo_las_de_arranque_manual(): void
    {
        $manual = $this->armar($this->cadena(['id' => 'disparo', 'tipo' => 'disparador', 'config' => ['tipo' => 'manual']], ['id' => 'a', 'tipo' => 'accion', 'accion' => 'notificar', 'config' => ['destinatario' => 'usuarios', 'usuarios' => [$this->admin->id], 'titulo' => 'Hola {{x}}']]));
        $evento = $this->armar($this->cadena($this->disparo(), $this->avisoAlResponsable()));

        $this->postJson("/api/automatizaciones/{$manual->id}/ejecutar")->assertStatus(202);
        $this->assertSame('Hola {{x}}', $this->avisos($this->admin)->first()->titulo, 'lo que no existe queda como está');

        $this->postJson("/api/automatizaciones/{$evento->id}/ejecutar")->assertStatus(422);
    }

    public function test_el_catalogo_solo_ofrece_lo_que_esta_instalacion_puede(): void
    {
        Modulos::guardar(['cotizaciones'], 'instalacion');

        $c = $this->getJson('/api/automatizaciones/catalogo')->assertOk()->json();

        $this->assertNotContains('cotizacion.estado', collect($c['eventos'])->pluck('clave')->all());
        $this->assertContains('lead.nuevo', collect($c['eventos'])->pluck('clave')->all());
        $this->assertSame([], $c['agentes'], 'sin el módulo Equipo de IA no se ofrecen agentes');
        $this->assertNotEmpty($c['plantillas']);
        $this->assertFalse(collect($c['plantillas'])->contains(fn ($p) => in_array('cotizaciones', $p['modulos'], true)), 'no se ofrecen plantillas de módulos apagados');
        $this->assertNotContains('cotizacion', collect($c['entidades'])->pluck('clave')->all());
    }

    public function test_las_plantillas_de_fabrica_son_validas(): void
    {
        app(AgentesDeFabrica::class)->sembrar();
        $agente = AgenteInterno::first();

        foreach (\App\Services\Automatizaciones\PlantillasAutomatizacion::todas() as $p) {
            $g = $p['grafo'];

            // La del agente trae el agente por elegir: se completa como lo haría la persona.
            foreach ($g['nodos'] as &$n) {
                if (($n['accion'] ?? null) === 'ejecutar_agente') {
                    $n['config']['agente_id'] = $agente->id;
                }

                if (in_array($n['accion'] ?? null, ['asignar_lead', 'asignar_conversacion'], true)) {
                    $n['config']['usuarios'] = [$this->admin->id];
                }
            }

            $this->assertSame([], GrafoAutomatizacion::validar($g, $this->admin), "La plantilla «{$p['nombre']}» no es válida.");
        }
    }

    public function test_una_corrida_se_puede_cancelar_mientras_espera(): void
    {
        $this->seguimientoACincoDias();
        $this->cotizacion('enviada');
        $e = AutomatizacionEjecucion::firstOrFail();

        $this->postJson("/api/automatizaciones/ejecuciones/{$e->id}/cancelar")->assertOk()->assertJsonPath('estado', 'cancelada');
        $this->postJson("/api/automatizaciones/ejecuciones/{$e->id}/cancelar")->assertStatus(422);

        Carbon::setTestNow(now()->addDays(6));
        $this->assertSame(0, app(LanzadorAutomatizaciones::class)->reanudar(), 'una corrida cancelada no se retoma');
    }
}
