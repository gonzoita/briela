<?php

namespace Tests\Feature;

use App\Models\CanalPrecio;
use App\Models\Cliente;
use App\Models\Configuracion;
use App\Models\Cotizacion;
use App\Models\CotizacionItem;
use App\Models\Producto;
use App\Models\SegmentacionOpcion;
use App\Models\TasaCambio;
use App\Models\User;
use App\Services\CostosEnMonedaService;
use App\Services\IA\LectorRutService;
use App\Services\PdfPlantillaMotor;
use App\Services\PdfVariablesEngine;
use App\Services\RetencionesService;
use App\Services\TasaCambioService;
use App\Support\Fiscal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Monedas, tasa del día, lectura del RUT y retenciones.
 *
 * La regla de fondo que fijan: por dentro todo está en pesos. La tasa convierte costos de
 * otra moneda a pesos y muestra en otra moneda lo que ya está en pesos, y nunca al revés.
 */
class MonedasYRetencionesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'administrador']);
    }

    private function tasa(string $moneda, float $valor, ?string $fecha = null, string $fuente = 'superfinanciera'): TasaCambio
    {
        return TasaCambio::create(['moneda' => $moneda, 'fecha' => $fecha ?? now()->toDateString(), 'valor' => $valor, 'fuente' => $fuente]);
    }

    private function canal(string $valor): SegmentacionOpcion
    {
        return SegmentacionOpcion::where('tipo', 'tipo_contacto')->where('valor', $valor)->firstOrFail();
    }

    // ─── Tasas ───────────────────────────────────────────────────────────────

    public function test_trae_la_trm_y_cruza_el_euro(): void
    {
        Http::fake([
            'www.datos.gov.co/*' => Http::response([['valor' => '4000.00', 'vigenciadesde' => now()->toDateString() . 'T00:00:00.000']]),
            'api.frankfurter.dev/*' => Http::response(['base' => 'EUR', 'rates' => ['USD' => 1.1]]),
        ]);

        $r = app(TasaCambioService::class)->actualizar();

        $this->assertTrue($r['USD']['ok']);
        $this->assertEquals(4000, app(TasaCambioService::class)->valor('USD'));
        $this->assertEquals(4400, app(TasaCambioService::class)->valor('EUR'));
    }

    public function test_sin_internet_no_revienta_y_una_tasa_a_mano_no_se_pisa(): void
    {
        $this->tasa('USD', 3900, now()->subDay()->toDateString());
        app(TasaCambioService::class)->registrarManual('EUR', 4500);

        Http::fake([
            'www.datos.gov.co/*' => Http::response('caído', 500),
            'api.frankfurter.dev/*' => Http::response(['rates' => ['USD' => 1.1]]),
        ]);

        $r = app(TasaCambioService::class)->actualizar();

        $this->assertFalse($r['USD']['ok']);
        // Se sigue usando la de ayer.
        $this->assertEquals(3900, app(TasaCambioService::class)->valor('USD'));
        // La escrita a mano hoy manda sobre el cruce automático.
        $this->assertEquals(4500, app(TasaCambioService::class)->valor('EUR'));
    }

    // ─── Productos con costo en otra moneda ──────────────────────────────────

    public function test_el_costo_en_dolares_se_recalcula_con_la_tasa_y_reprecia_solo_lo_calculado(): void
    {
        $this->tasa('USD', 4000, now()->subDay()->toDateString());

        $p = Producto::create([
            'tipo' => 'producto', 'nombre' => 'Compresor', 'referencia' => 'IMP-USD',
            'moneda_costo' => 'USD', 'costo_moneda' => 100, 'precio_costo' => 400000,
        ]);

        // Una fila calculada (25 % sobre 400.000 = 500.000) y una escrita a mano.
        CanalPrecio::create(['precionable_type' => $p->getMorphClass(), 'precionable_id' => $p->id,
            'segmentacion_opcion_id' => $this->canal('mayorista')->id, 'margen_pct' => 25, 'precio' => 500000]);
        CanalPrecio::create(['precionable_type' => $p->getMorphClass(), 'precionable_id' => $p->id,
            'segmentacion_opcion_id' => $this->canal('cliente_directo')->id, 'margen_pct' => 35, 'precio' => 777000]);

        $this->tasa('USD', 4200);
        app(CostosEnMonedaService::class)->actualizarProductos();

        $p->refresh();
        $this->assertEquals(420000, (float) $p->precio_costo);

        $precio = fn ($canal) => (float) CanalPrecio::where('precionable_id', $p->id)
            ->where('segmentacion_opcion_id', $this->canal($canal)->id)->value('precio');

        $this->assertEquals(525000, $precio('mayorista'));
        $this->assertEquals(777000, $precio('cliente_directo'), 'Un precio escrito a mano no se reprecia.');
    }

    public function test_el_colchon_sube_el_costo_en_pesos(): void
    {
        $this->tasa('EUR', 4500);
        Configuracion::set('monedas_colchon_pct', '2');

        $this->assertEquals(459000, app(TasaCambioService::class)->costoEnPesos(100, 'EUR'));
    }

    public function test_guardar_un_producto_en_dolares_sin_tasa_lo_dice(): void
    {
        $this->actingAs($this->admin())
            ->post('/productos', [
                'tipo' => 'producto', 'nombre' => 'Válvula', 'moneda_costo' => 'USD', 'costo_moneda' => 50,
            ])
            ->assertSessionHasErrors('costo_moneda');

        $this->tasa('USD', 4000);

        $this->actingAs($this->admin())
            ->post('/productos', [
                'tipo' => 'producto', 'nombre' => 'Válvula', 'moneda_costo' => 'USD', 'costo_moneda' => 50,
                'canales' => [['segmentacion_opcion_id' => $this->canal('mayorista')->id, 'margen_pct' => 25, 'precio' => 1]],
            ])
            ->assertSessionHasNoErrors();

        $p = Producto::where('nombre', 'Válvula')->firstOrFail();
        $this->assertEquals(200000, (float) $p->precio_costo);
        // El precio que mandó la pantalla no cuenta: sale del costo que calculó el servidor.
        $this->assertEquals(250000, (float) CanalPrecio::where('precionable_id', $p->id)->value('precio'));
    }

    // ─── Cotizaciones ────────────────────────────────────────────────────────

    private function cotizacion(array $extra = []): Cotizacion
    {
        $cliente = Cliente::create(['tipo' => 'empresa', 'nombre' => 'Cliente SAS', 'tipo_identificacion' => 'NIT']);

        $cot = Cotizacion::create($extra + [
            'cliente_id' => $cliente->id, 'moneda' => 'COP', 'tasa_cambio' => 1, 'estado' => 'borrador',
            'responsable_id' => $this->admin()->id,
        ]);

        CotizacionItem::create([
            'cotizacion_id' => $cot->id, 'tipo' => 'texto_libre', 'descripcion' => 'Puerta', 'orden' => 0,
            'cantidad' => 1, 'precio_unitario' => 6075000, 'descuento_pct' => 0, 'impuesto_pct' => 0,
            'subtotal' => 6075000, 'total_linea' => 6075000,
        ]);

        $cot->load('items')->recalcularTotales();

        return $cot->fresh();
    }

    public function test_en_modo_diario_las_abiertas_siguen_a_la_trm_y_las_aprobadas_no(): void
    {
        $this->tasa('USD', 4200);
        $abierta  = $this->cotizacion(['moneda' => 'USD', 'tasa_cambio' => 4000, 'estado' => 'enviada']);
        $aprobada = $this->cotizacion(['moneda' => 'USD', 'tasa_cambio' => 4000, 'estado' => 'aprobada']);

        // En modo fijo, nada se mueve.
        $this->assertSame(0, app(CostosEnMonedaService::class)->actualizarCotizacionesAbiertas());

        Configuracion::set('monedas_modo_cotizacion', 'diaria');
        app(CostosEnMonedaService::class)->actualizarCotizacionesAbiertas();

        $this->assertEquals(4200, (float) $abierta->fresh()->tasa_cambio);
        $this->assertEquals(4000, (float) $aprobada->fresh()->tasa_cambio);
    }

    public function test_el_pdf_de_una_cotizacion_en_dolares_sale_en_dolares(): void
    {
        $cot   = $this->cotizacion(['moneda' => 'USD', 'tasa_cambio' => 4500, 'tasa_fecha' => now()->toDateString()]);
        $datos = PdfVariablesEngine::prepararDatos('cotizacion', $cot);

        $this->assertSame('US$ 1.350,00', PdfPlantillaMotor::render('{{cotizacion.total|moneda}}', $datos));
        $this->assertSame('US$ 1.350,00', PdfPlantillaMotor::render('{{#each items}}{{precio_unitario|moneda}}{{/each}}', $datos));
        $this->assertStringContainsString('6.075.000', $datos['cotizacion.nota_moneda']);

        // Y una en pesos no cambia.
        $pesos = PdfVariablesEngine::prepararDatos('cotizacion', $this->cotizacion());
        $this->assertSame('$6.075.000', PdfPlantillaMotor::render('{{cotizacion.total|moneda}}', $pesos));
    }

    // ─── Retenciones ─────────────────────────────────────────────────────────

    private function clienteCon(array $codigos, bool $ica = false): Cliente
    {
        return Cliente::create(['tipo' => 'empresa', 'nombre' => 'Retenedor SA', 'tipo_identificacion' => 'NIT',
            'responsabilidades_fiscales' => $codigos, 'retenedor_ica' => $ica]);
    }

    public function test_un_agente_de_retencion_retiene_renta_e_iva(): void
    {
        Fiscal::guardarJson('fiscal_responsabilidades', ['48']);
        Configuracion::set('fiscal_uvt', '50000');
        Configuracion::set('fiscal_reteica_por_mil', '9.66');

        $r = app(RetencionesService::class)->estimar(
            [['concepto' => 'compras', 'base' => 10000000], ['concepto' => 'servicios', 'base' => 100000]],
            1900000,
            $this->clienteCon(['07', '09'], ica: true),
        );

        $valores = collect($r['lineas'])->pluck('valor', 'clave');

        $this->assertEquals(250000, $valores['retefuente_compras']);   // 2,5 % de 10 millones
        $this->assertArrayNotHasKey('retefuente_servicios', $valores->all()); // 100.000 no llega a 4 UVT (200.000)
        $this->assertEquals(285000, $valores['reteiva']);               // 15 % del IVA
        $this->assertEquals(97566, $valores['reteica']);                 // 9,66 por mil de 10,1 millones
        $this->assertNotEmpty(collect($r['motivos'])->filter(fn ($m) => str_contains($m, 'Servicios')));
    }

    public function test_a_una_empresa_autorretenedora_no_le_retienen_renta(): void
    {
        Fiscal::guardarJson('fiscal_responsabilidades', ['15', '48']);

        $r = app(RetencionesService::class)->estimar([['concepto' => 'compras', 'base' => 10000000]], 0, $this->clienteCon(['07']));

        $this->assertSame([], $r['lineas']);
        $this->assertStringContainsString('autorretenedora', implode(' ', $r['motivos']));
    }

    public function test_sin_rut_del_cliente_no_se_inventan_retenciones(): void
    {
        $r = app(RetencionesService::class)->estimar([['concepto' => 'compras', 'base' => 10000000]], 0, $this->clienteCon([]));

        $this->assertFalse($r['aplica']);
        $this->assertSame([], $r['lineas']);
    }

    public function test_la_pantalla_de_la_cotizacion_pide_las_retenciones_al_servidor(): void
    {
        Fiscal::guardarJson('fiscal_responsabilidades', ['48']);
        $cliente = $this->clienteCon(['13']);

        $this->actingAs($this->admin())
            ->postJson('/api/cotizaciones/estimar-retenciones', [
                'cliente_id' => $cliente->id,
                'iva'        => 0,
                'items'      => [['producto_id' => null, 'base' => 10000000]],
            ])
            ->assertOk()
            ->assertJsonPath('total', 250000);
    }

    // ─── RUT ─────────────────────────────────────────────────────────────────

    public function test_interpreta_un_rut_y_verifica_el_digito(): void
    {
        $lector = app(LectorRutService::class);

        $bien = $lector->interpretar('```json
        {"tipo_persona":"juridica","numero_identificacion":"900.123.456","digito_verificacion":"8",
         "razon_social":"ACME DE COLOMBIA S.A.S.","ciudad":"MEDELLÍN","actividad_principal":"2511",
         "responsabilidades":["O-13","7","48"],"email":"compras@acme.co"}
        ```');

        $this->assertSame([], $bien['avisos']);
        $this->assertSame('900123456', $bien['datos']['numero_identificacion']);
        $this->assertSame('8', $bien['datos']['digito_verificacion']);
        $this->assertSame('NIT', $bien['datos']['tipo_identificacion']);
        $this->assertSame(['07', '13', '48'], $bien['datos']['responsabilidades_fiscales']);
        $this->assertSame('Acme De Colombia S.A.S.', $bien['datos']['nombre']);
        $this->assertSame('Medellín', $bien['datos']['ciudad']);

        $mal = $lector->interpretar('{"tipo_persona":"juridica","numero_identificacion":"900123456","digito_verificacion":"5","responsabilidades":["48"]}');
        $this->assertStringContainsString('no corresponde', implode(' ', $mal['avisos']));
    }

    public function test_leer_rut_devuelve_los_datos_sin_guardar_nada(): void
    {
        $this->mock(LectorRutService::class, function ($m) {
            $m->shouldReceive('leer')->once()->andReturn([
                'datos'  => ['nombre' => 'Acme SAS', 'numero_identificacion' => '900123456'],
                'avisos' => [],
            ]);
        });

        $archivo = \Illuminate\Http\UploadedFile::fake()->create('rut.pdf', 50, 'application/pdf');

        $this->actingAs($this->admin())
            ->post('/clientes/leer-rut', ['archivo' => $archivo], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('datos.nombre', 'Acme SAS');

        $this->assertSame(0, Cliente::count());
    }

    public function test_las_pantallas_nuevas_abren(): void
    {
        $admin = $this->admin();

        foreach (['/configuracion/monedas', '/configuracion/fiscal', '/productos/crear', '/clientes/create', '/cotizaciones/crear'] as $ruta) {
            $this->actingAs($admin)->get($ruta)->assertOk();
        }
    }
}
