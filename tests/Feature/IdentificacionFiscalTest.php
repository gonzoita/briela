<?php

namespace Tests\Feature;

use App\Models\Configuracion;
use App\Models\Sede;
use App\Models\User;
use App\Services\PdfVariablesEngine;
use App\Support\Paises;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La identificación fiscal en cualquier país: los paquetes nuevos, la identificación libre del
 * exterior, el dígito de verificación de Chile y la empresa de otro país en el perfil fiscal.
 */
class IdentificacionFiscalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['rol' => 'administrador']));
    }

    private function sedePrincipal(string $pais = 'CO'): Sede
    {
        Sede::query()->update(['es_principal' => false]);
        $sede = Sede::create([
            'nombre' => 'Principal', 'codigo' => 'PRI' . random_int(100, 999), 'es_principal' => true,
            'tiene_ventas' => true, 'activa' => true, 'pais' => $pais,
        ]);
        Paises::olvidar();

        return $sede;
    }

    /** Lo mínimo que manda la pantalla del perfil fiscal. */
    private function perfil(array $empresa, array $extra = []): array
    {
        return $extra + [
            'empresa' => $empresa + ['nombre' => 'Empresa de prueba', 'ciudad' => '', 'direccion' => '', 'telefono' => '', 'email' => ''],
        ];
    }

    public function test_los_paquetes_nuevos_traen_sus_identificaciones_y_no_estan_revisados(): void
    {
        foreach (['MX' => 'RFC', 'EC' => 'RUC', 'PE' => 'RUC', 'CL' => 'RUT', 'US' => 'EIN'] as $iso => $codigo) {
            $perfil = Paises::perfil($iso);

            $this->assertTrue($perfil['con_paquete'], "{$iso} tiene paquete");
            $this->assertFalse($perfil['revisado'], "{$iso} no lo ha revisado un contador");
            $this->assertContains($codigo, array_column($perfil['identificaciones'], 'codigo'));
        }

        $this->assertSame('IGV', Paises::nombreImpuesto('PE'));
        $this->assertSame([16.0, 8.0, 0.0], Paises::tarifas('MX'));
    }

    public function test_todo_pais_acepta_una_identificacion_del_exterior_en_formato_libre(): void
    {
        foreach (['CO', 'MX', 'US', 'DE'] as $iso) {
            $this->assertContains('EXT', Paises::codigosIdentificacion($iso), "{$iso} acepta EXT");
        }

        $this->assertNull(Paises::avisoIdentificacion('CO', 'EXT', 'DE 123 456 789'), 'el formato libre no se revisa');
    }

    public function test_el_formato_se_revisa_con_el_paquete_de_cada_pais(): void
    {
        $this->assertNull(Paises::avisoIdentificacion('MX', 'RFC', 'ABC010203XY1'));
        $this->assertNotNull(Paises::avisoIdentificacion('MX', 'RFC', '12345'));
        $this->assertNull(Paises::avisoIdentificacion('PE', 'RUC', '20123456789'));
        $this->assertNotNull(Paises::avisoIdentificacion('PE', 'RUC', '30123456789'));
        $this->assertNull(Paises::avisoIdentificacion('US', 'EIN', '12-3456789'));
        $this->assertNull(Paises::avisoIdentificacion('EC', 'RUC', '1790012345001'));
    }

    public function test_el_rut_de_chile_calcula_su_digito_con_k_y_cero(): void
    {
        $this->assertSame('5', Paises::calcularDv('CL', 'RUT', '12345678'));
        $this->assertSame('K', Paises::calcularDv('CL', 'RUT', '10000013'));
        $this->assertNull(Paises::avisoIdentificacion('CL', 'RUT', '12345678', '5'));
        $this->assertStringContainsString('debería ser 5', (string) Paises::avisoIdentificacion('CL', 'RUT', '12345678', '9'));
        $this->assertNull(Paises::calcularDv('MX', 'RFC', 'ABC010203XY1'), 'un RFC no lleva dígito aparte');
    }

    public function test_la_empresa_puede_ser_de_otro_pais_y_su_identificacion_sale_en_los_pdf(): void
    {
        $sede = $this->sedePrincipal('CO');

        $this->post('/configuracion/fiscal', $this->perfil(['pais' => 'MX', 'tipo_identificacion' => 'RFC', 'nit' => 'ABC010203XY1']))
            ->assertSessionHasNoErrors()->assertSessionHas('success', 'Perfil fiscal guardado.');

        $this->assertSame('MX', $sede->fresh()->pais, 'el país de la empresa es el de su sede principal');
        Paises::olvidar();
        $this->assertSame(['tipo' => 'RFC', 'nombre' => 'RFC', 'numero' => 'ABC010203XY1', 'texto' => 'RFC: ABC010203XY1'], Paises::identificacionEmpresa());
        $empresa = (new \ReflectionMethod(PdfVariablesEngine::class, 'datosEmpresa'))->invoke(null);
        $this->assertSame('RFC', $empresa['tipo_identificacion'], 'los PDF dicen RFC, no NIT');
    }

    public function test_un_tipo_de_otro_pais_se_rechaza_y_un_formato_raro_solo_avisa(): void
    {
        $this->sedePrincipal('CO');

        $this->post('/configuracion/fiscal', $this->perfil(['pais' => 'CO', 'tipo_identificacion' => 'RFC', 'nit' => 'X']))
            ->assertSessionHasErrors('empresa.tipo_identificacion');

        $this->post('/configuracion/fiscal', $this->perfil(['pais' => 'CO', 'tipo_identificacion' => 'NIT', 'nit' => '900123456-9']))
            ->assertSessionHasNoErrors();
        $this->assertStringContainsString('dígito de verificación', (string) session('success'));
        $this->assertSame('900123456-9', Configuracion::get('empresa_nit'), 'el aviso no impide guardar');
    }

    public function test_sin_retenciones_en_su_pais_no_se_pisan_las_reglas_guardadas(): void
    {
        $this->sedePrincipal('CO');
        Configuracion::set('fiscal_uvt', '49799');

        $this->post('/configuracion/fiscal', $this->perfil(['pais' => 'US', 'tipo_identificacion' => 'EIN', 'nit' => '12-3456789'], ['uvt' => 1]))
            ->assertSessionHasNoErrors();

        $this->assertSame('49799', Configuracion::get('fiscal_uvt'));
    }

    public function test_el_cliente_rapido_pide_los_tipos_del_pais_de_la_casa(): void
    {
        $this->sedePrincipal('PE');

        $r = $this->getJson('/api/clientes/identificaciones')->assertOk();

        $this->assertSame('PE', $r->json('pais'));
        $this->assertContains('RUC', array_column($r->json('identificaciones'), 'codigo'));
        $this->assertNotContains('NIT', array_column($r->json('identificaciones'), 'codigo'));
    }

    public function test_el_perfil_fiscal_abre_con_el_pais_y_sus_identificaciones(): void
    {
        $this->sedePrincipal('CL');

        $this->get('/configuracion/fiscal')->assertOk()->assertInertia(fn ($p) => $p
            ->where('empresa.pais', 'CL')
            ->where('empresa.tipo_identificacion', 'RUT')
            ->has('identificaciones_pais.CL')
            ->where('paises_con_retencion', ['CO']));
    }
}
