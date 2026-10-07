<?php

namespace Tests\Feature;

use App\Models\Archivo;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappNumero;
use App\Services\WhatsappDiagnosticoService;
use App\Support\CredencialesRrss;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El webhook de WhatsApp: lo que entra, y quién tiene permitido meterlo.
 *
 * **La firma es lo más importante de este archivo.** El webhook no tiene login: lo llama Meta.
 * Hasta oct 2026, si no había App Secret guardado, se aceptaba cualquier cosa y solo quedaba
 * una línea en el log. Eso significa que cualquiera que supiera la URL podía inventar mensajes,
 * meter leads falsos al CRM y además hacer que se repartieran solos entre los vendedores. Y no
 * era un caso raro: el App Secret se carga en OTRA pantalla, así que una instalación sana podía
 * pasar meses sin él.
 *
 * Lo demás que se fija: que un webhook repetido no duplique nada —Meta reintenta—, y que un
 * mensaje que no es texto no entre vacío.
 */
class WhatsappWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRETO = 'secreto-de-la-app';

    protected function setUp(): void
    {
        parent::setUp();

        CredencialesRrss::guardar('whatsapp', 'secret', 'token-de-prueba');
        CredencialesRrss::guardar('whatsapp', 'redirect', 'token-del-webhook');
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

    /** El cuerpo que manda Meta con un mensaje entrante. */
    private function cuerpoDeMensaje(array $mensaje = []): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => '111222333'],
                        'contacts' => [['wa_id' => '573009998877', 'profile' => ['name' => 'Marta Ruiz']]],
                        'messages' => [array_merge([
                            'id'   => 'wamid.UNO',
                            'from' => '573009998877',
                            'type' => 'text',
                            'text' => ['body' => 'Buenas, ¿hacen puertas a la medida?'],
                        ], $mensaje)],
                    ],
                ]],
            ]],
        ];
    }

    /** Manda el webhook firmado como lo firma Meta. */
    private function enviarFirmado(array $cuerpo)
    {
        $json = json_encode($cuerpo);

        return $this->call(
            'POST',
            '/webhook/whatsapp',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $json, self::SECRETO),
            ],
            $json,
        );
    }

    // ─── La verificación inicial ─────────────────────────────────────────────

    public function test_meta_verifica_el_webhook_con_el_token_correcto(): void
    {
        $this->get('/webhook/whatsapp?hub_mode=subscribe&hub_verify_token=token-del-webhook&hub_challenge=12345')
            ->assertOk()
            ->assertSee('12345');
    }

    public function test_un_token_de_verificacion_equivocado_no_pasa(): void
    {
        $this->get('/webhook/whatsapp?hub_mode=subscribe&hub_verify_token=otro&hub_challenge=12345')
            ->assertForbidden();
    }

    // ─── La firma ────────────────────────────────────────────────────────────

    public function test_sin_app_secret_no_entra_nada(): void
    {
        $this->linea();

        // Sin App Secret no hay forma de saber si el mensaje viene de Meta, y lo que no se
        // puede verificar no entra. Antes se aceptaba y solo quedaba un aviso en el log.
        $this->postJson('/webhook/whatsapp', $this->cuerpoDeMensaje())->assertForbidden();

        $this->assertDatabaseCount('whatsapp_conversaciones', 0);
        $this->assertDatabaseCount('whatsapp_mensajes', 0);
    }

    public function test_con_firma_valida_el_mensaje_entra(): void
    {
        $linea = $this->linea();
        CredencialesRrss::guardar('meta', 'secret', self::SECRETO);

        $this->enviarFirmado($this->cuerpoDeMensaje())->assertOk();

        $conversacion = WhatsappConversacion::first();

        $this->assertNotNull($conversacion);
        $this->assertSame('Marta Ruiz', $conversacion->nombre_contacto);
        $this->assertSame($linea->id, $conversacion->whatsapp_numero_id);
        // Un mensaje entrante deja la conversación sin leer y abre la ventana de 24 horas.
        $this->assertFalse($conversacion->leido);
        $this->assertNotNull($conversacion->ultimo_entrante_at);
        $this->assertTrue($conversacion->ventanaAbierta());

        $this->assertDatabaseHas('whatsapp_mensajes', [
            'wa_message_id' => 'wamid.UNO',
            'direccion'     => 'entrante',
            'contenido'     => 'Buenas, ¿hacen puertas a la medida?',
        ]);
    }

    public function test_una_firma_que_no_cuadra_se_rechaza(): void
    {
        $this->linea();
        CredencialesRrss::guardar('meta', 'secret', self::SECRETO);

        $json = json_encode($this->cuerpoDeMensaje());

        $this->call('POST', '/webhook/whatsapp', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $json, 'un-secreto-inventado'),
        ], $json)->assertForbidden();

        $this->assertDatabaseCount('whatsapp_mensajes', 0);
    }

    public function test_sin_cabecera_de_firma_tampoco_pasa(): void
    {
        $this->linea();
        CredencialesRrss::guardar('meta', 'secret', self::SECRETO);

        $this->postJson('/webhook/whatsapp', $this->cuerpoDeMensaje())->assertForbidden();

        $this->assertDatabaseCount('whatsapp_mensajes', 0);
    }

    // ─── Repeticiones ────────────────────────────────────────────────────────

    public function test_el_mismo_mensaje_dos_veces_entra_una_sola(): void
    {
        $this->linea();
        CredencialesRrss::guardar('meta', 'secret', self::SECRETO);

        // Meta reintenta el webhook cuando no recibe el 200 a tiempo, y eso pasa seguido. Sin
        // la guarda, el mensaje entraba dos veces al hilo y la automatización contestaba dos
        // veces al mismo cliente.
        $this->enviarFirmado($this->cuerpoDeMensaje())->assertOk();
        $this->enviarFirmado($this->cuerpoDeMensaje())->assertOk();

        $this->assertSame(1, WhatsappMensaje::where('wa_message_id', 'wamid.UNO')->count());
    }

    // ─── Lo que no es texto ──────────────────────────────────────────────────

    public function test_una_foto_se_baja_al_servidor_y_queda_pegada_a_la_conversacion(): void
    {
        Storage::fake('public');
        $this->linea();
        CredencialesRrss::guardar('meta', 'secret', self::SECRETO);

        Http::fake([
            // 1) El identificador se cambia por una URL temporal.
            'graph.facebook.com/*/media-99' => Http::response([
                'url'       => 'https://lookaside.fbsbx.com/archivo-temporal',
                'mime_type' => 'image/jpeg',
                'file_size' => 2048,
            ], 200),
            // 2) La descarga, que también exige el token.
            'lookaside.fbsbx.com/*' => Http::response('bytes-de-la-foto', 200),
        ]);

        $this->enviarFirmado($this->cuerpoDeMensaje([
            'id'    => 'wamid.FOTO',
            'type'  => 'image',
            'image' => ['id' => 'media-99', 'caption' => 'Así la necesito'],
        ]))->assertOk();

        $mensaje = WhatsappMensaje::where('wa_message_id', 'wamid.FOTO')->first();

        $this->assertNotNull($mensaje);
        $this->assertSame('imagen', $mensaje->tipo);
        // El pie de foto es lo que la persona escribió: vale más que cualquier descripción.
        $this->assertSame('Así la necesito', $mensaje->contenido);
        $this->assertNotNull($mensaje->archivo_id);

        $archivo = Archivo::find($mensaje->archivo_id);

        $this->assertSame('bandeja', $archivo->categoria);
        $this->assertSame('jpg', $archivo->extension);
        // Pegado a su conversación: es lo que permite ver después todo lo que mandó un cliente.
        $this->assertSame(WhatsappConversacion::class, $archivo->archivable_type);
        Storage::disk('public')->assertExists($archivo->ruta);
    }

    public function test_si_la_foto_no_se_puede_bajar_el_mensaje_entra_igual(): void
    {
        Storage::fake('public');
        $this->linea();
        CredencialesRrss::guardar('meta', 'secret', self::SECRETO);

        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'no existe']], 400)]);

        $this->enviarFirmado($this->cuerpoDeMensaje([
            'id'    => 'wamid.FOTO2',
            'type'  => 'image',
            'image' => ['id' => 'media-roto'],
        ]))->assertOk();

        $mensaje = WhatsappMensaje::where('wa_message_id', 'wamid.FOTO2')->first();

        // Perder la foto es malo; perder el mensaje y no saber que alguien escribió es peor.
        $this->assertNotNull($mensaje);
        $this->assertNull($mensaje->archivo_id);
        $this->assertSame('📷 Foto', $mensaje->contenido);
    }

    public function test_una_ubicacion_entra_con_su_enlace_al_mapa(): void
    {
        $this->linea();
        CredencialesRrss::guardar('meta', 'secret', self::SECRETO);

        $this->enviarFirmado($this->cuerpoDeMensaje([
            'id'       => 'wamid.MAPA',
            'type'     => 'location',
            'location' => ['latitude' => 6.25, 'longitude' => -75.56, 'name' => 'Taller'],
        ]))->assertOk();

        $contenido = WhatsappMensaje::where('wa_message_id', 'wamid.MAPA')->value('contenido');

        // Un par de coordenadas sueltas no le sirve a nadie en la bandeja; el enlace sí.
        $this->assertStringContainsString('Taller', $contenido);
        $this->assertStringContainsString('maps.google.com/?q=6.25,-75.56', $contenido);
    }

    public function test_el_toque_a_un_boton_entra_como_el_texto_que_eligio(): void
    {
        $this->linea();
        CredencialesRrss::guardar('meta', 'secret', self::SECRETO);

        $this->enviarFirmado($this->cuerpoDeMensaje([
            'id'     => 'wamid.BOTON',
            'type'   => 'button',
            'button' => ['text' => 'Quiero cotizar', 'payload' => 'cotizar'],
        ]))->assertOk();

        // Tiene que quedar como si lo hubiera escrito: la automatización compara palabras
        // clave, y con el contenido vacío un botón no disparaba ninguna respuesta.
        $this->assertDatabaseHas('whatsapp_mensajes', [
            'wa_message_id' => 'wamid.BOTON',
            'contenido'     => 'Quiero cotizar',
        ]);
    }

    public function test_un_tipo_que_no_sabemos_leer_no_entra_en_blanco(): void
    {
        $this->linea();
        CredencialesRrss::guardar('meta', 'secret', self::SECRETO);

        $this->enviarFirmado($this->cuerpoDeMensaje([
            'id'   => 'wamid.RARO',
            'type' => 'order',
        ]))->assertOk();

        $this->assertDatabaseHas('whatsapp_mensajes', [
            'wa_message_id' => 'wamid.RARO',
            'contenido'     => '[order]',
        ]);
    }

    public function test_un_mensaje_para_una_linea_desconocida_no_crea_nada(): void
    {
        CredencialesRrss::guardar('meta', 'secret', self::SECRETO);

        // Sin número registrado con ese phone_number_id: se anota y no se inventa una línea.
        $this->enviarFirmado($this->cuerpoDeMensaje())->assertOk();

        $this->assertDatabaseCount('whatsapp_conversaciones', 0);
    }

    // ─── El semáforo de la pantalla ──────────────────────────────────────────

    public function test_sin_app_secret_la_conexion_no_cuenta_como_lista(): void
    {
        $this->linea();

        $estado = app(WhatsappDiagnosticoService::class)->estado();

        // Estaba como un aviso ámbar que no impedía nada. Ahora, sin App Secret no entra
        // ningún mensaje, así que decir «Conectado» era prometer algo que no entrega.
        $this->assertFalse($estado['lista']);
        $this->assertFalse($estado['tiene_app_secret']);

        CredencialesRrss::guardar('meta', 'secret', self::SECRETO);

        $this->assertTrue(app(WhatsappDiagnosticoService::class)->estado()['lista']);
    }
}
