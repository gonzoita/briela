<?php

namespace Tests\Feature;

use App\Models\AgenteIa;
use App\Models\BandejaConversacion;
use App\Models\BandejaMensaje;
use App\Models\CrmEtapa;
use App\Models\CrmLead;
use App\Models\CuentaRrss;
use App\Models\Notificacion;
use App\Models\User;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappNumero;
use App\Support\Canales;
use App\Support\CredencialesRrss;
use App\Support\Modulos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * La bandeja de redes: mensajes directos y comentarios de Instagram y Facebook.
 *
 * Lo que fijan estas pruebas, y por qué cada cosa importa:
 *
 * - **Un comentario nuestro no se procesa.** Es lo primero que hay que atrapar: la respuesta de
 *   la propia página vuelve por el mismo webhook, y sin el corte el agente de IA se contestaba
 *   a sí mismo en bucle, **en público**, debajo de una publicación de la empresa.
 * - **Un hilo de comentarios es UNA conversación.** Se agrupa por el comentario raíz; si cada
 *   respuesta abriera su propia conversación, una ida y vuelta de cinco mensajes serían cinco
 *   renglones en la bandeja.
 * - **Contestar comentarios con IA está apagado de fábrica.** Una respuesta automática en
 *   público la lee cualquiera, queda colgada ahí y la indexa Google.
 * - **El plazo de 24 horas vale para los directos y no para los comentarios**, porque un
 *   comentario no se le manda al buzón de nadie.
 * - **Las confirmaciones de entrega y de lectura no son mensajes.** Llegan por el mismo sitio y
 *   sin el corte cada «visto» del cliente creaba un renglón vacío.
 */
class BandejaRedesTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'secreto-de-la-app';

    protected function setUp(): void
    {
        parent::setUp();

        Modulos::olvidar();
        CredencialesRrss::guardar('meta', 'secret', self::SECRETO);
        CredencialesRrss::guardar('whatsapp', 'redirect', 'token-del-webhook');
    }

    // ─── Ayudas ──────────────────────────────────────────────────────────────

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'administrador', 'activo' => true]);
    }

    private function cuenta(string $red = 'instagram', array $extra = []): CuentaRrss
    {
        return CuentaRrss::create(array_merge([
            'red'               => $red,
            'nombre_cuenta'     => $red === 'instagram' ? '@laempresa' : 'Página de la empresa',
            'cuenta_id_externo' => $red === 'instagram' ? 'ig-cuenta-1' : 'pag-1',
            'access_token'      => 'token-de-la-pagina',
            'token_expira_en'   => now()->addDays(50),
            'activa'            => true,
        ], $extra));
    }

    /** Manda el webhook firmado como lo firma Meta. */
    private function enviar(array $cuerpo)
    {
        $json = json_encode($cuerpo);

        return $this->call('POST', '/webhook/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $json, self::SECRETO),
        ], $json);
    }

    /** Un mensaje directo de Instagram. */
    private function directoInstagram(array $mensaje = [], array $evento = []): array
    {
        return [
            'object' => 'instagram',
            'entry'  => [[
                'id' => 'ig-cuenta-1',
                'messaging' => [array_merge([
                    'sender'    => ['id' => 'persona-77'],
                    'recipient' => ['id' => 'ig-cuenta-1'],
                    'message'   => array_merge([
                        'mid'  => 'mid.UNO',
                        'text' => '¿Hacen puertas a la medida?',
                    ], $mensaje),
                ], $evento)],
            ]],
        ];
    }

    /** Un comentario en una publicación de Facebook. */
    private function comentarioFacebook(array $valor = []): array
    {
        return [
            'object' => 'page',
            'entry'  => [[
                'id' => 'pag-1',
                'changes' => [[
                    'field' => 'feed',
                    'value' => array_merge([
                        'item'       => 'comment',
                        'verb'       => 'add',
                        'comment_id' => 'com-1',
                        'post_id'    => 'post-9',
                        'message'    => '¿Cuánto vale?',
                        'from'       => ['id' => 'persona-88', 'name' => 'Luis Pérez'],
                    ], $valor),
                ]],
            ]],
        ];
    }

    /** Meta contesta a todo con un sí. */
    private function metaResponde(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'id' => 'respuesta-1', 'message_id' => 'mid.RESPUESTA',
        ], 200)]);
    }

    private function activarAutomatizacion(array $opciones = []): void
    {
        foreach (array_merge([
            'bandeja_auto_activo' => '1',
            'bandeja_auto_avisar' => '1',
        ], $opciones) as $clave => $valor) {
            \App\Models\Configuracion::set($clave, $valor);
        }
    }

    // ─── El webhook y su firma ───────────────────────────────────────────────

    public function test_meta_verifica_el_webhook_con_el_token_compartido(): void
    {
        // Es la misma aplicación de Meta que WhatsApp, así que el token de verificación es el
        // mismo: pedir dos contraseñas distintas para dos webhooks de la misma app es una más
        // que olvidar.
        $this->get('/webhook/meta?hub_mode=subscribe&hub_verify_token=token-del-webhook&hub_challenge=99')
            ->assertOk()
            ->assertSee('99');

        $this->get('/webhook/meta?hub_mode=subscribe&hub_verify_token=otro&hub_challenge=99')
            ->assertForbidden();
    }

    public function test_sin_app_secret_no_entra_nada(): void
    {
        \App\Models\Configuracion::set('rrss_meta_app_secret', '');
        $this->cuenta();

        $this->postJson('/webhook/meta', $this->directoInstagram())->assertForbidden();

        $this->assertDatabaseCount('bandeja_conversaciones', 0);
    }

    public function test_una_firma_que_no_cuadra_se_rechaza(): void
    {
        $this->cuenta();
        $json = json_encode($this->directoInstagram());

        $this->call('POST', '/webhook/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $json, 'inventado'),
        ], $json)->assertForbidden();

        $this->assertDatabaseCount('bandeja_mensajes', 0);
    }

    // ─── Mensajes directos ───────────────────────────────────────────────────

    public function test_un_directo_de_instagram_crea_la_conversacion(): void
    {
        $cuenta = $this->cuenta();
        Http::fake(['graph.facebook.com/*' => Http::response(['name' => 'Ana Gómez', 'username' => 'anag'], 200)]);

        $this->enviar($this->directoInstagram())->assertOk();

        $conversacion = BandejaConversacion::first();

        $this->assertNotNull($conversacion);
        $this->assertSame('instagram_dm', $conversacion->canal);
        $this->assertSame('persona-77', $conversacion->externo_id);
        $this->assertSame($cuenta->id, $conversacion->cuenta_rrss_id);
        // El webhook no trae el nombre: se le pregunta a Meta al crear la conversación.
        $this->assertSame('Ana Gómez', $conversacion->nombre_contacto);
        $this->assertSame('anag', $conversacion->usuario_externo);

        $this->assertFalse($conversacion->leido);
        $this->assertNotNull($conversacion->ultimo_entrante_at);
        $this->assertTrue($conversacion->ventanaAbierta());

        $this->assertDatabaseHas('bandeja_mensajes', [
            'externo_id' => 'mid.UNO',
            'direccion'  => 'entrante',
            'contenido'  => '¿Hacen puertas a la medida?',
        ]);
    }

    public function test_si_meta_no_da_el_nombre_la_conversacion_se_atiende_igual(): void
    {
        $this->cuenta();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'sin permiso']], 400)]);

        $this->enviar($this->directoInstagram())->assertOk();

        $conversacion = BandejaConversacion::first();

        // Perder el mensaje por no saber cómo se llama la persona sería absurdo.
        $this->assertNotNull($conversacion);
        $this->assertSame('Sin nombre', $conversacion->comoSeLlama());
    }

    public function test_una_confirmacion_de_lectura_no_es_un_mensaje(): void
    {
        $this->cuenta();
        Http::fake();

        $this->enviar([
            'object' => 'instagram',
            'entry'  => [[
                'id' => 'ig-cuenta-1',
                'messaging' => [[
                    'sender'    => ['id' => 'persona-77'],
                    'recipient' => ['id' => 'ig-cuenta-1'],
                    'read'      => ['watermark' => 1700000000],
                ]],
            ]],
        ])->assertOk();

        // Sin el corte, cada «visto» del cliente creaba un renglón vacío en la bandeja.
        $this->assertDatabaseCount('bandeja_mensajes', 0);
        $this->assertDatabaseCount('bandeja_conversaciones', 0);
    }

    public function test_el_mismo_mensaje_dos_veces_entra_una_sola(): void
    {
        $this->cuenta();
        Http::fake(['graph.facebook.com/*' => Http::response(['name' => 'Ana'], 200)]);

        $this->enviar($this->directoInstagram())->assertOk();
        $this->enviar($this->directoInstagram())->assertOk();

        $this->assertSame(1, BandejaMensaje::where('externo_id', 'mid.UNO')->count());
    }

    public function test_lo_que_escribio_alguien_desde_la_app_de_instagram_entra_como_saliente(): void
    {
        $this->cuenta();
        Http::fake(['graph.facebook.com/*' => Http::response(['name' => 'Ana'], 200)]);

        $this->enviar($this->directoInstagram())->assertOk();

        $cuerpo = $this->directoInstagram(
            ['mid' => 'mid.ECO', 'text' => 'Sí, claro', 'is_echo' => true],
            ['sender' => ['id' => 'ig-cuenta-1'], 'recipient' => ['id' => 'persona-77']],
        );

        $this->enviar($cuerpo)->assertOk();

        $eco = BandejaMensaje::where('externo_id', 'mid.ECO')->first();

        // Mantiene el hilo completo cuando un asesor contesta desde su celular.
        $this->assertNotNull($eco);
        $this->assertSame('saliente', $eco->direccion);
        $this->assertTrue($eco->es_echo);
        $this->assertSame(1, BandejaConversacion::count());
    }

    public function test_un_eco_no_reabre_la_ventana(): void
    {
        $this->cuenta();
        Http::fake(['graph.facebook.com/*' => Http::response(['name' => 'Ana'], 200)]);

        $this->enviar($this->directoInstagram())->assertOk();

        $conversacion = BandejaConversacion::first();
        $conversacion->update(['ultimo_entrante_at' => now()->subHours(30)]);
        $entranteOriginal = $conversacion->fresh()->ultimo_entrante_at;

        $this->enviar($this->directoInstagram(
            ['mid' => 'mid.ECO2', 'text' => 'Ahí le respondo', 'is_echo' => true],
            ['sender' => ['id' => 'ig-cuenta-1'], 'recipient' => ['id' => 'persona-77']],
        ))->assertOk();

        // Un eco es un mensaje nuestro: escribirlo en `ultimo_entrante_at` sería regalarnos 24
        // horas que Meta no dio.
        $this->assertSame(
            $entranteOriginal->format('Y-m-d H:i'),
            $conversacion->fresh()->ultimo_entrante_at->format('Y-m-d H:i'),
        );
    }

    public function test_un_mensaje_que_es_solo_una_foto_no_entra_en_blanco(): void
    {
        Storage::fake('public');
        $this->cuenta();

        Http::fake([
            'graph.facebook.com/*'   => Http::response(['name' => 'Ana'], 200),
            'cdn.instagram.com/*'    => Http::response('bytes-de-la-foto', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->enviar($this->directoInstagram([
            'mid'  => 'mid.FOTO',
            'text' => null,
            'attachments' => [[
                'type'    => 'image',
                'payload' => ['url' => 'https://cdn.instagram.com/foto.jpg'],
            ]],
        ]))->assertOk();

        $mensaje = BandejaMensaje::where('externo_id', 'mid.FOTO')->first();

        $this->assertNotNull($mensaje);
        $this->assertSame('imagen', $mensaje->tipo);
        // En la bandeja se vería un renglón en blanco y nadie sabría si el cliente escribió.
        $this->assertSame('📷 Foto', $mensaje->contenido);
        $this->assertNotNull($mensaje->archivo_id);

        $archivo = \App\Models\Archivo::find($mensaje->archivo_id);
        $this->assertSame('bandeja', $archivo->categoria);
        $this->assertSame('jpg', $archivo->extension);
        Storage::disk('public')->assertExists($archivo->ruta);
    }

    public function test_un_mensaje_para_una_cuenta_que_no_esta_conectada_no_crea_nada(): void
    {
        Http::fake();

        $this->enviar($this->directoInstagram())->assertOk();

        $this->assertDatabaseCount('bandeja_conversaciones', 0);
    }

    // ─── Comentarios ─────────────────────────────────────────────────────────

    public function test_un_comentario_de_facebook_crea_la_conversacion_con_su_publicacion(): void
    {
        $this->cuenta('facebook');

        Http::fake(['graph.facebook.com/*' => Http::response([
            'permalink_url' => 'https://facebook.com/post-9',
            'message'       => 'Puertas en madera maciza',
        ], 200)]);

        $this->enviar($this->comentarioFacebook())->assertOk();

        $conversacion = BandejaConversacion::first();

        $this->assertSame('facebook_comentario', $conversacion->canal);
        $this->assertSame('com-1', $conversacion->externo_id);
        $this->assertSame('Luis Pérez', $conversacion->nombre_contacto);

        // «¿Cuánto vale?» no se puede responder sin saber qué publicación estaba mirando.
        $this->assertSame('post-9', $conversacion->contexto['publicacion_id']);
        $this->assertSame('https://facebook.com/post-9', $conversacion->contexto['permalink']);
        $this->assertSame('Puertas en madera maciza', $conversacion->contexto['texto_publicacion']);

        $this->assertDatabaseHas('bandeja_mensajes', [
            'externo_id' => 'com-1',
            'tipo'       => 'comentario',
            'contenido'  => '¿Cuánto vale?',
        ]);
    }

    public function test_un_comentario_nuestro_no_se_procesa(): void
    {
        $cuenta = $this->cuenta('facebook');
        Http::fake();

        // La respuesta de la propia página vuelve por el mismo webhook. Sin este corte, el
        // agente de IA se contestaba a sí mismo en bucle y en público.
        $this->enviar($this->comentarioFacebook([
            'comment_id' => 'com-nuestro',
            'from'       => ['id' => $cuenta->cuenta_id_externo, 'name' => 'Página de la empresa'],
        ]))->assertOk();

        $this->assertDatabaseCount('bandeja_conversaciones', 0);
        $this->assertDatabaseCount('bandeja_mensajes', 0);
    }

    public function test_las_respuestas_de_un_comentario_son_la_misma_conversacion(): void
    {
        $this->cuenta('facebook');
        Http::fake(['graph.facebook.com/*' => Http::response(['permalink_url' => 'https://facebook.com/p'], 200)]);

        $this->enviar($this->comentarioFacebook())->assertOk();

        // La respuesta de la persona al mismo hilo trae `parent_id` apuntando al raíz.
        $this->enviar($this->comentarioFacebook([
            'comment_id' => 'com-2',
            'parent_id'  => 'com-1',
            'message'    => '¿Y en blanco?',
        ]))->assertOk();

        // Una ida y vuelta de cinco mensajes serían cinco renglones en la bandeja si cada
        // respuesta abriera su propia conversación.
        $this->assertSame(1, BandejaConversacion::count());
        $this->assertSame(2, BandejaMensaje::count());
    }

    public function test_un_comentario_borrado_no_entra(): void
    {
        $this->cuenta('facebook');
        Http::fake();

        $this->enviar($this->comentarioFacebook(['verb' => 'remove']))->assertOk();

        $this->assertDatabaseCount('bandeja_mensajes', 0);
    }

    public function test_una_reaccion_en_el_muro_no_es_un_comentario(): void
    {
        $this->cuenta('facebook');
        Http::fake();

        // Facebook mete en `feed` las reacciones, las publicaciones y todo lo demás del muro.
        $this->enviar($this->comentarioFacebook(['item' => 'reaction']))->assertOk();

        $this->assertDatabaseCount('bandeja_mensajes', 0);
    }

    // ─── Responder desde la bandeja ──────────────────────────────────────────

    public function test_se_contesta_un_directo_dentro_de_la_ventana(): void
    {
        $cuenta = $this->cuenta();
        $this->metaResponde();

        $conversacion = BandejaConversacion::create([
            'canal' => 'instagram_dm', 'cuenta_rrss_id' => $cuenta->id,
            'externo_id' => 'persona-77', 'nombre_contacto' => 'Ana',
            'ultimo_mensaje_at' => now()->subHour(), 'ultimo_entrante_at' => now()->subHour(),
        ]);

        $usuario = $this->admin();

        $this->actingAs($usuario)
            ->postJson('/bandeja/instagram_dm:' . $conversacion->id . '/responder', ['texto' => 'Sí, con gusto'])
            ->assertOk()
            ->assertJsonPath('mensaje.texto', 'Sí, con gusto')
            ->assertJsonPath('mensaje.autor', $usuario->name);

        Http::assertSent(fn ($p) => str_contains($p->url(), '/ig-cuenta-1/messages')
            && $p['recipient']['id'] === 'persona-77'
            && $p['message']['text'] === 'Sí, con gusto'
            // Sin `messaging_type` Meta lo trata como promocional y lo rechaza.
            && $p['messaging_type'] === 'RESPONSE');
    }

    public function test_fuera_de_la_ventana_un_directo_no_se_intenta_enviar(): void
    {
        $cuenta = $this->cuenta();
        Http::fake();

        $conversacion = BandejaConversacion::create([
            'canal' => 'instagram_dm', 'cuenta_rrss_id' => $cuenta->id,
            'externo_id' => 'persona-77',
            'ultimo_mensaje_at' => now()->subHours(30), 'ultimo_entrante_at' => now()->subHours(30),
        ]);

        $respuesta = $this->actingAs($this->admin())
            ->postJson('/bandeja/instagram_dm:' . $conversacion->id . '/responder', ['texto' => 'Hola otra vez']);

        $respuesta->assertStatus(422);
        // Instagram no tiene plantillas: no hay forma de escribir primero, y la pantalla lo dice.
        $respuesta->assertJsonPath('message', fn ($m) => str_contains($m, 'no permite escribir primero'));
        Http::assertNothingSent();
    }

    public function test_un_comentario_se_puede_responder_sin_plazo(): void
    {
        $cuenta = $this->cuenta('facebook');
        $this->metaResponde();

        $conversacion = BandejaConversacion::create([
            'canal' => 'facebook_comentario', 'cuenta_rrss_id' => $cuenta->id,
            'externo_id' => 'com-1', 'nombre_contacto' => 'Luis',
            // Escribió hace una semana: en un directo el plazo estaría cerrado.
            'ultimo_mensaje_at' => now()->subWeek(), 'ultimo_entrante_at' => now()->subWeek(),
        ]);

        BandejaMensaje::create([
            'bandeja_conversacion_id' => $conversacion->id,
            'externo_id' => 'com-1', 'direccion' => 'entrante',
            'tipo' => 'comentario', 'contenido' => '¿Cuánto vale?',
        ]);

        $this->actingAs($this->admin())
            ->postJson('/bandeja/facebook_comentario:' . $conversacion->id . '/responder', ['texto' => 'Te escribo al interno'])
            ->assertOk();

        // La respuesta se cuelga del comentario, no se le manda a nadie: no hay buzón donde
        // vencerse un plazo.
        Http::assertSent(fn ($p) => str_contains($p->url(), '/com-1/comments'));
    }

    public function test_la_respuesta_a_un_comentario_cuelga_del_ultimo_del_hilo(): void
    {
        $cuenta = $this->cuenta('instagram');
        $this->metaResponde();

        $conversacion = BandejaConversacion::create([
            'canal' => 'instagram_comentario', 'cuenta_rrss_id' => $cuenta->id,
            'externo_id' => 'com-1', 'ultimo_mensaje_at' => now(),
        ]);

        foreach (['com-1', 'com-5'] as $id) {
            BandejaMensaje::create([
                'bandeja_conversacion_id' => $conversacion->id,
                'externo_id' => $id, 'direccion' => 'entrante', 'tipo' => 'comentario', 'contenido' => 'hola',
            ]);
        }

        $this->actingAs($this->admin())
            ->postJson('/bandeja/instagram_comentario:' . $conversacion->id . '/responder', ['texto' => 'Ya te cuento'])
            ->assertOk();

        // Responder siempre al raíz deja las respuestas una debajo de otra sin relación con lo
        // último que dijo la persona, y el hilo se vuelve ilegible desde afuera. En Instagram
        // las respuestas van a /replies, no a /comments.
        Http::assertSent(fn ($p) => str_contains($p->url(), '/com-5/replies'));
    }

    public function test_sin_cuenta_conectada_no_se_puede_responder_y_se_explica(): void
    {
        $cuenta = $this->cuenta();
        Http::fake();

        $conversacion = BandejaConversacion::create([
            'canal' => 'instagram_dm', 'cuenta_rrss_id' => $cuenta->id,
            'externo_id' => 'persona-77',
            'ultimo_mensaje_at' => now(), 'ultimo_entrante_at' => now(),
        ]);

        $cuenta->update(['activa' => false]);

        $this->actingAs($this->admin())
            ->postJson('/bandeja/instagram_dm:' . $conversacion->id . '/responder', ['texto' => 'Hola'])
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'desconectada'));

        Http::assertNothingSent();
    }

    // ─── Automatización ──────────────────────────────────────────────────────

    public function test_avisa_por_la_campanita_en_el_primer_contacto(): void
    {
        $this->cuenta();
        $vendedor = User::factory()->create(['rol' => 'vendedor', 'activo' => true]);
        $this->activarAutomatizacion();
        Http::fake(['graph.facebook.com/*' => Http::response(['name' => 'Ana'], 200)]);

        $this->enviar($this->directoInstagram())->assertOk();

        $aviso = Notificacion::where('user_id', $vendedor->id)->where('tipo', 'bandeja_mensaje_nuevo')->first();

        $this->assertNotNull($aviso);
        // El aviso lleva a LA conversación, no a la bandeja a secas.
        $this->assertStringContainsString('conv=instagram_dm:', $aviso->url);
    }

    public function test_el_agente_de_ia_no_contesta_comentarios_de_fabrica(): void
    {
        $this->cuenta('facebook');
        $this->activarAutomatizacion(['bandeja_auto_responder' => '1']);

        AgenteIa::create([
            'nombre' => 'Bot', 'perfil' => 'publico', 'canales' => ['facebook'], 'activo' => true,
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['permalink_url' => 'https://f/p'], 200)]);

        $this->enviar($this->comentarioFacebook())->assertOk();

        // Encender respuestas en público es una decisión de la empresa, no un valor por omisión
        // que alguien descubre cuando ya pasó.
        $this->assertSame(0, BandejaMensaje::where('direccion', 'saliente')->count());
    }

    public function test_el_agente_no_contesta_una_conversacion_que_ya_tomo_una_persona(): void
    {
        $cuenta = $this->cuenta();
        $this->activarAutomatizacion(['bandeja_auto_responder' => '1']);

        AgenteIa::create(['nombre' => 'Bot', 'perfil' => 'publico', 'canales' => ['instagram'], 'activo' => true]);

        $conversacion = BandejaConversacion::create([
            'canal' => 'instagram_dm', 'cuenta_rrss_id' => $cuenta->id,
            'externo_id' => 'persona-77', 'asignado_a' => $this->admin()->id,
            'ultimo_mensaje_at' => now(), 'ultimo_entrante_at' => now(),
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['name' => 'Ana'], 200)]);

        $this->enviar($this->directoInstagram(['mid' => 'mid.DOS', 'text' => '¿Hola?']))->assertOk();

        // Dos voces en el mismo chat son peores que ninguna.
        $this->assertSame(0, $conversacion->mensajes()->where('direccion', 'saliente')->count());
    }

    public function test_crea_el_lead_del_primer_contacto_aunque_no_haya_telefono(): void
    {
        $this->cuenta();
        $etapa = CrmEtapa::create(['nombre' => 'Nuevos', 'orden' => 1, 'activa' => true]);
        $vendedor = User::factory()->create(['rol' => 'vendedor', 'activo' => true]);

        $this->activarAutomatizacion([
            'bandeja_auto_crear_lead'    => '1',
            'bandeja_auto_lead_etapa_id' => (string) $etapa->id,
            'bandeja_auto_asignacion'    => 'fijo',
            'bandeja_auto_responsables'  => json_encode([$vendedor->id]),
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['name' => 'Ana Gómez', 'username' => 'anag'], 200)]);

        $this->enviar($this->directoInstagram())->assertOk();

        $lead = CrmLead::first();

        // Por Instagram no llega teléfono ni correo: un lead con nombre y usuario al que hay
        // que contestarle por ahí sigue siendo una oportunidad de venta.
        $this->assertNotNull($lead);
        // `fuente` guarda la etiqueta legible del canal, no su clave: es lo que ve quien abre
        // el lead en el CRM. La clave vive en el origen (`CrmLeadOrigen`).
        $this->assertSame('Instagram', $lead->fuente);
        $this->assertSame('instagram', $lead->origenes()->value('canal'));
        $this->assertSame($vendedor->id, $lead->responsable_id);

        // Y la conversación queda atada al lead, que es lo que hace de la bandeja la puerta de
        // entrada al CRM y no un chat aparte.
        $this->assertSame($lead->id, BandejaConversacion::first()->crm_lead_id);
    }

    public function test_un_segundo_mensaje_no_crea_un_lead_repetido(): void
    {
        $this->cuenta();
        CrmEtapa::create(['nombre' => 'Nuevos', 'orden' => 1, 'activa' => true]);
        $this->activarAutomatizacion(['bandeja_auto_crear_lead' => '1']);

        Http::fake(['graph.facebook.com/*' => Http::response(['name' => 'Ana'], 200)]);

        $this->enviar($this->directoInstagram())->assertOk();
        $this->enviar($this->directoInstagram(['mid' => 'mid.DOS', 'text' => '¿Hola?']))->assertOk();

        $this->assertSame(1, CrmLead::count());
    }

    public function test_apagada_la_automatizacion_no_pasa_nada_mas_que_guardar(): void
    {
        $this->cuenta();
        User::factory()->create(['rol' => 'vendedor', 'activo' => true]);
        CrmEtapa::create(['nombre' => 'Nuevos', 'orden' => 1, 'activa' => true]);

        Http::fake(['graph.facebook.com/*' => Http::response(['name' => 'Ana'], 200)]);

        $this->enviar($this->directoInstagram())->assertOk();

        $this->assertSame(1, BandejaMensaje::count());
        $this->assertSame(0, Notificacion::count());
        $this->assertSame(0, CrmLead::count());
    }

    // ─── La lista unificada ──────────────────────────────────────────────────

    public function test_la_bandeja_mezcla_whatsapp_y_redes_por_actividad(): void
    {
        $cuenta = $this->cuenta();

        $linea = WhatsappNumero::create([
            'nombre' => 'Central', 'numero_telefono' => '+573001112233',
            'phone_number_id' => '111', 'rol' => 'central', 'activo' => true,
        ]);

        $wpp = WhatsappConversacion::create([
            'whatsapp_numero_id' => $linea->id, 'numero_contacto' => '573009998877',
            'nombre_contacto' => 'Marta', 'ultimo_mensaje_at' => now()->subHours(3),
            'ultimo_entrante_at' => now()->subHours(3),
        ]);
        WhatsappMensaje::create([
            'whatsapp_conversacion_id' => $wpp->id,
            'direccion' => 'entrante', 'tipo' => 'texto', 'contenido' => 'Por WhatsApp',
        ]);

        $red = BandejaConversacion::create([
            'canal' => 'instagram_dm', 'cuenta_rrss_id' => $cuenta->id,
            'externo_id' => 'persona-77', 'nombre_contacto' => 'Ana',
            'ultimo_mensaje_at' => now()->subMinutes(5), 'ultimo_entrante_at' => now()->subMinutes(5),
        ]);
        BandejaMensaje::create([
            'bandeja_conversacion_id' => $red->id,
            'direccion' => 'entrante', 'tipo' => 'texto', 'contenido' => 'Por Instagram',
        ]);

        $this->actingAs($this->admin())->get('/bandeja')
            ->assertInertia(fn ($pagina) => $pagina
                ->count('conversaciones.data', 2)
                // Lo último que se movió, arriba: es lo único que hace que una bandeja sea una
                // bandeja.
                ->where('conversaciones.data.0.canal', 'instagram_dm')
                ->where('conversaciones.data.0.contacto', 'Ana')
                ->where('conversaciones.data.1.canal', 'whatsapp')
                ->where('conversaciones.data.1.contacto', 'Marta')
            );
    }

    public function test_el_filtro_por_canal_deja_solo_ese(): void
    {
        $cuenta = $this->cuenta();

        BandejaConversacion::create([
            'canal' => 'instagram_dm', 'cuenta_rrss_id' => $cuenta->id,
            'externo_id' => 'p-1', 'nombre_contacto' => 'Ana', 'ultimo_mensaje_at' => now(),
        ]);
        BandejaConversacion::create([
            'canal' => 'instagram_comentario', 'cuenta_rrss_id' => $cuenta->id,
            'externo_id' => 'com-1', 'nombre_contacto' => 'Luis', 'ultimo_mensaje_at' => now(),
        ]);

        $this->actingAs($this->admin())->get('/bandeja?canal=instagram_comentario')
            ->assertInertia(fn ($pagina) => $pagina
                ->count('conversaciones.data', 1)
                ->where('conversaciones.data.0.contacto', 'Luis')
            );
    }

    // ─── Módulos ─────────────────────────────────────────────────────────────

    public function test_sin_el_modulo_de_redes_los_canales_sociales_desaparecen(): void
    {
        Modulos::guardar(['rrss'], 'instalacion');

        // WhatsApp sigue: la bandeja no depende de las redes.
        $this->assertTrue(Canales::activo('whatsapp'));
        $this->assertFalse(Canales::activo('instagram_dm'));
        $this->assertFalse(Canales::activo('facebook_comentario'));
    }

    public function test_con_el_modulo_de_redes_apagado_el_webhook_no_guarda_nada(): void
    {
        $this->cuenta();
        Modulos::guardar(['rrss'], 'instalacion');
        Http::fake();

        // Seguir recibiendo mensajes que nadie puede ver es peor que no recibirlos.
        $this->enviar($this->directoInstagram())->assertOk();

        $this->assertDatabaseCount('bandeja_conversaciones', 0);
    }

    public function test_una_conversacion_de_un_canal_apagado_no_se_puede_abrir(): void
    {
        $cuenta = $this->cuenta();

        $conversacion = BandejaConversacion::create([
            'canal' => 'instagram_dm', 'cuenta_rrss_id' => $cuenta->id,
            'externo_id' => 'persona-77', 'ultimo_mensaje_at' => now(),
        ]);

        Modulos::guardar(['rrss'], 'instalacion');

        $this->actingAs($this->admin())
            ->getJson('/bandeja/instagram_dm:' . $conversacion->id . '/hilo')
            ->assertNotFound();
    }
}
