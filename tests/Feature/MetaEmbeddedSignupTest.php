<?php

namespace Tests\Feature;

use App\Models\CuentaRrss;
use App\Models\User;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappNumero;
use App\Services\Meta\SuscripcionesMeta;
use App\Support\ConexionMeta;
use App\Support\CredencialesRrss;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * La vinculación de WhatsApp por Embedded Signup (con coexistencia), las suscripciones a los
 * webhooks de Meta y el webhook único que recibe todo.
 *
 * Meta no se llama de verdad: cada prueba declara qué responde la Graph API. Lo que se fija es lo
 * que sí depende de nosotros: qué se guarda, qué se suscribe, qué NO se hace con un número que
 * sigue vivo en el celular, y que lo que dice el navegador no se cree.
 */
class MetaEmbeddedSignupTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'secreto-de-la-app';

    protected function setUp(): void
    {
        parent::setUp();

        CredencialesRrss::guardar('meta', 'id', 'app-123');
        CredencialesRrss::guardar('meta', 'secret', self::SECRETO);
        CredencialesRrss::guardar('whatsapp', 'redirect', 'token-del-webhook');
        ConexionMeta::guardarConfigId('cfg-777');
    }

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'administrador', 'activo' => true]);
    }

    /** Lo que responde la Graph API en una vinculación que sale bien. */
    private function metaResponde(array $extra = []): void
    {
        Http::fake(array_merge([
            '*/oauth/access_token*'   => Http::response(['access_token' => 'token-del-negocio']),
            '*/debug_token*'          => Http::response(['data' => ['granular_scopes' => [
                ['scope' => 'whatsapp_business_management', 'target_ids' => ['900100']],
            ]]]),
            '*/900100/subscribed_apps' => Http::response(['success' => true]),
            '*/900100/phone_numbers*' => Http::response(['data' => [
                ['id' => '555001', 'display_phone_number' => '+57 300 111 2233', 'verified_name' => 'Puertas del Valle'],
            ]]),
            '*/555001/register'       => Http::response(['success' => true]),
            '*/app-123/subscriptions' => Http::response(['success' => true]),
        ], $extra));
    }

    private function vincular(array $cuerpo = [])
    {
        return $this->actingAs($this->admin())->postJson(
            '/configuracion/whatsapp-numeros/embedded-signup',
            array_merge(['code' => 'codigo-de-meta', 'waba_id' => '900100', 'phone_number_id' => '555001'], $cuerpo),
        );
    }

    private function enviaA(string $fragmento): bool
    {
        return Http::recorded(fn (PeticionHttp $p) => str_contains($p->url(), $fragmento))->isNotEmpty();
    }

    // ─── Embedded Signup ─────────────────────────────────────────────────────

    public function test_con_coexistencia_el_numero_queda_listo_y_no_se_registra_otra_vez(): void
    {
        $this->metaResponde();
        URL::forceRootUrl('https://briela.example.com');
        URL::forceScheme('https');

        $this->vincular(['coexistencia' => true])->assertOk()->assertJson(['ok' => true]);

        $numero = WhatsappNumero::where('phone_number_id', '555001')->firstOrFail();

        $this->assertSame('900100', $numero->waba_id);
        $this->assertTrue($numero->coexistencia);
        $this->assertSame('+573001112233', $numero->numero_telefono);
        $this->assertSame('central', $numero->rol);
        $this->assertTrue($numero->activo);

        // El token canjeado es el que se usa para enviar, y la cuenta quedó guardada.
        $this->assertSame('token-del-negocio', CredencialesRrss::valor('whatsapp', 'secret'));
        $this->assertSame('900100', CredencialesRrss::valor('whatsapp', 'waba'));

        // Se suscribió la aplicación a la cuenta, y SE REGISTRÓ NADA: el número sigue en el celular.
        $this->assertTrue($this->enviaA('/900100/subscribed_apps'));
        $this->assertFalse($this->enviaA('/register'), 'Registrar un número en coexistencia lo saca de la app del celular.');
    }

    public function test_un_numero_nuevo_si_se_registra_en_la_api_y_guarda_su_pin(): void
    {
        $this->metaResponde();

        $this->vincular(['coexistencia' => false])->assertOk();

        $numero = WhatsappNumero::where('phone_number_id', '555001')->firstOrFail();

        $this->assertFalse($numero->coexistencia);
        $this->assertTrue($this->enviaA('/555001/register'));
        $this->assertMatchesRegularExpression('/^\d{6}$/', $numero->pin_registro);
        // El PIN no viaja a la pantalla.
        $this->assertArrayNotHasKey('pin_registro', $numero->toArray());
    }

    public function test_el_waba_que_manda_el_navegador_se_contrasta_con_lo_que_dice_meta(): void
    {
        $this->metaResponde();

        // Cualquier script de la página puede fabricar el evento: este waba no es del token.
        $this->vincular(['waba_id' => '111111', 'coexistencia' => true])->assertOk();

        $this->assertSame('900100', CredencialesRrss::valor('whatsapp', 'waba'));
        $this->assertSame('900100', WhatsappNumero::firstOrFail()->waba_id);
    }

    public function test_si_el_navegador_no_manda_los_identificadores_se_averiguan_con_el_token(): void
    {
        $this->metaResponde();

        $this->vincular(['waba_id' => null, 'phone_number_id' => null, 'coexistencia' => true])->assertOk();

        $this->assertSame('555001', WhatsappNumero::firstOrFail()->phone_number_id);
    }

    public function test_un_numero_ya_cargado_a_mano_conserva_su_nombre_y_su_rol(): void
    {
        $this->metaResponde();

        WhatsappNumero::create([
            'nombre' => 'Ventas Cali', 'numero_telefono' => '+573001112233',
            'phone_number_id' => '555001', 'rol' => 'asesor', 'activo' => false,
        ]);

        $this->vincular(['coexistencia' => true])->assertOk();

        $this->assertSame(1, WhatsappNumero::count());
        $numero = WhatsappNumero::first();
        $this->assertSame('Ventas Cali', $numero->nombre);
        $this->assertSame('asesor', $numero->rol);
        $this->assertTrue($numero->activo, 'Vincularlo lo deja activo.');
        $this->assertTrue($numero->coexistencia);
    }

    public function test_un_codigo_que_meta_rechaza_devuelve_el_motivo_y_no_guarda_nada(): void
    {
        Http::fake(['*/oauth/access_token*' => Http::response(['error' => ['message' => 'This authorization code has expired.']], 400)]);

        $this->vincular()->assertStatus(422)->assertJsonPath('ok', false)
            ->assertJsonFragment(['mensaje' => 'Meta no aceptó el código de autorización (vence a los pocos minutos): This authorization code has expired.']);

        $this->assertDatabaseCount('whatsapp_numeros', 0);
        $this->assertSame('', CredencialesRrss::valor('whatsapp', 'secret'));
    }

    public function test_sin_la_configuracion_de_embedded_signup_no_se_intenta(): void
    {
        ConexionMeta::guardarConfigId('');
        Http::fake();

        $this->vincular()->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_si_la_suscripcion_falla_el_numero_igual_queda_conectado_y_se_avisa(): void
    {
        $this->metaResponde(['*/900100/subscribed_apps' => Http::response(['error' => ['message' => 'Permiso insuficiente']], 400)]);

        $respuesta = $this->vincular(['coexistencia' => true])->assertOk();

        $this->assertDatabaseCount('whatsapp_numeros', 1);
        $this->assertStringContainsString('Permiso insuficiente', implode(' ', $respuesta->json('avisos')));
    }

    public function test_quien_no_puede_editar_la_configuracion_no_vincula(): void
    {
        $this->metaResponde();
        $sinPermiso = User::factory()->create(['rol' => 'operario', 'activo' => true]);

        $this->actingAs($sinPermiso)
            ->postJson('/configuracion/whatsapp-numeros/embedded-signup', ['code' => 'x'])
            ->assertForbidden();
    }

    public function test_los_identificadores_tienen_que_ser_numeros(): void
    {
        $this->metaResponde();

        $this->vincular(['waba_id' => '../etc/passwd'])->assertStatus(422);
    }

    // ─── Suscripciones ───────────────────────────────────────────────────────

    public function test_una_pagina_se_suscribe_a_mensajes_y_muro(): void
    {
        Http::fake(['*/pag-1/subscribed_apps' => Http::response(['success' => true])]);

        $pagina = CuentaRrss::create([
            'red' => 'facebook', 'nombre_cuenta' => 'Mi página', 'cuenta_id_externo' => 'pag-1',
            'access_token' => 'tok', 'activa' => true,
        ]);

        $this->assertTrue(app(SuscripcionesMeta::class)->suscribirPagina($pagina));

        Http::assertSent(fn (PeticionHttp $p) => str_contains($p->url(), '/pag-1/subscribed_apps')
            && str_contains($p['subscribed_fields'], 'messages')
            && str_contains($p['subscribed_fields'], 'feed'));
    }

    public function test_si_la_pagina_no_se_puede_suscribir_el_motivo_queda_en_la_cuenta(): void
    {
        Http::fake(['*/pag-1/subscribed_apps' => Http::response(['error' => ['message' => '(#200) Requires pages_manage_metadata']], 400)]);

        $pagina = CuentaRrss::create([
            'red' => 'facebook', 'nombre_cuenta' => 'Mi página', 'cuenta_id_externo' => 'pag-1',
            'access_token' => 'tok', 'activa' => true,
        ]);

        $this->assertFalse(app(SuscripcionesMeta::class)->suscribirPagina($pagina));

        $pagina->refresh();
        $this->assertTrue($pagina->activa, 'Seguir publicando no depende de recibir.');
        $this->assertStringContainsString('pages_manage_metadata', $pagina->ultimo_error);
    }

    public function test_el_webhook_de_la_app_se_registra_para_los_tres_objetos(): void
    {
        URL::forceRootUrl('https://briela.example.com');
        URL::forceScheme('https');
        Http::fake(['*/app-123/subscriptions' => Http::response(['success' => true])]);

        $resultado = app(SuscripcionesMeta::class)->registrarWebhookDeLaApp();

        $this->assertSame(['whatsapp_business_account', 'page', 'instagram'], array_keys($resultado));
        $this->assertTrue(collect($resultado)->every(fn ($r) => $r['ok']));

        Http::assertSent(fn (PeticionHttp $p) => str_contains($p->url(), '/app-123/subscriptions')
            && $p['object'] === 'whatsapp_business_account'
            && $p['callback_url'] === 'https://briela.example.com/webhook/meta'
            && $p['verify_token'] === 'token-del-webhook'
            && str_contains($p['fields'], 'smb_message_echoes')
            && $p['access_token'] === 'app-123|' . self::SECRETO);
    }

    public function test_si_meta_rechaza_los_campos_de_coexistencia_se_reintenta_con_messages(): void
    {
        URL::forceRootUrl('https://briela.example.com');
        URL::forceScheme('https');

        Http::fake(function (PeticionHttp $p) {
            return str_contains($p['fields'] ?? '', ',')
                ? Http::response(['error' => ['message' => 'Invalid field']], 400)
                : Http::response(['success' => true]);
        });

        $resultado = app(SuscripcionesMeta::class)->registrarWebhookDeLaApp();

        $this->assertTrue($resultado['whatsapp_business_account']['ok']);
        $this->assertStringContainsString('solo con «messages»', $resultado['whatsapp_business_account']['mensaje']);
    }

    public function test_una_instalacion_que_no_se_alcanza_desde_internet_lo_dice_en_vez_de_llamar_a_meta(): void
    {
        URL::forceRootUrl('http://localhost');
        Http::fake();

        $resultado = app(SuscripcionesMeta::class)->registrarWebhookDeLaApp();

        $this->assertFalse($resultado['app']['ok']);
        $this->assertStringContainsString('internet', $resultado['app']['mensaje']);
        Http::assertNothingSent();
    }

    // ─── El webhook único ────────────────────────────────────────────────────

    private function enviarAMeta(array $cuerpo)
    {
        $json = json_encode($cuerpo);

        return $this->call('POST', '/webhook/meta', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $json, self::SECRETO),
        ], $json);
    }

    private function linea(): WhatsappNumero
    {
        return WhatsappNumero::create([
            'nombre' => 'Central', 'numero_telefono' => '+573001112233',
            'phone_number_id' => '111222333', 'rol' => 'central', 'activo' => true,
        ]);
    }

    private function cambio(string $campo, array $valor): array
    {
        return [
            'object' => 'whatsapp_business_account',
            'entry'  => [['id' => '900100', 'changes' => [[
                'field' => $campo,
                'value' => array_merge(['metadata' => ['phone_number_id' => '111222333']], $valor),
            ]]]],
        ];
    }

    public function test_un_mensaje_de_whatsapp_tambien_entra_por_la_direccion_unica(): void
    {
        $this->linea();

        $this->enviarAMeta($this->cambio('messages', [
            'contacts' => [['wa_id' => '573009998877', 'profile' => ['name' => 'Marta Ruiz']]],
            'messages' => [['id' => 'wamid.A', 'from' => '573009998877', 'type' => 'text', 'text' => ['body' => 'Hola']]],
        ]))->assertOk();

        $this->assertSame('Hola', WhatsappMensaje::firstOrFail()->contenido);
        $this->assertSame('Marta Ruiz', WhatsappConversacion::firstOrFail()->nombre_contacto);
    }

    public function test_lo_que_el_negocio_escribe_desde_el_celular_llega_como_eco_y_no_abre_la_ventana(): void
    {
        $this->linea();

        $this->enviarAMeta($this->cambio('smb_message_echoes', [
            'message_echoes' => [[
                'id' => 'wamid.ECO', 'from' => '573001112233', 'to' => '573009998877',
                'type' => 'text', 'text' => ['body' => 'Con gusto, mañana le cotizo.'],
            ]],
        ]))->assertOk();

        $mensaje = WhatsappMensaje::firstOrFail();
        $this->assertSame('saliente', $mensaje->direccion);
        $this->assertTrue($mensaje->es_echo);
        $this->assertSame('Con gusto, mañana le cotizo.', $mensaje->contenido);

        // Escribir desde el celular no regala 24 horas: la ventana la abre el cliente.
        $this->assertNull(WhatsappConversacion::firstOrFail()->ultimo_entrante_at);
    }

    public function test_el_mismo_eco_dos_veces_entra_una_sola(): void
    {
        $this->linea();
        $cuerpo = $this->cambio('smb_message_echoes', [
            'message_echoes' => [['id' => 'wamid.ECO', 'to' => '573009998877', 'type' => 'text', 'text' => ['body' => 'Hola']]],
        ]);

        $this->enviarAMeta($cuerpo)->assertOk();
        $this->enviarAMeta($cuerpo)->assertOk();

        $this->assertDatabaseCount('whatsapp_mensajes', 1);
    }

    public function test_un_estado_atrasado_no_deshace_el_leido(): void
    {
        $this->linea();
        $conversacion = WhatsappConversacion::create(['whatsapp_numero_id' => WhatsappNumero::first()->id, 'numero_contacto' => '573009998877']);
        $mensaje = WhatsappMensaje::create([
            'whatsapp_conversacion_id' => $conversacion->id, 'wa_message_id' => 'wamid.S',
            'direccion' => 'saliente', 'tipo' => 'texto', 'contenido' => 'x', 'estado' => 'read',
        ]);

        $this->enviarAMeta($this->cambio('messages', ['statuses' => [['id' => 'wamid.S', 'status' => 'delivered']]]))->assertOk();

        $this->assertSame('read', $mensaje->fresh()->estado);
    }

    public function test_un_mensaje_que_no_se_pudo_entregar_guarda_el_motivo(): void
    {
        $this->linea();
        $conversacion = WhatsappConversacion::create(['whatsapp_numero_id' => WhatsappNumero::first()->id, 'numero_contacto' => '573009998877']);
        $mensaje = WhatsappMensaje::create([
            'whatsapp_conversacion_id' => $conversacion->id, 'wa_message_id' => 'wamid.F',
            'direccion' => 'saliente', 'tipo' => 'texto', 'contenido' => 'x', 'estado' => 'sent',
        ]);

        $this->enviarAMeta($this->cambio('messages', ['statuses' => [[
            'id' => 'wamid.F', 'status' => 'failed',
            'errors' => [['code' => 131047, 'title' => 'Re-engagement message', 'message' => 'Pasaron más de 24 horas']],
        ]]]))->assertOk();

        $mensaje->refresh();
        $this->assertSame('failed', $mensaje->estado);
        $this->assertStringContainsString('Pasaron más de 24 horas', $mensaje->error);
    }

    public function test_el_historial_y_los_contactos_se_reconocen_sin_romper_ni_crear_nada(): void
    {
        $this->linea();

        $this->enviarAMeta($this->cambio('history', ['history' => [['metadata' => ['phase' => 0], 'threads' => []]]]))->assertOk();
        $this->enviarAMeta($this->cambio('smb_app_state_sync', ['state_sync' => [['type' => 'contact']]]))->assertOk();

        $this->assertDatabaseCount('whatsapp_mensajes', 0);
        $this->assertDatabaseCount('whatsapp_conversaciones', 0);
    }

    public function test_la_direccion_unica_rechaza_lo_que_no_trae_firma_valida(): void
    {
        $this->linea();

        $this->postJson('/webhook/meta', $this->cambio('messages', [
            'messages' => [['id' => 'wamid.X', 'from' => '573009998877', 'type' => 'text', 'text' => ['body' => 'falso']]],
        ]))->assertForbidden();

        $this->assertDatabaseCount('whatsapp_mensajes', 0);
    }

    public function test_los_dos_webhooks_estan_fuera_del_csrf(): void
    {
        // PHPUnit apaga el CSRF, así que ninguna prueba de POST lo ve. En producción, sin esta
        // exclusión, Meta recibía un 419 y nunca entró un mensaje de Instagram ni de Facebook.
        $excluidas = app(PreventRequestForgery::class)->getExcludedPaths();

        $this->assertContains('webhook/whatsapp', $excluidas);
        $this->assertContains('webhook/meta', $excluidas);
    }
}
