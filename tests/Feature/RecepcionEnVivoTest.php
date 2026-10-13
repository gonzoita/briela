<?php

namespace Tests\Feature;

use App\Models\AgenteIa;
use App\Models\Configuracion;
use App\Models\MensajeAsistente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappNumero;
use App\Support\Modulos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * La recepción en vivo (Atención a clientes) y la guía en la oficina del Equipo de IA. Son
 * pantallas para mirar: lo que importa es que no muestren más de lo que el permiso deja ver.
 */
class RecepcionEnVivoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Modulos::guardar([], 'instalacion');
        Cache::flush();
        Sede::query()->first() ?? Sede::create(['nombre' => 'Principal', 'es_principal' => true]);

        $this->admin = User::factory()->create(['rol' => 'administrador']);
        $this->actingAs($this->admin);

        AgenteIa::create([
            'nombre' => 'Recepción WhatsApp', 'activo' => true, 'perfil' => 'publico',
            'canales' => ['whatsapp'], 'herramientas' => [], 'instrucciones' => 'Sé amable.',
        ]);
    }

    private function conPermisos(array $permisos): User
    {
        $rol = Rol::create(['nombre' => 'Rol ' . uniqid(), 'rol_base' => 'vendedor', 'activo' => true, 'todas_las_sedes' => true]);
        $rol->sincronizarPermisos($permisos);

        return User::factory()->create(['rol' => 'vendedor', 'rol_id' => $rol->id]);
    }

    private function conversacion(string $nombre, string $texto, string $direccion, array $extra = []): WhatsappConversacion
    {
        $numero = WhatsappNumero::firstOrCreate(['phone_number_id' => '123'], ['nombre' => 'Ventas', 'numero_telefono' => '+57 300 000 0000', 'activo' => true]);
        $c = WhatsappConversacion::create([
            'whatsapp_numero_id' => $numero->id, 'numero_contacto' => '573001' . random_int(100000, 999999),
            'nombre_contacto' => $nombre, 'ultimo_mensaje_at' => now()->subMinutes(5), 'leido' => false,
        ]);
        if ($extra) $c->forceFill($extra)->save();
        DB::table('whatsapp_mensajes')->insert([
            'whatsapp_conversacion_id' => $c->id, 'direccion' => $direccion, 'tipo' => 'text', 'contenido' => $texto,
            'estado' => 'recibido', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $c;
    }

    public function test_cada_conversacion_activa_es_un_cliente_con_solo_su_nombre_y_la_inicial(): void
    {
        $this->conversacion('Marta Ruiz López', '¿Tienen cocinas en L?', 'entrante');
        $this->conversacion('Lucía Pérez', 'Con gusto te cotizamos', 'saliente');
        $this->conversacion('+57 300 123 4567', 'Hola', 'entrante', ['asignado_a' => $this->admin->id]);

        $r = $this->getJson('/api/agentes-ia/recepcion')->assertOk();

        $this->assertTrue($r->json('puede_ver'));
        $this->assertSame('Recepción WhatsApp', $r->json('agentes.0.nombre'));
        $clientes = collect($r->json('clientes'))->keyBy('nombre');

        $this->assertTrue($clientes['Marta R.']['esperando'], 'lo último lo escribió él');
        $this->assertFalse($clientes['Lucía P.']['esperando'], 'ya le contestaron');
        $this->assertTrue($clientes['Cliente']['humano'], 'un teléfono no se muestra: queda «Cliente»');
        $this->assertSame('whatsapp', $clientes['Marta R.']['red']);
        $this->assertStringNotContainsString('573001', json_encode($r->json()), 'el número del cliente no viaja');
        $this->assertSame(3, $r->json('tablero.activas'));
    }

    public function test_sin_permiso_de_la_bandeja_se_ven_los_agentes_pero_no_los_clientes(): void
    {
        $this->conversacion('Marta Ruiz', 'Hola', 'entrante');
        $this->actingAs($this->conPermisos(['agentes.ver']));

        $r = $this->getJson('/api/agentes-ia/recepcion')->assertOk();

        $this->assertFalse($r->json('puede_ver'));
        $this->assertSame([], $r->json('clientes'));
        $this->assertNull($r->json('tablero.activas'));
        $this->assertCount(1, $r->json('agentes'));
    }

    public function test_con_la_bandeja_apagada_no_hay_clientes(): void
    {
        $this->conversacion('Marta Ruiz', 'Hola', 'entrante');
        Modulos::guardar(['bandeja'], 'instalacion');
        Cache::flush();

        $r = $this->getJson('/api/agentes-ia/recepcion')->assertOk();

        $this->assertFalse($r->json('puede_ver'));
        $this->assertSame([], $r->json('clientes'));
    }

    public function test_sin_permiso_de_agentes_no_se_entra(): void
    {
        $this->actingAs($this->conPermisos(['bandeja.ver']));

        $this->getJson('/api/agentes-ia/recepcion')->assertForbidden();
    }

    public function test_la_oficina_trae_a_la_guia_con_su_nombre_y_si_esta_atendiendo(): void
    {
        Configuracion::set('ia_api_key', 'llave-de-prueba');
        Configuracion::set('ia_asistente_nombre', 'Ofelia');

        $r = $this->getJson('/api/equipo-ia/oficina')->assertOk();
        $this->assertSame('Ofelia', $r->json('guia.nombre'));
        $this->assertFalse($r->json('guia.ocupada'));

        MensajeAsistente::create(['user_id' => $this->admin->id, 'rol' => 'usuario', 'contenido' => 'Hola']);

        $this->assertTrue($this->getJson('/api/equipo-ia/oficina')->json('guia.ocupada'));
    }
}
