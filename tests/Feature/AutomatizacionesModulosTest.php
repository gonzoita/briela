<?php

namespace Tests\Feature;

use App\Models\Automatizacion;
use App\Models\AutomatizacionAprobacion;
use App\Models\AutomatizacionEjecucion;
use App\Models\Bodega;
use App\Models\Cliente;
use App\Models\Comentario;
use App\Models\Cotizacion;
use App\Models\CrmActividad;
use App\Models\CrmEtapa;
use App\Models\CrmLead;
use App\Models\CrmTarea;
use App\Models\Notificacion;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\ProductoStock;
use App\Models\Proveedor;
use App\Models\Remision;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\SolicitudCompra;
use App\Models\User;
use App\Models\WhatsappConversacion;
use App\Models\WhatsappMensaje;
use App\Models\WhatsappNumero;
use App\Services\Automatizaciones\CatalogoAutomatizaciones;
use App\Services\Automatizaciones\EntidadesAutomatizacion;
use App\Services\Automatizaciones\GrafoAutomatizacion;
use App\Services\Automatizaciones\LanzadorAutomatizaciones;
use App\Services\Automatizaciones\Webhook;
use App\Services\IA\IaService;
use App\Support\Modulos;
use App\Support\VentanaHoraria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Automatizaciones conectadas al resto de Briela: eventos de cada módulo, barridos programados,
 * aprobaciones humanas, pasos de IA, webhooks y las acciones nuevas.
 */
class AutomatizacionesModulosTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow();
        Cache::flush();
        Modulos::guardar([], 'instalacion');

        $this->admin = User::factory()->create(['rol' => 'administrador']);
        Sede::query()->first() ?? Sede::create(['nombre' => 'Principal', 'es_principal' => true]);
        $this->actingAs($this->admin);
    }

    // ─── Ayudas ──────────────────────────────────────────────────────────────

    /** Una cadena como la que arma la pantalla: rama «sí» después de condiciones y aprobaciones. */
    private function cadena(array $disparador, array ...$pasos): array
    {
        $nodos   = [['id' => 'disparo', 'tipo' => 'disparador', 'config' => $disparador]];
        $aristas = [];
        $previo  = 'disparo';
        $rama    = false;

        foreach ($pasos as $i => $p) {
            $id = 'p' . ($i + 1);
            $nodos[] = ['id' => $id] + $p;
            $aristas[] = ['de' => $previo, 'a' => $id] + ($rama ? ['rama' => 'si'] : []);
            $previo = $id;
            $rama = in_array($p['tipo'], ['condicion', 'aprobacion'], true);
        }

        return ['nodos' => $nodos, 'aristas' => $aristas];
    }

    private function si(string $campo, string $op, mixed $valor): array
    {
        return ['tipo' => 'condicion', 'config' => ['combinador' => 'y', 'reglas' => [['campo' => $campo, 'operador' => $op, 'valor' => $valor]]]];
    }

    private function accion(string $accion, array $config): array
    {
        return ['tipo' => 'accion', 'accion' => $accion, 'config' => $config];
    }

    private function avisarme(string $titulo): array
    {
        return $this->accion('notificar', ['destinatario' => 'autor', 'titulo' => $titulo]);
    }

    private function evento(string $evento): array
    {
        return ['tipo' => 'evento', 'evento' => $evento];
    }

    private function armar(array $grafo, array $extra = []): Automatizacion
    {
        $this->assertSame([], GrafoAutomatizacion::validar($grafo, $this->admin), 'el grafo de la prueba debe ser válido');
        $d = GrafoAutomatizacion::disparadorDe($grafo);

        return Automatizacion::create($extra + [
            'nombre' => 'Prueba', 'grafo' => $grafo, 'activa' => true, 'creado_por' => $this->admin->id,
            'disparador_tipo' => $d['tipo'], 'disparador_clave' => $d['clave'],
        ]);
    }

    private function avisos(string $tipo = 'automatizacion_aviso')
    {
        return Notificacion::where('user_id', $this->admin->id)->where('tipo', $tipo)->get();
    }

    private function cliente(string $nombre = 'Frío Andino SAS'): Cliente
    {
        return Cliente::create(['tipo' => 'empresa', 'nombre' => $nombre, 'tipo_identificacion' => 'NIT', 'numero_identificacion' => '900' . random_int(100000, 999999)]);
    }

    private function cotizacion(string $estado = 'borrador'): Cotizacion
    {
        return Cotizacion::create([
            'cliente_id' => $this->cliente()->id, 'moneda' => 'COP', 'tasa_cambio' => 1, 'estado' => $estado,
            'responsable_id' => $this->admin->id, 'total' => 4500000,
        ]);
    }

    private function etapa(string $nombre, int $orden = 1, array $extra = []): CrmEtapa
    {
        return CrmEtapa::create(['nombre' => $nombre, 'orden' => $orden, 'activa' => true] + $extra);
    }

    private function producto(float $minimo = 10): Producto
    {
        return Producto::create([
            'tipo' => 'producto', 'nombre' => 'Panel 10 cm', 'referencia' => 'PAN-' . uniqid(), 'es_insumo' => true,
            'inventariable' => true, 'unidad_medida' => 'm2', 'stock_minimo' => $minimo,
        ]);
    }

    // ─── El catálogo ─────────────────────────────────────────────────────────

    public function test_cada_documento_trae_ejemplo_para_todos_sus_campos_y_cada_evento_un_documento(): void
    {
        foreach (EntidadesAutomatizacion::todas() as $clave => $d) {
            $this->assertSame([], array_values(array_diff(array_keys($d['campos']), array_keys($d['ejemplo']))), "Al ejemplo de «{$clave}» le faltan campos.");
            $this->assertSame([], array_values(array_diff(array_keys($d['ejemplo']), array_keys($d['campos']))), "El ejemplo de «{$clave}» trae campos que no existen.");

            if ($d['barrido'] !== null) {
                $this->assertNotNull(EntidadesAutomatizacion::consultaBarrido($clave), "«{$clave}» dice que se puede barrer y no tiene consulta.");
                EntidadesAutomatizacion::consultaBarrido($clave)->count(); // la consulta corre en la base real
            }
        }

        foreach (CatalogoAutomatizaciones::eventos() as $clave => $e) {
            $this->assertTrue(EntidadesAutomatizacion::existe($e['entidad']), "El evento «{$clave}» apunta a un documento que no existe.");
        }

        foreach (CatalogoAutomatizaciones::acciones() as $clave => $a) {
            foreach ($a['entidades'] ?? [] as $ent) {
                $this->assertTrue(EntidadesAutomatizacion::existe($ent), "La acción «{$clave}» cuelga de «{$ent}», que no existe.");
            }
        }
    }

    public function test_el_catalogo_trae_los_documentos_barribles_y_lo_que_falta_en_cada_plantilla(): void
    {
        $c = $this->getJson('/api/automatizaciones/catalogo')->assertOk()->json();

        $this->assertContains('stock.bajo', collect($c['eventos'])->pluck('clave')->all());
        $this->assertContains('mensaje.entrante', collect($c['eventos'])->pluck('clave')->all());
        $this->assertTrue(collect($c['entidades'])->firstWhere('clave', 'orden_compra')['barrido']);
        $this->assertFalse(collect($c['entidades'])->firstWhere('clave', 'conversacion')['barrido']);

        // La plantilla que reparte leads necesita que la persona elija entre quiénes: se dice antes de usarla.
        $reparto = collect($c['plantillas'])->firstWhere('clave', 'lead_nuevo_reparto');
        $this->assertNotEmpty($reparto['pendientes']);
    }

    // ─── Eventos de cada módulo ──────────────────────────────────────────────

    public function test_un_lead_que_cambia_de_etapa_sabe_de_donde_venia_y_se_gana(): void
    {
        $nuevo   = $this->etapa('Nuevo', 1);
        $cotizar = $this->etapa('Cotizar', 2);
        $ganado  = $this->etapa('Ganado', 3, ['es_ganado' => true]);

        $this->armar($this->cadena($this->evento('lead.etapa'), $this->si('etapa_anterior', 'igual', 'Nuevo'), $this->avisarme('{{titulo}}: de {{etapa_anterior}} a {{etapa}}')));
        $this->armar($this->cadena($this->evento('lead.cerrado'), $this->si('estado', 'igual', 'ganado'), $this->avisarme('Ganado: {{titulo}}')));

        $lead = CrmLead::create(['etapa_id' => $nuevo->id, 'titulo' => 'Puertas', 'estado' => 'activo', 'nombre_contacto' => 'Laura']);
        $lead->update(['etapa_id' => $cotizar->id]);
        $lead->update(['etapa_id' => $ganado->id, 'estado' => 'ganado']);

        $titulos = $this->avisos()->pluck('titulo')->all();
        $this->assertContains('Puertas: de Nuevo a Cotizar', $titulos);
        $this->assertContains('Ganado: Puertas', $titulos);
        $this->assertCount(2, $titulos, 'de Cotizar a Ganado no cumple «venía de Nuevo»');
    }

    public function test_cliente_nuevo_y_orden_de_compra_disparan_con_su_estado_anterior(): void
    {
        $this->armar($this->cadena($this->evento('cliente.nuevo'), $this->avisarme('Cliente nuevo: {{nombre}}')));
        $this->armar($this->cadena($this->evento('orden_compra.estado'), $this->si('estado_anterior', 'igual', 'enviada'), $this->avisarme('{{numero}} de {{proveedor}}: {{estado_anterior}} → {{estado}}')));

        $this->cliente('Aceros SAS');

        $oc = OrdenCompra::create(['estado' => 'enviada', 'proveedor_id' => Proveedor::create(['nombre' => 'Aceros del Valle', 'activo' => true])->id, 'creado_por' => $this->admin->id]);
        $oc->update(['estado' => 'confirmada']);

        $titulos = $this->avisos()->pluck('titulo')->all();
        $this->assertContains('Cliente nuevo: Aceros SAS', $titulos);
        $this->assertContains("{$oc->numero} de Aceros del Valle: enviada → confirmada", $titulos);
    }

    public function test_el_stock_bajo_avisa_solo_al_cruzar_el_minimo(): void
    {
        $this->armar($this->cadena($this->evento('stock.bajo'), $this->avisarme('Bajo: {{nombre}} ({{existencias}} de {{minimo}}, faltan {{faltante}})')));

        $p = $this->producto(10);
        $bodega = Bodega::create(['nombre' => 'Principal', 'activa' => true, 'es_principal' => true]);
        $s = ProductoStock::create(['producto_id' => $p->id, 'bodega_id' => $bodega->id, 'cantidad' => 30]);

        $s->update(['cantidad' => 12]);
        $this->assertCount(0, $this->avisos(), 'todavía por encima del mínimo');

        $s->update(['cantidad' => 4]);
        $this->assertSame(['Bajo: Panel 10 cm (4 de 10, faltan 6)'], $this->avisos()->pluck('titulo')->all());

        $s->update(['cantidad' => 2]);
        $this->assertCount(1, $this->avisos(), 'ya estaba bajo: no vuelve a avisar');
    }

    public function test_un_mensaje_que_entra_a_la_bandeja_dispara_y_los_que_salen_no(): void
    {
        $this->armar($this->cadena($this->evento('mensaje.entrante'), $this->si('sin_responder', 'igual', 'si'), $this->avisarme('{{contacto}} por {{canal}}: {{mensaje}}')));

        $linea = WhatsappNumero::create(['nombre' => 'Central', 'numero_telefono' => '+573001112233', 'phone_number_id' => '111', 'rol' => 'central', 'activo' => true]);
        $conv  = WhatsappConversacion::create(['whatsapp_numero_id' => $linea->id, 'numero_contacto' => '573009998877', 'nombre_contacto' => 'Marta', 'leido' => false]);

        WhatsappMensaje::create(['whatsapp_conversacion_id' => $conv->id, 'direccion' => 'entrante', 'tipo' => 'texto', 'contenido' => 'Necesito una cotización']);
        WhatsappMensaje::create(['whatsapp_conversacion_id' => $conv->id, 'direccion' => 'saliente', 'tipo' => 'texto', 'contenido' => 'Con gusto']);

        $this->assertSame(['Marta por whatsapp: Necesito una cotización'], $this->avisos()->pluck('titulo')->all());
    }

    public function test_un_mensaje_sin_respuesta_a_los_30_minutos_se_revisa_con_datos_frescos(): void
    {
        $this->armar($this->cadena(
            $this->evento('mensaje.entrante'),
            ['tipo' => 'espera', 'config' => ['cantidad' => 30, 'unidad' => 'minutos']],
            $this->si('sin_responder', 'igual', 'si'),
            $this->avisarme('Sin respuesta: {{contacto}}'),
        ));

        $linea = WhatsappNumero::create(['nombre' => 'Central', 'numero_telefono' => '+573001112233', 'phone_number_id' => '111', 'rol' => 'central', 'activo' => true]);
        $conv  = WhatsappConversacion::create(['whatsapp_numero_id' => $linea->id, 'numero_contacto' => '573009998877', 'nombre_contacto' => 'Marta', 'leido' => false]);
        WhatsappMensaje::create(['whatsapp_conversacion_id' => $conv->id, 'direccion' => 'entrante', 'tipo' => 'texto', 'contenido' => 'Hola']);

        // Alguien contesta antes de que pase la media hora.
        WhatsappMensaje::create(['whatsapp_conversacion_id' => $conv->id, 'direccion' => 'saliente', 'tipo' => 'texto', 'contenido' => 'Hola, ¿en qué te ayudo?']);

        Carbon::setTestNow(now()->addMinutes(31));
        app(LanzadorAutomatizaciones::class)->reanudar();

        $this->assertCount(0, $this->avisos(), 'ya le respondieron: no se avisa');
        $this->assertSame('terminada', AutomatizacionEjecucion::first()->estado);
    }

    // ─── Barridos programados ────────────────────────────────────────────────

    public function test_un_barrido_crea_una_corrida_por_documento_que_cumple_y_no_repite_en_la_ventana(): void
    {
        $a = $this->armar($this->cadena(
            ['tipo' => 'horario', 'frecuencia' => ['tipo' => 'diaria', 'hora' => '07:00'], 'para_cada' => 'cotizacion', 'no_repetir_dias' => 7],
            $this->si('estado', 'igual', 'enviada'),
            $this->avisarme('Seguimiento a {{numero}}'),
        ));

        $enviada  = $this->cotizacion('enviada');
        $this->cotizacion('borrador');
        $this->cotizacion('aprobada');

        $lanzador = app(LanzadorAutomatizaciones::class);

        Carbon::setTestNow(now()->setTime(7, 10));
        $this->assertSame(1, $lanzador->programadas(now()), 'solo la enviada pasa la condición, y la condición se mira antes de crear la corrida');
        $this->assertSame(["Seguimiento a {$enviada->numero}"], $this->avisos()->pluck('titulo')->all());
        $this->assertSame(1, AutomatizacionEjecucion::count(), 'la borrador y la aprobada no dejan corridas vacías');

        Carbon::setTestNow(now()->setTime(7, 11));
        $this->assertSame(0, $lanzador->programadas(now()), 'la misma ventana no se vuelve a barrer');

        // Al día siguiente, «no repetir en 7 días» la deja fuera.
        Carbon::setTestNow(now()->addDay()->setTime(7, 5));
        $this->assertSame(0, $lanzador->programadas(now()));
        $this->assertSame(now()->toDateString(), $a->fresh()->ultima_ventana);
    }

    public function test_un_barrido_respeta_el_tope_por_hora(): void
    {
        $this->armar($this->cadena(
            ['tipo' => 'horario', 'frecuencia' => ['tipo' => 'diaria', 'hora' => '07:00'], 'para_cada' => 'cotizacion'],
            $this->avisarme('{{numero}}'),
        ), ['max_por_hora' => 2]);

        foreach (range(1, 4) as $i) {
            $this->cotizacion('enviada');
        }

        Carbon::setTestNow(now()->setTime(7, 0));
        $this->assertSame(2, app(LanzadorAutomatizaciones::class)->programadas(now()));
    }

    public function test_solo_se_barre_lo_que_el_autor_puede_ver(): void
    {
        $rol = Rol::create(['nombre' => 'Comercial', 'rol_base' => 'vendedor', 'activo' => true, 'todas_las_sedes' => true]);
        $rol->sincronizarPermisos(['automatizaciones.ver', 'automatizaciones.gestionar', 'cotizaciones.ver']);
        $vendedor = User::factory()->create(['rol' => 'vendedor', 'rol_id' => $rol->id]);

        $compras = $this->cadena(['tipo' => 'horario', 'frecuencia' => ['tipo' => 'diaria', 'hora' => '08:00'], 'para_cada' => 'orden_compra'], $this->avisarme('{{numero}}'));

        $this->assertStringContainsString('ordenes.ver', implode(' ', GrafoAutomatizacion::validar($compras, $vendedor)));
        $this->assertSame([], GrafoAutomatizacion::validar($compras, $this->admin));
    }

    public function test_las_ventanas_cada_pocas_horas_y_mensual(): void
    {
        $f = ['tipo' => 'cada_horas', 'intervalo' => 2, 'desde' => '08:00', 'hasta' => '18:00'];

        $this->assertNull(VentanaHoraria::actual($f, Carbon::parse('2026-10-14 07:59')));
        $this->assertSame('2026-10-14-08', VentanaHoraria::actual($f, Carbon::parse('2026-10-14 08:00')));
        $this->assertSame('2026-10-14-08', VentanaHoraria::actual($f, Carbon::parse('2026-10-14 09:59')));
        $this->assertSame('2026-10-14-10', VentanaHoraria::actual($f, Carbon::parse('2026-10-14 10:01')));
        $this->assertNull(VentanaHoraria::actual($f, Carbon::parse('2026-10-14 18:30')));

        $m = ['tipo' => 'mensual', 'dia_mes' => 1, 'hora' => '08:00'];
        $this->assertSame('2026-11-01', VentanaHoraria::actual($m, Carbon::parse('2026-11-01 09:00')));
        $this->assertNull(VentanaHoraria::actual($m, Carbon::parse('2026-11-02 09:00')));
    }

    // ─── Aprobaciones ────────────────────────────────────────────────────────

    private function compraConAprobacion(User $aprueba): Automatizacion
    {
        return $this->armar($this->cadena(
            $this->evento('stock.bajo'),
            ['tipo' => 'aprobacion', 'config' => ['destinatario' => 'usuarios', 'usuarios' => [$aprueba->id], 'pregunta' => '¿Pido {{faltante}} {{unidad}} de {{nombre}}?', 'vence_horas' => 24]],
            $this->accion('crear_solicitud_compra', ['multiplicador' => 2]),
        ));
    }

    private function bajarStock(): Producto
    {
        $p = $this->producto(10);
        $s = ProductoStock::create(['producto_id' => $p->id, 'bodega_id' => Bodega::create(['nombre' => 'B', 'activa' => true])->id, 'cantidad' => 20]);
        $s->update(['cantidad' => 4]);

        return $p;
    }

    public function test_una_aprobacion_detiene_la_corrida_hasta_que_alguien_dice_que_si(): void
    {
        $jefe = User::factory()->create(['rol' => 'administrador']);
        $this->compraConAprobacion($jefe);
        $this->bajarStock();

        $e  = AutomatizacionEjecucion::firstOrFail();
        $ap = AutomatizacionAprobacion::firstOrFail();
        $this->assertSame('esperando', $e->estado);
        $this->assertSame('¿Pido 6 m2 de Panel 10 cm?', $ap->pregunta);
        $this->assertSame(0, SolicitudCompra::count(), 'nada se pide sin aprobación');
        $this->assertSame(1, Notificacion::where('user_id', $jefe->id)->where('tipo', 'automatizacion_aprobacion')->count());

        // Quien no es destinatario no decide.
        $this->postJson("/api/automatizaciones/aprobaciones/{$ap->id}/decidir", ['decision' => 'aprobar'])->assertForbidden();
        $this->get("/automatizaciones/aprobar/{$ap->id}")->assertForbidden();

        $this->actingAs($jefe);
        $this->getJson('/api/automatizaciones/aprobaciones')->assertOk()->assertJsonPath('0.pregunta', '¿Pido 6 m2 de Panel 10 cm?');
        $this->get("/automatizaciones/aprobar/{$ap->id}")->assertOk();
        $this->postJson("/api/automatizaciones/aprobaciones/{$ap->id}/decidir", ['decision' => 'aprobar', 'nota' => 'Dale'])->assertOk()->assertJsonPath('estado', 'aprobada');

        $this->assertSame('terminada', $e->fresh()->estado);
        $s = SolicitudCompra::with('items')->firstOrFail();
        $this->assertSame('borrador', $s->estado);
        $this->assertEquals(12, (float) $s->items->first()->cantidad, 'faltante 6 × 2');

        // Decidir dos veces no se puede.
        $this->postJson("/api/automatizaciones/aprobaciones/{$ap->id}/decidir", ['decision' => 'rechazar'])->assertStatus(422);
    }

    public function test_rechazar_o_dejar_vencer_una_aprobacion_detiene_el_flujo(): void
    {
        $this->compraConAprobacion($this->admin);

        $this->bajarStock();
        $ap = AutomatizacionAprobacion::firstOrFail();
        $this->postJson("/api/automatizaciones/aprobaciones/{$ap->id}/decidir", ['decision' => 'rechazar'])->assertOk();
        $this->assertSame(0, SolicitudCompra::count());
        $this->assertSame('terminada', $ap->ejecucion->fresh()->estado);

        // Otro producto: nadie contesta en 24 horas.
        $this->bajarStock();
        $segunda = AutomatizacionAprobacion::latest('id')->firstOrFail();
        Carbon::setTestNow(now()->addHours(25));
        app(LanzadorAutomatizaciones::class)->reanudar();

        $this->assertSame('vencida', $segunda->fresh()->estado);
        $this->assertSame(0, SolicitudCompra::count(), 'lo que nadie aprobó no se hace');
        $this->assertStringContainsString('Venció', $segunda->ejecucion->pasos()->where('tipo', 'aprobacion')->get()->last()->resumen);
    }

    public function test_pausar_cancela_lo_que_espera_y_sus_aprobaciones(): void
    {
        $a = $this->compraConAprobacion($this->admin);
        $this->bajarStock();

        $this->postJson("/api/automatizaciones/{$a->id}/activar", ['activa' => false])->assertOk();

        $this->assertSame('cancelada', AutomatizacionEjecucion::first()->estado);
        $this->assertSame('cancelada', AutomatizacionAprobacion::first()->estado);
    }

    // ─── Acciones nuevas ─────────────────────────────────────────────────────

    public function test_mover_y_asignar_un_lead_reparte_al_que_tiene_menos(): void
    {
        $nuevo  = $this->etapa('Nuevo', 1);
        $cotiza = $this->etapa('Cotizar', 2);
        $ana    = User::factory()->create(['rol' => 'vendedor', 'name' => 'Ana']);
        $beto   = User::factory()->create(['rol' => 'vendedor', 'name' => 'Beto']);

        CrmLead::create(['etapa_id' => $nuevo->id, 'titulo' => 'Viejo', 'estado' => 'activo', 'responsable_id' => $ana->id]);

        $this->armar($this->cadena(
            $this->evento('lead.nuevo'),
            $this->si('tiene_responsable', 'igual', 'no'),
            $this->accion('asignar_lead', ['usuarios' => [$ana->id, $beto->id]]),
            $this->accion('mover_lead', ['etapa_id' => $cotiza->id]),
        ));

        $lead = CrmLead::create(['etapa_id' => $nuevo->id, 'titulo' => 'Puertas', 'estado' => 'activo', 'nombre_contacto' => 'Laura']);

        $lead->refresh();
        $this->assertSame($beto->id, $lead->responsable_id, 'Ana ya tenía uno');
        $this->assertSame($cotiza->id, $lead->etapa_id);
        $this->assertTrue(CrmActividad::where('lead_id', $lead->id)->where('tipo', 'etapa')->exists());
    }

    public function test_una_automatizacion_no_gana_ni_pierde_leads(): void
    {
        $ganado = $this->etapa('Ganado', 9, ['es_ganado' => true]);
        $g = $this->cadena($this->evento('lead.nuevo'), $this->accion('mover_lead', ['etapa_id' => $ganado->id]));

        $this->assertStringContainsString('no gana ni pierde', implode(' ', GrafoAutomatizacion::validar($g, $this->admin)));
    }

    public function test_comentar_un_documento_deja_una_tarea_al_responsable(): void
    {
        $this->armar($this->cadena($this->evento('cotizacion.estado'), $this->si('estado', 'igual', 'enviada'),
            $this->accion('comentar_documento', ['tipo' => 'tarea', 'dias' => 2, 'texto' => 'Llamar por {{numero}} ({{total|dinero}})'])));

        $c = $this->cotizacion('enviada');

        $com = Comentario::firstOrFail();
        $this->assertSame(Cotizacion::class, $com->comentable_type);
        $this->assertSame('tarea', $com->tipo);
        $this->assertSame('pendiente', $com->estado);
        $this->assertSame($this->admin->id, $com->asignado_a);
        $this->assertStringContainsString("Llamar por {$c->numero} ($ 4.500.000)", $com->contenido);
    }

    public function test_la_solicitud_de_compra_no_se_duplica(): void
    {
        $this->armar($this->cadena($this->evento('stock.bajo'), $this->accion('crear_solicitud_compra', [])));

        $p = $this->bajarStock();
        $this->assertSame(1, SolicitudCompra::count());

        // Sube y vuelve a bajar: ya hay una solicitud abierta con ese producto.
        $s = ProductoStock::where('producto_id', $p->id)->first();
        $s->update(['cantidad' => 50]);
        $s->update(['cantidad' => 1]);
        $this->assertSame(1, SolicitudCompra::count());
        $this->assertStringContainsString('ya tiene una solicitud', AutomatizacionEjecucion::latest('id')->first()->pasos()->where('tipo', 'accion')->first()->resumen);
    }

    // ─── IA ──────────────────────────────────────────────────────────────────

    public function test_la_ia_clasifica_y_una_condicion_decide_con_su_respuesta(): void
    {
        $this->mock(IaService::class, function ($m) {
            $m->shouldReceive('configurado')->andReturn(true);
            $m->shouldReceive('texto')->andReturn('Reclamo.');
        });

        $this->armar($this->cadena(
            $this->evento('cliente.nuevo'),
            $this->accion('clasificar_ia', ['pregunta' => '¿Qué tipo de cliente es?', 'categorias' => 'prospecto, reclamo, otro']),
            $this->si('categoria_ia', 'igual', 'reclamo'),
            $this->avisarme('IA dijo {{categoria_ia}} para {{nombre}}'),
        ));

        $this->cliente('Aceros SAS');

        $this->assertSame(['IA dijo reclamo para Aceros SAS'], $this->avisos()->pluck('titulo')->all());
    }

    public function test_lo_que_redacta_la_ia_sobrevive_a_una_espera(): void
    {
        $this->mock(IaService::class, function ($m) {
            $m->shouldReceive('configurado')->andReturn(true);
            $m->shouldReceive('texto')->once()->andReturn('Llamar el martes.');
        });

        $this->armar($this->cadena(
            $this->evento('cliente.nuevo'),
            $this->accion('redactar_ia', ['instrucciones' => 'Sugiere un siguiente paso para {{nombre}}']),
            ['tipo' => 'espera', 'config' => ['cantidad' => 1, 'unidad' => 'horas']],
            $this->avisarme('Idea: {{texto_ia}}'),
        ));

        $this->cliente();
        Carbon::setTestNow(now()->addHours(2));
        app(LanzadorAutomatizaciones::class)->reanudar();

        $this->assertSame(['Idea: Llamar el martes.'], $this->avisos()->pluck('titulo')->all());
    }

    public function test_los_datos_de_colaboradores_no_van_a_la_ia_ni_fuera(): void
    {
        foreach (['redactar_ia' => ['instrucciones' => 'x'], 'webhook' => ['url' => 'https://hooks.example.com/x']] as $accion => $cfg) {
            $g = $this->cadena($this->evento('permiso.solicitado'), $this->accion($accion, $cfg));
            $this->assertStringContainsString('colaboradores', implode(' ', GrafoAutomatizacion::validar($g, $this->admin)), $accion);
        }
    }

    // ─── Webhooks ────────────────────────────────────────────────────────────

    public function test_el_webhook_manda_los_datos_firmados_sin_las_claves_internas(): void
    {
        Http::fake(['hooks.example.com/*' => Http::response(['ok' => true])]);

        $this->armar($this->cadena($this->evento('cotizacion.estado'), $this->accion('webhook', ['url' => 'https://hooks.example.com/briela', 'clave' => 'secreta'])));
        $c = $this->cotizacion('enviada');

        Http::assertSent(function (PeticionHttp $r) use ($c) {
            $cuerpo = json_decode($r->body(), true);

            return $r->url() === 'https://hooks.example.com/briela'
                && $cuerpo['datos']['numero'] === $c->numero
                && ! array_key_exists('_responsable_id', $cuerpo['datos'])
                && $r->header('X-Briela-Firma')[0] === 'sha256=' . hash_hmac('sha256', $r->body(), 'secreta');
        });
        $this->assertSame('terminada', AutomatizacionEjecucion::first()->estado);
    }

    public function test_el_webhook_no_apunta_a_la_red_interna(): void
    {
        foreach (['http://hooks.example.com', 'https://127.0.0.1/x', 'https://10.0.0.5/x', 'https://localhost/x', 'https://usuario:clave@example.com', 'https://example.com:22/x', 'https://[::1]/x', 'https://169.254.169.254/latest'] as $url) {
            $this->assertNotNull(Webhook::errorDeDireccion($url, resolver: false), $url);
        }

        $this->assertNull(Webhook::errorDeDireccion('https://hooks.zapier.com/hooks/catch/1/abc', resolver: false));
    }

    // ─── Probar con un documento real ────────────────────────────────────────

    public function test_probar_con_un_documento_real_por_su_numero(): void
    {
        $c = $this->cotizacion('enviada');
        $g = $this->cadena($this->evento('cotizacion.estado'), $this->si('estado', 'igual', 'enviada'), $this->avisarme('{{numero}}'));

        $r = $this->postJson('/api/automatizaciones/probar', ['grafo' => $g, 'documento' => $c->numero])->assertOk();

        $this->assertTrue($r->json('real'));
        $this->assertSame($c->numero, $r->json('contexto.numero'));
        $this->assertSame('ok', $r->json('pasos.1.estado'));
        $this->assertSame(0, Notificacion::where('tipo', 'automatizacion_aviso')->count());

        $this->postJson('/api/automatizaciones/probar', ['grafo' => $g, 'documento' => 'NO-EXISTE'])->assertStatus(422);
    }

    public function test_una_remision_entregada_se_puede_automatizar(): void
    {
        $this->armar($this->cadena($this->evento('remision.estado'), $this->si('estado', 'en_lista', 'entregada, cerrada'), $this->avisarme('Entregada {{numero}}')));

        $r = Remision::create(['numero' => 'REM-0001', 'tipo' => 'manual', 'estado' => 'en_camino', 'fecha_remision' => now()->toDateString(), 'created_by' => $this->admin->id]);
        $r->update(['estado' => 'entregada']);

        $this->assertSame(['Entregada REM-0001'], $this->avisos()->pluck('titulo')->all());
    }

    public function test_una_tarea_vencida_del_crm_se_encuentra_en_el_barrido(): void
    {
        $lead = CrmLead::create(['etapa_id' => $this->etapa('Nuevo')->id, 'titulo' => 'Puertas', 'estado' => 'activo']);
        CrmTarea::create(['lead_id' => $lead->id, 'responsable_id' => $this->admin->id, 'titulo' => 'Llamar', 'tipo' => 'llamada', 'fecha_vencimiento' => now()->subDays(3)->toDateString()]);
        CrmTarea::create(['lead_id' => $lead->id, 'responsable_id' => $this->admin->id, 'titulo' => 'Futura', 'tipo' => 'llamada', 'fecha_vencimiento' => now()->addDays(3)->toDateString()]);

        $this->armar($this->cadena(
            ['tipo' => 'horario', 'frecuencia' => ['tipo' => 'diaria', 'hora' => '07:00'], 'para_cada' => 'tarea_crm'],
            $this->si('dias_vencida', 'mayor', '0'),
            $this->accion('notificar', ['destinatario' => 'responsable', 'titulo' => '{{titulo}} vencida hace {{dias_vencida}} días']),
        ));

        Carbon::setTestNow(now()->setTime(7, 30));
        app(LanzadorAutomatizaciones::class)->programadas(now());

        $this->assertSame(['Llamar vencida hace 3 días'], $this->avisos()->pluck('titulo')->all());
    }
}
