<?php

namespace Tests\Feature;

use App\Models\AgenteInterno;
use App\Models\Automatizacion;
use App\Models\AutomatizacionEjecucion;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Cotizacion;
use App\Models\Notificacion;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Services\Agentes\AgentesDeFabrica;
use App\Services\Agentes\AutomatizacionesParaAgentes;
use App\Services\Agentes\CatalogoHerramientas;
use App\Services\Agentes\LanzadorAgentes;
use App\Support\Modulos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Un agente decide CUÁNDO y SOBRE QUÉ documento; la automatización hace el trabajo, sin tokens.
 * La IA va simulada con `Http::fake`.
 */
class AgentesEjecutanAutomatizacionesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private AgenteInterno $agente;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow();
        Cache::flush();
        Modulos::guardar([], 'instalacion');
        Configuracion::set('ia_api_key', 'llave-de-prueba');

        $this->admin = User::factory()->create(['rol' => 'administrador']);
        Sede::query()->first() ?? Sede::create(['nombre' => 'Principal', 'es_principal' => true]);
        $this->actingAs($this->admin);

        app(AgentesDeFabrica::class)->sembrar();

        // El agente de seguimiento, con la herramienta nueva y el permiso que la habilita en su rol.
        $this->agente = AgenteInterno::where('clave', 'seguimiento_comercial')->firstOrFail();
        $this->agente->update(['activo' => true]);
        $this->agente->cargo->update(['herramientas' => array_merge($this->agente->cargo->herramientas, ['ejecutar_automatizacion'])]);
        $this->agente->cargo->rol->permisosAsignados()->create(['permiso' => 'automatizaciones.ejecutar']);
    }

    // ─── Ayudas ──────────────────────────────────────────────────────────────

    private function automatizacion(bool $paraAgentes = true, bool $activa = true, string $estado = 'enviada'): Automatizacion
    {
        $grafo = [
            'nodos' => [
                ['id' => 'disparo', 'tipo' => 'disparador', 'config' => ['tipo' => 'evento', 'evento' => 'cotizacion.estado']],
                ['id' => 'p1', 'tipo' => 'condicion', 'config' => ['combinador' => 'y', 'reglas' => [['campo' => 'estado', 'operador' => 'igual', 'valor' => $estado]]]],
                ['id' => 'p2', 'tipo' => 'accion', 'accion' => 'notificar', 'config' => ['destinatario' => 'autor', 'titulo' => 'Seguimiento de {{numero}}']],
            ],
            'aristas' => [['de' => 'disparo', 'a' => 'p1'], ['de' => 'p1', 'a' => 'p2', 'rama' => 'si']],
        ];
        $this->assertSame([], \App\Services\Automatizaciones\GrafoAutomatizacion::validar($grafo, $this->admin));

        return Automatizacion::create([
            'nombre' => 'Seguimiento de cotización', 'descripcion' => 'Avisa al vendedor.', 'grafo' => $grafo, 'activa' => $activa,
            'disponible_agentes' => $paraAgentes, 'creado_por' => $this->admin->id,
            'disparador_tipo' => 'evento', 'disparador_clave' => 'cotizacion.estado',
        ]);
    }

    private function cotizacion(string $estado = 'enviada'): Cotizacion
    {
        $cliente = Cliente::create(['tipo' => 'empresa', 'nombre' => 'Frío Andino SAS', 'tipo_identificacion' => 'NIT', 'numero_identificacion' => '900' . random_int(100000, 999999)]);

        return Cotizacion::create(['cliente_id' => $cliente->id, 'moneda' => 'COP', 'tasa_cambio' => 1, 'estado' => $estado, 'responsable_id' => $this->admin->id]);
    }

    /** La herramienta como la ejecuta el bucle del agente, con su usuario de servicio puesto. */
    private function lanzar(array $args, bool $prueba = false): array
    {
        $usuario = app(\App\Services\Agentes\AgenteUsuarioService::class)->asegurar($this->agente);
        $this->actingAs($usuario);
        app()->forgetInstance('briela.permisos.' . $usuario->id);

        $permitidas = CatalogoHerramientas::paraAgente($this->agente->fresh(), $usuario);
        $r = app(CatalogoHerramientas::class)->ejecutar('ejecutar_automatizacion', $args, $permitidas, $this->agente, null, $prueba);

        $this->actingAs($this->admin);

        return json_decode($r['json'], true);
    }

    // ─── Qué se le ofrece ────────────────────────────────────────────────────

    public function test_sin_automatizaciones_para_agentes_la_herramienta_no_se_ofrece(): void
    {
        $this->automatizacion(paraAgentes: false);
        $usuario = app(\App\Services\Agentes\AgenteUsuarioService::class)->asegurar($this->agente);

        $this->assertArrayNotHasKey('ejecutar_automatizacion', CatalogoHerramientas::paraAgente($this->agente->fresh(), $usuario));
    }

    public function test_con_una_disponible_se_ofrece_descrita_y_solo_a_quien_propone(): void
    {
        $a = $this->automatizacion();
        $usuario = app(\App\Services\Agentes\AgenteUsuarioService::class)->asegurar($this->agente);

        $h = CatalogoHerramientas::paraAgente($this->agente->fresh(), $usuario);
        $this->assertStringContainsString("#{$a->id} «Seguimiento de cotización»", $h['ejecutar_automatizacion']['descripcion']);
        $this->assertStringContainsString('cotización', $h['ejecutar_automatizacion']['descripcion']);

        // Una apagada no se ofrece, aunque esté marcada.
        $a->update(['activa' => false]);
        $this->assertArrayNotHasKey('ejecutar_automatizacion', CatalogoHerramientas::paraAgente($this->agente->fresh(), $usuario));
        $a->update(['activa' => true]);

        // Un agente de solo lectura no escribe nada: tampoco lanza.
        $this->agente->update(['modo' => 'solo_lectura']);
        $this->assertArrayNotHasKey('ejecutar_automatizacion', CatalogoHerramientas::paraAgente($this->agente->fresh(), $usuario));
    }

    public function test_sin_el_permiso_en_el_rol_del_agente_no_se_ofrece(): void
    {
        $this->automatizacion();
        $this->agente->cargo->rol->permisosAsignados()->where('permiso', 'automatizaciones.ejecutar')->delete();
        $usuario = app(\App\Services\Agentes\AgenteUsuarioService::class)->asegurar($this->agente);

        $this->assertArrayNotHasKey('ejecutar_automatizacion', CatalogoHerramientas::paraAgente($this->agente->fresh(), $usuario));
    }

    // ─── Lanzar ──────────────────────────────────────────────────────────────

    public function test_el_agente_lanza_la_automatizacion_sobre_un_documento_y_ella_hace_el_trabajo(): void
    {
        // El documento existe ANTES que la automatización: así el evento de crearlo no la dispara.
        $cot = $this->cotizacion();
        $a   = $this->automatizacion();

        $r = $this->lanzar(['automatizacion_id' => $a->id, 'documento' => $cot->numero]);

        $this->assertTrue($r['lanzada'], json_encode($r));
        $this->assertSame(1, AutomatizacionEjecucion::count());
        $corrida = AutomatizacionEjecucion::firstOrFail();
        $this->assertSame('agente', $corrida->origen);
        $this->assertSame($cot->id, $corrida->entidad_id);
        $this->assertSame('terminada', $corrida->fresh()->estado, (string) $corrida->fresh()->error);
        $this->assertTrue(Notificacion::where('user_id', $this->admin->id)->where('titulo', "Seguimiento de {$cot->numero}")->exists(), 'la automatización avisó, sin gastar tokens');
        Http::assertNothingSent();
    }

    public function test_una_automatizacion_no_ofrecida_no_se_puede_lanzar(): void
    {
        $cot = $this->cotizacion();
        $a   = $this->automatizacion(paraAgentes: false);

        $r = $this->lanzar(['automatizacion_id' => $a->id, 'documento' => $cot->numero]);

        $this->assertArrayHasKey('error', $r);
        $this->assertSame(0, AutomatizacionEjecucion::count());
    }

    public function test_si_el_documento_no_cumple_las_condiciones_no_se_lanza(): void
    {
        $cot = $this->cotizacion('enviada');
        $a   = $this->automatizacion(estado: 'aprobada');

        $r = $this->lanzar(['automatizacion_id' => $a->id, 'documento' => $cot->numero]);

        $this->assertFalse($r['lanzada']);
        $this->assertSame(0, AutomatizacionEjecucion::count());
    }

    public function test_pide_el_documento_y_no_acepta_uno_inexistente(): void
    {
        $a = $this->automatizacion();

        $this->assertStringContainsString('documento', $this->lanzar(['automatizacion_id' => $a->id])['error']);
        $this->assertStringContainsString('No encontré', $this->lanzar(['automatizacion_id' => $a->id, 'documento' => 'COT-NO-EXISTE'])['error']);
        $this->assertSame(0, AutomatizacionEjecucion::count());
    }

    public function test_repetir_la_llamada_en_el_mismo_minuto_no_duplica(): void
    {
        $cot = $this->cotizacion();
        $a   = $this->automatizacion();

        $this->assertTrue($this->lanzar(['automatizacion_id' => $a->id, 'documento' => $cot->numero])['lanzada']);
        $this->assertFalse($this->lanzar(['automatizacion_id' => $a->id, 'documento' => $cot->numero])['lanzada']);
        $this->assertSame(1, AutomatizacionEjecucion::count());
    }

    public function test_una_prueba_en_seco_solo_simula(): void
    {
        $cot = $this->cotizacion();
        $a   = $this->automatizacion();

        $r = $this->lanzar(['automatizacion_id' => $a->id, 'documento' => $cot->numero], prueba: true);

        $this->assertTrue($r['simulacion']);
        $this->assertNotEmpty($r['pasos']);
        $this->assertSame(0, AutomatizacionEjecucion::count());
    }

    public function test_hay_un_tope_por_corrida(): void
    {
        $a = $this->automatizacion();

        // Una corrida real del agente, con el tope ya gastado.
        $ej = \App\Models\AgenteEjecucion::create(['agente_id' => $this->agente->id, 'estado' => 'corriendo', 'origen' => 'manual']);
        foreach (range(1, AutomatizacionesParaAgentes::MAX_POR_CORRIDA) as $i) {
            AutomatizacionEjecucion::create([
                'automatizacion_id' => $a->id, 'estado' => 'terminada', 'origen' => 'agente', 'grafo' => $a->grafo,
                'agente_ejecucion_id' => $ej->id, 'clave_idempotencia' => "x{$i}",
            ]);
        }

        $usuario = app(\App\Services\Agentes\AgenteUsuarioService::class)->asegurar($this->agente);
        $this->actingAs($usuario);
        $permitidas = CatalogoHerramientas::paraAgente($this->agente->fresh(), $usuario);
        $r = app(CatalogoHerramientas::class)->ejecutar('ejecutar_automatizacion', ['automatizacion_id' => $a->id, 'documento' => $this->cotizacion()->numero], $permitidas, $this->agente, $ej, false);

        $this->assertStringContainsString('máximo', json_decode($r['json'], true)['error']);
    }

    // ─── Quién la ofrece ─────────────────────────────────────────────────────

    public function test_solo_quien_gestiona_el_equipo_de_ia_la_ofrece_a_los_agentes(): void
    {
        $grafo = $this->automatizacion(paraAgentes: false)->grafo;

        // Un administrador puede.
        $id = $this->postJson('/api/automatizaciones', ['nombre' => 'Para agentes', 'grafo' => $grafo, 'disponible_agentes' => true])->assertCreated()->json('id');
        $this->assertTrue(Automatizacion::find($id)->disponible_agentes);

        // Alguien que gestiona automatizaciones pero NO el Equipo de IA no puede darles ese poder.
        $rol = Rol::create(['nombre' => 'Solo automatizaciones', 'activo' => true]);
        foreach (['automatizaciones.ver', 'automatizaciones.gestionar', 'cotizaciones.ver'] as $permiso) {
            $rol->permisosAsignados()->create(['permiso' => $permiso]);
        }
        $otro = User::factory()->create(['rol' => 'operario', 'rol_id' => $rol->id]);
        $this->actingAs($otro);

        $nuevo = $this->postJson('/api/automatizaciones', ['nombre' => 'Otra', 'grafo' => $grafo, 'disponible_agentes' => true])->assertCreated()->json('id');
        $this->assertFalse(Automatizacion::find($nuevo)->disponible_agentes);

        // Y editar la que ya estaba ofrecida le quita el visto bueno: cambia lo que hace y de quién es.
        $this->putJson("/api/automatizaciones/{$id}", ['nombre' => 'Para agentes', 'grafo' => $grafo, 'disponible_agentes' => true])->assertOk();
        $this->assertFalse(Automatizacion::find($id)->disponible_agentes);
    }

    public function test_el_modulo_apagado_no_ofrece_nada(): void
    {
        $this->automatizacion();
        Modulos::guardar(['automatizaciones'], 'instalacion');

        $this->assertTrue(AutomatizacionesParaAgentes::disponibles()->isEmpty());
    }

    public function test_en_una_corrida_completa_el_modelo_pide_la_automatizacion_y_queda_anotado(): void
    {
        $cot = $this->cotizacion();
        $a   = $this->automatizacion();

        $llamada = ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'ejecutar_automatizacion', 'arguments' => json_encode(['automatizacion_id' => $a->id, 'documento' => $cot->numero])]];
        $cuerpo  = fn (array $m) => ['model' => 'modelo/prueba', 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 20],
            'choices' => [['message' => $m + ['role' => 'assistant'], 'finish_reason' => 'stop']]];

        Http::fake(['*' => Http::sequence()
            ->push($cuerpo(['content' => null, 'tool_calls' => [$llamada]]))
            ->push($cuerpo(['content' => 'Lancé el seguimiento de ' . $cot->numero . '; queda en cola.']))]);

        $e = app(LanzadorAgentes::class)->manual($this->agente, $this->admin)->fresh();

        $this->assertSame('terminada', $e->estado, (string) $e->error);
        Http::assertSent(fn ($r) => collect($r['tools'] ?? [])->pluck('function.name')->contains('ejecutar_automatizacion')
            && str_contains(json_encode($r['tools']), "#{$a->id}"));

        $corrida = AutomatizacionEjecucion::where('origen', 'agente')->firstOrFail();
        $this->assertSame($e->id, (int) $corrida->agente_ejecucion_id, 'se sabe qué corrida del agente la pidió');
        $this->assertSame('herramienta', $e->registroPasos[1]->tipo);
        $this->assertSame('ejecutar_automatizacion', $e->registroPasos[1]->herramienta);
    }
}
