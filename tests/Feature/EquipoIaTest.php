<?php

namespace Tests\Feature;

use App\Jobs\EjecutarAgenteJob;
use App\Models\AgenteEjecucion;
use App\Models\AgenteInterno;
use App\Models\AgentePropuesta;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Cotizacion;
use App\Models\CotizacionSeguimiento;
use App\Models\CrmEtapa;
use App\Models\CrmLead;
use App\Models\CrmTarea;
use App\Models\IaConsumo;
use App\Models\Notificacion;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Services\Agentes\AgentesDeFabrica;
use App\Services\Agentes\AprobacionService;
use App\Services\Agentes\CatalogoHerramientas;
use App\Services\Agentes\LanzadorAgentes;
use App\Support\Modulos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * El Equipo de IA: agentes internos que leen con el rol de su cargo y PROPONEN; una persona decide.
 *
 * La IA va simulada con `Http::fake`: ninguna prueba gasta tokens ni depende de la red.
 */
class EquipoIaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow();

        // El módulo nace apagado (migración `apagar_equipo_ia_de_fabrica`): cada prueba lo enciende.
        Modulos::guardar([], 'instalacion');

        Configuracion::set('ia_api_key', 'llave-de-prueba');

        $this->admin = User::factory()->create(['rol' => 'administrador']);
        Sede::query()->first() ?? Sede::create(['nombre' => 'Principal', 'es_principal' => true]);

        app(AgentesDeFabrica::class)->sembrar();
        $this->actingAs($this->admin);
    }

    // ─── Ayudas ──────────────────────────────────────────────────────────────

    private function agente(string $clave): AgenteInterno
    {
        $a = AgenteInterno::where('clave', $clave)->firstOrFail();
        $a->update(['activo' => true]);

        return $a;
    }

    /** El cuerpo de una respuesta del proveedor (se usa en `Http::response` o en una secuencia). */
    private function respuesta(array $mensaje, int $entrada = 100, int $salida = 20): array
    {
        return [
            'model'   => 'modelo/prueba',
            'choices' => [['message' => $mensaje + ['role' => 'assistant'], 'finish_reason' => 'stop']],
            'usage'   => ['prompt_tokens' => $entrada, 'completion_tokens' => $salida],
        ];
    }

    /** Las respuestas que irá dando el proveedor, una por llamada. */
    private function secuencia(array ...$cuerpos): void
    {
        $seq = Http::sequence();

        foreach ($cuerpos as $c) {
            $seq->push($c);
        }

        Http::fake(['*' => $seq]);
    }

    private function pideHerramienta(string $nombre, array $args = [], string $id = 'c1'): array
    {
        return $this->respuesta([
            'content'    => null,
            'tool_calls' => [['id' => $id, 'type' => 'function', 'function' => ['name' => $nombre, 'arguments' => json_encode($args ?: new \stdClass)]]],
        ]);
    }

    private function texto(string $t): array
    {
        return $this->respuesta(['content' => $t]);
    }

    private function correr(AgenteInterno $a, bool $prueba = false): AgenteEjecucion
    {
        // La cola de pruebas es `sync`: el job corre ahí mismo.
        return app(LanzadorAgentes::class)->manual($a, $this->admin, null, $prueba)->fresh();
    }

    private function cotizacionSinRespuesta(int $dias = 9): Cotizacion
    {
        $cliente = Cliente::create(['tipo' => 'empresa', 'nombre' => 'Frío Andino SAS', 'tipo_identificacion' => 'NIT', 'numero_identificacion' => '900' . random_int(100000, 999999)]);
        $cot     = Cotizacion::create(['cliente_id' => $cliente->id, 'moneda' => 'COP', 'tasa_cambio' => 1, 'estado' => 'enviada', 'responsable_id' => $this->admin->id]);
        Cotizacion::where('id', $cot->id)->update(['updated_at' => now()->subDays($dias)]);

        return $cot->fresh();
    }

    // ─── El bucle ────────────────────────────────────────────────────────────

    public function test_un_agente_de_lectura_recorre_sus_herramientas_y_entrega_un_resumen(): void
    {
        $this->secuencia(
            $this->pideHerramienta('ventas_resumen', ['dias' => 7]),
            $this->texto('Ayer se aprobaron 2 cotizaciones.'),
        );

        $e = $this->correr($this->agente('analista_del_dia'));

        $this->assertSame('terminada', $e->estado, (string) $e->error);
        $this->assertSame('Ayer se aprobaron 2 cotizaciones.', $e->resumen);
        $this->assertSame(['ventas_resumen'], $e->resultado['herramientas']);
        $this->assertSame(200, $e->tokens_entrada);

        // La caja negra: lo que pidió el modelo, la herramienta y la respuesta final.
        $this->assertSame(['mensaje', 'herramienta', 'mensaje'], $e->registroPasos->pluck('tipo')->all());
        $this->assertSame('ventas_resumen', $e->registroPasos[1]->herramienta);

        // Y el consumo queda anotado como de los agentes, con el agente y la corrida.
        $this->assertSame(2, IaConsumo::where('origen', 'agentes')->where('ejecucion_id', $e->id)->count());
    }

    public function test_el_consumo_de_un_agente_viaja_etiquetado_para_el_cupo_aparte(): void
    {
        config(['briela.serial' => 'BRL-AAAA-BBBB-CCCC']);
        Http::fake(['*' => Http::response($this->texto('Todo en orden.'))]);

        $this->correr($this->agente('analista_del_dia'));

        Http::assertSent(fn ($r) => $r->hasHeader('X-Briela-Origen', 'agentes'));

        // Y fuera de un agente, el asistente NO lleva la etiqueta.
        Http::fake(['*' => Http::response($this->texto('hola'))]);
        app(\App\Services\IA\IaService::class)->texto('hola');
        Http::assertSent(fn ($r) => ! $r->hasHeader('X-Briela-Origen'));
    }

    public function test_las_herramientas_se_recortan_al_rol_del_agente(): void
    {
        $a = $this->agente('analista_del_dia');

        // Se le quita al rol el permiso de cartera: esa herramienta ya no se le ofrece.
        $a->cargo->rol->permisosAsignados()->where('permiso', 'cartera.ver')->delete();

        Http::fake(['*' => Http::response($this->texto('Listo.'))]);
        $this->correr($a);

        Http::assertSent(function ($r) {
            $nombres = collect($r['tools'] ?? [])->pluck('function.name');

            return $nombres->contains('ventas_resumen') && ! $nombres->contains('cartera_pendiente');
        });
    }

    public function test_pedir_una_herramienta_que_no_tiene_no_la_ejecuta(): void
    {
        $this->secuencia(
            $this->pideHerramienta('comparar_proveedores', ['texto' => 'lamina']),
            $this->texto('No pude.'),
        );

        $e = $this->correr($this->agente('analista_del_dia'));

        $this->assertSame('terminada', $e->estado);
        $this->assertStringContainsString('no existe o no está permitida', $e->registroPasos[1]->resumen);
    }

    public function test_cuando_se_acaban_los_pasos_cierra_con_un_resumen_sin_herramientas(): void
    {
        $a = $this->agente('analista_del_dia');
        $a->update(['max_pasos' => 1]);

        $this->secuencia(
            $this->pideHerramienta('ventas_resumen'),
            $this->texto('Resumen parcial: me faltó revisar cartera.'),
        );

        $e = $this->correr($a);

        $this->assertSame('terminada', $e->estado);
        $this->assertStringContainsString('Resumen parcial', $e->resumen);
        // La segunda llamada es el cierre forzado: sin herramientas.
        Http::assertSent(fn ($r) => ! isset($r['tools']) && str_contains(json_encode($r['messages'], JSON_UNESCAPED_UNICODE), 'límite de pasos'));
    }

    public function test_los_resultados_de_las_herramientas_no_llevan_costos(): void
    {
        $limpio = CatalogoHerramientas::limpiar([
            'producto' => 'Lámina', 'precio_costo' => 100, 'margen_pct' => 30,
            'detalle'  => [['retencion_fuente' => 5, 'cantidad' => 2, 'salario_base' => 1]],
        ]);

        $this->assertSame(['producto' => 'Lámina', 'detalle' => [['cantidad' => 2]]], $limpio);
    }

    // ─── Sedes ───────────────────────────────────────────────────────────────

    /** Las cotizaciones que vio el agente en su primera consulta, tal como se las devolvió la herramienta. */
    private function numerosVistos(AgenteInterno $a): array
    {
        // Un cliente HTTP limpio: los `fake` anteriores se quedarían con la respuesta primero.
        Http::swap(new \Illuminate\Http\Client\Factory());
        $this->secuencia($this->pideHerramienta('cotizaciones_sin_respuesta', ['dias' => 5]), $this->texto('Listo.'));
        $e = $this->correr($a);
        $this->assertSame('terminada', $e->estado, (string) $e->error);

        $segunda = collect(Http::recorded())->map(fn ($par) => $par[0])->last();
        $tool    = collect($segunda['messages'])->firstWhere('role', 'tool');

        return collect(json_decode($tool['content'], true)['cotizaciones'])->pluck('numero')->sort()->values()->all();
    }

    public function test_un_agente_sin_sede_ve_todas_las_sedes_y_uno_con_sede_solo_la_suya(): void
    {
        $principal = Sede::first();
        $otra      = Sede::create(['nombre' => 'Norte', 'codigo' => 'NOR']);

        $a = $this->cotizacionSinRespuesta();
        $b = $this->cotizacionSinRespuesta();
        // (`update` por el constructor toca `updated_at`, y de ahí salen los días sin respuesta.)
        Cotizacion::where('id', $a->id)->update(['sede_id' => $principal->id, 'updated_at' => now()->subDays(9)]);
        Cotizacion::where('id', $b->id)->update(['sede_id' => $otra->id, 'updated_at' => now()->subDays(9)]);

        // Quien pide la corrida está mirando OTRA sede: no debe contagiarle su vista al agente.
        session()->put('sede_activa_id', $principal->id);

        $agente = $this->agente('seguimiento_comercial');

        $this->assertSame([$a->numero, $b->numero], $this->numerosVistos($agente), 'sin sede propia: todas');
        $this->assertSame($principal->id, session()->get('sede_activa_id'), 'la sede de la persona se restaura');

        $agente->update(['sede_id' => $otra->id]);
        $this->assertSame([$b->numero], $this->numerosVistos($agente->fresh()), 'con sede propia: solo esa');
    }

    // ─── Respaldo y errores ──────────────────────────────────────────────────

    public function test_si_el_modelo_falla_reintenta_una_vez_con_el_respaldo(): void
    {
        $a = $this->agente('analista_del_dia');
        $a->update(['modelo' => 'principal/modelo', 'modelo_respaldo' => 'respaldo/modelo']);

        Http::fake(['*' => Http::sequence()
            ->push(['error' => ['message' => 'upstream']], 503)
            ->push($this->texto('Con el respaldo.')),
        ]);

        $e = $this->correr($a);

        $this->assertSame('terminada', $e->estado, (string) $e->error);
        $this->assertContains('error', $e->registroPasos->pluck('tipo')->all());
        Http::assertSentCount(2);
    }

    public function test_un_402_no_se_reintenta_con_otro_modelo(): void
    {
        $a = $this->agente('analista_del_dia');
        $a->update(['modelo' => 'principal/modelo', 'modelo_respaldo' => 'respaldo/modelo']);

        Http::fake(['*' => Http::response(['error' => ['message' => 'sin saldo']], 402)]);

        $e = $this->correr($a);

        $this->assertSame('fallida', $e->estado);
        Http::assertSentCount(1);
    }

    public function test_con_el_modulo_apagado_la_corrida_falla_con_un_motivo_claro(): void
    {
        Modulos::guardar(['equipo_ia'], 'instalacion');
        Http::fake();

        $e = $this->correr($this->agente('analista_del_dia'));

        $this->assertSame('fallida', $e->estado);
        $this->assertStringContainsString('apagado', $e->error);
        Http::assertNothingSent();
    }

    public function test_un_agente_con_el_rol_inactivo_no_corre(): void
    {
        $a = $this->agente('analista_del_dia');
        $a->cargo->rol->update(['activo' => false]);
        Http::fake();

        $e = $this->correr($a);

        $this->assertSame('fallida', $e->estado);
        $this->assertStringContainsString('rol', $e->error);
        Http::assertNothingSent();
    }

    public function test_una_corrida_fallida_avisa_a_quien_configura(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'sin saldo']], 402)]);

        $this->correr($this->agente('analista_del_dia'));

        $this->assertTrue(Notificacion::where('user_id', $this->admin->id)->where('tipo', 'agente_fallo')->exists());
    }

    // ─── Propuestas: el agente no escribe ────────────────────────────────────

    private function correrSeguimiento(Cotizacion $cot): AgenteEjecucion
    {
        $this->secuencia(
            $this->pideHerramienta('cotizaciones_sin_respuesta', ['dias' => 5]),
            $this->pideHerramienta('proponer_nota_cotizacion', ['cotizacion_id' => $cot->id, 'nota' => 'Hola Marta, ¿pudo revisar la cotización?']),
            $this->texto('Dejé 1 propuesta.'),
        );

        return $this->correr($this->agente('seguimiento_comercial'));
    }

    public function test_el_agente_propone_y_no_escribe_en_el_negocio(): void
    {
        $cot = $this->cotizacionSinRespuesta();

        $e = $this->correrSeguimiento($cot);

        $this->assertSame('terminada', $e->estado, (string) $e->error);
        $p = AgentePropuesta::firstOrFail();
        $this->assertSame('pendiente', $p->estado);
        $this->assertSame('nota_cotizacion', $p->accion);
        $this->assertSame($cot->id, $p->entidad_id);
        $this->assertSame(0, CotizacionSeguimiento::count(), 'proponer no debe escribir');

        // Y se avisa a quien puede decidir.
        $this->assertTrue(Notificacion::where('user_id', $this->admin->id)->where('tipo', 'agente_propuesta')->exists());
    }

    public function test_no_repite_una_propuesta_reciente(): void
    {
        $cot = $this->cotizacionSinRespuesta();
        $this->correrSeguimiento($cot);

        $this->correrSeguimiento($cot);

        $this->assertSame(1, AgentePropuesta::count());
    }

    public function test_una_corrida_de_prueba_no_guarda_propuestas_ni_avisa(): void
    {
        $cot = $this->cotizacionSinRespuesta();
        $this->secuencia(
            $this->pideHerramienta('proponer_nota_cotizacion', ['cotizacion_id' => $cot->id, 'nota' => 'Hola']),
            $this->texto('Simulé 1 propuesta.'),
        );

        $e = $this->correr($this->agente('seguimiento_comercial'), prueba: true);

        $this->assertSame('terminada', $e->estado);
        $this->assertSame(0, AgentePropuesta::count());
        $this->assertSame(0, Notificacion::where('tipo', 'agente_propuesta')->count());
    }

    public function test_un_identificador_inventado_no_llega_a_una_persona(): void
    {
        $this->secuencia(
            $this->pideHerramienta('proponer_nota_cotizacion', ['cotizacion_id' => 99999, 'nota' => 'Hola']),
            $this->texto('No pude.'),
        );

        $this->correr($this->agente('seguimiento_comercial'));

        $this->assertSame(0, AgentePropuesta::count());
    }

    public function test_un_agente_de_solo_lectura_no_tiene_herramientas_de_propuesta(): void
    {
        $a = $this->agente('seguimiento_comercial');
        $a->update(['modo' => 'solo_lectura']);

        Http::fake(['*' => Http::response($this->texto('Listo.'))]);
        $this->correr($a);

        Http::assertSent(fn ($r) => ! collect($r['tools'] ?? [])->pluck('function.name')->contains('proponer_nota_cotizacion'));
    }

    // ─── Aprobar ─────────────────────────────────────────────────────────────

    public function test_aprobar_ejecuta_a_nombre_de_quien_aprueba(): void
    {
        $cot = $this->cotizacionSinRespuesta();
        $this->correrSeguimiento($cot);
        $p = AgentePropuesta::firstOrFail();

        $r = app(AprobacionService::class)->aprobar($p, $this->admin);

        $this->assertTrue($r['exito'], $r['mensaje']);
        $this->assertSame('aprobada', $p->fresh()->estado);
        $nota = CotizacionSeguimiento::firstOrFail();
        $this->assertSame($this->admin->id, $nota->user_id, 'queda a nombre de la persona, no del agente');
        $this->assertSame($cot->id, $nota->cotizacion_id);

        // Una segunda aprobación no repite nada.
        $this->assertFalse(app(AprobacionService::class)->aprobar($p->fresh(), $this->admin)['exito']);
        $this->assertSame(1, CotizacionSeguimiento::count());
    }

    public function test_aprobar_exige_ademas_el_permiso_de_la_accion(): void
    {
        $cot = $this->cotizacionSinRespuesta();
        $this->correrSeguimiento($cot);

        $rol = Rol::create(['nombre' => 'Aprobador sin cotizaciones', 'rol_base' => 'vendedor', 'activo' => true, 'todas_las_sedes' => true]);
        $rol->sincronizarPermisos(['equipo_ia.aprobar', 'equipo_ia.ver']);
        $persona = User::factory()->create(['rol' => 'vendedor', 'rol_id' => $rol->id]);

        $r = app(AprobacionService::class)->aprobar(AgentePropuesta::firstOrFail(), $persona);

        $this->assertFalse($r['exito']);
        $this->assertStringContainsString('cotizaciones.editar', $r['mensaje']);
        $this->assertSame(0, CotizacionSeguimiento::count());
    }

    public function test_una_propuesta_vencida_no_se_aprueba(): void
    {
        $cot = $this->cotizacionSinRespuesta();
        $this->correrSeguimiento($cot);
        $p = AgentePropuesta::firstOrFail();
        $p->update(['vence_at' => now()->subHour()]);

        $r = app(AprobacionService::class)->aprobar($p, $this->admin);

        $this->assertFalse($r['exito']);
        $this->assertSame('vencida', $p->fresh()->estado);
        $this->assertSame(0, CotizacionSeguimiento::count());
    }

    public function test_si_el_documento_cambio_la_propuesta_falla_con_el_motivo(): void
    {
        $cot = $this->cotizacionSinRespuesta();
        $this->correrSeguimiento($cot);
        // El cliente respondió y se aprobó mientras la propuesta esperaba.
        $cot->update(['estado' => 'aprobada']);

        $r = app(AprobacionService::class)->aprobar(AgentePropuesta::firstOrFail(), $this->admin);

        $this->assertFalse($r['exito']);
        $this->assertStringContainsString('ya no está', $r['mensaje']);
        $this->assertSame('fallida', AgentePropuesta::firstOrFail()->estado);
        $this->assertSame(0, CotizacionSeguimiento::count());
    }

    public function test_rechazar_deja_el_motivo_y_no_escribe(): void
    {
        $cot = $this->cotizacionSinRespuesta();
        $this->correrSeguimiento($cot);
        $p = AgentePropuesta::firstOrFail();

        $r = app(AprobacionService::class)->rechazar($p, $this->admin, 'Ya lo llamé yo');

        $this->assertTrue($r['exito']);
        $this->assertSame('rechazada', $p->fresh()->estado);
        $this->assertSame('Ya lo llamé yo', $p->fresh()->motivo);
        $this->assertSame(0, CotizacionSeguimiento::count());
    }

    public function test_aprobar_una_tarea_de_lead_la_crea_para_su_responsable(): void
    {
        $etapa = CrmEtapa::create(['nombre' => 'Nuevo', 'orden' => 1, 'activa' => true]);
        $lead  = CrmLead::create(['etapa_id' => $etapa->id, 'titulo' => 'Puertas', 'estado' => 'activo', 'nombre_contacto' => 'Laura', 'responsable_id' => $this->admin->id]);

        $this->secuencia(
            $this->pideHerramienta('proponer_tarea_crm', ['lead_id' => $lead->id, 'titulo' => 'Llamar a Laura', 'descripcion' => 'Hola Laura…', 'dias' => 2]),
            $this->texto('Dejé 1 tarea.'),
        );
        $this->correr($this->agente('seguimiento_comercial'));

        $this->assertSame(0, CrmTarea::count());

        $r = app(AprobacionService::class)->aprobar(AgentePropuesta::firstOrFail(), $this->admin);

        $this->assertTrue($r['exito'], $r['mensaje']);
        $t = CrmTarea::firstOrFail();
        $this->assertSame($lead->id, $t->lead_id);
        $this->assertSame('Llamar a Laura', $t->titulo);
        $this->assertSame(now()->addDays(2)->toDateString(), $t->fecha_vencimiento->toDateString());
    }

    // ─── La identidad del agente ─────────────────────────────────────────────

    public function test_el_usuario_de_servicio_no_aparece_en_las_listas_ni_inicia_sesion(): void
    {
        Http::fake(['*' => Http::response($this->texto('Listo.'))]);
        $a = $this->agente('analista_del_dia');
        $this->correr($a);

        $servicio = User::withoutGlobalScopes()->findOrFail($a->fresh()->usuario_id);

        $this->assertStringEndsWith('@' . User::DOMINIO_AGENTES, $servicio->email);
        $this->assertSame(1, User::count(), 'solo la persona; el agente no es de las listas');
        $this->assertNull(User::where('email', $servicio->email)->first());

        auth()->logout();
        $this->post('/login', ['email' => $servicio->email, 'password' => 'cualquiera'])->assertSessionHasErrors();
        $this->assertGuest();
    }

    public function test_correr_un_agente_no_cambia_el_usuario_de_quien_lo_pidio(): void
    {
        Http::fake(['*' => Http::response($this->texto('Listo.'))]);

        $this->correr($this->agente('analista_del_dia'));

        $this->assertSame($this->admin->id, auth()->id());
    }

    // ─── El cron ─────────────────────────────────────────────────────────────

    public function test_el_cron_solo_lanza_dentro_de_la_ventana_y_una_sola_vez(): void
    {
        Queue::fake();
        $a = $this->agente('analista_del_dia'); // diaria a las 06:30
        $lanzador = app(LanzadorAgentes::class);

        $this->assertSame(0, $lanzador->programadas(Carbon::parse('2026-10-13 06:00')), 'todavía no es la hora');
        $this->assertSame(0, $lanzador->programadas(Carbon::parse('2026-10-13 10:00')), 'pasó la ventana de 3 horas');

        $this->assertSame(1, $lanzador->programadas(Carbon::parse('2026-10-13 06:35')));
        Queue::assertPushed(EjecutarAgenteJob::class, 1);

        // Aunque la corrida ya terminó, la misma ventana no se repite: la clave es única.
        $a->ejecuciones()->update(['estado' => 'terminada']);
        $this->assertSame(0, $lanzador->programadas(Carbon::parse('2026-10-13 06:40')));

        // Al día siguiente sí.
        $this->assertSame(1, $lanzador->programadas(Carbon::parse('2026-10-14 06:31')));
    }

    public function test_un_agente_apagado_no_se_despacha(): void
    {
        Queue::fake();
        $a = $this->agente('analista_del_dia');
        $a->update(['activo' => false]);

        $this->assertSame(0, app(LanzadorAgentes::class)->programadas(Carbon::parse('2026-10-13 06:35')));
    }

    public function test_no_se_lanza_otra_corrida_si_ya_hay_una_en_curso(): void
    {
        Queue::fake();
        $a = $this->agente('analista_del_dia');
        $lanzador = app(LanzadorAgentes::class);

        $primera = $lanzador->manual($a, $this->admin);
        $segunda = $lanzador->manual($a, $this->admin);

        $this->assertSame($primera->id, $segunda->id);
        Queue::assertPushed(EjecutarAgenteJob::class, 1);
    }

    public function test_las_corridas_atascadas_se_rescatan(): void
    {
        $a = $this->agente('analista_del_dia');
        $e = AgenteEjecucion::create(['agente_id' => $a->id, 'estado' => 'corriendo', 'origen' => 'cron']);
        AgenteEjecucion::where('id', $e->id)->update(['updated_at' => now()->subMinutes(30)]);

        $this->assertSame(1, app(LanzadorAgentes::class)->rescatarAtascadas());
        $this->assertSame('fallida', $e->fresh()->estado);
    }

    public function test_la_siembra_es_idempotente_y_los_agentes_nacen_apagados(): void
    {
        $antes = AgenteInterno::count();
        $c = app(AgentesDeFabrica::class)->sembrar();

        $this->assertSame(['cargos' => 0, 'agentes' => 0, 'roles' => 0], $c);
        $this->assertSame($antes, AgenteInterno::count());
        $this->assertSame(3, $antes);

        // El rol de los agentes NO es de sistema: `permisos:sincronizar` no debe ampliarlo.
        $this->assertFalse(Rol::where('nombre', 'like', 'Agente IA%')->where('es_sistema', true)->exists());
    }
}
