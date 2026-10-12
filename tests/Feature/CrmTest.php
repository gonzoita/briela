<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CrmEtapa;
use App\Models\CrmFormulario;
use App\Models\CrmLead;
use App\Models\Sede;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El CRM conectado al resto: lo que pasa con la cotización mueve el lead, la conversión no
 * duplica clientes, la cotización automática respeta lo configurado y las puertas de entrada
 * externas (formularios públicos, agente de IA) pasan por la misma puerta única.
 */
class CrmTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private CrmEtapa $nuevo;
    private CrmEtapa $cotizar;
    private CrmEtapa $ganado;
    private CrmEtapa $perdido;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['rol' => 'administrador']);
        Sede::query()->first() ?? Sede::create(['nombre' => 'Principal', 'es_principal' => true]);

        $this->nuevo   = CrmEtapa::create(['nombre' => 'Nuevo', 'orden' => 1, 'activa' => true]);
        $this->cotizar = CrmEtapa::create(['nombre' => 'Cliente nuevo', 'orden' => 2, 'activa' => true, 'accion_automatica' => 'cotizacion']);
        $this->ganado  = CrmEtapa::create(['nombre' => 'Ganado', 'orden' => 3, 'activa' => true, 'es_ganado' => true]);
        $this->perdido = CrmEtapa::create(['nombre' => 'Perdido', 'orden' => 4, 'activa' => true, 'es_perdido' => true]);

        $this->actingAs($this->admin);
    }

    private function lead(?int $clienteId = null): CrmLead
    {
        return CrmLead::create([
            'etapa_id' => $this->nuevo->id, 'titulo' => 'Puertas para bodega', 'estado' => 'activo',
            'nombre_contacto' => 'Laura Gómez', 'telefono_contacto' => '300 123 4567', 'cliente_id' => $clienteId,
        ]);
    }

    private function cliente(): Cliente
    {
        return Cliente::create(['tipo' => 'empresa', 'nombre' => 'Frío Andino SAS', 'tipo_identificacion' => 'NIT', 'numero_identificacion' => '900555111']);
    }

    private function cotizacion(CrmLead $lead, string $estado = 'enviada'): Cotizacion
    {
        return Cotizacion::create([
            'lead_id' => $lead->id, 'cliente_id' => $lead->cliente_id ?? $this->cliente()->id,
            'moneda' => 'COP', 'tasa_cambio' => 1, 'estado' => $estado, 'responsable_id' => $this->admin->id,
        ]);
    }

    // ─── La cotización mueve el lead ─────────────────────────────────────────

    public function test_cotizacion_aprobada_por_el_cliente_gana_el_lead(): void
    {
        $lead = $this->lead();
        $cot  = $this->cotizacion($lead);

        auth()->logout();
        $this->post("/cotizaciones/{$cot->token_publico}/aprobar");

        $lead->refresh();
        $this->assertSame('ganado', $lead->estado);
        $this->assertSame($this->ganado->id, $lead->etapa_id);
        $this->assertNotNull($lead->fecha_cierre);
    }

    public function test_cotizacion_rechazada_pierde_el_lead_con_el_motivo(): void
    {
        $lead = $this->lead();
        $cot  = $this->cotizacion($lead);

        auth()->logout();
        $this->post("/cotizaciones/{$cot->token_publico}/rechazar", ['motivo' => 'Muy costoso']);

        $lead->refresh();
        $this->assertSame('perdido', $lead->estado);
        $this->assertSame($this->perdido->id, $lead->etapa_id);
        $this->assertSame('Muy costoso', $lead->motivo_cierre);
    }

    public function test_si_hay_otra_cotizacion_viva_el_lead_sigue_abierto(): void
    {
        $lead = $this->lead();
        $vieja = $this->cotizacion($lead);
        $this->cotizacion($lead, 'borrador'); // la versión que está mirando ahora

        $vieja->update(['estado' => 'rechazada']);

        $this->assertSame('activo', $lead->fresh()->estado);
    }

    public function test_una_cotizacion_vencida_pierde_el_lead(): void
    {
        $lead = $this->lead();
        $cot  = $this->cotizacion($lead);
        $cot->update(['fecha_validez' => now()->subDay()->toDateString()]);

        $this->artisan('cotizaciones:marcar-vencidas')->assertSuccessful();

        $this->assertSame('perdido', $lead->fresh()->estado);
        $this->assertStringContainsString('venció', $lead->fresh()->motivo_cierre);
    }

    public function test_un_lead_ganado_no_se_pierde_porque_se_venza_otra_cotizacion(): void
    {
        $lead  = $this->lead();
        $this->cotizacion($lead)->update(['estado' => 'aprobada']);
        $otra = $this->cotizacion($lead);

        $otra->update(['estado' => 'vencida']);

        $this->assertSame('ganado', $lead->fresh()->estado);
    }

    // ─── Cotización automática y conversión ──────────────────────────────────

    public function test_la_cotizacion_automatica_usa_las_condiciones_configuradas(): void
    {
        \App\Models\Configuracion::set('cotizacion_condiciones', 'Pago contra entrega. Garantía 1 año.');
        $lead = $this->lead($this->cliente()->id);

        $this->postJson("/crm/leads/{$lead->id}/mover", ['etapa_id' => $this->cotizar->id, 'orden_en_etapa' => 0])
            ->assertOk()->assertJsonPath('accion', 'cotizacion_creada');

        $cot = $lead->cotizaciones()->firstOrFail();
        $this->assertSame('Pago contra entrega. Garantía 1 año.', $cot->condiciones_comerciales);
    }

    public function test_convertir_a_cliente_reutiliza_el_que_ya_existe(): void
    {
        $existente = $this->cliente();
        $lead      = $this->lead();

        $this->postJson("/crm/leads/{$lead->id}/convertir", [
            'tipo' => 'empresa', 'nombre' => 'Frio Andino', 'numero_identificacion' => '900.555.111',
        ])->assertOk()->assertJsonPath('ya_existia', true);

        $this->assertSame(1, Cliente::count());
        $this->assertSame($existente->id, $lead->fresh()->cliente_id);
    }

    public function test_convertir_crea_la_empresa_con_su_contacto_y_documento(): void
    {
        $lead = $this->lead();

        $this->postJson("/crm/leads/{$lead->id}/convertir", [
            'tipo' => 'empresa', 'nombre' => 'Hielo del Norte', 'numero_identificacion' => '901222333',
        ])->assertOk()->assertJsonPath('ya_existia', false);

        $cliente = Cliente::where('nombre', 'Hielo del Norte')->firstOrFail();
        $this->assertSame('NIT', $cliente->tipo_identificacion);
        $this->assertSame('Laura Gómez', $cliente->contactos()->value('nombre'));
        // Y al pasar a la etapa de cotizar se generó la cotización en borrador.
        $this->assertSame(1, $lead->cotizaciones()->count());
    }

    // ─── Puertas de entrada externas ─────────────────────────────────────────

    public function test_el_formulario_publico_crea_el_lead_y_no_duplica_a_quien_vuelve(): void
    {
        $vendedor = User::factory()->create(['rol' => 'vendedor']);
        CrmFormulario::create([
            'nombre' => 'Contacto web', 'slug' => 'contacto', 'activo' => true, 'responsable_id' => $vendedor->id,
            'asignacion_tipo' => 'fijo',
            'campos' => [
                ['nombre' => 'nombre', 'etiqueta' => 'Nombre', 'requerido' => true],
                ['nombre' => 'telefono', 'etiqueta' => 'Teléfono', 'requerido' => true],
                ['nombre' => 'mensaje', 'etiqueta' => 'Mensaje', 'requerido' => false],
            ],
        ]);
        auth()->logout();

        $this->post('/f/contacto', ['nombre' => 'Pedro Ruiz', 'telefono' => '+57 310 555 0000', 'mensaje' => 'Cotizar cuarto frío']);
        $this->post('/f/contacto', ['nombre' => 'Pedro Ruiz', 'telefono' => '3105550000', 'mensaje' => 'Sigo interesado']);

        $this->assertSame(1, CrmLead::count());
        $lead = CrmLead::firstOrFail();
        $this->assertSame($vendedor->id, $lead->responsable_id);
        $this->assertSame(2, $lead->origenes()->where('canal', 'formulario')->count());
        $this->assertTrue(\App\Models\Notificacion::where('user_id', $vendedor->id)->where('titulo', 'Volvió a escribir desde la web')->exists());
    }

    public function test_un_lead_de_otra_sede_no_se_abre_por_enlace_directo(): void
    {
        $otra = Sede::create(['nombre' => 'Sucursal', 'codigo' => 'SUC']);
        $lead = $this->lead();
        $lead->update(['sede_id' => $otra->id]);

        $principal = Sede::where('id', '!=', $otra->id)->firstOrFail();
        $this->withSession(['sede_activa_id' => $principal->id]);

        $this->getJson("/crm/leads/{$lead->id}")->assertNotFound();
    }
}
