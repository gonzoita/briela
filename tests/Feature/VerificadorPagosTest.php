<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\CuentaBancaria;
use App\Models\MovimientoBancario;
use App\Models\Op;
use App\Models\OpCuota;
use App\Models\OpPago;
use App\Models\User;
use App\Services\VerificadorPagosService;
use App\Support\Modulos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * El verificador de pagos: confirma con el banco que el dinero de una cuota llegó.
 *
 * Lo que se concilia solo es lo que no deja duda. En montos altos, equivocarse en silencio es peor
 * que preguntar, así que ante dos explicaciones posibles la prueba exige que pregunte.
 */
class VerificadorPagosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private CuentaBancaria $cuenta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin  = User::factory()->create(['rol' => 'administrador']);
        $this->cuenta = CuentaBancaria::create(['nombre' => 'Bancolombia ahorros', 'banco' => 'Bancolombia', 'ultimos_digitos' => '4821']);
    }

    private function servicio(): VerificadorPagosService
    {
        return app(VerificadorPagosService::class);
    }

    private function cliente(string $nombre): Cliente
    {
        return Cliente::create(['tipo' => 'empresa', 'nombre' => $nombre, 'tipo_identificacion' => 'NIT']);
    }

    /** Una orden con una cuota por cada valor dado. @param list<float> $valores */
    private function op(string $numero, string $cliente, array $valores): Op
    {
        $op = Op::create([
            'numero' => $numero, 'estado' => 'confirmada', 'cliente_id' => $this->cliente($cliente)->id,
            'responsable_id' => $this->admin->id, 'fecha_creacion' => now(), 'fecha_entrega_estimada' => now()->addDays(10),
        ]);

        foreach ($valores as $i => $valor) {
            OpCuota::create([
                'op_id' => $op->id, 'numero_cuota' => $i + 1, 'concepto' => 'Cuota '.($i + 1),
                'valor' => $valor, 'estado' => 'pendiente', 'valor_pagado' => 0,
            ]);
        }

        return $op;
    }

    /** @return array{fecha: string, valor: float, descripcion: string, referencia: ?string, contraparte: ?string, id_externo: ?string} */
    private function abono(float $valor, string $descripcion = 'TRANSFERENCIA', ?string $referencia = null, string $fecha = '2026-10-11'): array
    {
        return ['fecha' => $fecha, 'valor' => $valor, 'descripcion' => $descripcion, 'referencia' => $referencia, 'contraparte' => null, 'id_externo' => null];
    }

    private function importar(array ...$abonos): array
    {
        return $this->servicio()->importar($this->cuenta, $abonos, 'extracto', $this->admin->id);
    }

    // ─── El emparejamiento ───────────────────────────────────────────────────

    public function test_un_abono_que_nombra_la_orden_y_coincide_con_la_cuota_se_concilia_solo(): void
    {
        $op = $this->op('OP-2026-001', 'Muebles del Valle SAS', [5000000, 5000000]);

        $r = $this->importar($this->abono(5000000, 'TRANSF DE MUEBLES DEL VALLE', 'OP-2026-001'));

        $this->assertSame(1, $r['conciliados']);

        $mov = MovimientoBancario::firstOrFail();
        $this->assertSame('conciliado', $mov->estado);
        $this->assertTrue($mov->automatico);

        $pago = OpPago::firstOrFail();
        $this->assertSame($op->id, $pago->op_id);
        $this->assertNotNull($pago->verificado_at);
        $this->assertSame('transferencia', $pago->medio_pago);
        $this->assertStringStartsWith('REC-', $pago->numero_recibo);

        $cuotas = $op->cuotas()->get();
        $this->assertSame('pagado', $cuotas[0]->estado);    // las cuotas iguales se pagan en orden
        $this->assertSame('pendiente', $cuotas[1]->estado);
    }

    public function test_el_valor_exacto_con_el_cliente_en_la_descripcion_se_concilia_solo(): void
    {
        $this->op('OP-2026-001', 'Muebles del Valle SAS', [7300000]);
        $this->op('OP-2026-002', 'Puertas del Norte SAS', [7300000]); // mismo valor, otro cliente

        $r = $this->importar($this->abono(7300000, 'ABONO PUERTAS DEL NORTE'));

        $this->assertSame(1, $r['conciliados']);
        $this->assertSame('OP-2026-002', OpPago::firstOrFail()->op->numero);
    }

    public function test_el_mismo_valor_de_dos_clientes_sin_nombre_se_pregunta_no_se_adivina(): void
    {
        $this->op('OP-2026-001', 'Muebles del Valle SAS', [7300000]);
        $this->op('OP-2026-002', 'Puertas del Norte SAS', [7300000]);

        $r = $this->importar($this->abono(7300000, 'TRANSFERENCIA EN LINEA'));

        $this->assertSame(0, $r['conciliados']);
        $this->assertSame(1, $r['dudosos']);
        $this->assertSame(0, OpPago::count());
        $this->assertSame('dudoso', MovimientoBancario::firstOrFail()->estado);
    }

    public function test_un_valor_que_coincide_sin_que_nadie_lo_reclame_se_pregunta(): void
    {
        $this->op('OP-2026-001', 'Muebles del Valle SAS', [7300000]);

        $r = $this->importar($this->abono(7300000, 'CONSIGNACION'));

        $this->assertSame(1, $r['dudosos']);
        $this->assertSame(0, OpPago::count());
    }

    public function test_dinero_que_no_coincide_con_nada_queda_visible_sin_dueno(): void
    {
        $this->op('OP-2026-001', 'Muebles del Valle SAS', [7300000]);

        $r = $this->importar($this->abono(1234567, 'ABONO MISTERIOSO'));

        $this->assertSame(1, $r['pendientes']);
        $this->assertSame('pendiente', MovimientoBancario::firstOrFail()->estado);
    }

    public function test_nombrar_la_orden_con_otro_valor_se_pregunta_con_las_cuotas_de_esa_orden(): void
    {
        $this->op('OP-2026-001', 'Muebles del Valle SAS', [5000000, 5000000]);

        $r = $this->importar($this->abono(3000000, 'ABONO', 'OP-2026-001'));  // un pago parcial

        $this->assertSame(1, $r['dudosos']);
        $this->assertCount(2, $this->servicio()->candidatas(MovimientoBancario::firstOrFail()));
    }

    public function test_op_2026_001_no_se_confunde_con_op_2026_0012(): void
    {
        $this->op('OP-2026-0012', 'Otro Cliente SAS', [5000000]);

        // Menciona la 001, que no existe entre las cuotas abiertas: no puede caer en la 0012.
        $r = $this->importar($this->abono(5000000, 'PAGO', 'OP-2026-001'));

        $this->assertSame(0, $r['conciliados']);
    }

    public function test_reconoce_la_orden_aunque_el_banco_la_escriba_distinto(): void
    {
        $this->op('OP-2026-001', 'Muebles del Valle SAS', [5000000]);

        $this->assertSame(1, $this->importar($this->abono(5000000, 'PAGO OP 2026 001'))['conciliados']);
    }

    // ─── No contar dos veces ─────────────────────────────────────────────────

    public function test_subir_dos_veces_el_mismo_extracto_no_duplica_nada(): void
    {
        $this->op('OP-2026-001', 'Muebles del Valle SAS', [5000000]);
        $fila = $this->abono(5000000, 'PAGO', 'OP-2026-001');

        $this->importar($fila);
        $segunda = $this->importar($fila);

        $this->assertSame(1, $segunda['repetidos']);
        $this->assertSame(0, $segunda['nuevos']);
        $this->assertSame(1, MovimientoBancario::count());
        $this->assertSame(1, OpPago::count());
    }

    public function test_dos_pagos_identicos_el_mismo_dia_son_dos_pagos_no_uno_repetido(): void
    {
        $r = $this->importar($this->abono(1000000, 'PAGO'), $this->abono(1000000, 'PAGO'));

        $this->assertSame(2, $r['nuevos']);

        // Y al subir otra vez el mismo archivo, siguen siendo dos y no se cuentan de nuevo.
        $this->assertSame(2, $this->importar($this->abono(1000000, 'PAGO'), $this->abono(1000000, 'PAGO'))['repetidos']);
        $this->assertSame(2, MovimientoBancario::count());
    }

    public function test_un_extracto_que_se_traslapa_con_el_anterior_solo_suma_lo_nuevo(): void
    {
        $this->importar($this->abono(100000, 'A', null, '2026-10-01'), $this->abono(200000, 'B', null, '2026-10-02'));
        $r = $this->importar($this->abono(200000, 'B', null, '2026-10-02'), $this->abono(300000, 'C', null, '2026-10-03'));

        $this->assertSame(1, $r['repetidos']);
        $this->assertSame(1, $r['nuevos']);
    }

    // ─── Asignar, deshacer, ignorar ──────────────────────────────────────────

    public function test_asignar_a_mano_registra_el_pago_verificado_y_no_se_aplica_dos_veces(): void
    {
        $op = $this->op('OP-2026-001', 'Muebles del Valle SAS', [7300000]);
        $this->importar($this->abono(7300000, 'CONSIGNACION'));
        $mov = MovimientoBancario::firstOrFail();

        $this->actingAs($this->admin)->post("/financiero/verificador-pagos/movimientos/{$mov->id}/asignar", ['cuota_id' => $op->cuotas()->first()->id])
            ->assertSessionHas('success');

        $this->assertSame('conciliado', $mov->fresh()->estado);
        $this->assertFalse($mov->fresh()->automatico);
        $this->assertSame($this->admin->id, $mov->fresh()->conciliado_por);
        $this->assertNotNull(OpPago::firstOrFail()->verificado_at);

        $this->actingAs($this->admin)->post("/financiero/verificador-pagos/movimientos/{$mov->id}/asignar", ['cuota_id' => $op->cuotas()->first()->id])
            ->assertSessionHas('error');
        $this->assertSame(1, OpPago::count());
    }

    public function test_deshacer_devuelve_la_cuota_borra_el_pago_y_no_se_vuelve_a_emparejar_solo(): void
    {
        $op = $this->op('OP-2026-001', 'Muebles del Valle SAS', [5000000]);
        $this->importar($this->abono(5000000, 'PAGO', 'OP-2026-001'));
        $mov = MovimientoBancario::firstOrFail();

        $this->actingAs($this->admin)->post("/financiero/verificador-pagos/movimientos/{$mov->id}/deshacer")->assertSessionHas('success');

        $cuota = $op->cuotas()->first();
        $this->assertSame('pendiente', $cuota->estado);
        $this->assertEquals(0, $cuota->valor_pagado);
        $this->assertSame(0, OpPago::count());
        $this->assertSame('pendiente', $mov->fresh()->estado);

        // Subir el mismo extracto otra vez no lo vuelve a conciliar: era un error, y no se insiste.
        $this->importar($this->abono(5000000, 'PAGO', 'OP-2026-001'));
        $this->assertSame(0, OpPago::count());
    }

    public function test_deshacer_pide_el_permiso_de_borrar_pagos(): void
    {
        $this->op('OP-2026-001', 'Muebles del Valle SAS', [5000000]);
        $this->importar($this->abono(5000000, 'PAGO', 'OP-2026-001'));

        // El vendedor ni siquiera ve el verificador.
        $vendedor = User::factory()->create(['rol' => 'vendedor']);

        $this->actingAs($vendedor)->post('/financiero/verificador-pagos/movimientos/'.MovimientoBancario::first()->id.'/deshacer')->assertForbidden();
        $this->assertSame(1, OpPago::count());
    }

    public function test_si_borran_el_pago_desde_la_cartera_el_abono_vuelve_a_la_bandeja(): void
    {
        $this->op('OP-2026-001', 'Muebles del Valle SAS', [5000000]);
        $this->importar($this->abono(5000000, 'PAGO', 'OP-2026-001'));
        $pago = OpPago::firstOrFail();

        $this->actingAs($this->admin)->delete("/financiero/pagos/{$pago->id}")->assertSuccessful();

        $mov = MovimientoBancario::firstOrFail();
        $this->assertSame('pendiente', $mov->estado);
        $this->assertNull($mov->op_pago_id);
    }

    public function test_ignorar_y_volver_a_la_bandeja(): void
    {
        $this->importar($this->abono(999999, 'ALGO'));
        $mov = MovimientoBancario::firstOrFail();

        $this->actingAs($this->admin)->post("/financiero/verificador-pagos/movimientos/{$mov->id}/ignorar", ['nota' => 'Es un traslado propio']);
        $this->assertSame('ignorado', $mov->fresh()->estado);

        $this->actingAs($this->admin)->post("/financiero/verificador-pagos/movimientos/{$mov->id}/restaurar");
        $this->assertSame('pendiente', $mov->fresh()->estado);
    }

    // ─── Subir un extracto por la pantalla ───────────────────────────────────

    public function test_sube_un_csv_del_banco_y_concilia(): void
    {
        $this->op('OP-2026-001', 'Muebles del Valle SAS', [5000000]);

        $csv = "Fecha;Descripción;Débito;Crédito;Saldo\n11/10/2026;PAGO OP-2026-001;;5.000.000;9.000.000\n12/10/2026;PAGO A PROVEEDOR;400.000;;8.600.000\n";

        $this->actingAs($this->admin)->postJson('/financiero/verificador-pagos/importar', [
            'cuenta_id' => $this->cuenta->id,
            'archivo'   => UploadedFile::fake()->createWithContent('extracto.csv', $csv),
        ])->assertOk()->assertJson(['nuevos' => 1, 'conciliados' => 1, 'salidas' => 1]);
    }

    public function test_si_no_reconoce_las_columnas_devuelve_la_vista_previa_y_acepta_que_se_las_indiquen(): void
    {
        $this->op('OP-2026-001', 'Muebles del Valle SAS', [5000000]);

        $csv = "dia;texto;plata\n11/10/2026;PAGO OP-2026-001;5.000.000\n";
        $archivo = fn () => UploadedFile::fake()->createWithContent('raro.csv', $csv);

        $this->actingAs($this->admin)->postJson('/financiero/verificador-pagos/importar', ['cuenta_id' => $this->cuenta->id, 'archivo' => $archivo()])
            ->assertStatus(422)
            ->assertJsonPath('vista_previa.0.0', 'dia');

        $this->actingAs($this->admin)->postJson('/financiero/verificador-pagos/importar', [
            'cuenta_id' => $this->cuenta->id, 'archivo' => $archivo(), 'columnas' => ['fecha' => 0, 'valor' => 2, 'descripcion' => 1],
        ])->assertOk()->assertJson(['conciliados' => 1]);
    }

    public function test_un_pdf_se_rechaza_con_una_explicacion(): void
    {
        $this->actingAs($this->admin)->postJson('/financiero/verificador-pagos/importar', [
            'cuenta_id' => $this->cuenta->id, 'archivo' => UploadedFile::fake()->createWithContent('extracto.pdf', '%PDF-1.4'),
        ])->assertStatus(422)->assertJsonFragment(['message' => 'Un PDF no se puede leer con seguridad. Descarga el extracto como Excel o CSV desde la página del banco.']);
    }

    // ─── Pantalla, permisos y módulo ─────────────────────────────────────────

    public function test_la_pantalla_lista_lo_que_hay_por_revisar_y_ordena(): void
    {
        $this->importar($this->abono(111111, 'A'), $this->abono(999999, 'B'));

        $this->actingAs($this->admin)->get('/financiero/verificador-pagos?orden=valor&dir=desc')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Financiero/VerificadorPagos')
                ->where('resumen.por_revisar', 2)
                ->where('movimientos.data.0.valor', 999999)
                ->where('orden.campo', 'valor'));
    }

    public function test_un_campo_de_orden_inventado_se_ignora(): void
    {
        $this->importar($this->abono(111111, 'A'));

        $this->actingAs($this->admin)->get('/financiero/verificador-pagos?orden=valor;DROP TABLE users')->assertOk();
    }

    public function test_sin_permiso_no_se_ve_ni_se_sube_nada(): void
    {
        $vendedor = User::factory()->create(['rol' => 'vendedor']);

        $this->actingAs($vendedor)->get('/financiero/verificador-pagos')->assertForbidden();
        $this->actingAs($vendedor)->postJson('/financiero/verificador-pagos/importar', ['cuenta_id' => $this->cuenta->id])->assertForbidden();
    }

    public function test_con_el_modulo_apagado_se_guarda_pero_no_se_concilia_y_las_rutas_se_cierran(): void
    {
        $this->op('OP-2026-001', 'Muebles del Valle SAS', [5000000]);
        Modulos::guardar(['verificador_pagos'], 'test');

        $r = $this->importar($this->abono(5000000, 'PAGO', 'OP-2026-001'));

        $this->assertSame(0, $r['conciliados']);
        $this->assertSame(0, OpPago::count());

        $this->actingAs($this->admin)->get('/financiero/verificador-pagos')->assertRedirect('/dashboard');
    }

    public function test_los_cuentas_solo_guardan_los_ultimos_digitos(): void
    {
        $this->actingAs($this->admin)->post('/financiero/verificador-pagos/cuentas', [
            'nombre' => 'Davivienda corriente', 'banco' => 'Davivienda', 'ultimos_digitos' => '9910',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('cuentas_bancarias', ['nombre' => 'Davivienda corriente', 'ultimos_digitos' => '9910']);
    }

    public function test_la_ficha_de_pagos_de_la_orden_dice_cuales_confirmo_el_banco(): void
    {
        $op = $this->op('OP-2026-001', 'Muebles del Valle SAS', [5000000]);
        $this->importar($this->abono(5000000, 'PAGO', 'OP-2026-001'));

        $this->actingAs($this->admin)->getJson("/ops/{$op->id}/cuotas")
            ->assertOk()
            ->assertJsonPath('cuotas.0.pagos.0.verificado_at', fn ($v) => $v !== null);
    }
}
