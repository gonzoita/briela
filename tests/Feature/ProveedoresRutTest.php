<?php

namespace Tests\Feature;

use App\Models\Proveedor;
use App\Models\User;
use App\Services\IA\LectorRutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;

/**
 * Cargar un proveedor desde su RUT.
 *
 * Lo que decide cómo se le compra está en el RUT: si es responsable de IVA, si es
 * autorretenedor, a qué actividad se dedica. La IA lee el documento; aquí se prueba lo que
 * Briela hace con lo leído, sin llamar a la IA.
 */
class ProveedoresRutTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'administrador']);
    }

    /** Un lector que devuelve lo que se le diga, en vez de llamar a la IA. */
    private function lectorQueLee(array $datos, array $avisos = []): void
    {
        $lector = Mockery::mock(LectorRutService::class);
        $lector->shouldReceive('leer')->andReturn(['datos' => $datos, 'avisos' => $avisos]);

        $this->app->instance(LectorRutService::class, $lector);
    }

    private function rut(): UploadedFile
    {
        return UploadedFile::fake()->create('rut.pdf', 20, 'application/pdf');
    }

    private function datosDeEmpresa(array $extra = []): array
    {
        return array_merge([
            'tipo'                       => 'empresa',
            'tipo_identificacion'        => 'NIT',
            'numero_identificacion'      => '900123456',
            'digito_verificacion'        => '8',
            'nombre'                     => 'Herrajes Del Norte S.A.S.',
            'apellido'                   => '',
            'email'                      => 'ventas@herrajes.test',
            'telefono'                   => '3001234567',
            'ciudad'                     => 'Bogotá',
            'direccion'                  => 'Cra 10 # 20-30',
            'actividad_economica'        => '2599',
            'responsabilidades_fiscales' => ['05', '07', '48'],
            'datos_rut'                  => ['razon_social' => 'HERRAJES DEL NORTE S.A.S.'],
        ], $extra);
    }

    // ─── Leer el RUT ─────────────────────────────────────────────────────────

    public function test_leer_el_rut_devuelve_los_datos_sin_guardar_nada(): void
    {
        $this->lectorQueLee($this->datosDeEmpresa(), ['Revisa el teléfono.']);

        $this->actingAs($this->admin())
            ->postJson('/compras/proveedores/leer-rut', ['archivo' => $this->rut()])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('datos.numero_identificacion', '900123456')
            ->assertJsonPath('datos.responsabilidades_fiscales', ['05', '07', '48'])
            ->assertJsonPath('avisos.0', 'Revisa el teléfono.')
            ->assertJsonPath('existente', null);

        $this->assertSame(0, Proveedor::count(), 'Leer no guarda: una persona lo revisa primero.');
    }

    public function test_avisa_si_ese_proveedor_ya_existe_por_su_numero(): void
    {
        Proveedor::create(['nombre' => 'Herrajes del Norte', 'nit' => '900123456-8', 'activo' => true]);
        $this->lectorQueLee($this->datosDeEmpresa());

        $this->actingAs($this->admin())
            ->postJson('/compras/proveedores/leer-rut', ['archivo' => $this->rut()])
            ->assertOk()
            ->assertJsonPath('existente.nombre', 'Herrajes del Norte');
    }

    public function test_el_numero_ya_registrado_con_los_campos_nuevos_tambien_cuenta(): void
    {
        Proveedor::create(['nombre' => 'Ya cargado por RUT', 'numero_identificacion' => '900123456', 'activo' => true]);
        $this->lectorQueLee($this->datosDeEmpresa());

        $this->actingAs($this->admin())
            ->postJson('/compras/proveedores/leer-rut', ['archivo' => $this->rut()])
            ->assertJsonPath('existente.nombre', 'Ya cargado por RUT');
    }

    public function test_excluir_evita_que_un_proveedor_se_avise_a_si_mismo(): void
    {
        $propio = Proveedor::create(['nombre' => 'Herrajes', 'numero_identificacion' => '900123456', 'activo' => true]);
        $this->lectorQueLee($this->datosDeEmpresa());

        $this->actingAs($this->admin())
            ->postJson('/compras/proveedores/leer-rut', ['archivo' => $this->rut(), 'excluir' => $propio->id])
            ->assertJsonPath('existente', null);
    }

    public function test_un_archivo_que_no_es_pdf_ni_foto_se_rechaza(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/compras/proveedores/leer-rut', ['archivo' => UploadedFile::fake()->create('rut.exe', 5, 'application/x-msdownload')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('archivo');
    }

    public function test_si_la_ia_no_puede_leerlo_responde_con_el_motivo(): void
    {
        $lector = Mockery::mock(LectorRutService::class);
        $lector->shouldReceive('leer')->andThrow(new \App\Exceptions\IaException('La foto está muy borrosa.'));
        $this->app->instance(LectorRutService::class, $lector);

        $this->actingAs($this->admin())
            ->postJson('/compras/proveedores/leer-rut', ['archivo' => $this->rut()])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('mensaje', 'La foto está muy borrosa.');
    }

    public function test_sin_sesion_o_sin_permiso_no_se_lee(): void
    {
        $this->postJson('/compras/proveedores/leer-rut', ['archivo' => $this->rut()])->assertUnauthorized();

        // Cada lectura es una llamada a la IA y cuesta: no la puede disparar cualquiera.
        $this->actingAs(User::factory()->create(['rol' => 'operario']))
            ->postJson('/compras/proveedores/leer-rut', ['archivo' => $this->rut()])
            ->assertForbidden();
    }

    // ─── Guardar lo leído ────────────────────────────────────────────────────

    public function test_guardar_conserva_los_datos_fiscales_y_arma_el_nit(): void
    {
        $this->actingAs($this->admin())->post('/compras/proveedores', [
            'nombre'                     => 'Herrajes del Norte S.A.S.',
            'tipo'                       => 'mixto',
            'tipo_persona'               => 'empresa',
            'tipo_identificacion'        => 'NIT',
            'numero_identificacion'      => '900123456',
            'digito_verificacion'        => '8',
            'actividad_economica'        => '2599',
            'responsabilidades_fiscales' => ['48', '05', '05'],
            'datos_rut'                  => ['razon_social' => 'HERRAJES'],
        ])->assertRedirect();

        $proveedor = Proveedor::firstOrFail();

        $this->assertSame('900123456-8', $proveedor->nit, 'El NIT de texto se arma solo si no se escribió uno.');
        $this->assertSame(['05', '48'], $proveedor->responsabilidades_fiscales, 'Sin repetidos y en orden.');
        $this->assertSame('2599', $proveedor->actividad_economica);
        $this->assertSame('HERRAJES', $proveedor->datos_rut['razon_social']);
    }

    public function test_un_nit_escrito_a_mano_no_se_pisa(): void
    {
        $this->actingAs($this->admin())->post('/compras/proveedores', [
            'nombre' => 'X', 'tipo' => 'mixto', 'nit' => '111.222.333-4',
            'numero_identificacion' => '900123456', 'digito_verificacion' => '8',
        ])->assertRedirect();

        $this->assertSame('111.222.333-4', Proveedor::firstOrFail()->nit);
    }

    public function test_editar_sin_mandar_el_rut_no_lo_borra(): void
    {
        $proveedor = Proveedor::create([
            'nombre' => 'X', 'activo' => true, 'datos_rut' => ['razon_social' => 'GUARDADO'],
            'responsabilidades_fiscales' => ['48'],
        ]);

        // La lista no manda `datos_rut` de vuelta: la ficha no lo tiene.
        $this->actingAs($this->admin())->put("/compras/proveedores/{$proveedor->id}", [
            'nombre' => 'X renombrado', 'tipo' => 'mixto',
        ])->assertRedirect();

        $this->assertSame('GUARDADO', $proveedor->fresh()->datos_rut['razon_social']);
    }

    public function test_la_lista_no_manda_el_texto_completo_del_rut(): void
    {
        Proveedor::create(['nombre' => 'X', 'activo' => true, 'datos_rut' => ['razon_social' => 'GUARDADO']]);

        $this->actingAs($this->admin())->get('/compras/proveedores')
            ->assertInertia(fn ($page) => $page
                ->component('Compras/Proveedores/Index')
                ->missing('proveedores.data.0.datos_rut')
                ->has('catalogo_fiscal'));
    }

    // ─── El IVA que dice el RUT ──────────────────────────────────────────────

    public function test_el_rut_decide_si_cobra_iva(): void
    {
        $this->assertTrue((new Proveedor(['responsabilidades_fiscales' => ['05', '48']]))->responsableDeIva());
        $this->assertFalse((new Proveedor(['responsabilidades_fiscales' => ['49']]))->responsableDeIva());
        $this->assertNull((new Proveedor(['responsabilidades_fiscales' => ['05']]))->responsableDeIva(), 'Sin marcar, no se sabe.');
        $this->assertNull((new Proveedor)->responsableDeIva());
    }

    public function test_solo_un_no_responsable_fija_el_iva_de_la_linea(): void
    {
        $this->assertSame(0.0, (new Proveedor(['responsabilidades_fiscales' => ['49']]))->ivaPorDefecto());

        // Un responsable no fija tarifa: depende del bien, y no se escribe en el código.
        $this->assertNull((new Proveedor(['responsabilidades_fiscales' => ['48']]))->ivaPorDefecto());
        $this->assertNull((new Proveedor)->ivaPorDefecto());
    }

    public function test_la_orden_de_compra_recibe_el_iva_de_cada_proveedor(): void
    {
        Proveedor::create(['nombre' => 'A No responsable', 'activo' => true, 'responsabilidades_fiscales' => ['49']]);
        Proveedor::create(['nombre' => 'B Responsable', 'activo' => true, 'responsabilidades_fiscales' => ['48']]);
        Proveedor::create(['nombre' => 'C Sin RUT', 'activo' => true]);

        $this->actingAs($this->admin())->get('/compras/ordenes/crear')
            ->assertInertia(fn ($page) => $page
                ->where('proveedores.0.responsable_iva', false)
                ->where('proveedores.0.iva_defecto', 0)
                ->where('proveedores.1.responsable_iva', true)
                ->where('proveedores.1.iva_defecto', null)
                ->where('proveedores.2.responsable_iva', null));
    }
}
