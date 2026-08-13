<?php

namespace App\Services\IA;

use App\Models\Cliente;
use App\Models\Cotizacion;
use App\Models\CrmLead;
use App\Models\Op;
use App\Models\OpCuota;
use App\Models\Operario;
use App\Models\OrdenCompra;
use App\Models\Producto;
use App\Models\Remision;
use App\Models\SolicitudCompra;
use App\Support\ContextoSede;

/**
 * Consultas que el asistente puede hacer sobre los datos del SGI.
 *
 * DECISIÓN DE DISEÑO IMPORTANTE: la IA nunca escribe SQL ni toca la base de
 * datos. Solo elige una consulta de este catálogo cerrado; el cálculo lo hace
 * este código en PHP. Así las cifras que da el asistente son las mismas que
 * muestran las pantallas del sistema, y no puede inventarse una consulta rara
 * ni leer algo que no debería.
 *
 * Además cada consulta respeta:
 *  - Los PERMISOS del usuario que pregunta (si no puede ver Cartera en el
 *    sistema, tampoco se la cuenta el asistente).
 *  - La SEDE activa (o todas, si el usuario tiene ese alcance).
 *
 * Todas las consultas son de SOLO LECTURA.
 */
class ConsultasDatosService
{
    /**
     * Catálogo que se le muestra a la IA para que elija.
     * 'permiso' = qué necesita el usuario para que la consulta esté disponible.
     */
    public static function catalogo(): array
    {
        return [
            'ventas_resumen' => [
                'descripcion' => 'Resumen de cotizaciones: cantidad y montos por estado en un periodo. Sirve para "cuánto se vendió/cotizó".',
                'parametros'  => ['dias' => 'número de días hacia atrás (por defecto 30)'],
                'permiso'     => 'cotizaciones.ver',
            ],
            'ventas_por_vendedor' => [
                'descripcion' => 'Cotizaciones y montos agrupados por vendedor responsable.',
                'parametros'  => ['dias' => 'número de días hacia atrás (por defecto 30)'],
                'permiso'     => 'cotizaciones.ver',
            ],
            'produccion_resumen' => [
                'descripcion' => 'Cantidad de órdenes de producción por estado y su avance promedio.',
                'parametros'  => [],
                'permiso'     => 'ops.ver',
            ],
            'ops_por_entregar' => [
                'descripcion' => 'Órdenes de producción con entrega próxima o vencida que aún no se despachan.',
                'parametros'  => ['dias' => 'ventana de días hacia adelante (por defecto 15)'],
                'permiso'     => 'ops.ver',
            ],
            'inventario_bajo_stock' => [
                'descripcion' => 'Insumos que están por debajo de su stock mínimo.',
                'parametros'  => [],
                'permiso'     => 'inventario.ver',
            ],
            'cartera_pendiente' => [
                'descripcion' => 'Cuotas por cobrar: total pendiente y cuánto está vencido.',
                'parametros'  => [],
                'permiso'     => 'cartera.ver',
            ],
            'compras_pendientes' => [
                'descripcion' => 'Solicitudes y órdenes de compra que siguen abiertas.',
                'parametros'  => [],
                'permiso'     => 'solicitudes.ver',
            ],
            'crm_embudo' => [
                'descripcion' => 'Leads del CRM por estado y tasa de conversión.',
                'parametros'  => ['dias' => 'número de días hacia atrás (por defecto 90)'],
                'permiso'     => 'crm.ver',
            ],
            'clientes_resumen' => [
                'descripcion' => 'Cantidad de clientes y los que más han cotizado.',
                'parametros'  => [],
                'permiso'     => 'clientes.ver',
            ],
            'logistica_resumen' => [
                'descripcion' => 'Remisiones por estado (pendientes, en camino, entregadas).',
                'parametros'  => [],
                'permiso'     => 'remisiones.ver',
            ],
            'rrhh_resumen' => [
                'descripcion' => 'Cantidad de colaboradores activos por sede.',
                'parametros'  => [],
                'permiso'     => 'rrhh.ver',
            ],
            'productividad' => [
                'descripcion' => 'Informe de productividad: pasos completados por colaborador, tiempo real contra estimado y desviación. Sirve para "cómo va la productividad" o "quién rinde más".',
                'parametros'  => ['dias' => 'número de días hacia atrás (por defecto 30)'],
                'permiso'     => 'trabajos.ver',
            ],
            'buscar_producto' => [
                'descripcion' => 'Stock y datos de un producto concreto. Úsala cuando pregunten por un producto por su nombre o referencia, o "cuánto tengo de X".',
                'parametros'  => ['texto' => 'nombre o referencia a buscar (obligatorio)'],
                'permiso'     => 'productos.ver',
            ],
            'recomendar_producto' => [
                'descripcion' => 'Encuentra qué productos o ensambles del catálogo sirven para una '
                    .'necesidad descrita con palabras: uso, temperatura, medidas, material, presupuesto. '
                    .'Úsala cuando pregunten "qué me recomiendas para…", "qué sirve para…" o "cuál me '
                    .'conviene si necesito…". NO la uses para buscar un producto por su nombre exacto: '
                    .'para eso está buscar_producto.',
                'parametros'  => ['necesidad' => 'la necesidad tal como la contó el cliente, completa (obligatorio)'],
                'permiso'     => 'productos.ver',
            ],
            'estado_op' => [
                'descripcion' => 'Diagnóstico completo de UNA orden de producción por su número: avance, calidad, saldo por cobrar, remisiones, y si se puede despachar o qué falta. Úsala para "cómo va la OP X" o "puedo entregar la OP X".',
                'parametros'  => ['numero' => 'número de la OP, ej. OP-0191 o 191 (obligatorio)'],
                'permiso'     => 'ops.ver',
            ],
        ];
    }

    /**
     * Catálogo filtrado por lo que el usuario tiene permitido ver.
     */
    public function disponibles(): array
    {
        $user = auth()->user();

        return collect(static::catalogo())
            ->filter(fn ($cfg) => $user?->tienePermiso($cfg['permiso']))
            ->all();
    }

    /**
     * Ejecuta una consulta del catálogo. Devuelve null si no existe o si el
     * usuario no tiene permiso — nunca lanza datos que no debería ver.
     */
    public function ejecutar(string $consulta, array $parametros = []): ?array
    {
        if (! array_key_exists($consulta, $this->disponibles())) {
            return null;
        }

        $dias = (int) ($parametros['dias'] ?? 0);

        return match ($consulta) {
            'ventas_resumen'        => $this->ventasResumen($dias ?: 30),
            'ventas_por_vendedor'   => $this->ventasPorVendedor($dias ?: 30),
            'produccion_resumen'    => $this->produccionResumen(),
            'ops_por_entregar'      => $this->opsPorEntregar($dias ?: 15),
            'inventario_bajo_stock' => $this->inventarioBajoStock(),
            'cartera_pendiente'     => $this->carteraPendiente(),
            'compras_pendientes'    => $this->comprasPendientes(),
            'crm_embudo'            => $this->crmEmbudo($dias ?: 90),
            'clientes_resumen'      => $this->clientesResumen(),
            'logistica_resumen'     => $this->logisticaResumen(),
            'rrhh_resumen'          => $this->rrhhResumen(),
            'productividad'         => $this->productividad($dias ?: 30),
            'buscar_producto'       => $this->buscarProducto((string) ($parametros['texto'] ?? '')),
            'recomendar_producto'   => app(RecomendadorProductosService::class)
                                            ->candidatos((string) ($parametros['necesidad'] ?? '')),
            'estado_op'             => $this->estadoOp((string) ($parametros['numero'] ?? '')),
            default                 => null,
        };
    }

    /** Contexto de sede, para que el asistente pueda decir de qué está hablando. */
    private function alcance(): string
    {
        $sede = ContextoSede::actual();

        return $sede ? "Sede: {$sede->nombre}" : 'Alcance: todas las sedes';
    }

    // ─── Ventas ───────────────────────────────────────────────────────────────

    private function ventasResumen(int $dias): array
    {
        $desde = now()->subDays($dias);

        $filas = ContextoSede::aplicar(Cotizacion::query())
            ->where('created_at', '>=', $desde)
            ->selectRaw('estado, COUNT(*) as cantidad, SUM(total) as monto')
            ->groupBy('estado')
            ->get();

        return [
            'alcance'      => $this->alcance(),
            'periodo'      => "Últimos {$dias} días",
            'por_estado'   => $filas->map(fn ($f) => [
                'estado'   => $f->estado,
                'cantidad' => (int) $f->cantidad,
                'monto'    => round((float) $f->monto),
            ])->all(),
            'total_cotizado' => round((float) $filas->sum('monto')),
            'total_aprobado' => round((float) $filas->whereIn('estado', ['aprobada', 'en_produccion'])->sum('monto')),
            'moneda'         => 'COP',
        ];
    }

    private function ventasPorVendedor(int $dias): array
    {
        $filas = ContextoSede::aplicar(Cotizacion::query())
            ->where('created_at', '>=', now()->subDays($dias))
            ->with('responsable:id,name')
            ->selectRaw('responsable_id, COUNT(*) as cantidad, SUM(total) as monto')
            ->groupBy('responsable_id')
            ->orderByDesc('monto')
            ->get();

        return [
            'alcance'   => $this->alcance(),
            'periodo'   => "Últimos {$dias} días",
            'vendedores'=> $filas->map(fn ($f) => [
                'vendedor' => $f->responsable?->name ?? 'Sin asignar',
                'cantidad' => (int) $f->cantidad,
                'monto'    => round((float) $f->monto),
            ])->all(),
            'moneda'    => 'COP',
        ];
    }

    // ─── Producción ───────────────────────────────────────────────────────────

    private function produccionResumen(): array
    {
        $filas = ContextoSede::aplicar(Op::query())
            ->selectRaw('estado, COUNT(*) as cantidad, AVG(porcentaje_avance) as avance')
            ->groupBy('estado')
            ->get();

        return [
            'alcance'    => $this->alcance(),
            'por_estado' => $filas->map(fn ($f) => [
                'estado'           => $f->estado,
                'cantidad'         => (int) $f->cantidad,
                'avance_promedio'  => round((float) $f->avance, 1) . '%',
            ])->all(),
            'total_ops'  => (int) $filas->sum('cantidad'),
        ];
    }

    private function opsPorEntregar(int $dias): array
    {
        $ops = ContextoSede::aplicar(Op::query())
            ->whereNotNull('fecha_entrega_estimada')
            ->where('estado', '!=', 'despachada')
            ->whereDate('fecha_entrega_estimada', '<=', now()->addDays($dias))
            ->with('cliente:id,nombre')
            ->orderBy('fecha_entrega_estimada')
            ->limit(30)
            ->get();

        return [
            'alcance' => $this->alcance(),
            'ventana' => "Entregas hasta dentro de {$dias} días (incluye vencidas)",
            'ops'     => $ops->map(fn (Op $op) => [
                'numero'   => $op->numero,
                'cliente'  => $op->cliente?->nombre ?? '—',
                'estado'   => $op->estado,
                'entrega'  => $op->fecha_entrega_estimada?->format('d/m/Y'),
                'vencida'  => $op->fecha_entrega_estimada?->isPast() ?? false,
                'avance'   => round((float) $op->porcentaje_avance, 1) . '%',
            ])->all(),
            'cantidad' => $ops->count(),
        ];
    }

    // ─── Inventario ───────────────────────────────────────────────────────────

    private function inventarioBajoStock(): array
    {
        $bodegas = ContextoSede::idsBodegasVisibles();

        $items = Producto::insumos()
            ->where('activo', true)
            ->with('stocks')
            ->get()
            ->map(function (Producto $p) use ($bodegas) {
                $stock = (float) $p->stocks->whereIn('bodega_id', $bodegas)->sum('cantidad');

                return [
                    'producto' => $p->nombre,
                    'stock'    => $stock,
                    'minimo'   => (float) $p->stock_minimo,
                    'unidad'   => $p->unidad_medida,
                ];
            })
            ->filter(fn ($i) => $i['stock'] <= $i['minimo'])
            ->sortBy('stock')
            ->take(30)
            ->values();

        return [
            'alcance'  => $this->alcance(),
            'items'    => $items->all(),
            'cantidad' => $items->count(),
        ];
    }

    // ─── Financiero ───────────────────────────────────────────────────────────

    private function carteraPendiente(): array
    {
        $sedeId = ContextoSede::id();

        $cuotas = OpCuota::whereIn('estado', ['pendiente', 'parcial'])
            ->when($sedeId, fn ($q) => $q->whereHas('op', fn ($q2) => $q2->where('sede_id', $sedeId)))
            ->with('op:id,numero,cliente_id', 'op.cliente:id,nombre')
            ->get();

        $vencidas = $cuotas->filter(fn (OpCuota $c) => $c->fecha_vencimiento && $c->fecha_vencimiento->isPast());

        return [
            'alcance'          => $this->alcance(),
            'total_pendiente'  => round($cuotas->sum(fn (OpCuota $c) => $c->saldo)),
            'total_vencido'    => round($vencidas->sum(fn (OpCuota $c) => $c->saldo)),
            'cuotas_pendientes'=> $cuotas->count(),
            'cuotas_vencidas'  => $vencidas->count(),
            'top_vencidas'     => $vencidas->sortByDesc(fn (OpCuota $c) => $c->saldo)->take(10)
                ->map(fn (OpCuota $c) => [
                    'op'      => $c->op?->numero,
                    'cliente' => $c->op?->cliente?->nombre ?? '—',
                    'saldo'   => round($c->saldo),
                    'vencio'  => $c->fecha_vencimiento?->format('d/m/Y'),
                ])->values()->all(),
            'moneda'           => 'COP',
        ];
    }

    // ─── Compras ──────────────────────────────────────────────────────────────

    private function comprasPendientes(): array
    {
        return [
            'alcance'     => $this->alcance(),
            'solicitudes' => ContextoSede::aplicar(SolicitudCompra::query())
                ->selectRaw('estado, COUNT(*) as cantidad')
                ->groupBy('estado')
                ->pluck('cantidad', 'estado')
                ->all(),
            'ordenes'     => ContextoSede::aplicar(OrdenCompra::query())
                ->selectRaw('estado, COUNT(*) as cantidad')
                ->groupBy('estado')
                ->pluck('cantidad', 'estado')
                ->all(),
        ];
    }

    // ─── CRM ──────────────────────────────────────────────────────────────────

    private function crmEmbudo(int $dias): array
    {
        $sedeId = ContextoSede::id();

        $base = CrmLead::where('created_at', '>=', now()->subDays($dias))
            ->when($sedeId, fn ($q) => $q->where('sede_id', $sedeId));

        $porEstado = (clone $base)->selectRaw('estado, COUNT(*) as cantidad')
            ->groupBy('estado')->pluck('cantidad', 'estado')->all();

        $total   = array_sum($porEstado);
        $ganados = $porEstado['ganado'] ?? 0;

        return [
            'alcance'    => $this->alcance(),
            'periodo'    => "Últimos {$dias} días",
            'por_estado' => $porEstado,
            'total'      => $total,
            'conversion' => $total > 0 ? round($ganados / $total * 100, 1) . '%' : '0%',
        ];
    }

    // ─── Clientes ─────────────────────────────────────────────────────────────

    private function clientesResumen(): array
    {
        $sedeId = ContextoSede::id();

        $top = Cotizacion::when($sedeId, fn ($q) => $q->where('sede_id', $sedeId))
            ->with('cliente:id,nombre')
            ->selectRaw('cliente_id, COUNT(*) as cantidad, SUM(total) as monto')
            ->groupBy('cliente_id')
            ->orderByDesc('monto')
            ->limit(10)
            ->get();

        return [
            'alcance' => $this->alcance(),
            'total_clientes' => ContextoSede::aplicar(Cliente::query())->count(),
            'top_clientes'   => $top->map(fn ($f) => [
                'cliente'      => $f->cliente?->nombre ?? '—',
                'cotizaciones' => (int) $f->cantidad,
                'monto'        => round((float) $f->monto),
            ])->all(),
            'moneda'  => 'COP',
        ];
    }

    // ─── Logística ────────────────────────────────────────────────────────────

    private function logisticaResumen(): array
    {
        return [
            'alcance'    => $this->alcance(),
            'por_estado' => ContextoSede::aplicar(Remision::query())
                ->selectRaw('estado, COUNT(*) as cantidad')
                ->groupBy('estado')
                ->pluck('cantidad', 'estado')
                ->all(),
        ];
    }

    // ─── RRHH ─────────────────────────────────────────────────────────────────

    private function rrhhResumen(): array
    {
        return [
            'alcance'  => $this->alcance(),
            'activos'  => ContextoSede::aplicar(Operario::query())->where('estado', 'activo')->count(),
            'total'    => ContextoSede::aplicar(Operario::query())->count(),
        ];
    }

    // ─── Productividad ────────────────────────────────────────────────────────

    /**
     * Pasos completados por colaborador, con tiempo real contra estimado.
     * Es el insumo para responder "cómo va la productividad".
     */
    private function productividad(int $dias): array
    {
        $sedeId = ContextoSede::id();

        $pasos = \App\Models\OpItemTrabajoPaso::where('completado', true)
            ->where('completado_at', '>=', now()->subDays($dias))
            ->when($sedeId, fn ($q) => $q->whereHas(
                'trabajo.opItem.op',
                fn ($q2) => $q2->where('sede_id', $sedeId)
            ))
            ->with(['operario:id,nombre', 'estacion:id,nombre'])
            ->get();

        $porColaborador = $pasos
            ->groupBy(fn ($p) => $p->operario?->nombre ?? 'Sin asignar')
            ->map(function ($grupo) {
                $real     = (float) $grupo->sum('tiempo_minutos');
                $estimado = (float) $grupo->sum('tiempo_estimado_minutos');

                return [
                    'pasos_completados' => $grupo->count(),
                    'minutos_reales'    => round($real),
                    'minutos_estimados' => round($estimado),
                    // Positivo = se demoró más de lo estimado.
                    'desviacion_pct'    => $estimado > 0
                        ? round(($real - $estimado) / $estimado * 100, 1)
                        : null,
                ];
            })
            ->sortByDesc('pasos_completados');

        $porEstacion = $pasos
            ->groupBy(fn ($p) => $p->estacion?->nombre ?? 'Sin estación')
            ->map(fn ($g) => [
                'pasos'          => $g->count(),
                'minutos_reales' => round((float) $g->sum('tiempo_minutos')),
            ])
            ->sortByDesc('pasos');

        $realTotal     = (float) $pasos->sum('tiempo_minutos');
        $estimadoTotal = (float) $pasos->sum('tiempo_estimado_minutos');

        return [
            'alcance'          => $this->alcance(),
            'periodo'          => "Últimos {$dias} días",
            'pasos_completados'=> $pasos->count(),
            'minutos_reales'   => round($realTotal),
            'minutos_estimados'=> round($estimadoTotal),
            'desviacion_pct'   => $estimadoTotal > 0
                ? round(($realTotal - $estimadoTotal) / $estimadoTotal * 100, 1)
                : null,
            'nota'             => 'Desviación positiva significa que el trabajo tomó más tiempo del estimado.',
            'por_colaborador'  => $porColaborador->all(),
            'por_estacion'     => $porEstacion->all(),
        ];
    }

    // ─── Producto puntual ─────────────────────────────────────────────────────

    private function buscarProducto(string $texto): array
    {
        if (trim($texto) === '') {
            return ['error' => 'Falta el nombre o referencia del producto a buscar.'];
        }

        $bodegas = ContextoSede::idsBodegasVisibles();

        $productos = Producto::where('activo', true)
            ->where(fn ($q) => $q->where('nombre', 'like', "%{$texto}%")
                                 ->orWhere('referencia', 'like', "%{$texto}%"))
            ->with('stocks.bodega:id,nombre')
            ->limit(10)
            ->get();

        return [
            'alcance'   => $this->alcance(),
            'buscado'   => $texto,
            'productos' => $productos->map(function (Producto $p) use ($bodegas) {
                $stocks = $p->stocks->whereIn('bodega_id', $bodegas);

                return [
                    'nombre'      => $p->nombre,
                    'referencia'  => $p->referencia,
                    'unidad'      => $p->unidad_medida,
                    'stock_total' => (float) $stocks->sum('cantidad'),
                    'stock_minimo'=> (float) $p->stock_minimo,
                    'por_bodega'  => $stocks->map(fn ($s) => [
                        'bodega'   => $s->bodega?->nombre ?? '—',
                        'cantidad' => (float) $s->cantidad,
                    ])->values()->all(),
                ];
            })->all(),
            'encontrados' => $productos->count(),
        ];
    }

    // ─── Diagnóstico de una OP ────────────────────────────────────────────────

    /**
     * Todo lo que hace falta para responder "¿puedo entregar la OP X?".
     *
     * La regla del negocio es que el control de calidad es obligatorio antes
     * de despachar; aquí se evalúa eso junto con el avance y el saldo, y se
     * devuelve el veredicto ya calculado para que la IA no tenga que
     * interpretarlo por su cuenta.
     */
    private function estadoOp(string $numero): array
    {
        if (trim($numero) === '') {
            return ['error' => 'Falta el número de la OP.'];
        }

        // Acepta "OP-0191", "op 191" o "191".
        $soloDigitos = preg_replace('/\D/', '', $numero);

        $op = ContextoSede::aplicar(Op::query())
            ->where(function ($q) use ($numero, $soloDigitos) {
                $q->where('numero', $numero);
                if ($soloDigitos !== '') {
                    $q->orWhere('numero', 'like', "%{$soloDigitos}");
                }
            })
            ->with(['cliente:id,nombre', 'sede:id,nombre'])
            ->first();

        if (! $op) {
            return ['error' => "No se encontró la OP \"{$numero}\" dentro de tu alcance de sedes."];
        }

        $saldo = (float) OpCuota::where('op_id', $op->id)
            ->whereIn('estado', ['pendiente', 'parcial'])
            ->get()
            ->sum(fn (OpCuota $c) => $c->saldo);

        $remisiones = Remision::where('op_id', $op->id)->get(['numero', 'estado']);

        // Requisitos para poder despachar.
        $calidadOk = $op->calidad_aprobada_at !== null;
        $avanceOk  = (float) $op->porcentaje_avance >= 100;
        $yaDespachada = $op->estado === 'despachada';

        $faltantes = [];
        if (! $calidadOk)   $faltantes[] = 'El control de calidad no está aprobado (es obligatorio antes de despachar).';
        if (! $avanceOk)    $faltantes[] = 'La producción no está al 100%: va en ' . round((float) $op->porcentaje_avance, 1) . '%.';
        if ($saldo > 0)     $faltantes[] = 'Queda saldo por cobrar: $' . number_format($saldo, 0, ',', '.') . '. Revisa la política de cartera antes de entregar.';

        return [
            'alcance'    => $this->alcance(),
            'op'         => $op->numero,
            'cliente'    => $op->cliente?->nombre ?? '—',
            'sede'       => $op->sede?->nombre ?? '—',
            'estado'     => $op->estado,
            'avance'     => round((float) $op->porcentaje_avance, 1) . '%',
            'calidad_aprobada'    => $calidadOk,
            'calidad_aprobada_el' => $op->calidad_aprobada_at?->format('d/m/Y'),
            'fecha_entrega'       => $op->fecha_entrega_estimada?->format('d/m/Y'),
            'saldo_pendiente'     => round($saldo),
            'remisiones'          => $remisiones->all(),
            'ya_despachada'       => $yaDespachada,
            // Veredicto ya calculado: la IA solo lo comunica, no lo deduce.
            'puede_despacharse'   => $calidadOk && $avanceOk && ! $yaDespachada,
            'que_falta'           => $faltantes,
            'moneda'              => 'COP',
        ];
    }
}
