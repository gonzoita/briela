<?php

namespace Tests\Feature;

use App\Models\CuentaRrss;
use App\Models\Notificacion;
use App\Models\User;
use App\Services\Rrss\TokensRrssService;
use App\Support\CredencialesRrss;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Que no se venza el permiso de una red sin que nadie se entere.
 *
 * **El problema que resuelve.** El token que Meta entrega al conectar una página dura unos 60
 * días. No se renovaba ni se avisaba: a los dos meses las publicaciones de Facebook e Instagram
 * empezaban a fallar, y el único rastro era un «parcial» en la lista de publicaciones. Una
 * empresa que programa su contenido con dos semanas de anticipación se enteraba cuando ya se
 * habían dejado de publicar tres cosas. Estaba anotado como pendiente en el manual del módulo.
 */
class RrssTokensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CredencialesRrss::guardar('meta', 'id', 'app-123');
        CredencialesRrss::guardar('meta', 'secret', 'secreto-de-la-app');
    }

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'administrador', 'activo' => true]);
    }

    private function cuenta(string $red, int $diasParaVencer, array $extra = []): CuentaRrss
    {
        return CuentaRrss::create(array_merge([
            'red'               => $red,
            'nombre_cuenta'     => 'Página de la empresa',
            'cuenta_id_externo' => 'pag-1',
            'access_token'      => 'token-actual',
            'token_expira_en'   => now()->addDays($diasParaVencer),
            'activa'            => true,
        ], $extra));
    }

    private function servicio(): TokensRrssService
    {
        return app(TokensRrssService::class);
    }

    // ─── Renovar ─────────────────────────────────────────────────────────────

    public function test_un_token_de_meta_por_vencer_se_renueva_solo(): void
    {
        $this->admin();
        $cuenta = $this->cuenta('facebook', 10);

        Http::fake(['graph.facebook.com/*' => Http::response([
            'access_token' => 'token-nuevo',
            'expires_in'   => 5184000, // 60 días
        ], 200)]);

        $resultado = $this->servicio()->revisar();

        $this->assertCount(1, $resultado['renovadas']);

        $fresca = $cuenta->fresh();

        $this->assertSame('token-nuevo', $fresca->access_token);
        $this->assertTrue($fresca->token_expira_en->greaterThan(now()->addDays(50)));

        // Lo que el sistema puede arreglar solo no molesta a nadie: un aviso que llega cuando
        // no había nada que hacer es ruido, y el ruido enseña a ignorar los avisos.
        $this->assertSame(0, Notificacion::where('tipo', 'rrss_token_por_vencer')->count());
    }

    public function test_renovar_la_pagina_renueva_el_instagram_que_cuelga_de_ella(): void
    {
        $this->admin();
        $facebook  = $this->cuenta('facebook', 10);
        $instagram = $this->cuenta('instagram', 10, [
            'cuenta_id_externo'    => 'ig-1',
            'cuenta_id_secundario' => 'pag-1', // la página a la que está ligado
            'nombre_cuenta'        => '@laempresa',
        ]);

        Http::fake(['graph.facebook.com/*' => Http::response([
            'access_token' => 'token-nuevo',
            'expires_in'   => 5184000,
        ], 200)]);

        $this->servicio()->revisar();

        // Instagram publica con el token de la página, así que renovar una renueva la otra.
        $this->assertSame('token-nuevo', $instagram->fresh()->access_token);

        // Y solo se le pidió UNA vez a Meta: pedir el mismo token dos veces es una llamada de
        // más y un riesgo de pisar el valor recién guardado.
        Http::assertSentCount(1);
        $this->assertNotNull($facebook->fresh()->token_expira_en);
    }

    public function test_un_token_que_todavia_le_queda_mucho_no_se_toca(): void
    {
        $this->admin();
        $this->cuenta('facebook', 45);

        Http::fake();

        $resultado = $this->servicio()->revisar();

        $this->assertSame([], $resultado['renovadas']);
        Http::assertNothingSent();
    }

    // ─── Avisar ──────────────────────────────────────────────────────────────

    public function test_si_no_se_puede_renovar_le_avisa_a_un_administrador(): void
    {
        $admin = $this->admin();
        $this->cuenta('facebook', 10);

        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'token revocado'],
        ], 400)]);

        $resultado = $this->servicio()->revisar();

        $this->assertCount(1, $resultado['avisadas']);

        $aviso = Notificacion::where('user_id', $admin->id)->where('tipo', 'rrss_token_por_vencer')->first();

        $this->assertNotNull($aviso);
        // Con antelación y diciendo qué va a pasar: avisar el día del vencimiento es tarde.
        $this->assertStringContainsString('vence en 10 días', $aviso->titulo);
        $this->assertSame('/rrss/cuentas', $aviso->url);
    }

    public function test_el_aviso_va_a_los_administradores_y_no_a_quien_publica(): void
    {
        $admin    = $this->admin();
        $vendedor = User::factory()->create(['rol' => 'vendedor', 'activo' => true]);

        $this->cuenta('linkedin', 10);
        Http::fake();

        $this->servicio()->revisar();

        // Reconectar exige entrar al Business Manager de la empresa, y eso no lo puede hacer
        // quien solo programa contenido. Un aviso dirigido a alguien que no puede resolverlo
        // es un aviso que nadie atiende.
        $this->assertSame(1, Notificacion::where('user_id', $admin->id)->count());
        $this->assertSame(0, Notificacion::where('user_id', $vendedor->id)->count());
    }

    public function test_linkedin_no_se_intenta_renovar_porque_no_se_puede(): void
    {
        $this->admin();
        $this->cuenta('linkedin', 12);

        Http::fake();

        $resultado = $this->servicio()->revisar();

        // Renovar un token de página de LinkedIn exige un permiso que LinkedIn aprueba aparte.
        // Mientras no esté aprobado, lo honesto es avisar para que alguien reconecte, en vez
        // de intentar una llamada que se sabe que va a fallar.
        $this->assertSame([], $resultado['renovadas']);
        $this->assertCount(1, $resultado['avisadas']);
        Http::assertNothingSent();
    }

    public function test_un_token_ya_vencido_no_se_intenta_renovar_con_el_mismo(): void
    {
        $this->admin();
        $this->cuenta('facebook', -2);

        Http::fake();

        $resultado = $this->servicio()->revisar();

        // Renovar usa el token actual, así que con uno vencido no hay nada que hacer salvo
        // reconectar a mano.
        $this->assertCount(1, $resultado['vencidas']);
        Http::assertNothingSent();

        $aviso = Notificacion::where('tipo', 'rrss_token_por_vencer')->first();

        $this->assertStringContainsString('Se venció', $aviso->titulo);
        $this->assertStringContainsString('están fallando', $aviso->mensaje);
    }

    public function test_el_error_queda_anotado_en_la_cuenta_para_que_la_pantalla_lo_muestre(): void
    {
        $this->admin();
        $cuenta = $this->cuenta('facebook', 10);

        Http::fake(['graph.facebook.com/*' => Http::response([
            'error' => ['message' => 'token revocado'],
        ], 400)]);

        $this->servicio()->revisar();

        $this->assertStringContainsString('No se pudo renovar', $cuenta->fresh()->ultimo_error);
    }

    public function test_una_cuenta_desconectada_no_se_revisa(): void
    {
        $this->admin();
        $this->cuenta('facebook', 5, ['activa' => false]);

        Http::fake();

        $resultado = $this->servicio()->revisar();

        $this->assertSame(['renovadas' => [], 'avisadas' => [], 'vencidas' => []], $resultado);
        $this->assertSame(0, Notificacion::count());
    }

    // ─── El comando ──────────────────────────────────────────────────────────

    public function test_el_comando_programado_corre_y_dice_que_hizo(): void
    {
        $this->admin();
        $this->cuenta('facebook', 8);

        Http::fake(['graph.facebook.com/*' => Http::response([
            'access_token' => 'token-nuevo',
            'expires_in'   => 5184000,
        ], 200)]);

        $this->artisan('rrss:revisar-tokens')
            ->expectsOutputToContain('Renovado solo')
            ->assertSuccessful();
    }

    public function test_sin_cuentas_el_comando_lo_dice_y_no_falla(): void
    {
        Http::fake();

        $this->artisan('rrss:revisar-tokens')
            ->expectsOutputToContain('al día')
            ->assertSuccessful();
    }
}
