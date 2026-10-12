<?php

namespace Tests\Unit;

use App\Services\Banco\LectorExtracto;
use App\Services\Banco\NormalizadorExtracto;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

/**
 * Leer un extracto sin inventar nada: cada banco escribe distinto el dinero, las fechas y los
 * nombres de las columnas, y un valor mal leído es dinero mal conciliado.
 */
class NormalizadorExtractoTest extends TestCase
{
    private NormalizadorExtracto $n;

    protected function setUp(): void
    {
        parent::setUp();
        $this->n = new NormalizadorExtracto();
    }

    #[DataProvider('dineros')]
    public function test_lee_el_dinero_como_lo_escribe_cada_banco(string $texto, ?float $esperado): void
    {
        $this->assertSame($esperado, $this->n->dinero($texto));
    }

    public static function dineros(): array
    {
        return [
            'colombiano con decimales'  => ['1.234.567,89', 1234567.89],
            'estadounidense'            => ['$ 1,234,567.89', 1234567.89],
            'miles con punto'           => ['250.000', 250000.0],   // doscientos cincuenta mil, no doscientos cincuenta
            'miles con coma'            => ['250,000', 250000.0],
            'sin separadores'           => ['2500000', 2500000.0],
            'solo decimales'            => ['1500,50', 1500.5],
            'negativo'                  => ['-250.000', -250000.0],
            'entre paréntesis'          => ['(1.500)', -1500.0],
            'con la sigla'              => ['COP 3.000.000', 3000000.0],
            'vacío'                     => ['', null],
            'texto'                     => ['TOTAL', null],
        ];
    }

    #[DataProvider('fechas')]
    public function test_lee_las_fechas(string $texto, ?string $esperada): void
    {
        $this->assertSame($esperada, $this->n->fecha($texto));
    }

    public static function fechas(): array
    {
        return [
            'día primero'      => ['04/05/2026', '2026-05-04'],   // 4 de mayo, no 5 de abril
            'ISO'              => ['2026-10-11', '2026-10-11'],
            'con guiones'      => ['11-10-2026', '2026-10-11'],
            'año de dos cifras' => ['11/10/26', '2026-10-11'],
            'con hora'         => ['2026-10-11 14:30:00', '2026-10-11'],
            'serial de Excel'  => ['46306', '2026-10-11'],
            'inexistente'      => ['31/02/2026', null],
            'texto'            => ['Saldo anterior', null],
            'vacía'            => ['', null],
        ];
    }

    public function test_reconoce_las_columnas_y_la_fila_de_encabezados(): void
    {
        $filas = [
            ['Extracto de cuenta de ahorros', '', '', ''],
            ['Fecha', 'Descripción', 'Valor', 'Saldo'],
            ['11/10/2026', 'TRANSFERENCIA DE CLIENTE SAS OP-2026-001', '5.000.000,00', '9.000.000,00'],
        ];

        $d = $this->n->detectar($filas);

        $this->assertSame(1, $d['encabezado']);
        $this->assertEquals(['fecha' => 0, 'descripcion' => 1, 'valor' => 2], $d['columnas']); // «Saldo» nunca es el valor
    }

    public function test_el_valor_no_se_confunde_con_la_fecha_valor(): void
    {
        $c = $this->n->columnas(['Fecha movimiento', 'Fecha valor', 'Concepto', 'Importe']);

        $this->assertSame(0, $c['fecha']);
        $this->assertSame(3, $c['valor']);
    }

    public function test_el_id_de_transaccion_no_se_toma_por_la_descripcion(): void
    {
        $c = $this->n->columnas(['Fecha', 'ID transacción', 'Detalle', 'Monto']);

        $this->assertSame(1, $c['id']);
        $this->assertSame(2, $c['descripcion']);
    }

    public function test_con_columnas_de_credito_y_debito_solo_pasa_lo_que_entra(): void
    {
        $filas = [
            ['Fecha', 'Descripción', 'Débito', 'Crédito'],
            ['01/10/2026', 'Pago cliente', '', '1.000.000'],
            ['02/10/2026', 'Pago a proveedor', '400.000', ''],
            ['03/10/2026', 'Pago cliente 2', '', '2.500.000'],
        ];

        $d = $this->n->detectar($filas);
        $r = $this->n->normalizar($filas, $d['encabezado'], $d['columnas']);

        $this->assertSame([1000000.0, 2500000.0], array_column($r['abonos'], 'valor'));
        $this->assertSame(1, $r['salidas']);
    }

    public function test_con_una_sola_columna_de_valor_lo_negativo_es_una_salida(): void
    {
        $filas = [
            ['Fecha', 'Descripción', 'Valor'],
            ['01/10/2026', 'Abono', '300.000'],
            ['02/10/2026', 'Retiro', '-120.000'],
            ['Total', '', '180.000'],
        ];

        $d = $this->n->detectar($filas);
        $r = $this->n->normalizar($filas, $d['encabezado'], $d['columnas']);

        $this->assertCount(1, $r['abonos']);
        $this->assertSame(1, $r['salidas']);
        $this->assertSame(1, $r['invalidas']); // la fila de «Total» no es un movimiento
    }

    public function test_si_no_reconoce_las_columnas_lo_dice_y_no_inventa(): void
    {
        $this->expectException(RuntimeException::class);

        $this->n->detectar([['a', 'b', 'c'], ['1', '2', '3']]);
    }

    // ─── Lector ──────────────────────────────────────────────────────────────

    public function test_lee_un_csv_con_punto_y_coma_y_codificacion_antigua(): void
    {
        $ruta = tempnam(sys_get_temp_dir(), 'ext').'.csv';
        file_put_contents($ruta, mb_convert_encoding("Fecha;Descripción;Valor\n11/10/2026;Transferencia;1.500.000\n", 'ISO-8859-1', 'UTF-8'));

        $filas = (new LectorExtracto())->leer($ruta, 'csv');
        unlink($ruta);

        $this->assertSame(['Fecha', 'Descripción', 'Valor'], $filas[0]); // la tilde sobrevive
        $this->assertSame('1.500.000', $filas[1][2]);
    }

    public function test_lee_un_xlsx_con_textos_compartidos_y_celdas_vacias(): void
    {
        $ruta = sys_get_temp_dir().'/ext'.uniqid().'.xlsx';
        $zip  = new ZipArchive();
        $zip->open($ruta, ZipArchive::CREATE);
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Fecha</t></si><si><t>Valor</t></si><si><t>Pago OP-2026-001</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="C1" t="s"><v>1</v></c></row>'
            .'<row r="2"><c r="A2"><v>46306</v></c><c r="B2" t="s"><v>2</v></c><c r="C2"><v>1500000</v></c></row>'
            .'</sheetData></worksheet>');
        $zip->close();

        $filas = (new LectorExtracto())->leer($ruta, 'xlsx');
        unlink($ruta);

        $this->assertSame(['Fecha', '', 'Valor'], $filas[0]);   // la columna B está vacía: no se corre nada
        $this->assertSame(['46306', 'Pago OP-2026-001', '1500000'], $filas[1]);
    }

    public function test_el_pdf_y_el_xls_viejo_se_rechazan_con_una_explicacion(): void
    {
        foreach (['pdf', 'xls', 'docx'] as $ext) {
            try {
                (new LectorExtracto())->leer('/no/importa', $ext);
                $this->fail("{$ext} debió rechazarse");
            } catch (RuntimeException $e) {
                $this->assertNotEmpty($e->getMessage());
            }
        }
    }
}
