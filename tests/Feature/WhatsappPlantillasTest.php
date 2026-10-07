<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WhatsappPlantilla;
use App\Services\WhatsappPlantillaService;
use App\Support\CredencialesRrss;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * El espejo local de las plantillas aprobadas en Meta.
 *
 * Importan porque son **lo único que se puede enviar cuando pasaron más de 24 horas** desde el
 * último mensaje del cliente: sin ellas, el sistema no puede avisar que una cotización está
 * lista ni que una orden salió a despacho, que son justo los avisos por los que una empresa
 * paga un ERP con WhatsApp.
 */
class WhatsappPlantillasTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CredencialesRrss::guardar('whatsapp', 'secret', 'token-de-prueba');
        CredencialesRrss::guardar('whatsapp', 'waba', '555666777');
    }

    private function servicio(): WhatsappPlantillaService
    {
        return app(WhatsappPlantillaService::class);
    }

    private function plantillaDeMeta(array $extra = []): array
    {
        return array_merge([
            'id'       => 'tpl-1',
            'name'     => 'aviso_despacho',
            'language' => 'es',
            'category' => 'UTILITY',
            'status'   => 'APPROVED',
            'components' => [
                ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Su pedido va en camino'],
                ['type' => 'BODY',   'text' => 'Hola {{1}}, la orden {{2}} salió hoy. Gracias, {{1}}.'],
                ['type' => 'FOOTER', 'text' => 'Responda este mensaje si necesita algo.'],
            ],
        ], $extra);
    }

    // ─── Sincronizar ─────────────────────────────────────────────────────────

    public function test_trae_las_plantillas_y_separa_encabezado_cuerpo_y_pie(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [$this->plantillaDeMeta()]], 200)]);

        $resultado = $this->servicio()->sincronizar();

        $this->assertTrue($resultado['ok']);
        $this->assertSame(1, $resultado['creadas']);

        $plantilla = WhatsappPlantilla::first();

        $this->assertSame('aviso_despacho', $plantilla->nombre);
        $this->assertSame('APPROVED', $plantilla->estado);
        $this->assertSame('Su pedido va en camino', $plantilla->encabezado);
        $this->assertSame('Responda este mensaje si necesita algo.', $plantilla->pie);
        $this->assertNotNull($plantilla->sincronizada_at);
    }

    public function test_cuenta_las_variables_distintas_y_no_las_repeticiones(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [$this->plantillaDeMeta()]], 200)]);

        $this->servicio()->sincronizar();

        // El cuerpo usa {{1}} dos veces y {{2}} una. Son DOS datos que pedirle a quien la
        // manda, no tres: contar apariciones haría que el formulario pidiera un dato de más
        // y que el envío a Meta fuera inválido.
        $this->assertSame(2, WhatsappPlantilla::first()->variables);
    }

    public function test_la_vista_previa_reemplaza_los_datos_y_deja_ver_lo_que_falta(): void
    {
        $plantilla = WhatsappPlantilla::create([
            'nombre' => 'aviso', 'idioma' => 'es', 'estado' => 'APPROVED',
            'cuerpo' => 'Hola {{1}}, su orden {{2}} está lista.', 'variables' => 2,
        ]);

        $this->assertSame(
            'Hola Marta, su orden OP-7 está lista.',
            $plantilla->previsualizar(['Marta', 'OP-7']),
        );

        // Lo que falta se queda como {{2}}: ver el hueco es más útil que ver el mensaje a
        // medias y creer que así va a salir.
        $this->assertSame(
            'Hola Marta, su orden {{2}} está lista.',
            $plantilla->previsualizar(['Marta']),
        );
    }

    public function test_sigue_el_cursor_de_paginas_de_meta(): void
    {
        // Sin seguir `paging.next`, una cuenta con más de 100 plantillas sincronizaba solo las
        // primeras y las demás no aparecían nunca en el selector.
        Http::fakeSequence()
            ->push([
                'data'   => [$this->plantillaDeMeta(['id' => 'tpl-1', 'name' => 'primera'])],
                'paging' => ['next' => 'https://graph.facebook.com/v21.0/555666777/message_templates?after=abc'],
            ], 200)
            ->push([
                'data' => [$this->plantillaDeMeta(['id' => 'tpl-2', 'name' => 'segunda'])],
            ], 200);

        $resultado = $this->servicio()->sincronizar();

        $this->assertSame(2, $resultado['total']);
        $this->assertSame(['primera', 'segunda'], WhatsappPlantilla::orderBy('id')->pluck('nombre')->all());
    }

    public function test_volver_a_sincronizar_actualiza_en_vez_de_duplicar(): void
    {
        // Una secuencia y no dos `Http::fake`: los stubs se acumulan y gana el primero que
        // casa, así que registrar el segundo no reemplaza al primero.
        Http::fakeSequence()
            ->push(['data' => [$this->plantillaDeMeta(['status' => 'PENDING'])]], 200)
            ->push(['data' => [$this->plantillaDeMeta(['status' => 'APPROVED'])]], 200);

        $this->servicio()->sincronizar();
        $this->assertSame('PENDING', WhatsappPlantilla::first()->estado);

        $resultado = $this->servicio()->sincronizar();

        $this->assertSame(1, WhatsappPlantilla::count());
        $this->assertSame(0, $resultado['creadas']);
        $this->assertSame('APPROVED', WhatsappPlantilla::first()->estado);
    }

    public function test_la_misma_plantilla_en_otro_idioma_es_otra_plantilla(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
            $this->plantillaDeMeta(['language' => 'es']),
            $this->plantillaDeMeta(['id' => 'tpl-2', 'language' => 'en']),
        ]], 200)]);

        $this->servicio()->sincronizar();

        // Son mensajes distintos, y mandar el de otro idioma es mandarle a un cliente un texto
        // que no entiende.
        $this->assertSame(2, WhatsappPlantilla::count());
    }

    public function test_una_plantilla_que_ya_no_esta_en_meta_se_marca_pero_no_se_borra(): void
    {
        WhatsappPlantilla::create([
            'nombre' => 'vieja', 'idioma' => 'es', 'estado' => 'APPROVED', 'cuerpo' => 'Hola',
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [$this->plantillaDeMeta()]], 200)]);

        $this->servicio()->sincronizar();

        $vieja = WhatsappPlantilla::where('nombre', 'vieja')->first();

        // Borrarla rompería el historial: un mensaje guardado dice con qué plantilla salió. Y
        // alguien puede borrar una plantilla en Meta por error.
        $this->assertNotNull($vieja);
        $this->assertSame('BORRADA_EN_META', $vieja->estado);
        // Marcada, deja de ofrecerse.
        $this->assertSame(0, WhatsappPlantilla::aprobadas()->where('nombre', 'vieja')->count());
    }

    public function test_solo_se_piden_los_datos_del_cuerpo(): void
    {
        // El encabezado de esta plantilla trae {{1}} y el cuerpo trae {{1}} y {{2}}.
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [$this->plantillaDeMeta([
            'components' => [
                ['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Novedad de {{1}}'],
                ['type' => 'BODY',   'text' => 'Hola {{1}}, su orden {{2}} salió.'],
            ],
        ])]], 200)]);

        $this->servicio()->sincronizar();

        // Se mandan dos, porque el envío solo arma el componente `body`. Contar también las del
        // encabezado hacía que el formulario pidiera un dato de más y que Meta rechazara el
        // envío por número de parámetros.
        $this->assertSame(2, WhatsappPlantilla::first()->variables);
    }

    public function test_la_vista_previa_tolera_los_espacios_dentro_de_las_llaves(): void
    {
        $plantilla = WhatsappPlantilla::create([
            'nombre' => 'con_espacios', 'idioma' => 'es', 'estado' => 'APPROVED',
            'cuerpo' => 'Hola {{ 1 }}, su orden {{2}} está lista.',
            'variables' => 2,
        ]);

        // Meta acepta `{{ 1 }}`, y el contador siempre lo contó. La vista previa no lo
        // reemplazaba, así que se veía el hueco y parecía que el dato escrito no servía.
        $this->assertSame(
            'Hola Marta, su orden OP-7 está lista.',
            $plantilla->previsualizar(['Marta', 'OP-7']),
        );
    }

    public function test_un_dato_con_signo_de_pesos_pasa_literal(): void
    {
        $plantilla = WhatsappPlantilla::create([
            'nombre' => 'con_monto', 'idioma' => 'es', 'estado' => 'APPROVED',
            'cuerpo' => 'Su saldo es {{1}}.', 'variables' => 1,
        ]);

        // Con un `preg_replace` a secas, `$1` se interpreta como referencia a un grupo y el
        // cliente recibía otra cosa. El valor lo escribe una persona o viene de un documento.
        $this->assertSame('Su saldo es $1.500.000.', $plantilla->previsualizar(['$1.500.000']));
    }

    // ─── Cuando no se puede ──────────────────────────────────────────────────

    public function test_sin_el_identificador_de_la_cuenta_dice_donde_encontrarlo(): void
    {
        CredencialesRrss::guardar('whatsapp', 'waba', '');
        Http::fake();

        $resultado = $this->servicio()->sincronizar();

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('WABA ID', $resultado['mensaje']);
        Http::assertNothingSent();
    }

    public function test_un_identificador_de_numero_en_vez_de_cuenta_se_explica(): void
    {
        // Es el error más fácil de cometer: los dos son números largos y están cerca en la
        // misma pantalla de Meta.
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['code' => 803, 'message' => 'Some of the aliases you requested do not exist'],
        ], 400)]);

        $resultado = $this->servicio()->sincronizar();

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('Phone Number ID', $resultado['mensaje']);
    }

    public function test_un_token_vencido_se_dice_como_tal(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['code' => 190, 'message' => 'Error validating access token'],
        ], 400)]);

        $resultado = $this->servicio()->sincronizar();

        $this->assertFalse($resultado['ok']);
        $this->assertStringContainsString('venció', $resultado['mensaje']);
    }

    public function test_una_cuenta_sin_plantillas_dice_donde_se_crean(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => []], 200)]);

        $resultado = $this->servicio()->sincronizar();

        $this->assertTrue($resultado['ok']);
        $this->assertStringContainsString('WhatsApp Manager', $resultado['mensaje']);
    }

    // ─── Desde la pantalla ───────────────────────────────────────────────────

    public function test_se_pueden_traer_desde_la_pantalla_de_configuracion(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [$this->plantillaDeMeta()]], 200)]);

        $admin = User::factory()->create(['rol' => 'administrador', 'activo' => true]);

        $this->actingAs($admin)
            ->postJson('/configuracion/whatsapp-numeros/sincronizar-plantillas')
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame(1, WhatsappPlantilla::count());
    }

    public function test_la_pantalla_recibe_las_plantillas_con_su_estado(): void
    {
        WhatsappPlantilla::create([
            'nombre' => 'aviso', 'idioma' => 'es', 'estado' => 'APPROVED',
            'cuerpo' => 'Hola {{1}}', 'variables' => 1,
        ]);

        $admin = User::factory()->create(['rol' => 'administrador', 'activo' => true]);

        $this->actingAs($admin)->get('/configuracion/whatsapp-numeros')
            ->assertInertia(fn ($pagina) => $pagina
                ->where('plantillas.0.nombre', 'aviso')
                ->where('plantillas.0.estado', 'APPROVED')
                ->where('plantillas.0.variables', 1)
            );
    }
}
