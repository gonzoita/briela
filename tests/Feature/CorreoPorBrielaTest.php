<?php

namespace Tests\Feature;

use App\Mail\TransporteBriela;
use App\Models\Configuracion;
use App\Models\CorreoSupresion;
use App\Models\User;
use App\Services\LicenciaService;
use App\Services\SmtpConfigService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * El correo de la instalación sale por el panel de Briela cuando su dominio está
 * verificado, y por el SMTP propio cuando el panel no lo puede tomar.
 */
class CorreoPorBrielaTest extends TestCase
{
    use RefreshDatabase;

    private const PANEL = 'https://panel.briela.test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['briela.licencia_url' => self::PANEL]);
        Configuracion::set('briela_serial', 'BRL-AAAA-BBBB-CCCC');
    }

    private function correoDisponible(bool $disponible = true): void
    {
        Configuracion::set('briela_licencia_estado', json_encode([
            'valido' => true, 'estado' => 'activa', 'al_dia' => true, 'consultado_at' => now()->toIso8601String(),
            'correo' => [
                'disponible' => $disponible, 'dominio' => 'acme.envios.briela.app',
                'remitentes' => ['transaccional' => 'notificaciones@acme.envios.briela.app', 'masivo' => 'boletin@acme.envios.briela.app'],
                'incluidos_mes' => 2000, 'masivos_mes' => 10, 'transaccionales_mes' => 4,
            ],
        ]));
    }

    /** Un transporte «briela» con un respaldo que se puede inspeccionar. */
    private function conRespaldo(): ArrayTransport
    {
        $respaldo = new ArrayTransport();
        Mail::extend('briela', fn () => new TransporteBriela(app(LicenciaService::class), fn () => $respaldo));
        config(['mail.mailers.briela' => ['transport' => 'briela'], 'mail.from.address' => 'notificaciones@acme.envios.briela.app']);
        Mail::purge('briela');

        return $respaldo;
    }

    public function test_con_el_dominio_verificado_las_notificaciones_salen_por_el_panel(): void
    {
        $this->correoDisponible();
        Http::fake([self::PANEL . '/api/correo/enviar' => Http::response(['ok' => true, 'resultados' => [['id' => '0', 'ok' => true]]])]);

        SmtpConfigService::aplicar();
        $this->assertSame('briela', config('mail.default'));

        Mail::raw('La OP 12 pasó a calidad.', fn ($m) => $m->to('jefe@acme.co')->subject('Aviso'));

        Http::assertSent(function (Request $r) {
            $m = $r->data()['mensajes'][0];

            return $r->header('X-Briela-Serial')[0] === 'BRL-AAAA-BBBB-CCCC'
                && $m['tipo'] === 'transaccional'
                && $m['para'] === 'jefe@acme.co'
                && str_contains($m['texto'], 'La OP 12');
        });
    }

    public function test_sin_dominio_verificado_se_sigue_usando_el_smtp(): void
    {
        $this->correoDisponible(false);
        config(['mail.default' => 'log']);

        SmtpConfigService::aplicar();

        $this->assertSame('log', config('mail.default'));
    }

    public function test_si_el_panel_no_lo_toma_sale_por_el_smtp_de_respaldo(): void
    {
        $this->correoDisponible();
        $respaldo = $this->conRespaldo();
        Http::fake([self::PANEL . '/*' => Http::response(['ok' => false, 'mensaje' => 'El dominio no está verificado.'], 409)]);

        Mail::mailer('briela')->raw('Hola', fn ($m) => $m->to('a@x.co')->subject('Prueba'));

        $this->assertCount(1, $respaldo->messages());
    }

    public function test_lo_que_el_panel_rechaza_de_fondo_no_se_reintenta_por_smtp(): void
    {
        $this->correoDisponible();
        $respaldo = $this->conRespaldo();
        Http::fake([self::PANEL . '/*' => Http::response(['ok' => true, 'resultados' => [
            ['id' => '0', 'ok' => false, 'motivo' => 'Se alcanzó el límite diario de notificaciones.'],
        ]])]);

        try {
            Mail::mailer('briela')->raw('Hola', fn ($m) => $m->to('a@x.co')->subject('Prueba'));
            $this->fail('Debía avisar el rechazo.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('límite diario', $e->getMessage());
        }

        $this->assertCount(0, $respaldo->messages());
    }

    public function test_un_masivo_se_marca_y_una_direccion_suprimida_no_es_un_error(): void
    {
        $this->correoDisponible();
        $this->conRespaldo();
        Http::fake([self::PANEL . '/*' => Http::response(['ok' => true, 'resultados' => [
            ['id' => '0', 'ok' => false, 'suprimido' => true, 'motivo' => 'rebotó'],
        ]])]);

        Mail::mailer('briela')->raw('Boletín', function ($m) {
            $m->to('b@x.co')->subject('Novedades');
            $m->getSymfonyMessage()->getHeaders()->addTextHeader('X-Briela-Tipo', 'masivo');
            $m->getSymfonyMessage()->getHeaders()->addTextHeader('List-Unsubscribe', '<https://acme/baja/1>');
        });

        Http::assertSent(fn (Request $r) => $r->data()['mensajes'][0]['tipo'] === 'masivo'
            && $r->data()['mensajes'][0]['encabezados']['List-Unsubscribe'] === '<https://acme/baja/1>');
    }

    public function test_los_rebotes_del_panel_se_suprimen_y_no_se_vuelven_a_traer(): void
    {
        $this->correoDisponible();
        Http::fake([self::PANEL . '/api/correo/eventos*' => Http::response(['ok' => true, 'eventos' => [
            ['id' => 7, 'tipo' => 'rebote_duro', 'email' => 'Muerto@X.co', 'detalle' => '550'],
            ['id' => 8, 'tipo' => 'rebote_suave', 'email' => 'lleno@x.co'],
            ['id' => 9, 'tipo' => 'queja', 'email' => 'molesto@x.co'],
        ]])]);

        $this->artisan('correo:sincronizar-eventos')->assertSuccessful();

        $this->assertTrue(CorreoSupresion::suprimido('muerto@x.co'));
        $this->assertTrue(CorreoSupresion::suprimido('molesto@x.co'));
        $this->assertFalse(CorreoSupresion::suprimido('lleno@x.co'));
        $this->assertSame('9', (string) Configuracion::get('correo_ultimo_evento'));
    }

    public function test_la_pantalla_de_correo_abre(): void
    {
        $this->correoDisponible();

        $this->actingAs(User::factory()->create(['rol' => 'administrador']))
            ->get('/configuracion/correo')
            ->assertOk();
    }
}
