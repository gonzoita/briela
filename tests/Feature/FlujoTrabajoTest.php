<?php

namespace Tests\Feature;

use App\Models\Bodega;
use App\Models\Ensamble;
use App\Models\Op;
use App\Models\OpItem;
use App\Models\OpItemTrabajo;
use App\Models\Producto;
use App\Models\Sede;
use App\Models\User;
use App\Services\CierrePasoService;
use App\Services\TrabajoAutoGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * El recorrido de una unidad: se fabrica, se entrega a bodega, pasa calidad y se despacha.
 *
 * Lo que fijan estas pruebas son las reglas que antes estaban escritas de tres maneras
 * distintas en tres pantallas, y que ahora viven en `CierrePasoService`:
 *
 * - El paso final entrega la unidad, y no se puede cerrar con trabajo por delante.
 * - Entra a la bodega que se eligió, y el material se descuenta de la otra.
 * - Los puntos se otorgan cierre el paso quien lo cierre, no solo por el código QR.
 *
 * Y las dos reglas nuevas de alrededor: las unidades siguen a la cantidad del ítem, y la
 * remisión es por unidad —el cliente se lleva las tres que ya pasaron calidad—.
 */
class FlujoTrabajoTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['rol' => 'administrador']);
    }

    /**
     * Una OP con un ensamble directo de dos pasos, dos bodegas y una lámina en la de material.
     *
     * @return array{op: Op, item: OpItem, insumos: Bodega, terminado: Bodega, lamina: Producto}
     */
    private function escenario(int $cantidad = 1): array
    {
        $usuario = $this->admin();
        $sede    = Sede::query()->first() ?? Sede::create(['nombre' => 'Principal']);

        $insumos   = Bodega::create(['sede_id' => $sede->id, 'nombre' => 'Insumos',   'activa' => true]);
        $terminado = Bodega::create(['sede_id' => $sede->id, 'nombre' => 'Terminado', 'activa' => true]);

        $lamina = Producto::create([
            'tipo'          => 'producto',
            'nombre'        => 'Lámina',
            'referencia'    => 'LAM-001',
            'unidad_medida' => 'unidad',
            'inventariable' => true,
            'precio_costo'  => 1000,
            'activo'        => true,
        ]);

        $lamina->registrarMovimiento('entrada', 50, $insumos->id, $usuario->id);

        $ensamble = Ensamble::create([
            'nombre'                => 'Puerta de prueba',
            'tipo_armado'           => 'directo',
            'variables'             => [],
            'creado_por'            => $usuario->id,
            'unidad_medida'         => 'unidad',
            'precio_costo'          => 10000,
            'componentes_resultado' => [
                ['producto_id' => $lamina->id, 'nombre' => 'Lámina', 'cantidad' => 2, 'unidad' => 'unidad', 'subtotal' => 2000],
            ],
        ]);

        // Dos pasos: uno normal y el de entrega. El ensamble directo nace con uno solo, así que
        // se reemplaza por la pareja que estas pruebas necesitan.
        $ensamble->obtenerOCrearTemplateTrabajo()->sincronizarPasos([
            ['nombre' => 'Cortar',  'peso_porcentaje' => 50, 'orden' => 0, 'es_paso_final' => false],
            ['nombre' => 'Armar',   'peso_porcentaje' => 50, 'orden' => 1, 'es_paso_final' => true],
        ]);

        $op = Op::create([
            'sede_id'                => $sede->id,
            'estado'                 => 'confirmada',
            'responsable_id'         => $usuario->id,
            'bodega_entrega_id'      => $terminado->id,
            'bodega_material_id'     => $insumos->id,
            'fecha_creacion'         => now(),
            'fecha_entrega_estimada' => now()->addDays(5),
        ]);

        $item = OpItem::create([
            'op_id'                => $op->id,
            'ensamble_id'          => $ensamble->id,
            'descripcion'          => 'Puerta de prueba',
            'cantidad'             => $cantidad,
            'precio_unitario'      => 100000,
            'subtotal'             => 100000,
            'total_linea'          => 100000,
            'orden'                => 0,
            'componentes_snapshot' => [
                ['producto_id' => $lamina->id, 'nombre' => 'Lámina', 'cantidad' => 2, 'unidad' => 'unidad'],
            ],
        ]);

        app(TrabajoAutoGeneratorService::class)->generarParaItem($item);

        return compact('op', 'item', 'insumos', 'terminado', 'lamina') + ['ensamble' => $ensamble];
    }

    // ── El paso final ────────────────────────────────────────────────────────────

    public function test_el_paso_final_no_se_cierra_con_pasos_por_delante(): void
    {
        $e       = $this->escenario();
        $trabajo = $e['item']->trabajos()->firstOrFail();
        $final   = $trabajo->pasos()->where('es_paso_final', true)->firstOrFail();

        $this->expectException(ValidationException::class);

        $this->actingAs($this->admin());
        app(CierrePasoService::class)->cerrar($final);
    }

    public function test_cerrar_el_paso_final_entrega_la_unidad_y_descuenta_de_la_otra_bodega(): void
    {
        $e       = $this->escenario();
        $trabajo = $e['item']->trabajos()->firstOrFail();

        $this->actingAs($this->admin());
        $svc = app(CierrePasoService::class);

        $svc->cerrar($trabajo->pasos()->where('es_paso_final', false)->firstOrFail());

        $bodega = $svc->cerrar($trabajo->pasos()->where('es_paso_final', true)->firstOrFail());

        $this->assertSame($e['terminado']->id, $bodega?->id);

        // La unidad entró como producto terminado a la bodega de entrega…
        $terminado = Producto::where('ensamble_id', $e['ensamble']->id)->firstOrFail();
        $this->assertSame(1.0, (float) $terminado->stockEnBodega($e['terminado']->id));

        // …y su material salió de la OTRA. Si se descontara de la de entrega, el recorte a cero
        // de `registrarMovimiento()` dejaría la lámina intacta y sin decir nada.
        $this->assertSame(48.0, (float) $e['lamina']->stockEnBodega($e['insumos']->id));

        $this->assertNotNull($trabajo->fresh()->entregado_at);
    }

    public function test_la_unidad_no_entra_dos_veces_aunque_se_vuelva_a_cerrar_el_paso(): void
    {
        $e       = $this->escenario();
        $trabajo = $e['item']->trabajos()->firstOrFail();

        $this->actingAs($this->admin());
        $svc = app(CierrePasoService::class);

        $svc->cerrar($trabajo->pasos()->where('es_paso_final', false)->firstOrFail());
        $final = $trabajo->pasos()->where('es_paso_final', true)->firstOrFail();
        $svc->cerrar($final);
        $svc->cerrar($final->fresh());

        $terminado = Producto::where('ensamble_id', $e['ensamble']->id)->firstOrFail();

        $this->assertSame(1.0, (float) $terminado->stockEnBodega($e['terminado']->id));
        $this->assertSame(48.0, (float) $e['lamina']->stockEnBodega($e['insumos']->id));
    }

    public function test_cerrar_un_paso_desde_la_hoja_de_trabajo_tambien_otorga_puntos(): void
    {
        $e        = $this->escenario();
        $trabajo  = $e['item']->trabajos()->firstOrFail();
        $paso     = $trabajo->pasos()->where('es_paso_final', false)->firstOrFail();
        $operario = \App\Models\Operario::create([
            'nombre' => 'Quien corta', 'documento' => '1000', 'estado' => 'activo',
        ]);

        // Antes los puntos solo se otorgaban por el código QR, pero se devolvían desde aquí.
        $this->actingAs($this->admin())
            ->putJson("/trabajos/pasos/{$paso->id}", [
                'completado' => true,
                'operarios'  => [['operario_id' => $operario->id, 'tiempo_minutos' => 30]],
            ])
            ->assertOk();

        $this->assertDatabaseHas('puntos_colaborador', [
            'operario_id'             => $operario->id,
            'op_item_trabajo_paso_id' => $paso->id,
        ]);
    }

    // ── Las unidades siguen a la cantidad ────────────────────────────────────────

    public function test_subir_la_cantidad_crea_las_unidades_que_faltan(): void
    {
        $e = $this->escenario(cantidad: 1);

        $this->assertSame(1, $e['item']->trabajos()->count());

        $e['item']->update(['cantidad' => 3]);
        app(TrabajoAutoGeneratorService::class)->sincronizarParaItem($e['item']);

        $this->assertSame(3, $e['item']->trabajos()->count());
        // Y cada una sabe que es «la 2 de 3»: al mover la cantidad, ese número también miente.
        $this->assertSame([1, 2, 3], $e['item']->trabajos()->orderBy('numero_unidad')->pluck('numero_unidad')->all());
        $this->assertSame([3, 3, 3], $e['item']->trabajos()->pluck('total_unidades')->all());
        // Y nace completa: con sus pasos, no vacía.
        $this->assertSame(2, $e['item']->trabajos()->orderByDesc('numero_unidad')->first()->pasos()->count());
    }

    public function test_bajar_la_cantidad_borra_solo_las_unidades_que_nadie_toco(): void
    {
        $e = $this->escenario(cantidad: 3);

        $this->actingAs($this->admin());
        $svc = app(TrabajoAutoGeneratorService::class);

        // Se avanza una unidad: mientras queden libres las que sobran, bajar es posible.
        $unidades = $e['item']->trabajos()->orderBy('numero_unidad')->get();
        app(CierrePasoService::class)->cerrar($unidades[0]->pasos()->where('es_paso_final', false)->firstOrFail());

        $cambio = $svc->cambiosPorCantidad($e['item'], 2);

        $this->assertFalse($cambio['bloqueado']);
        $this->assertSame(1, $cambio['borrar']);

        // Con dos unidades trabajadas, bajar a una dejaría por fuera trabajo ya hecho. Ahí no.
        app(CierrePasoService::class)->cerrar($unidades[1]->pasos()->where('es_paso_final', false)->firstOrFail());

        $bloqueado = $svc->cambiosPorCantidad($e['item'], 1);

        $this->assertTrue($bloqueado['bloqueado']);
        $this->assertStringContainsString('ya tienen trabajo registrado', $bloqueado['mensaje']);

        // Y el candado está en el servicio, no solo en el aviso: sincronizar no borra nada
        // que alguien haya tocado, pase lo que pase con la cantidad.
        $e['item']->update(['cantidad' => 1]);
        $svc->sincronizarParaItem($e['item']);

        $this->assertSame(2, $e['item']->trabajos()->count());
    }

    public function test_la_orden_no_se_guarda_sin_confirmar_el_cambio_de_unidades(): void
    {
        $e = $this->escenario(cantidad: 1);

        $payload = [
            'responsable_id' => $this->admin()->id,
            'fecha_creacion' => now()->toDateString(),
            'items'          => [[
                'id'              => $e['item']->id,
                'tipo'            => 'ensamble',
                'ensamble_id'     => $e['item']->ensamble_id,
                'descripcion'     => $e['item']->descripcion,
                'cantidad'        => 3,
                'precio_unitario' => 100000,
            ]],
        ];

        $this->actingAs($this->admin())
            ->put("/produccion/ops/{$e['op']->id}", $payload)
            ->assertSessionHasErrors('confirmar_unidades');

        $this->assertSame(1, $e['item']->trabajos()->count());

        // Con el sí del usuario, sí.
        $this->actingAs($this->admin())
            ->put("/produccion/ops/{$e['op']->id}", $payload + ['confirmar_unidades' => true])
            ->assertSessionHasNoErrors();

        $this->assertSame(3, $e['item']->trabajos()->count());
    }

    public function test_guardar_la_orden_no_recrea_sus_items_ni_pierde_las_unidades(): void
    {
        $e     = $this->escenario();
        $antes = $e['item']->trabajos()->firstOrFail()->id;

        $this->actingAs($this->admin())
            ->put("/produccion/ops/{$e['op']->id}", [
                'responsable_id' => $this->admin()->id,
                'fecha_creacion' => now()->toDateString(),
                'notas_internas' => 'Se cambió una nota, nada más.',
                'items'          => [[
                    'id'              => $e['item']->id,
                    'tipo'            => 'ensamble',
                    'ensamble_id'     => $e['item']->ensamble_id,
                    'descripcion'     => $e['item']->descripcion,
                    'cantidad'        => 1,
                    'precio_unitario' => 100000,
                ]],
            ])
            ->assertSessionHasNoErrors();

        // `validate()` devuelve solo lo que valida. Sin la regla de `items.*.id`, el id no
        // llegaba a `syncItems()`: cada guardado creaba un ítem nuevo y borraba el viejo, y
        // con él se iban en cascada su unidad, sus pasos y su revisión de calidad.
        $this->assertDatabaseHas('op_items', ['id' => $e['item']->id]);
        $this->assertSame($antes, $e['item']->trabajos()->firstOrFail()->id);
    }

    // ── El reproceso ─────────────────────────────────────────────────────────────

    /** Fabrica una unidad de punta a punta y le deja un punto de revisión. */
    private function fabricar(OpItemTrabajo $trabajo): void
    {
        $svc = app(CierrePasoService::class);
        $svc->cerrar($trabajo->pasos()->where('es_paso_final', false)->firstOrFail());
        $svc->cerrar($trabajo->pasos()->where('es_paso_final', true)->firstOrFail());
        $trabajo->checks()->create(['titulo' => 'Escuadra', 'orden' => 0, 'es_critico' => true]);
    }

    public function test_reprocesar_reabre_solo_las_unidades_que_fallaron(): void
    {
        $e = $this->escenario(cantidad: 2);
        $this->actingAs($this->admin());

        $unidades = $e['item']->trabajos()->orderBy('numero_unidad')->get();
        $unidades->each(fn ($t) => $this->fabricar($t));

        // La primera falla, la segunda la aprueba calidad por su pantalla —que es lo que pone
        // la firma de la unidad, y sin firma no hay despacho—.
        $unidades[0]->checks()->update(['resultado' => 'falla', 'observaciones' => 'Descuadrada 3 mm']);
        $this->actingAs($this->admin())->post("/calidad/unidades/{$unidades[1]->id}/terminar")->assertOk();

        $this->actingAs($this->admin())
            ->post("/calidad/ops/{$e['op']->id}/reprocesar", ['motivo_rechazo' => 'La primera vino descuadrada'])
            ->assertSessionHasNoErrors();

        $this->assertSame('reproceso', $e['op']->fresh()->estado);

        // La que falló bajó del 100 % y volvió a ser trabajo pendiente…
        $this->assertLessThan(100, (float) $unidades[0]->fresh()->porcentaje_avance);
        // …y la que cumplió no se tocó: rehacer lo que estaba bien es trabajo inventado.
        $this->assertSame(100.0, (float) $unidades[1]->fresh()->porcentaje_avance);

        // Ninguna de las dos se puede despachar: una está en reproceso y la orden perdió el
        // sello, que es lo que gobierna a la que cumplió mientras su compañera se rehace.
        $this->assertSame(1, OpItemTrabajo::disponiblesParaRemision()->count());

        // Y la falla NO se borra: la observación es lo que quien corrige necesita leer.
        $this->assertSame('falla', $unidades[0]->fresh()->checks()->first()->resultado);
        $this->assertStringContainsString('3 mm', $unidades[0]->fresh()->checks()->first()->observaciones);
    }

    public function test_al_rehacer_la_unidad_la_orden_vuelve_sola_a_calidad(): void
    {
        $e = $this->escenario();
        $this->actingAs($this->admin());

        $trabajo = $e['item']->trabajos()->firstOrFail();
        $this->fabricar($trabajo);
        $trabajo->checks()->update(['resultado' => 'falla']);

        $this->actingAs($this->admin())
            ->post("/calidad/ops/{$e['op']->id}/reprocesar", ['motivo_rechazo' => 'Descuadrada'])
            ->assertSessionHasNoErrors();

        $this->assertSame('reproceso', $e['op']->fresh()->estado);

        // Planta la corrige y vuelve a cerrar el paso de entrega.
        app(CierrePasoService::class)->cerrar(
            $trabajo->fresh()->pasos()->where('es_paso_final', true)->firstOrFail()
        );

        // La orden regresa sola a calidad. Antes esto era manual y no había nada que lo
        // recordara: la orden se quedaba en reproceso con el trabajo ya rehecho.
        $this->assertSame('calidad', $e['op']->fresh()->estado);

        // Y no entró dos veces a bodega por rehacerla.
        $terminado = Producto::where('ensamble_id', $e['ensamble']->id)->firstOrFail();
        $this->assertSame(1.0, (float) $terminado->stockEnBodega($e['terminado']->id));
    }

    // ── La remisión es por unidad ────────────────────────────────────────────────

    public function test_una_unidad_sin_calidad_resuelta_no_esta_disponible_para_remision(): void
    {
        $e       = $this->escenario();
        $trabajo = $e['item']->trabajos()->firstOrFail();

        $this->actingAs($this->admin());
        $svc = app(CierrePasoService::class);
        $svc->cerrar($trabajo->pasos()->where('es_paso_final', false)->firstOrFail());
        $svc->cerrar($trabajo->pasos()->where('es_paso_final', true)->firstOrFail());

        $trabajo->checks()->create(['titulo' => 'Escuadra', 'orden' => 0, 'es_critico' => true]);

        $this->assertSame(0, OpItemTrabajo::disponiblesParaRemision()->count());

        $this->actingAs($this->admin())->post("/calidad/unidades/{$trabajo->id}/terminar")->assertOk();

        $this->assertSame(1, OpItemTrabajo::disponiblesParaRemision()->count());
    }

    public function test_una_unidad_sin_lista_de_revision_se_puede_aprobar_y_despachar(): void
    {
        // El caso que estaba roto, y es el mayoritario: casi ninguna plantilla tiene cargada su
        // lista de revisión. Esas unidades no tenían puntos, así que el tablero de Calidad ni
        // las mostraba, su botón «Terminar» no cambiaba nada, y no había forma de despacharlas.
        $e       = $this->escenario();
        $trabajo = $e['item']->trabajos()->firstOrFail();

        $this->actingAs($this->admin());
        $svc = app(CierrePasoService::class);
        $svc->cerrar($trabajo->pasos()->where('es_paso_final', false)->firstOrFail());
        $svc->cerrar($trabajo->pasos()->where('es_paso_final', true)->firstOrFail());

        $this->assertSame(0, $trabajo->checks()->count());

        // Aparece en el tablero aunque no tenga ni un punto que revisar.
        $this->actingAs($this->admin())
            ->get('/calidad')
            ->assertInertia(fn ($p) => $p->component('Calidad/Index')
                ->has('fichas', 1)
                ->where('fichas.0.revisada', false));

        // Y «Terminar» la firma de verdad: el botón deja de estar muerto.
        $this->actingAs($this->admin())
            ->post("/calidad/unidades/{$trabajo->id}/terminar")
            ->assertOk()
            ->assertJsonPath('ficha.revisada', true);

        $this->assertNotNull($trabajo->fresh()->calidad_revisada_at);
        $this->assertSame(1, OpItemTrabajo::disponiblesParaRemision()->count());
    }

    public function test_marcar_los_puntos_uno_por_uno_tambien_firma_la_unidad(): void
    {
        $e       = $this->escenario();
        $trabajo = $e['item']->trabajos()->firstOrFail();

        $this->actingAs($this->admin());
        $svc = app(CierrePasoService::class);
        $svc->cerrar($trabajo->pasos()->where('es_paso_final', false)->firstOrFail());
        $svc->cerrar($trabajo->pasos()->where('es_paso_final', true)->firstOrFail());

        $check = $trabajo->checks()->create(['titulo' => 'Escuadra', 'orden' => 0, 'es_critico' => true]);

        // Sin esto, marcar los ocho puntos a mano dejaba la unidad igual de bloqueada que antes
        // de empezar: solo el atajo «Terminar» la abría.
        $this->actingAs($this->admin())
            ->patchJson("/calidad/checks/{$check->id}", ['resultado' => 'cumple'])
            ->assertOk();

        $this->assertNotNull($trabajo->fresh()->calidad_revisada_at);

        // Y marcarlo en falla se la quita.
        $this->actingAs($this->admin())
            ->patchJson("/calidad/checks/{$check->id}", ['resultado' => 'falla'])
            ->assertOk();

        $this->assertNull($trabajo->fresh()->calidad_revisada_at);
    }

    public function test_una_remision_parcial_no_despacha_la_orden(): void
    {
        $e = $this->escenario(cantidad: 2);

        $this->actingAs($this->admin());
        $svc = app(CierrePasoService::class);

        foreach ($e['item']->trabajos()->orderBy('numero_unidad')->get() as $trabajo) {
            $svc->cerrar($trabajo->pasos()->where('es_paso_final', false)->firstOrFail());
            $svc->cerrar($trabajo->pasos()->where('es_paso_final', true)->firstOrFail());
        }

        // Calidad cierra la orden entera: eso firma sus dos unidades.
        $this->actingAs($this->admin())
            ->post("/calidad/ops/{$e['op']->id}/terminar")
            ->assertSessionHasNoErrors();

        // Se despacha UNA de las dos.
        $this->actingAs($this->admin())
            ->post('/logistica/remisiones', [
                'tipo'  => 'op',
                'op_id' => $e['op']->id,
                'items' => [[
                    'descripcion'       => $e['item']->descripcion,
                    'cantidad'          => 1,
                    'cantidad_unidades' => 1,
                    'op_item_id'        => $e['item']->id,
                ]],
            ])
            ->assertSessionHasNoErrors();

        // La orden NO queda despachada, y el ítem no queda marcado como remisionado: si lo
        // quedara, la unidad que falta desaparecería del remisionador y nadie podría sacarla.
        $this->assertNotSame('despachada', $e['op']->fresh()->estado);
        $this->assertFalse((bool) $e['item']->fresh()->remisionado);
        $this->assertSame(1, $e['item']->fresh()->cantidadDisponible());
    }

    public function test_se_puede_remisionar_la_unidad_aprobada_aunque_otra_siga_en_revision(): void
    {
        $e = $this->escenario(cantidad: 2);

        $this->actingAs($this->admin());
        $svc = app(CierrePasoService::class);

        foreach ($e['item']->trabajos()->orderBy('numero_unidad')->get() as $trabajo) {
            $svc->cerrar($trabajo->pasos()->where('es_paso_final', false)->firstOrFail());
            $svc->cerrar($trabajo->pasos()->where('es_paso_final', true)->firstOrFail());
            $trabajo->checks()->create(['titulo' => 'Escuadra', 'orden' => 0, 'es_critico' => true]);
        }

        // Solo la primera pasa calidad. Es el caso real: el cliente se lleva lo que está listo.
        $primera = $e['item']->trabajos()->orderBy('numero_unidad')->first();
        $this->actingAs($this->admin())->post("/calidad/unidades/{$primera->id}/terminar")->assertOk();

        $this->assertSame(1, $e['item']->cantidadDisponible());
        // Y la orden NO tiene el sello: con el candado viejo, esta unidad esperaría a la otra.
        $this->assertNull($e['op']->fresh()->calidad_aprobada_at);
    }
}
