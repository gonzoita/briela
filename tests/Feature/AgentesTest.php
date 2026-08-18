<?php

namespace Tests\Feature;

use App\Models\AgenteIa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Los agentes se crean, se configuran, y no alcanzan lo que no les toca.
 *
 * La prueba que importa es la última: un agente público que pida una consulta de cliente tiene
 * que quedarse sin ella. Es la línea que separa «atender a un desconocido» de «mostrarle la
 * cartera de alguien», y no puede depender de que la pantalla se porte bien.
 */
class AgentesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'administrador']);
    }

    public function test_se_crea_un_agente_sin_horario(): void
    {
        // Sin horario es el caso normal: atiende siempre. Si esto falla, el módulo no se puede
        // usar sin llenar campos que nadie pidió.
        $this->actingAs($this->admin())->post('/configuracion/agentes', [
            'nombre'  => 'Ana, de ventas',
            'perfil'  => 'publico',
            'canales' => ['whatsapp'],
            'activo'  => true,
            'horario' => ['desde' => '', 'hasta' => ''],
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, AgenteIa::count());
        $this->assertTrue(AgenteIa::first()->enHorario());
    }

    public function test_un_agente_publico_no_se_queda_con_consultas_de_cliente(): void
    {
        $this->actingAs($this->admin())->post('/configuracion/agentes', [
            'nombre'       => 'Ana',
            'perfil'       => 'publico',
            'canales'      => ['whatsapp'],
            // Se le mandan a la fuerza, como haría alguien tocando la petición.
            'herramientas' => ['empresa', 'mi_cartera', 'mis_pedidos'],
        ])->assertSessionHasNoErrors();

        $herramientas = AgenteIa::first()->herramientas;

        $this->assertContains('empresa', $herramientas);
        $this->assertNotContains('mi_cartera', $herramientas);
        $this->assertNotContains('mis_pedidos', $herramientas);
    }

    public function test_el_de_clientes_si_las_conserva(): void
    {
        $this->actingAs($this->admin())->post('/configuracion/agentes', [
            'nombre'       => 'Posventa',
            'perfil'       => 'cliente',
            'canales'      => ['whatsapp'],
            'herramientas' => ['mis_pedidos', 'mi_cartera', 'empresa'],
        ])->assertSessionHasNoErrors();

        $herramientas = AgenteIa::first()->herramientas;

        $this->assertContains('mis_pedidos', $herramientas);
        $this->assertContains('mi_cartera', $herramientas);
        // «empresa» es del catálogo público: en este perfil no existe.
        $this->assertNotContains('empresa', $herramientas);
    }

    public function test_el_agente_de_un_canal_es_el_que_atiende_ese_canal(): void
    {
        AgenteIa::create(['nombre' => 'Web', 'perfil' => 'publico', 'canales' => ['web'], 'activo' => true]);
        AgenteIa::create(['nombre' => 'Wpp', 'perfil' => 'publico', 'canales' => ['whatsapp'], 'activo' => true]);
        AgenteIa::create(['nombre' => 'Apagado', 'perfil' => 'publico', 'canales' => ['whatsapp'], 'activo' => false]);

        $this->assertSame('Web', AgenteIa::paraCanal('web', 'publico')?->nombre);
        $this->assertSame('Wpp', AgenteIa::paraCanal('whatsapp', 'publico')?->nombre);
        $this->assertNull(AgenteIa::paraCanal('whatsapp', 'cliente'));
    }

    public function test_el_chat_publico_de_la_web_responde_sin_sesion(): void
    {
        // Sin agente para web no inventa que hay alguien atendiendo.
        $this->postJson('/api/agente/web', ['mensaje' => 'Hola, ¿qué venden?'])
            ->assertOk()
            ->assertJson(['atendido' => false]);
    }
}
