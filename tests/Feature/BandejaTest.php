<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappNumero;
use App\Models\WhatsappPlantilla;
use App\Support\CredencialesRrss;
use App\Support\Modulos;
use App\Support\Permisos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * La bandeja: leer y contestar lo que escriben los clientes.
 *
 * Lo que fijan estas pruebas, y por qué cada una:
 *
 * - **La ventana de 24 horas.** Es la regla que más cuesta y la que más se rompe: Meta solo
 *   deja escribir texto libre dentro de las 24 horas siguientes al último mensaje de la
 *   persona, y su error no dice eso. Se fija que fuera del plazo no se intente enviar, que la
 *   cuenta corra desde el último mensaje ENTRANTE —no desde el último de la conversación— y
 *   que una plantilla sí salga con el plazo cerrado.
 * - **`ultimo_entrante_at` no lo escribe un mensaje nuestro.** Si lo escribiera, contestar
 *   sería regalarse 24 horas que Meta no dio, y la pantalla ofrecería una caja de texto que
 *   la API rechaza.
 * - **La clave «canal:id» llega del navegador.** Un canal inventado no puede alcanzar ninguna
 *   tabla.
 * - **Permisos y módulo.** Leer y contestar son permisos distintos, y la bandeja apagada no
 *   recibe ni deja contestar.
 */
class BandejaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Modulos::olvidar();

        // Sin token no se envía nada, y la mitad de las pruebas son sobre enviar.
        CredencialesRrss::guardar('whatsapp', 'secret', 'token-de-prueba');
    }

    // ─── Ayudas ──────────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'administrador', 'activo' => true]);
    }

    private function linea(): WhatsappNumero
    {
        return WhatsappNumero::create([
            'nombre'          => 'Central',
            'numero_telefono' => '+573001112233',
            'phone_number_id' => '111222333',
            'rol'             => 'central',
            'activo'          => true,
        ]);
    }

    /**
     * Una conversación con un mensaje entrante hace `$haceHoras` horas.
     *
     * Las horas son el parámetro que importa: toda la lógica de la ventana depende de eso.
     */
    private function conversacion(WhatsappNumero $linea, float $haceHoras = 1, array $extra = []): WhatsappConversacion
    {
        $cuando = now()->subMinutes((int) round($haceHoras * 60));

        $conversacion = WhatsappConversacion::create(array_merge([
            'whatsapp_numero_id' => $linea->id,
            'numero_contacto'    => '573009998877',
            'nombre_contacto'    => 'Marta Ruiz',
            'ultimo_mensaje_at'  => $cuando,
            'ultimo_entrante_at' => $cuando,
            'leido'              => false,
        ], $extra));

        WhatsappMensaje::create([
            'whatsapp_conversacion_id' => $conversacion->id,
            'direccion' => 'entrante',
            'tipo'      => 'texto',
            'contenido' => 'Buenas, necesito una cotización',
            'created_at' => $cuando,
        ]);

        return $conversacion;
    }

    /** Meta responde que sí. */
    private function metaResponde(): void
    {
        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'messages' => [['id' => 'wamid.PRUEBA']],
            ], 200),
        ]);
    }

    // ─── La lista ────────────────────────────────────────────────────────────

    public function test_la_bandeja_lista_las_conversaciones_de_whatsapp(): void
    {
        $linea = $this->linea();
        $this->conversacion($linea);

        $respuesta = $this->actingAs($this->admin())->get('/bandeja');

        $respuesta->assertOk();
        $respuesta->assertInertia(fn ($pagina) => $pagina
            ->component('Bandeja/Index')
            ->where('conversaciones.data.0.contacto', 'Marta Ruiz')
            ->where('conversaciones.data.0.canal', 'whatsapp')
            ->where('conversaciones.data.0.sin_leer', true)
            ->where('conversaciones.data.0.ventana_abierta', true)
            ->where('contadores.sin_leer', 1)
            ->where('contadores.sin_asignar', 1)
        );
    }

    public function test_la_vista_previa_dice_cuando_el_ultimo_mensaje_es_nuestro(): void
    {
        $linea = $this->linea();
        $conversacion = $this->conversacion($linea);

        WhatsappMensaje::create([
            'whatsapp_conversacion_id' => $conversacion->id,
            'direccion' => 'saliente',
            'tipo'      => 'texto',
            'contenido' => 'Con gusto, ¿qué medidas necesita?',
        ]);

        $this->actingAs($this->admin())->get('/bandeja')
            ->assertInertia(fn ($pagina) => $pagina
                ->where('conversaciones.data.0.ultimo_texto', 'Tú: Con gusto, ¿qué medidas necesita?')
            );
    }

    public function test_el_filtro_de_sin_leer_deja_fuera_las_leidas(): void
    {
        $linea = $this->linea();
        $this->conversacion($linea, 1, ['numero_contacto' => '573001111111', 'leido' => true]);
        $this->conversacion($linea, 2, ['numero_contacto' => '573002222222', 'leido' => false]);

        $this->actingAs($this->admin())->get('/bandeja?estado=sin_leer')
            ->assertInertia(fn ($pagina) => $pagina
                ->count('conversaciones.data', 1)
                ->where('conversaciones.data.0.handle', '573002222222')
            );
    }

    public function test_las_archivadas_no_salen_en_la_lista_normal(): void
    {
        $linea = $this->linea();
        $this->conversacion($linea, 1, ['archivada_at' => now()]);

        $this->actingAs($this->admin())->get('/bandeja')
            ->assertInertia(fn ($pagina) => $pagina->count('conversaciones.data', 0));

        $this->actingAs($this->admin())->get('/bandeja?estado=archivadas')
            ->assertInertia(fn ($pagina) => $pagina->count('conversaciones.data', 1));
    }

    // ─── El hilo ─────────────────────────────────────────────────────────────

    public function test_abrir_el_hilo_lo_marca_leido(): void
    {
        $linea = $this->linea();
        $conversacion = $this->conversacion($linea);

        $respuesta = $this->actingAs($this->admin())
            ->getJson('/bandeja/whatsapp:' . $conversacion->id . '/hilo');

        $respuesta->assertOk();
        $respuesta->assertJsonPath('contacto', 'Marta Ruiz');
        $respuesta->assertJsonPath('mensajes.0.texto', 'Buenas, necesito una cotización');
        $respuesta->assertJsonPath('ventana.abierta', true);

        // Abrirla ES leerla: pedir además un botón de «marcar como leído» es un contador que
        // nunca baja.
        $this->assertTrue($conversacion->fresh()->leido);
    }

    public function test_una_clave_con_un_canal_inventado_no_alcanza_ninguna_tabla(): void
    {
        $linea = $this->linea();
        $conversacion = $this->conversacion($linea);

        // La clave viaja en la URL, así que el canal se valida contra el catálogo antes de
        // tocar la base. Sin eso, «canal:id» sería una forma de pedir cualquier tabla.
        $this->actingAs($this->admin())
            ->getJson('/bandeja/usuarios:' . $conversacion->id . '/hilo')
            ->assertNotFound();
    }

    // ─── Responder, y la ventana de 24 horas ─────────────────────────────────

    public function test_responder_dentro_de_la_ventana_envia_y_queda_guardado(): void
    {
        $this->metaResponde();

        $linea = $this->linea();
        $conversacion = $this->conversacion($linea, 2);
        $usuario = $this->admin();

        $respuesta = $this->actingAs($usuario)
            ->postJson('/bandeja/whatsapp:' . $conversacion->id . '/responder', [
                'texto' => 'Claro que sí, ya le cotizo',
            ]);

        $respuesta->assertOk();
        $respuesta->assertJsonPath('mensaje.texto', 'Claro que sí, ya le cotizo');
        $respuesta->assertJsonPath('mensaje.direccion', 'saliente');
        // Queda con nombre y apellido: un mensaje mandado por una persona no es «Automático».
        $respuesta->assertJsonPath('mensaje.autor', $usuario->name);

        $this->assertDatabaseHas('whatsapp_mensajes', [
            'whatsapp_conversacion_id' => $conversacion->id,
            'direccion'  => 'saliente',
            'contenido'  => 'Claro que sí, ya le cotizo',
            'usuario_id' => $usuario->id,
        ]);

        Http::assertSent(fn ($peticion) => str_contains($peticion->url(), '/111222333/messages')
            && $peticion['text']['body'] === 'Claro que sí, ya le cotizo');
    }

    public function test_contestar_no_reabre_la_ventana(): void
    {
        $this->metaResponde();

        $linea = $this->linea();
        $conversacion = $this->conversacion($linea, 3);
        $entranteOriginal = $conversacion->ultimo_entrante_at;

        $this->actingAs($this->admin())
            ->postJson('/bandeja/whatsapp:' . $conversacion->id . '/responder', ['texto' => 'Ya le respondo'])
            ->assertOk();

        $fresca = $conversacion->fresh();

        // La última actividad sí se mueve —hay que reordenar la lista—, pero la ventana la
        // abre el cliente y nadie más. Si esto se rompiera, la pantalla ofrecería una caja de
        // texto durante 24 horas más y Meta rechazaría cada mensaje.
        $this->assertTrue($fresca->ultimo_mensaje_at->greaterThan($entranteOriginal));
        $this->assertSame(
            $entranteOriginal->format('Y-m-d H:i'),
            $fresca->ultimo_entrante_at->format('Y-m-d H:i'),
        );
    }

    public function test_fuera_de_la_ventana_no_se_intenta_enviar_y_se_explica_por_que(): void
    {
        Http::fake();

        $linea = $this->linea();
        $conversacion = $this->conversacion($linea, 30); // más de 24 horas

        $respuesta = $this->actingAs($this->admin())
            ->postJson('/bandeja/whatsapp:' . $conversacion->id . '/responder', ['texto' => 'Hola otra vez']);

        $respuesta->assertStatus(422);
        $respuesta->assertJsonPath('message', fn ($m) => str_contains($m, '24 horas') && str_contains($m, 'plantilla'));

        // Lo importante: NO se llamó a Meta. El error que devuelve no dice «se te venció el
        // plazo», así que dejar que falle allá es dejar a quien atiende creyendo que envió.
        Http::assertNothingSent();
        $this->assertDatabaseMissing('whatsapp_mensajes', ['contenido' => 'Hola otra vez']);
    }

    public function test_la_ventana_se_cuenta_desde_el_ultimo_mensaje_del_cliente(): void
    {
        $linea = $this->linea();

        // El cliente escribió hace 30 horas y la empresa contestó hace 10 minutos. La ventana
        // está CERRADA: que nosotros hayamos escrito hace un rato no reabre nada.
        $conversacion = $this->conversacion($linea, 30);
        $conversacion->update(['ultimo_mensaje_at' => now()->subMinutes(10)]);

        $this->assertFalse($conversacion->fresh()->ventanaAbierta());
    }

    public function test_sin_texto_ni_archivo_no_se_manda_nada(): void
    {
        Http::fake();

        $linea = $this->linea();
        $conversacion = $this->conversacion($linea);

        $this->actingAs($this->admin())
            ->postJson('/bandeja/whatsapp:' . $conversacion->id . '/responder', ['texto' => '   '])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    // ─── Plantillas ──────────────────────────────────────────────────────────

    public function test_una_plantilla_si_sale_con_la_ventana_cerrada(): void
    {
        $this->metaResponde();

        $linea = $this->linea();
        $conversacion = $this->conversacion($linea, 40);

        $plantilla = WhatsappPlantilla::create([
            'nombre'    => 'aviso_cotizacion',
            'idioma'    => 'es',
            'estado'    => 'APPROVED',
            'cuerpo'    => 'Hola {{1}}, su cotización {{2}} ya está lista.',
            'variables' => 2,
        ]);

        $respuesta = $this->actingAs($this->admin())
            ->postJson('/bandeja/whatsapp:' . $conversacion->id . '/plantilla', [
                'plantilla_id' => $plantilla->id,
                'variables'    => ['Marta', 'COT-100'],
            ]);

        $respuesta->assertOk();

        // En el hilo queda el texto ya armado, no el nombre de la plantilla: dentro de un año
        // nadie va a saber qué decía «aviso_cotizacion».
        $this->assertDatabaseHas('whatsapp_mensajes', [
            'whatsapp_conversacion_id' => $conversacion->id,
            'contenido' => 'Hola Marta, su cotización COT-100 ya está lista.',
            'plantilla' => 'aviso_cotizacion',
        ]);

        Http::assertSent(fn ($peticion) => $peticion['type'] === 'template'
            && $peticion['template']['name'] === 'aviso_cotizacion'
            && $peticion['template']['components'][0]['parameters'][1]['text'] === 'COT-100');
    }

    public function test_una_plantilla_sin_aprobar_no_se_manda(): void
    {
        Http::fake();

        $linea = $this->linea();
        $conversacion = $this->conversacion($linea, 40);

        $plantilla = WhatsappPlantilla::create([
            'nombre' => 'en_revision',
            'idioma' => 'es',
            'estado' => 'PENDING',
            'cuerpo' => 'Hola',
        ]);

        $this->actingAs($this->admin())
            ->postJson('/bandeja/whatsapp:' . $conversacion->id . '/plantilla', [
                'plantilla_id' => $plantilla->id,
            ])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_una_plantilla_a_la_que_le_faltan_datos_no_se_manda_a_medias(): void
    {
        Http::fake();

        $linea = $this->linea();
        $conversacion = $this->conversacion($linea, 40);

        $plantilla = WhatsappPlantilla::create([
            'nombre'    => 'con_dos_datos',
            'idioma'    => 'es',
            'estado'    => 'APPROVED',
            'cuerpo'    => 'Hola {{1}}, su orden {{2}}.',
            'variables' => 2,
        ]);

        $this->actingAs($this->admin())
            ->postJson('/bandeja/whatsapp:' . $conversacion->id . '/plantilla', [
                'plantilla_id' => $plantilla->id,
                'variables'    => ['Marta'],
            ])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_solo_se_ofrecen_las_plantillas_aprobadas(): void
    {
        $linea = $this->linea();
        $conversacion = $this->conversacion($linea);

        WhatsappPlantilla::create(['nombre' => 'lista', 'idioma' => 'es', 'estado' => 'APPROVED', 'cuerpo' => 'Hola']);
        WhatsappPlantilla::create(['nombre' => 'rechazada', 'idioma' => 'es', 'estado' => 'REJECTED', 'cuerpo' => 'Hola']);

        $this->actingAs($this->admin())
            ->getJson('/bandeja/whatsapp:' . $conversacion->id . '/hilo')
            ->assertJsonCount(1, 'plantillas')
            ->assertJsonPath('plantillas.0.nombre', 'lista');
    }

    // ─── Asignar y archivar ──────────────────────────────────────────────────

    public function test_asignar_una_conversacion_la_saca_del_agente_de_ia(): void
    {
        $linea = $this->linea();
        $conversacion = $this->conversacion($linea);
        $asesor = User::factory()->create(['rol' => 'vendedor', 'activo' => true]);

        $this->actingAs($this->admin())
            ->postJson('/bandeja/whatsapp:' . $conversacion->id . '/asignar', ['usuario_id' => $asesor->id])
            ->assertOk()
            ->assertJsonPath('asignado_a', $asesor->id);

        $fresca = $conversacion->fresh();

        $this->assertSame($asesor->id, $fresca->asignado_a);
        // Si una persona se hizo cargo, el agente no vuelve a hablar: dos voces en el mismo
        // chat son peores que ninguna.
        $this->assertNotNull($fresca->escalada_at);
    }

    public function test_archivar_la_saca_de_la_bandeja_y_la_deja_leida(): void
    {
        $linea = $this->linea();
        $conversacion = $this->conversacion($linea);

        $this->actingAs($this->admin())
            ->postJson('/bandeja/whatsapp:' . $conversacion->id . '/archivar', ['archivar' => true])
            ->assertOk();

        $fresca = $conversacion->fresh();

        $this->assertNotNull($fresca->archivada_at);
        // Archivar es «ya la atendí»: dejarla sin leer la haría contar en el menú para siempre.
        $this->assertTrue($fresca->leido);
    }

    public function test_un_mensaje_nuevo_devuelve_a_la_bandeja_una_conversacion_archivada(): void
    {
        $linea = $this->linea();
        $conversacion = $this->conversacion($linea, 1, ['archivada_at' => now()->subDay()]);

        $conversacion->registrarActividad('entrante');

        // Archivar no es bloquear: lo que vuelve a moverse vuelve a la lista.
        $this->assertNull($conversacion->fresh()->archivada_at);
        $this->assertFalse($conversacion->fresh()->leido);
    }

    // ─── Permisos y módulo ───────────────────────────────────────────────────

    public function test_sin_permiso_de_ver_no_se_entra_a_la_bandeja(): void
    {
        $operario = User::factory()->create(['rol' => 'operario', 'activo' => true]);

        $this->actingAs($operario)->get('/bandeja')->assertForbidden();
    }

    public function test_se_puede_leer_sin_poder_contestar(): void
    {
        Http::fake();

        $linea = $this->linea();
        $conversacion = $this->conversacion($linea);

        // Un rol configurable con `ver` y sin `responder`: es el caso de la empresa donde
        // varios miran la bandeja y solo los asesores hablan a nombre de la marca.
        $rol = \App\Models\Rol::create(['nombre' => 'Mirón', 'activo' => true]);
        $rol->sincronizarPermisos(['bandeja.ver']);

        $mirando = User::factory()->create(['rol' => 'vendedor', 'rol_id' => $rol->id, 'activo' => true]);

        $this->actingAs($mirando)
            ->getJson('/bandeja/whatsapp:' . $conversacion->id . '/hilo')
            ->assertOk();

        $this->actingAs($mirando)
            ->postJson('/bandeja/whatsapp:' . $conversacion->id . '/responder', ['texto' => 'Hola'])
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_quien_solo_lee_no_puede_archivar(): void
    {
        $linea = $this->linea();
        $conversacion = $this->conversacion($linea);

        $rol = \App\Models\Rol::create(['nombre' => 'Mirón', 'activo' => true]);
        $rol->sincronizarPermisos(['bandeja.ver']);

        $mirando = User::factory()->create(['rol' => 'vendedor', 'rol_id' => $rol->id, 'activo' => true]);

        // Archivar es «ya la atendí»: quien solo puede leer no debería poder sacarle de la
        // vista a los demás lo que falta atender.
        $this->actingAs($mirando)
            ->postJson('/bandeja/whatsapp:' . $conversacion->id . '/archivar', ['archivar' => true])
            ->assertForbidden();

        $this->assertNull($conversacion->fresh()->archivada_at);
    }

    public function test_con_la_bandeja_apagada_no_hay_pantalla_ni_permisos(): void
    {
        Modulos::guardar(['bandeja'], 'instalacion');

        $this->actingAs($this->admin())->get('/bandeja')->assertRedirect('/dashboard');

        // Un módulo apagado se lleva sus permisos, igual que todos los demás.
        $this->assertNotContains('bandeja.ver', $this->admin()->permisos());
        $this->assertArrayNotHasKey('whatsapp', \App\Support\Canales::activos());
    }

    public function test_el_catalogo_de_permisos_sigue_siendo_valido(): void
    {
        // Los permisos de la bandeja tienen que estar en el catálogo: lo que no esté ahí no se
        // puede dar desde la pantalla de Roles, y el rol quedaría sin forma de concederlo.
        $todos = Permisos::todos();

        $this->assertContains('bandeja.ver', $todos);
        $this->assertContains('bandeja.responder', $todos);
        $this->assertContains('bandeja.asignar', $todos);
    }
}
