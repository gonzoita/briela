<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Cotizacion;
use App\Models\Sede;
use App\Models\User;
use App\Services\ConsultaNitService;
use App\Services\IA\LectorRutService;
use App\Services\TasaCambioService;
use App\Support\Monedas;
use App\Support\Paises;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Países y monedas: lo que depende de un país es un dato, y la moneda base es de la instalación.
 */
class PaisesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'administrador']);
    }

    /** La sede principal, en el país que se pida. */
    private function sedePrincipal(string $pais = 'CO', ?string $moneda = null): Sede
    {
        Sede::query()->update(['es_principal' => false]);

        $sede = Sede::create([
            'nombre' => 'Principal', 'codigo' => 'PRI' . random_int(100, 999), 'es_principal' => true,
            'tiene_ventas' => true, 'activa' => true, 'pais' => $pais, 'moneda' => $moneda,
        ]);

        Paises::olvidar();

        return $sede;
    }

    // ─── Paquetes ────────────────────────────────────────────────────────────

    public function test_colombia_trae_sus_identificaciones_y_su_iva(): void
    {
        $co = Paises::perfil('CO');

        $this->assertSame('RUT', $co['documento_fiscal']);
        $this->assertTrue($co['retenciones']);
        $this->assertContains('NIT', array_column($co['identificaciones'], 'codigo'));
        $this->assertSame([19.0, 5.0, 0.0], Paises::tarifas('CO'));
        $this->assertSame('IVA', Paises::nombreImpuesto('CO'));
    }

    public function test_panama_y_venezuela_tienen_su_propio_impuesto_y_sus_documentos(): void
    {
        $this->assertSame('ITBMS', Paises::nombreImpuesto('PA'));
        $this->assertSame([15.0, 10.0, 7.0, 0.0], Paises::tarifas('PA'));
        $this->assertContains('RUC', Paises::codigosIdentificacion('PA'));
        $this->assertFalse(Paises::perfil('PA')['retenciones']);
        $this->assertFalse(Paises::perfil('PA')['revisado'], 'Un paquete sin contador se marca como no revisado.');

        $this->assertContains('RIF', Paises::codigosIdentificacion('VE'));
        $this->assertSame([16.0, 8.0, 0.0], Paises::tarifas('VE'));
        $this->assertSame('VES', Paises::monedaDe('VE'));
        $this->assertSame('USD', Paises::monedaDe('PA'));
    }

    public function test_un_pais_sin_paquete_funciona_con_identificaciones_genericas(): void
    {
        $this->assertFalse(Paises::perfil('PE')['con_paquete']);
        $this->assertSame(['ID', 'DOC', 'PAS'], Paises::codigosIdentificacion('PE'));
        $this->assertSame('PEN', Paises::monedaDe('PE'));
        // Sin impuestos conocidos no se inventa uno: solo ofrece 0 hasta que la empresa lo escriba.
        $this->assertSame([0.0], Paises::tarifas('PE'));
    }

    public function test_los_paquetes_son_json_valido_y_completo(): void
    {
        foreach (glob(resource_path('paises/*.json')) as $ruta) {
            $p = json_decode(file_get_contents($ruta), true);

            $this->assertIsArray($p, basename($ruta));
            $this->assertTrue(Paises::existe($p['iso']), basename($ruta));
            $this->assertNotEmpty($p['identificaciones'], basename($ruta));
            $this->assertNotEmpty($p['impuestos'], basename($ruta));

            foreach ($p['identificaciones'] as $i) {
                if (! empty($i['patron'])) {
                    $this->assertNotFalse(@preg_match('/' . str_replace('/', '\/', $i['patron']) . '/', ''), "{$p['iso']} {$i['codigo']}: patrón inválido");
                }
            }
        }
    }

    public function test_la_casa_es_el_pais_de_la_sede_principal(): void
    {
        $this->assertSame('CO', Paises::casa());

        $this->sedePrincipal('PA');
        $this->assertSame('PA', Paises::casa());
    }

    public function test_lo_que_la_instalacion_ajusta_gana_sobre_el_paquete(): void
    {
        Paises::guardarAjuste('PA', [
            'impuestos'   => [['clave' => 'itbms', 'nombre' => 'ITBMS', 'tarifas' => [7.0, 0.0], 'defecto' => 7.0]],
            'importacion' => ['iva_descontable' => false],
        ]);

        $this->assertSame([7.0, 0.0], Paises::tarifas('PA'));
        $this->assertFalse(Paises::perfil('PA')['importacion']['iva_descontable']);
        // El paquete de otro país no se toca.
        $this->assertSame([19.0, 5.0, 0.0], Paises::tarifas('CO'));
    }

    // ─── Identificaciones ────────────────────────────────────────────────────

    public function test_avisa_cuando_la_identificacion_no_tiene_el_formato_del_pais(): void
    {
        $this->assertNull(Paises::avisoIdentificacion('VE', 'RIF', 'J-12345678-9'));
        $this->assertNotNull(Paises::avisoIdentificacion('VE', 'RIF', '12345'));
        $this->assertNull(Paises::avisoIdentificacion('PA', 'RUC', '155612345-2-2015'));

        $dv = app(ConsultaNitService::class)->calcularDv('900123456');
        $this->assertNull(Paises::avisoIdentificacion('CO', 'NIT', '900123456', $dv));
        $this->assertStringContainsString('no corresponde', Paises::avisoIdentificacion('CO', 'NIT', '900123456', $dv === '0' ? '1' : '0'));
    }

    public function test_un_cliente_de_venezuela_se_guarda_con_su_rif_y_no_con_un_nit(): void
    {
        $this->actingAs($this->admin());
        $base = [
            'tipo' => 'empresa', 'nombre' => 'Distribuidora Caracas', 'pais' => 'VE', 'numero_identificacion' => 'J-12345678-9',
            'contactos' => [['nombre' => 'Ana', 'apellido' => 'Pérez']],
        ];

        $this->post('/clientes', $base + ['tipo_identificacion' => 'NIT'])->assertSessionHasErrors('tipo_identificacion');

        $this->post('/clientes', $base + ['tipo_identificacion' => 'RIF'])->assertSessionHasNoErrors();
        $this->assertSame('VE', Cliente::where('nombre', 'Distribuidora Caracas')->value('pais'));
    }

    public function test_un_cliente_sin_pais_sigue_validando_como_colombiano(): void
    {
        $this->actingAs($this->admin())
            ->post('/clientes', [
                'tipo' => 'empresa', 'nombre' => 'Acme', 'tipo_identificacion' => 'NIT', 'numero_identificacion' => '900123456',
                'contactos' => [['nombre' => 'Ana', 'apellido' => 'Pérez']],
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull(Cliente::where('nombre', 'Acme')->value('pais'));
    }

    public function test_la_consulta_de_identificacion_de_otro_pais_no_calcula_dv_ni_va_al_rues(): void
    {
        Http::fake();
        Cliente::create(['tipo' => 'empresa', 'nombre' => 'Ya existe', 'tipo_identificacion' => 'RIF', 'numero_identificacion' => 'J-12345678-9', 'pais' => 'VE']);

        $r = $this->actingAs($this->admin())
            ->getJson('/clientes/consultar-identificacion?numero=J123456789&tipo_identificacion=RIF&pais=VE')
            ->assertOk();

        $r->assertJsonPath('dv', null)->assertJsonPath('rues', null)->assertJsonPath('duplicado.nombre', 'Ya existe');
        Http::assertNothingSent();
    }

    public function test_el_lector_de_un_documento_extranjero_no_usa_las_casillas_del_rut(): void
    {
        $leido = app(LectorRutService::class)->interpretar(
            '{"tipo_persona":"juridica","tipo_documento":"RIF","numero_identificacion":"J-12345678-9","razon_social":"DISTRIBUIDORA CARACAS C.A.","ciudad":"CARACAS","atributos":["Contribuyente especial"]}',
            'VE',
        );

        $this->assertSame('RIF', $leido['datos']['tipo_identificacion']);
        $this->assertSame('J-12345678-9', $leido['datos']['numero_identificacion']);
        $this->assertSame('VE', $leido['datos']['pais']);
        $this->assertSame([], $leido['datos']['responsabilidades_fiscales']);
        $this->assertSame([], $leido['avisos']);
        $this->assertSame(['Contribuyente especial'], $leido['datos']['datos_rut']['atributos']);

        // Un número que no es de un RIF se avisa, no se rechaza.
        $mal = app(LectorRutService::class)->interpretar('{"tipo_persona":"juridica","tipo_documento":"RIF","numero_identificacion":"123"}', 'VE');
        $this->assertNotEmpty($mal['avisos']);
    }

    // ─── Moneda base ─────────────────────────────────────────────────────────

    public function test_la_moneda_base_arranca_en_pesos_y_se_puede_cambiar_al_empezar(): void
    {
        $this->assertSame('COP', Monedas::base());
        $this->assertNotContains('COP', Monedas::extranjeras());

        $this->actingAs($this->admin())->post('/configuracion/monedas/base', ['moneda' => 'USD'])->assertSessionHasNoErrors();

        $this->assertSame('USD', Monedas::base());
        $this->assertContains('COP', Monedas::extranjeras());
        $this->assertNotContains('USD', Monedas::extranjeras());
        $this->assertSame('US$1.350,00', Monedas::formatear(1350));
        // El peso ya no es la base, así que se escribe como moneda de un documento: con espacio.
        $this->assertSame('$ 6.075.000', Monedas::formatear(6075000, 'COP', 1));
    }

    public function test_con_documentos_guardados_la_moneda_base_ya_no_cambia(): void
    {
        $cliente = Cliente::create(['tipo' => 'empresa', 'nombre' => 'Cliente', 'tipo_identificacion' => 'NIT']);
        Cotizacion::create(['cliente_id' => $cliente->id, 'moneda' => 'COP', 'tasa_cambio' => 1, 'estado' => 'borrador', 'responsable_id' => $this->admin()->id]);

        $this->assertTrue(Monedas::baseBloqueada());

        $this->actingAs($this->admin())->post('/configuracion/monedas/base', ['moneda' => 'USD'])->assertSessionHas('error');
        $this->assertSame('COP', Monedas::base());
    }

    public function test_el_catalogo_de_monedas_de_php_y_de_javascript_coinciden(): void
    {
        $js = file_get_contents(resource_path('js/formato.js'));
        preg_match_all("/^\s{4}([A-Z]{3}): \{ simbolo: '([^']+)',\s+decimales: (\d) \}/m", $js, $m, PREG_SET_ORDER);

        $this->assertCount(count(Monedas::CATALOGO), $m, 'formato.js tiene otra cantidad de monedas que Monedas::CATALOGO.');

        foreach ($m as [, $codigo, $simbolo, $decimales]) {
            $this->assertSame(Monedas::CATALOGO[$codigo]['simbolo'] ?? null, $simbolo, $codigo);
            $this->assertSame(Monedas::CATALOGO[$codigo]['decimales'] ?? null, (int) $decimales, $codigo);
        }
    }

    public function test_con_base_en_dolares_las_tasas_se_cruzan_con_el_bce(): void
    {
        Configuracion::set('moneda_base', 'USD');
        Http::fake(['api.frankfurter.dev/*' => Http::response(['base' => 'USD', 'rates' => ['EUR' => 0.8, 'GBP' => 0.5]])]);

        $r = app(TasaCambioService::class)->actualizar();

        // Si 1 USD = 0,8 EUR, entonces 1 EUR = 1,25 USD.
        $this->assertEquals(1.25, app(TasaCambioService::class)->valor('EUR'));
        $this->assertEquals(2.0, app(TasaCambioService::class)->valor('GBP'));
        $this->assertFalse($r['CNY']['ok'], 'Lo que el BCE no devolvió se reporta, no se inventa.');
        $this->assertEquals(1.0, app(TasaCambioService::class)->valor('USD'));
    }

    public function test_una_base_sin_fuente_automatica_pide_las_tasas_a_mano(): void
    {
        Configuracion::set('moneda_base', 'PEN');
        Http::fake();

        $this->assertSame([], app(TasaCambioService::class)->automaticas());
        $this->assertArrayHasKey('*', app(TasaCambioService::class)->actualizar());
        Http::assertNothingSent();

        // Y una tasa de 0,00024 no se redondea a cero.
        $t = app(TasaCambioService::class)->registrarManual('COP', 0.00024);
        $this->assertEquals(0.00024, (float) $t->fresh()->valor);
    }

    // ─── Cotizaciones por país ───────────────────────────────────────────────

    public function test_la_cotizacion_de_una_sede_en_panama_ofrece_itbms_y_arranca_en_dolares(): void
    {
        $this->sedePrincipal('PA');

        $this->actingAs($this->admin())->get('/cotizaciones/crear')
            ->assertOk()
            ->assertInertia(fn ($p) => $p
                ->where('impuesto.nombre', 'ITBMS')
                ->where('impuesto.tarifas', [15, 10, 7, 0])
                ->where('moneda_defecto', 'USD')
                ->where('retenciones_activas', false));
    }

    public function test_en_panama_las_retenciones_colombianas_no_se_estiman(): void
    {
        $this->sedePrincipal('PA');
        $cliente = Cliente::create(['tipo' => 'empresa', 'nombre' => 'C', 'tipo_identificacion' => 'RUC', 'pais' => 'PA', 'responsabilidades_fiscales' => ['13']]);

        $this->actingAs($this->admin())
            ->postJson('/api/cotizaciones/estimar-retenciones', ['cliente_id' => $cliente->id, 'iva' => 0, 'items' => [['producto_id' => null, 'base' => 10000000]]])
            ->assertOk()
            ->assertJsonPath('aplica', false)
            ->assertJsonPath('total', 0);
    }

    public function test_una_cotizacion_se_puede_guardar_en_bolivares(): void
    {
        $this->assertTrue(Monedas::existe('VES'));

        $cliente = Cliente::create(['tipo' => 'empresa', 'nombre' => 'C', 'tipo_identificacion' => 'NIT']);
        $cot = Cotizacion::create(['cliente_id' => $cliente->id, 'moneda' => 'VES', 'tasa_cambio' => 0.0001, 'estado' => 'borrador', 'responsable_id' => $this->admin()->id]);

        $this->assertSame('VES', $cot->fresh()->moneda);
        $this->assertEquals(0.0001, (float) $cot->fresh()->tasa_cambio);
    }

    // ─── Sedes y pantallas ───────────────────────────────────────────────────

    public function test_una_sede_guarda_su_pais_y_su_moneda(): void
    {
        $sede = $this->sedePrincipal('CO');

        $this->actingAs($this->admin())->put("/configuracion/sedes/{$sede->id}", [
            'nombre' => 'Principal', 'codigo' => $sede->codigo, 'zona_horaria' => 'America/Panama',
            'es_principal' => true, 'tiene_ventas' => true, 'activa' => true, 'pais' => 'PA', 'moneda' => 'USD',
        ])->assertSessionHasNoErrors();

        $this->assertSame('PA', $sede->fresh()->pais);
        $this->assertSame('USD', $sede->fresh()->monedaDocumentos());
        $this->assertSame('PA', Paises::casa());

        $this->put("/configuracion/sedes/{$sede->id}", [
            'nombre' => 'Principal', 'codigo' => $sede->codigo, 'zona_horaria' => 'America/Panama', 'pais' => 'ZZ',
        ])->assertSessionHasErrors('pais');
    }

    public function test_la_sede_toma_la_moneda_de_su_pais_si_no_dice_otra(): void
    {
        $this->assertSame('VES', $this->sedePrincipal('VE')->monedaDocumentos());
        $this->assertSame('USD', $this->sedePrincipal('VE', 'USD')->monedaDocumentos());
    }

    public function test_la_pantalla_de_paises_abre_y_guarda_ajustes(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get('/configuracion/paises?pais=VE')->assertOk()
            ->assertInertia(fn ($p) => $p->where('pais.iso', 'VE')->where('pais.documento_fiscal', 'RIF'));

        $this->put('/configuracion/paises/VE', [
            'impuestos' => [['clave' => 'iva', 'nombre' => 'IVA', 'tarifas' => [16, 0], 'defecto' => 16]],
            'iva_descontable' => false,
        ])->assertSessionHasNoErrors();

        $this->assertSame([16.0, 0.0], Paises::tarifas('VE'));

        $this->delete('/configuracion/paises/VE')->assertSessionHasNoErrors();
        $this->assertSame([16.0, 8.0, 0.0], Paises::tarifas('VE'));
    }

    public function test_las_pantallas_con_pais_abren(): void
    {
        $admin = $this->admin();

        foreach (['/configuracion/sedes', '/configuracion/monedas', '/clientes/create', '/compras/proveedores'] as $ruta) {
            $this->actingAs($admin)->get($ruta)->assertOk();
        }
    }
}
