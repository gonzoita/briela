<?php

namespace App\Http\Controllers;

use App\Models\OrdenCompra;
use App\Models\OrdenCompraItem;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\ProveedoresProductoService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;

class OrdenCompraController extends Controller
{
    public function index(Request $request): Response
    {
        $query = \App\Support\ContextoSede::aplicar(OrdenCompra::query())
            ->with(['proveedor:id,nombre', 'creadoPor:id,name', 'sede:id,nombre'])
            ->when($request->filled('estado'), fn ($q) => $q->where('estado', $request->estado))
            ->when($request->filled('proveedor_id'), fn ($q) => $q->where('proveedor_id', $request->proveedor_id))
            ->when($request->filled('buscar'), fn ($q) => $q->where('numero', 'like', "%{$request->buscar}%"))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('created_at', '>=', $request->desde))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('created_at', '<=', $request->hasta))
;

        // El orden lo pide la pantalla. `Orden::aplicar` valida el campo contra esta
        // lista: lo que llegue por `?orden=` y no esté aquí se ignora, así que el
        // parámetro nunca toca el SQL.
        $ordenLista = \App\Support\Orden::aplicar($query, $request, [
            'numero'     => 'numero',
            'estado'     => 'estado',
            'total'      => 'total',
            'created_at' => 'created_at',
        ]);

        $ordenes = $query->paginate(20)->withQueryString();

        return Inertia::render('Compras/Ordenes/Index', [
            'ordenes'     => $ordenes,
            'orden'      => $ordenLista,
            'filters'     => $request->only(['estado', 'proveedor_id', 'buscar', 'desde', 'hasta']),
            'proveedores' => Proveedor::where('activo', true)->select('id', 'nombre')->orderBy('nombre')->get(),
        ]);
    }

    public function create(): Response
    {
        $insumos = Producto::insumos()->where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'referencia', 'nombre', 'unidad_medida', 'precio_promedio_compra']);

        $mapa = app(ProveedoresProductoService::class)->mapaParaOrden($insumos->pluck('id'));

        $activos = Proveedor::where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'responsabilidades_fiscales']);

        $calificaciones = app(\App\Services\CalificacionProveedorService::class)->paraProveedores($activos->pluck('id'));

        return Inertia::render('Compras/Ordenes/Create', [
            // Con lo que dice su RUT sobre el IVA: un proveedor no responsable no factura IVA, y
            // la línea no debería arrancar con uno. `null` es «no sabemos»: no se asume.
            'proveedores' => $activos->map(fn (Proveedor $p) => [
                'id'              => $p->id,
                'nombre'          => $p->nombre,
                'responsable_iva' => $p->responsableDeIva(),
                'iva_defecto'     => $p->ivaPorDefecto(),
                'calificacion'    => $calificaciones[$p->id] ?? null,
            ]),
            // Insumos del inventario real (productos). Se normaliza a la
            // forma que espera el Vue (codigo/nombre/unidad/precio_promedio).
            'items'       => $insumos->map(fn ($p) => [
                'id'              => $p->id,
                'codigo'          => $p->referencia,
                'nombre'          => $p->nombre,
                'unidad'          => $p->unidad_medida,
                'precio_promedio' => (float) $p->precio_promedio_compra,
                // Código, precio y vigencia de CADA proveedor para este insumo, por id de
                // proveedor: al elegir el de la orden, la línea se llena sola.
                'proveedores'     => (object) ($mapa[$p->id] ?? []),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'proveedor_id'           => 'required|exists:proveedores,id',
            'solicitud_id'           => 'nullable|exists:solicitudes_compra,id',
            'fecha_entrega_esperada' => 'nullable|date',
            'condiciones'            => 'nullable|string',
            'notas'                  => 'nullable|string',
            'items'                  => 'required|array|min:1',
            'items.*.item_id'        => 'nullable|exists:productos,id',
            'items.*.referencia_proveedor' => 'nullable|string|max:80',
            'items.*.descripcion'    => 'required|string',
            'items.*.cantidad'       => 'required|numeric|min:0.001',
            'items.*.unidad'         => 'required|string',
            'items.*.precio_unitario'=> 'required|numeric|min:0',
            'items.*.impuesto_pct'   => 'numeric|min:0|max:100',
        ]);

        $orden = OrdenCompra::create([
            'estado'                 => 'borrador',
            'proveedor_id'           => $data['proveedor_id'],
            'solicitud_id'           => $data['solicitud_id'] ?? null,
            'creado_por'             => auth()->id(),
            'fecha_entrega_esperada' => $data['fecha_entrega_esperada'] ?? null,
            'condiciones'            => $data['condiciones'] ?? null,
            'notas'                  => $data['notas'] ?? null,
        ]);

        $this->crearLineas($orden, $data['items']);

        $orden->recalcularTotales();

        return redirect("/compras/ordenes/{$orden->id}")->with('success', "Orden {$orden->numero} creada.");
    }

    /**
     * Crea las líneas de una orden con el código del proveedor al que se le compra.
     *
     * Manda el código que quien compra escribió; si no escribió ninguno, el que ese proveedor
     * ya tiene registrado para el producto. Y si escribió uno, queda guardado como equivalencia
     * en la ficha: es la segunda puerta para configurarla, y la que se usa de verdad, porque
     * el código del proveedor se descubre al armar la orden.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function crearLineas(OrdenCompra $orden, array $items): void
    {
        $proveedores = app(ProveedoresProductoService::class);

        foreach ($items as $linea) {
            $itemId = $linea['item_id'] ?? null;
            $escrito = trim((string) ($linea['referencia_proveedor'] ?? ''));

            OrdenCompraItem::create([
                'orden_id'             => $orden->id,
                'item_id'              => $itemId,
                'referencia_proveedor' => $escrito !== ''
                    ? $escrito
                    : $proveedores->de($itemId, $orden->proveedor_id)?->referencia_proveedor,
                'descripcion'          => $linea['descripcion'],
                'cantidad'             => $linea['cantidad'],
                'unidad'               => $linea['unidad'],
                'precio_unitario'      => $linea['precio_unitario'],
                'impuesto_pct'         => $linea['impuesto_pct'] ?? 0,
                'total_linea'          => $linea['cantidad'] * $linea['precio_unitario'],
            ]);

            if ($itemId) {
                $proveedores->guardarEquivalencia($itemId, $orden->proveedor_id, $escrito, (float) $linea['precio_unitario']);
            }
        }
    }

    public function show(OrdenCompra $orden): Response
    {
        $orden->load([
            'proveedor',
            'creadoPor:id,name',
            'solicitud:id,numero',
            // 'item' ahora apunta a productos: el código es 'referencia'.
            'items.item:id,nombre,referencia',
            'recepciones.recibidoPor:id,name',
        ]);

        return Inertia::render('Compras/Ordenes/Show', [
            'orden'       => $orden,
            // Lo que la empresa le retiene al proveedor al pagar. Estimación: ver RetencionesService.
            'retenciones' => app(\App\Services\RetencionesService::class)->paraCompra($orden),
        ]);
    }

    public function update(Request $request, OrdenCompra $orden): RedirectResponse
    {
        if (!in_array($orden->estado, ['borrador'])) {
            return back()->with('error', 'Solo se pueden editar órdenes en borrador.');
        }

        $data = $request->validate([
            'proveedor_id'           => 'required|exists:proveedores,id',
            'fecha_entrega_esperada' => 'nullable|date',
            'condiciones'            => 'nullable|string',
            'notas'                  => 'nullable|string',
            'items'                  => 'nullable|array',
            'items.*.item_id'        => 'nullable|exists:productos,id',
            'items.*.referencia_proveedor' => 'nullable|string|max:80',
            'items.*.descripcion'    => 'required|string',
            'items.*.cantidad'       => 'required|numeric|min:0.001',
            'items.*.unidad'         => 'required|string',
            'items.*.precio_unitario'=> 'required|numeric|min:0',
            'items.*.impuesto_pct'   => 'numeric|min:0|max:100',
        ]);

        $orden->update([
            'proveedor_id'           => $data['proveedor_id'],
            'fecha_entrega_esperada' => $data['fecha_entrega_esperada'] ?? null,
            'condiciones'            => $data['condiciones'] ?? null,
            'notas'                  => $data['notas'] ?? null,
        ]);

        if (!empty($data['items'])) {
            $orden->items()->delete();
            $this->crearLineas($orden, $data['items']);
            $orden->recalcularTotales();
        }

        return back()->with('success', 'Orden actualizada correctamente.');
    }

    public function enviar(OrdenCompra $orden): RedirectResponse
    {
        if ($orden->estado !== 'borrador') {
            return back()->with('error', 'La orden no está en borrador.');
        }

        $orden->update(['estado' => 'enviada']);

        // Lo que sale al proveedor es lo acordado: ahí se actualiza su precio y se anota en el
        // historial. En el borrador no, que es una intención y se cambia diez veces.
        app(ProveedoresProductoService::class)->registrarPrecios($orden);

        return back()->with('success', "Orden {$orden->numero} enviada al proveedor.");
    }

    public function recibir(Request $request, OrdenCompra $orden): RedirectResponse
    {
        if (!in_array($orden->estado, ['enviada', 'confirmada', 'recibida_parcial'])) {
            return back()->with('error', 'La orden no está en un estado válido para recibir.');
        }

        $data = $request->validate([
            'items'                    => 'required|array|min:1',
            'items.*.id'               => 'required|exists:ordenes_compra_items,id',
            'items.*.cantidad_recibida'=> 'required|numeric|min:0',
            // El papel que trae el proveedor. Opcional, pero sin él la entrega no se puede
            // comprobar —y la calificación del proveedor lo tiene en cuenta—.
            'factura_numero'           => 'nullable|string|max:60',
            'remision_numero'          => 'nullable|string|max:60',
            'fecha_documento'          => 'nullable|date',
            'fecha_recepcion'          => 'nullable|date|before_or_equal:today',
            'observaciones'            => 'nullable|string|max:1000',
        ]);

        $orden->recibir($data['items'], auth()->id(), collect($data)->except('items')->all());

        // Aviso a producción: llegó mercancía (puede resolver un faltante).
        app(\App\Services\NotificacionService::class)->paraRol(
            ['administrador', 'jefe_produccion'],
            'mercancia_recibida',
            "Mercancía recibida — OC {$orden->numero}",
            'Se registró la recepción de una orden de compra. El stock se actualizó.',
            '/inventario',
            excluirUserId: auth()->id(),
        );

        return back()->with('success', 'Recepción registrada correctamente.');
    }

    public function pdf(OrdenCompra $orden): HttpResponse
    {
        $orden->load(['proveedor', 'creadoPor:id,name', 'items.item:id,nombre,referencia']);

        $pdf = Pdf::loadView('pdf.orden-compra', compact('orden'))
            ->setPaper('letter', 'portrait');

        return $pdf->download("OC-{$orden->numero}.pdf");
    }
}
