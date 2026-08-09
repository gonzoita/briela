<?php

namespace Tests\Feature;

use App\Models\Configuracion;
use App\Models\CrmLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/api/wp/leads` es la puerta que el plugin de WordPress usa para meter leads
 * al CRM. No pide login: lo único que la protege es el token de integración,
 * así que conviene tener fijado que ese candado no se abra solo.
 */
class WordpressIntegracionTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-de-prueba-1234567890';

    private function activarIntegracion(): void
    {
        Configuracion::set('integracion_wordpress_token', self::TOKEN);
    }

    private function formulario(array $extra = []): array
    {
        return array_merge([
            'nombre'   => 'Ana Pérez',
            'email'    => 'ana@ejemplo.com',
            'telefono' => '3001234567',
            'mensaje'  => 'Quiero cotizar un cuarto frío.',
        ], $extra);
    }

    public function test_sin_token_no_entra_nada(): void
    {
        $this->activarIntegracion();

        $this->postJson('/api/wp/leads', $this->formulario())
            ->assertStatus(401);

        $this->assertDatabaseCount('crm_leads', 0);
    }

    public function test_con_token_equivocado_tampoco(): void
    {
        $this->activarIntegracion();

        $this->withHeader('Authorization', 'Bearer otro-token-cualquiera')
            ->postJson('/api/wp/leads', $this->formulario())
            ->assertStatus(401);

        $this->assertDatabaseCount('crm_leads', 0);
    }

    public function test_si_la_integracion_no_esta_activada_se_rechaza(): void
    {
        // Sin token guardado, la puerta ni siquiera existe.
        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)
            ->postJson('/api/wp/leads', $this->formulario())
            ->assertStatus(403);

        $this->assertDatabaseCount('crm_leads', 0);
    }

    public function test_con_el_token_correcto_se_crea_el_lead_con_su_atribucion(): void
    {
        $this->activarIntegracion();

        $respuesta = $this->withHeader('Authorization', 'Bearer '.self::TOKEN)
            ->postJson('/api/wp/leads', $this->formulario([
                'empresa'       => 'Frigoríficos del Norte',
                'pagina_origen' => 'https://ejemplo.com/cuartos-frios',
                'utm_source'    => 'google',
                'utm_medium'    => 'cpc',
                'utm_campaign'  => 'cuartos-frios-2026',
            ]))
            ->assertStatus(201)
            ->assertJson(['ok' => true])
            ->json();

        $lead = CrmLead::find($respuesta['lead_id']);

        $this->assertNotNull($lead);
        $this->assertSame('Ana Pérez', $lead->nombre_contacto);
        $this->assertSame('Frigoríficos del Norte', $lead->empresa_contacto);
        $this->assertSame('Sitio web', $lead->fuente);
        $this->assertSame('google', $lead->utm_source);
        $this->assertSame('cpc', $lead->utm_medium);
        $this->assertSame('cuartos-frios-2026', $lead->utm_campaign);
        $this->assertSame('https://ejemplo.com/cuartos-frios', $lead->pagina_origen);
        $this->assertNotNull($lead->etapa_id, 'El lead debe caer en la primera etapa del pipeline.');
    }

    public function test_un_formulario_sin_nombre_no_crea_lead(): void
    {
        $this->activarIntegracion();

        $this->withHeader('Authorization', 'Bearer '.self::TOKEN)
            ->postJson('/api/wp/leads', ['email' => 'ana@ejemplo.com'])
            ->assertStatus(422);

        $this->assertDatabaseCount('crm_leads', 0);
    }
}
