<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CrmEtapa;
use App\Models\CrmLead;
use App\Models\CrmLeadOrigen;
use App\Models\Informe;
use App\Models\MensajeAsistente;
use App\Models\Sede;
use App\Models\User;
use App\Services\IA\IaService;
use App\Services\Informes\MotorInformes;
use App\Services\Informes\Periodo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Los informes que se piden en el chat: la IA elige, el servidor calcula y guarda, y lo que se
 * descarga es un CSV que Excel en español abre bien o un libro de Excel con varias hojas.
 */
class InformesIaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private CrmEtapa $etapa;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['rol' => 'administrador', 'name' => 'Ana Pérez']);
        Sede::query()->first() ?? Sede::create(['nombre' => 'Principal']);
        $this->etapa = CrmEtapa::create(['nombre' => 'Nuevo', 'orden' => 1, 'activa' => true]);
        $this->actingAs($this->admin);
    }

    /** Un lead que llegó hace `$hace` días por `$canal`, cotizado a los `$diasACotizar` días (o no). */
    private function lead(string $canal, int $hace, ?int $diasACotizar = null, string $estado = 'activo', float $valor = 1000000): CrmLead
    {
        $llego = now()->subDays($hace);
        $lead  = CrmLead::create([
            'etapa_id' => $this->etapa->id, 'titulo' => "Lead {$canal} {$hace}", 'estado' => $estado,
            'nombre_contacto' => 'Laura Gómez', 'telefono_contacto' => '3001234567', 'responsable_id' => $this->admin->id,
        ]);
        $lead->forceFill(['created_at' => $llego, 'updated_at' => $llego])->saveQuietly();
        CrmLeadOrigen::create(['lead_id' => $lead->id, 'canal' => $canal]);

        if ($diasACotizar !== null) {
            $cliente = Cliente::create(['tipo' => 'empresa', 'nombre' => "Cliente {$lead->id}", 'tipo_identificacion' => 'NIT']);
            $cot = Cotizacion::create([
                'lead_id' => $lead->id, 'cliente_id' => $cliente->id, 'moneda' => 'COP', 'tasa_cambio' => 1,
                'estado' => $estado === 'ganado' ? 'aprobada' : 'enviada', 'responsable_id' => $this->admin->id, 'total' => $valor,
            ]);
            $cot->forceFill(['created_at' => $llego->copy()->addDays($diasACotizar)])->saveQuietly();
            $lead->forceFill(['estado' => $estado])->saveQuietly();
        }

        return $lead;
    }

    // ─── Motor ───────────────────────────────────────────────────────────────

    public function test_leads_del_periodo_con_conversion_y_tiempo_hasta_cotizar(): void
    {
        $this->lead('whatsapp', 5, 2, 'ganado', 2000000);
        $this->lead('whatsapp', 6, 4);
        $this->lead('whatsapp', 7);
        $this->lead('formulario', 8, 0, 'ganado', 1000000);
        $this->lead('formulario', 90, 1); // fuera del periodo

        $r = app(MotorInformes::class)->ejecutarConfig('leads', [], ['periodo' => 'ultimos_30'], ['canal'], $this->admin);

        $cifras = collect($r['metricas'])->pluck('valor', 'clave');
        $this->assertSame(4, $r['total_filas']);
        $this->assertSame(3, $cifras['cotizados']);
        $this->assertSame(2, $cifras['ganados']);
        $this->assertEquals(50.0, $cifras['conversion']);
        $this->assertEquals(2.0, $cifras['dias_cotizar']); // (2 + 4 + 0) / 3
        $this->assertEquals(3000000, $cifras['valor_ganado']);
        $this->assertSame(1, $cifras['sin_cotizar']);

        $porCanal = collect($r['resumenes'][0]['filas'])->keyBy('grupo');
        $this->assertSame(3, $porCanal['WhatsApp']['leads']);
        $this->assertEquals(33.3, $porCanal['WhatsApp']['pct_ganados']);
        $this->assertEquals(100.0, $porCanal['Formulario']['pct_ganados']);
        $this->assertSame(4, $r['resumenes'][0]['total']['leads']);
    }

    public function test_a_la_ia_solo_le_llegan_totales_nunca_las_filas(): void
    {
        $this->lead('whatsapp', 3, 1);

        $motor = app(MotorInformes::class);
        $paraIa = json_encode($motor->paraIa($motor->ejecutarConfig('leads', [], ['periodo' => 'ultimos_30'], ['vendedor'], $this->admin)));

        $this->assertStringNotContainsString('3001234567', $paraIa);
        $this->assertStringNotContainsString('Laura', $paraIa);
        $this->assertStringContainsString('Leads que llegaron', $paraIa);
    }

    public function test_sin_permiso_de_la_pantalla_no_hay_informe(): void
    {
        $operario = User::factory()->create(['rol' => 'operario']);

        $this->assertNull(app(MotorInformes::class)->normalizarPlan(['fuente' => 'leads'], $operario));
        $this->expectException(\RuntimeException::class);
        app(MotorInformes::class)->ejecutarConfig('cartera', [], [], [], $operario);
    }

    public function test_el_costo_no_sale_sin_permiso_de_costos(): void
    {
        $vendedor = User::factory()->create(['rol' => 'vendedor']);

        $motor = app(MotorInformes::class);
        $fuente = $motor->fuente('inventario');

        $this->assertContains('costo', $fuente->columnasOcultas($vendedor));
        $this->assertSame([], $fuente->columnasOcultas($this->admin));
    }

    public function test_el_plan_de_la_ia_se_limpia_antes_de_ejecutarse(): void
    {
        $plan = app(MotorInformes::class)->normalizarPlan([
            'fuente' => 'ventas', 'periodo' => 'inventado', 'columnas' => ['numero', 'DROP TABLE', 'total'],
            'agrupar_por' => ['vendedor', 'contraseña'], 'filtros' => ['estado' => 'aprobada', 'otro' => 'x'],
        ], $this->admin);

        $this->assertSame(['numero', 'total'], $plan['campos']);
        $this->assertSame(['vendedor'], $plan['agrupar']);
        $this->assertSame('ultimos_30', $plan['filtros']['periodo']);
        $this->assertSame('aprobada', $plan['filtros']['estado']);
        $this->assertArrayNotHasKey('otro', $plan['filtros']);
    }

    public function test_el_mes_pasado_es_el_mes_calendario_anterior(): void
    {
        $p = Periodo::resolver('mes_anterior', hoy: Carbon::parse('2026-03-15 10:00'));

        $this->assertSame('2026-02-01 00:00:00', $p['desde']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-02-28 23:59:59', $p['hasta']->format('Y-m-d H:i:s'));
    }

    // ─── La guía ─────────────────────────────────────────────────────────────

    public function test_la_guia_genera_el_informe_guarda_el_analisis_y_da_la_tarjeta(): void
    {
        $this->lead('whatsapp', 3, 1, 'ganado');
        $this->lead('instagram', 4);

        $prompts = [];
        $this->mock(IaService::class, function ($m) use (&$prompts) {
            $m->shouldReceive('maxTokensRespuesta')->andReturn(1500);
            $m->shouldReceive('texto')->andReturnUsing(function ($prompt, $instr = '', $max = 700, $rapido = false) use (&$prompts) {
                $prompts[] = $prompt;

                return $rapido
                    ? json_encode(['consultas' => [], 'informe' => [
                        'fuente' => 'leads', 'periodo' => 'ultimos_30', 'agrupar_por' => ['canal'], 'nombre' => 'Leads del último mes',
                    ]])
                    : "**2 leads** llegaron y se ganó 1. Responde más rápido por Instagram.";
            });
        });

        $r = $this->postJson('/api/asistente', ['mensaje' => 'Dime cuántos clientes llegaron el último mes, cuáles cotizaron y cómo mejorar'])
            ->assertOk();

        $tarjeta = $r->json('informe');
        $this->assertSame('Leads del último mes', $tarjeta['nombre']);
        $this->assertSame(2, $tarjeta['total_filas']);
        $this->assertStringEndsWith('/xlsx', $tarjeta['urls']['xlsx']);

        $informe = Informe::findOrFail($tarjeta['id']);
        $this->assertSame('ia', $informe->origen);
        $this->assertSame(['canal'], $informe->agrupar);
        $this->assertStringContainsString('Responde más rápido', $informe->analisis);
        $this->assertStringContainsString('cómo mejorar', $informe->pregunta);

        // La redacción vio los totales, no los teléfonos.
        $this->assertStringNotContainsString('3001234567', $prompts[1]);
        $this->assertStringContainsString('Leads que llegaron', $prompts[1]);

        // Y la tarjeta sigue ahí al recargar el chat.
        $this->assertSame($informe->id, MensajeAsistente::where('rol', 'asistente')->latest('id')->first()->informe['id']);
    }

    // ─── Exportar ────────────────────────────────────────────────────────────

    private function informeDeVentas(): Informe
    {
        $cliente = Cliente::create(['tipo' => 'empresa', 'nombre' => '=HYPERLINK("http://malo")', 'tipo_identificacion' => 'NIT']);
        Cotizacion::create(['cliente_id' => $cliente->id, 'moneda' => 'COP', 'tasa_cambio' => 1, 'estado' => 'aprobada', 'responsable_id' => $this->admin->id, 'total' => 1234567.5]);

        return Informe::create([
            'nombre' => 'Ventas del mes', 'fuente' => 'ventas', 'campos' => ['numero', 'cliente', 'total', 'aprobada'],
            'filtros' => ['periodo' => 'ultimos_30'], 'agrupar' => ['vendedor'], 'publico' => false,
            'created_by' => $this->admin->id, 'analisis' => 'Vendimos bien.', 'origen' => 'ia',
        ]);
    }

    public function test_el_csv_lo_abre_excel_en_espanol_y_no_ejecuta_formulas(): void
    {
        $informe = $this->informeDeVentas();

        $csv = $this->get("/informes/{$informe->id}/csv")->assertOk()->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Número;Cliente;Total;Aprobada', $csv);
        $this->assertStringContainsString('1234567,5', $csv);          // número con coma decimal, sin miles
        $this->assertStringContainsString("'=HYPERLINK", $csv);         // la fórmula queda como texto
        $this->assertStringContainsString(';Sí', $csv);

        $resumen = $this->get("/informes/{$informe->id}/csv?tabla=vendedor")->streamedContent();
        $this->assertStringContainsString('Ana Pérez', $resumen);
        $this->assertStringContainsString('Total;1', $resumen);
    }

    public function test_el_excel_trae_resumen_analisis_detalle_y_una_hoja_por_resumen(): void
    {
        $informe = $this->informeDeVentas();

        $r = $this->get("/informes/{$informe->id}/xlsx")->assertOk();
        $this->assertStringContainsString('spreadsheetml', $r->headers->get('Content-Type'));

        $ruta = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($ruta, $r->getContent());
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($ruta) === true);

        $libro = $zip->getFromName('xl/workbook.xml');
        foreach (['Resumen', 'Análisis', 'Detalle', 'Por vendedor'] as $hoja) {
            $this->assertStringContainsString("name=\"{$hoja}\"", $libro);
        }
        // El total es un número de verdad, no un texto con puntos.
        $this->assertStringContainsString('<v>1234567.5</v>', $zip->getFromName('xl/worksheets/sheet3.xml'));
        $zip->close();
        @unlink($ruta);
    }

    public function test_la_pantalla_del_informe_trae_cifras_y_resumenes(): void
    {
        $informe = $this->informeDeVentas();

        $this->postJson("/informes/{$informe->id}/ejecutar")->assertOk()
            ->assertJsonPath('motor', true)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('resumenes.0.dimension', 'vendedor');

        $this->get("/informes/{$informe->id}/pdf")->assertOk();
    }

    public function test_un_informe_de_los_de_siempre_se_sigue_exportando(): void
    {
        $informe = Informe::create([
            'nombre' => 'Cotizaciones', 'fuente' => 'cotizaciones', 'campos' => ['numero', 'total'],
            'filtros' => [], 'publico' => false, 'created_by' => $this->admin->id,
        ]);

        $csv = $this->get("/informes/{$informe->id}/csv")->assertOk()->streamedContent();
        $this->assertStringContainsString('Número;Total', $csv);
        $this->get("/informes/{$informe->id}/xlsx")->assertOk();
    }

    public function test_se_arma_a_mano_un_informe_del_motor(): void
    {
        $this->post('/informes', [
            'nombre' => 'Inventario bajo', 'fuente' => 'inventario', 'campos' => ['producto', 'existencia'],
            'filtros' => ['periodo' => 'este_mes', 'estado' => 'bajo_minimo'], 'agrupar' => ['categoria'],
        ])->assertRedirect();

        $informe = Informe::where('nombre', 'Inventario bajo')->firstOrFail();
        $this->assertSame(['categoria'], $informe->agrupar);
        $this->assertSame('manual', $informe->origen);
    }
}
