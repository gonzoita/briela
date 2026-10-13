<?php

namespace Tests\Feature;

use App\Models\AgenteEjecucion;
use App\Models\AgenteIa;
use App\Models\AgenteInterno;
use App\Models\Configuracion;
use App\Models\PromptVersionIa;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Services\Agentes\AgentesDeFabrica;
use App\Services\IA\TransaccionesIaService;
use App\Support\Modulos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * La guía —el agente principal— configura a los demás agentes desde el chat. Con las mismas reglas
 * que cualquier transacción: proponer no escribe, confirmar usa el controlador de la pantalla y
 * exige su permiso.
 */
class GuiaAgentesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Modulos::guardar([], 'instalacion');
        Cache::flush();
        Configuracion::set('ia_api_key', 'llave-de-prueba');
        Sede::query()->first() ?? Sede::create(['nombre' => 'Principal', 'es_principal' => true]);

        $this->admin = User::factory()->create(['rol' => 'administrador']);
        $this->actingAs($this->admin);

        app(AgentesDeFabrica::class)->sembrar();
    }

    private function svc(): TransaccionesIaService
    {
        return app(TransaccionesIaService::class);
    }

    /** Propone y confirma, como la tarjeta del chat. */
    private function hacer(string $accion, array $p): array
    {
        $propuesta = $this->svc()->proponer($accion, $p);
        $this->assertTrue($propuesta['exito'] ?? false, json_encode($propuesta));

        return $this->svc()->ejecutar($accion, $propuesta['propuesta']['parametros']);
    }

    private function analista(): AgenteInterno
    {
        return AgenteInterno::where('clave', 'analista_del_dia')->firstOrFail();
    }

    public function test_proponer_un_cambio_no_toca_al_agente(): void
    {
        $antes = $this->analista()->frecuencia;

        $r = $this->svc()->proponer('configurar_agente', ['agente' => 'analista', 'frecuencia' => 'diaria', 'hora' => '7am']);

        $this->assertTrue($r['exito']);
        $this->assertStringContainsString('todos los días a las 07:00', $r['propuesta']['resumen']);
        $this->assertEquals($antes, $this->analista()->fresh()->frecuencia, 'proponer no escribe');
    }

    public function test_confirmar_cambia_solo_lo_pedido_y_deja_version(): void
    {
        $a = $this->analista();
        $tarea = $a->tarea;
        $versiones = PromptVersionIa::count();

        $r = $this->hacer('configurar_agente', [
            'agente' => 'Analista del día', 'frecuencia' => 'diaria', 'hora' => '06:45',
            'agregar_instrucciones' => 'Menciona siempre las órdenes atrasadas.',
        ]);

        $this->assertTrue($r['exito'], $r['mensaje']);
        $a->refresh();
        $this->assertSame('diaria', $a->frecuencia['tipo']);
        $this->assertSame('06:45', $a->frecuencia['hora']);
        $this->assertStringEndsWith('Menciona siempre las órdenes atrasadas.', $a->instrucciones);
        $this->assertSame($tarea, $a->tarea, 'lo que no se pidió queda igual');
        $this->assertGreaterThan($versiones, PromptVersionIa::count(), 'el cambio de instrucciones queda como versión');
    }

    public function test_encender_pasa_por_la_misma_revision_que_la_pantalla(): void
    {
        // Sin herramientas que su rol alcance, la pantalla no deja encenderlo. La guía tampoco:
        // devuelve el mismo motivo, porque guarda con el mismo controlador.
        $this->analista()->update(['herramientas' => []]);

        $r = $this->hacer('configurar_agente', ['agente' => 'analista', 'activo' => 'sí']);

        $this->assertFalse($r['exito']);
        $this->assertSame('Para activarlo necesita al menos una herramienta.', $r['mensaje']);
        $this->assertFalse($this->analista()->fresh()->activo);
    }

    public function test_un_nombre_que_no_existe_pregunta_con_la_lista(): void
    {
        $r = $this->svc()->proponer('configurar_agente', ['agente' => 'Pepito', 'activo' => 'si']);

        $this->assertFalse($r['exito']);
        $this->assertStringContainsString('Analista del día', $r['falta']);
    }

    public function test_sin_nada_que_cambiar_pregunta_que_cambiar(): void
    {
        $r = $this->svc()->proponer('configurar_agente', ['agente' => 'analista']);

        $this->assertFalse($r['exito']);
        $this->assertStringContainsString('¿Qué quieres cambiarle', $r['falta']);
    }

    public function test_crear_un_agente_nace_apagado_con_las_herramientas_de_su_cargo(): void
    {
        $cargo = $this->analista()->cargo;

        $r = $this->hacer('crear_agente', [
            'nombre' => 'Vigía de cartera', 'cargo' => $cargo->nombre,
            'tarea' => 'Revisa los pagos vencidos y dime cuáles llamar.', 'frecuencia' => 'diaria', 'hora' => '8:00',
        ]);

        $this->assertTrue($r['exito'], $r['mensaje']);
        $nuevo = AgenteInterno::where('nombre', 'Vigía de cartera')->firstOrFail();
        $this->assertFalse($nuevo->activo, 'nace apagado');
        $this->assertSame($cargo->id, $nuevo->cargo_id);
        $this->assertSame('08:00', $nuevo->frecuencia['hora']);
    }

    public function test_crear_sin_tarea_pregunta_que_hara(): void
    {
        $r = $this->svc()->proponer('crear_agente', ['nombre' => 'Sin tarea']);

        $this->assertFalse($r['exito']);
        $this->assertStringContainsString('¿Qué tiene que hacer', $r['falta']);
    }

    public function test_poner_a_trabajar_a_un_agente_apagado_no_se_propone(): void
    {
        $r = $this->svc()->proponer('ejecutar_agente', ['agente' => 'analista']);

        $this->assertFalse($r['exito']);
        $this->assertStringContainsString('está apagado', $r['falta']);
    }

    public function test_poner_a_trabajar_a_un_agente_encendido_crea_la_corrida(): void
    {
        $this->analista()->update(['activo' => true]);

        $r = $this->hacer('ejecutar_agente', ['agente' => 'analista']);

        $this->assertTrue($r['exito'], $r['mensaje']);
        $this->assertSame(1, AgenteEjecucion::where('agente_id', $this->analista()->id)->count());
    }

    public function test_un_agente_de_atencion_se_apaga_desde_el_chat(): void
    {
        $agente = AgenteIa::create([
            'nombre' => 'Recepción WhatsApp', 'activo' => true, 'perfil' => 'publico',
            'canales' => ['whatsapp'], 'herramientas' => [], 'instrucciones' => 'Sé amable.',
        ]);

        $r = $this->hacer('configurar_agente_atencion', ['agente' => 'recepción', 'activo' => 'no', 'saludo' => '¡Hola! ¿En qué te ayudo?']);

        $this->assertTrue($r['exito'], $r['mensaje']);
        $agente->refresh();
        $this->assertFalse($agente->activo);
        $this->assertSame('¡Hola! ¿En qué te ayudo?', $agente->saludo);
        $this->assertSame('Sé amable.', $agente->instrucciones, 'lo que no se pidió queda igual');
    }

    public function test_sin_permiso_de_gestionar_la_guia_no_puede_configurar_agentes(): void
    {
        $rol = Rol::create(['nombre' => 'Solo mira', 'rol_base' => 'vendedor', 'activo' => true, 'todas_las_sedes' => true]);
        $rol->sincronizarPermisos(['equipo_ia.ver']);
        $this->actingAs(User::factory()->create(['rol' => 'vendedor', 'rol_id' => $rol->id]));

        $this->assertNull($this->svc()->proponer('configurar_agente', ['agente' => 'analista', 'activo' => 'si']));
        $this->assertSame('No tienes permiso para hacer esto.', $this->svc()->ejecutar('configurar_agente', [])['mensaje']);
    }
}
