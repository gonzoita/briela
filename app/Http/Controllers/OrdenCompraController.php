<?php

namespace App\Http\Controllers;

use App\Models\OrdenCompra;
use App\Models\OrdenCompraItem;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Services\ProveedoresProductoService;
use App\Services\TasaCambioService;
use App\Support\Monedas;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Validation\Rule;
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
        return $this->formulario();
    }

    /** El formulario de crear y de editar es el mismo: con `$orden` llega cargado. */
    private function formulario(?array $orden = null): Response
    {
        $activos = Proveedor::where('activo', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'responsabilidades_fiscales']);

        $calificaciones = app(\App\Services\CalificacionProveedorService::class)->paraProveedores($activos->pluck('id'));

        return Inertia::render('Compras/Ordenes/Create', $this->propsMoneda() + [
            'orden' => $orden,
            // Con lo que dice su RUT sobre el IVA: un proveedor no responsable no factura IVA, y
            // la línea no debería arrancar con uno. `null` es «no sabemos»: no se asume.
            'proveedores' => $activos->map(fn (Proveedor $p) => [
                'id'              => $p->id,
                'nombre'          => $p->nombre,
                'responsable_iva' => $p->responsableDeIva(),
                'iva_defecto'     => $p->ivaPorDefecto(),
                'calificacion'    => $calificaciones[$p->id] ?? null,
            ]),
        ]);
    }

    /**
     * El buscador de productos de la orden.
     *
     * Busca en **todo** lo que se puede comprar —insumos, productos de reventa, variantes—, no
     * solo en los marcados como insumo: la orden tiene que ser coherente con el módulo de
     * productos, y un producto que se compra para revender también se compra con una orden. Y se
     * encuentra por lo que cada quien lo llama: el nombre, la referencia interna **o el código de
     * cualquiera de sus proveedores** (`1256899P` lleva a la bisagra `IC5260`).
     *
     * Va en el servidor y no como una lista cargada en la pantalla: con miles de productos,
     * mandarlos todos en cada «Nueva orden» es un costo que se paga en cada clic.
     */
    public function buscarProductos(Request $request): \Illuminate\Http\JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        $porCodigo = \App\Models\ProductoProveedor::where('referencia_proveedor', 'like', "%{$q}%")->select('producto_id');

        $productos = Producto::with('padre:id,nombre')
            ->seleccionables()
            ->where('activo', true)
            ->where('tipo', 'producto')
            ->whereNull('ensamble_id') // lo que la empresa fabrica no se le compra a un proveedor
            ->where(fn ($w) => $w
                ->where('nombre', 'like', "%{$q}%")
                ->orWhere('referencia', 'like', "%{$q}%")
                ->orWhereHas('padre', fn ($p) => $p->where('nombre', 'like', "%{$q}%"))
                ->orWhereIn('id', $porCodigo))
            ->orderBy('nombre')
            ->limit(15)
            ->get(['id', 'referencia', 'nombre', 'producto_padre_id', 'valor_variante', 'unidad_medida', 'precio_promedio_compra']);

        $servicio = app(ProveedoresProductoService::class);
        $ids      = $productos->pluck('id');
        $mapa     = $servicio->mapaParaOrden($ids);
        $equiv    = $servicio->equivalencias($ids);

        return response()->json($productos->map(function (Producto $p) use ($mapa, $equiv, $q) {
            $lista = $equiv[$p->id] ?? [];
            // Si lo encontró el código de un proveedor y no el nombre, se dice cuál fue.
            $coincide = collect($lista)->first(fn ($e) => $e['codigo'] && mb_stripos($e['codigo'], $q) !== false);

            return [
                'id'              => $p->id,
                'codigo'          => $p->referencia,
                'nombre'          => $p->nombre_completo,
                'unidad'          => $p->unidad_medida,
                'precio_promedio' => (float) $p->precio_promedio_compra,
                'proveedores'     => (object) ($mapa[$p->id] ?? []),
                'equivalencias'   => $lista,
                'coincidencia'    => $coincide ? "{$coincide['proveedor']}: {$coincide['codigo']}" : null,
            ];
        })->values());
    }

    /** Las monedas que se pueden elegir y las tasas de hoy, para el formulario de crear y de editar. */
    private function propsMoneda(): array
    {
        return [
            'base'    => Monedas::base(),
            'monedas' => collect(Monedas::CATALOGO)->except(Monedas::base())->map(fn ($m, $c) => ['codigo' => $c, 'nombre' => $m['nombre']])->values(),
            'tasas'   => app(TasaCambioService::class)->paraInterfaz(),
        ];
    }

    /** Las reglas que comparten crear y editar. @return array<string, mixed> */
    private function reglas(): array
    {
        return [
            'proveedor_id'           => 'required|exists:proveedores,id',
            'moneda'                 => ['nullable', 'string', Rule::in(array_keys(Monedas::CATALOGO))],
            'tasa_cambio'            => 'nullable|numeric|gt:0|max:9999999999',
            'tasa_fecha'             => 'nullable|date',
            'fecha_entrega_esperada' => 'nullable|date',
            'condiciones'            => 'nullable|string',
            'notas'                  => 'nullable|string',
            'items'                  => 'required|array|min:1',
            'items.*.id'             => 'nullable|integer',
            'items.*.item_id'        => 'nullable|exists:productos,id',
            'items.*.referencia_proveedor' => 'nullable|string|max:80',
            'items.*.descripcion'    => 'required|string',
            'items.*.cantidad'       => 'required|numeric|min:0.001',
            'items.*.unidad'         => 'required|string',
            // En la moneda de la orden: se pasa a la base al guardar.
            'items.*.precio_unitario'=> 'required|numeric|min:0|max:9999999999999',
            'items.*.impuesto_pct'   => 'numeric|min:0|max:100',
        ];
    }

    /**
     * La moneda y la tasa de la orden, ya normalizadas: en la moneda base la tasa es 1 y no se guarda
     * moneda; en otra, la tasa es obligatoria (sin ella no hay cómo pasar a la base).
     *
     * @return array{moneda: ?string, tasa_cambio: ?float, tasa_fecha: ?string}
     */
    private function monedaDe(array $data): array
    {
        $moneda = strtoupper((string) ($data['moneda'] ?? ''));

        if ($moneda === '' || $moneda === Monedas::base()) {
            return ['moneda' => null, 'tasa_cambio' => null, 'tasa_fecha' => null];
        }

        $tasa = (float) ($data['tasa_cambio'] ?? 0);

        if ($tasa <= 0) {
            throw \Illuminate\Validation\ValidationException::withMessages(['tasa_cambio' => "Escribe la tasa de {$moneda}: sin ella no se puede pasar el valor a " . Monedas::base() . '.']);
        }

        return ['moneda' => $moneda, 'tasa_cambio' => $tasa, 'tasa_fecha' => $data['tasa_fecha'] ?? today()->toDateString()];
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->reglas() + ['solicitud_id' => 'nullable|exists:solicitudes_compra,id']);

        $orden = OrdenCompra::create([
            'estado'                 => 'borrador',
            'proveedor_id'           => $data['proveedor_id'],
            'solicitud_id'           => $data['solicitud_id'] ?? null,
            'creado_por'             => auth()->id(),
            'fecha_entrega_esperada' => $data['fecha_entrega_esperada'] ?? null,
            'condiciones'            => $data['condiciones'] ?? null,
            'notas'                  => $data['notas'] ?? null,
        ] + $this->monedaDe($data));

        $this->crearLineas($orden, $data['items']);

        $orden->recalcularTotales();

        return redirect("/compras/ordenes/{$orden->id}")->with('success', "Orden {$orden->numero} creada.");
    }

    /** El formulario de editar: el mismo de crear, con la orden cargada. */
    public function edit(OrdenCompra $orden): Response|RedirectResponse
    {
        if (! $orden->esEditable()) {
            return redirect("/compras/ordenes/{$orden->id}")->with('error', 'Una orden recibida por completo o cancelada no se edita.');
        }

        $orden->load(['items.item:id,nombre,referencia', 'recepciones:id,orden_compra_id']);

        return $this->formulario([
            'id' => $orden->id, 'numero' => $orden->numero, 'estado' => $orden->estado,
            'proveedor_id' => $orden->proveedor_id, 'moneda' => $orden->moneda ?: Monedas::base(),
            'tasa_cambio' => $orden->tasa_cambio !== null ? (float) $orden->tasa_cambio : null,
            'tasa_fecha' => $orden->tasa_fecha?->toDateString(),
            'fecha_entrega_esperada' => $orden->fecha_entrega_esperada?->toDateString(),
            'condiciones' => $orden->condiciones, 'notas' => $orden->notas,
            // Con recepciones, el proveedor y la moneda ya no cambian: lo recibido es de ese proveedor y a esa tasa.
            'bloqueada' => $orden->recepciones->isNotEmpty(),
            'items' => $orden->items->map(fn ($i) => [
                'id' => $i->id, 'item_id' => $i->item_id, 'referencia_proveedor' => $i->referencia_proveedor,
                'descripcion' => $i->descripcion, 'cantidad' => (float) $i->cantidad, 'cantidad_recibida' => (float) $i->cantidad_recibida,
                'unidad' => $i->unidad, 'impuesto_pct' => (float) $i->impuesto_pct,
                // Lo que se tecleó, exacto; y si no se guardó (orden vieja), el valor en la moneda de la orden.
                'precio_unitario' => $i->precio_moneda !== null ? (float) $i->precio_moneda
                    : ($orden->esEnOtraMoneda() ? round((float) $i->precio_unitario / (float) $orden->tasa_cambio, 4) : (float) $i->precio_unitario),
                '_nombre_item' => $i->item?->nombre, '_codigo_item' => $i->item?->referencia,
            ])->values(),
        ]);
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
        foreach ($items as $linea) {
            $this->crearLinea($orden, $linea);
        }
    }

    /**
     * Una línea de la orden. El precio llega en la moneda de la orden y se guarda en la base
     * (`precio_unitario`), con lo tecleado aparte (`precio_moneda`) para mostrarlo exacto.
     *
     * @param  array<string, mixed>  $linea
     */
    private function crearLinea(OrdenCompra $orden, array $linea): OrdenCompraItem
    {
        $proveedores = app(ProveedoresProductoService::class);
        $itemId      = $linea['item_id'] ?? null;
        $escrito     = trim((string) ($linea['referencia_proveedor'] ?? ''));

        $item = OrdenCompraItem::create([
            'orden_id'             => $orden->id,
            'item_id'              => $itemId,
            'referencia_proveedor' => $escrito !== ''
                ? $escrito
                : $proveedores->de($itemId, $orden->proveedor_id)?->referencia_proveedor,
        ] + $this->valoresDeLinea($orden, $linea));

        if ($itemId) {
            $proveedores->guardarEquivalencia($itemId, $orden->proveedor_id, $escrito, (float) $item->precio_unitario);
        }

        return $item;
    }

    /** @return array<string, mixed> */
    private function valoresDeLinea(OrdenCompra $orden, array $linea): array
    {
        $precio = (float) $linea['precio_unitario'];
        $base   = $orden->aBase($precio);

        return [
            'descripcion'     => $linea['descripcion'],
            'cantidad'        => $linea['cantidad'],
            'unidad'          => $linea['unidad'],
            'precio_unitario' => $base,
            'precio_moneda'   => $orden->esEnOtraMoneda() ? $precio : null,
            'impuesto_pct'    => $linea['impuesto_pct'] ?? 0,
            'total_linea'     => round((float) $linea['cantidad'] * $base, 2),
        ];
    }

    public function show(OrdenCompra $orden): Response
    {
        $orden->load([
            'proveedor',
            'creadoPor:id,name',
            'solicitud:id,numero',
            // 'item' ahora apunta a productos: el código es 'referencia'.
            'items.item:id,nombre,referencia,producto_padre_id,valor_variante',
            'items.item.padre:id,nombre',
            'recepciones.recibidoPor:id,name',
        ]);

        // Cómo llaman a cada producto los OTROS proveedores y a cuánto: para comparar sin salir de
        // la orden. Es interno: el PDF que recibe el proveedor no lo lleva.
        $equivalencias = app(ProveedoresProductoService::class)->equivalencias($orden->items->pluck('item_id'));

        return Inertia::render('Compras/Ordenes/Show', [
            'orden'       => $orden,
            'equivalencias' => collect($equivalencias)->map(
                fn ($lista) => collect($lista)->where('proveedor_id', '!=', $orden->proveedor_id)->values()
            ),
            // Lo que la empresa le retiene al proveedor al pagar. Estimación: ver RetencionesService.
            'retenciones' => app(\App\Services\RetencionesService::class)->paraCompra($orden),
        ]);
    }

    /**
     * Edita la orden mientras no esté cerrada. En borrador cambia todo; con la orden ya enviada o
     * con recepciones, lo recibido manda: no se borra una línea que ya entró, no se baja su
     * cantidad por debajo de lo recibido, y el proveedor y la moneda se quedan como estaban.
     */
    public function update(Request $request, OrdenCompra $orden): RedirectResponse
    {
        if (! $orden->esEditable()) {
            return back()->with('error', 'Una orden recibida por completo o cancelada no se edita.');
        }

        $data      = $request->validate($this->reglas());
        $orden->load('items', 'recepciones');
        $bloqueada = $orden->recepciones->isNotEmpty();

        // Con recepciones, el proveedor y la moneda no se tocan: lo recibido es de ese proveedor y a esa tasa.
        $cabecera = $bloqueada
            ? ['proveedor_id' => $orden->proveedor_id, 'moneda' => $orden->moneda, 'tasa_cambio' => $orden->tasa_cambio, 'tasa_fecha' => $orden->tasa_fecha?->toDateString()]
            : ['proveedor_id' => $data['proveedor_id']] + $this->monedaDe($data);

        $enviadas = collect($data['items']);
        $propias  = $orden->items->keyBy('id');

        // Antes de tocar nada: que no se quede a medias.
        $errores = [];
        foreach ($enviadas as $i => $linea) {
            $id = $linea['id'] ?? null;
            if ($id && ! $propias->has($id)) {
                $errores["items.{$i}.id"] = 'Esa línea no es de esta orden.';
            } elseif ($id && (float) $linea['cantidad'] < (float) $propias[$id]->cantidad_recibida) {
                $errores["items.{$i}.cantidad"] = 'Ya se recibieron '.rtrim(rtrim(number_format((float) $propias[$id]->cantidad_recibida, 3, '.', ''), '0'), '.').': la cantidad no puede ser menor.';
            }
        }
        foreach ($propias->diffKeys($enviadas->pluck('id')->filter()->flip()) as $quitada) {
            if ((float) $quitada->cantidad_recibida > 0) {
                $errores['items'] = "«{$quitada->descripcion}» ya tiene mercancía recibida: no se puede quitar de la orden.";
            }
        }
        if ($errores) {
            throw \Illuminate\Validation\ValidationException::withMessages($errores);
        }

        \Illuminate\Support\Facades\DB::transaction(function () use ($orden, $data, $cabecera, $enviadas, $propias) {
            $orden->update($cabecera + [
                'fecha_entrega_esperada' => $data['fecha_entrega_esperada'] ?? null,
                'condiciones'            => $data['condiciones'] ?? null,
                'notas'                  => $data['notas'] ?? null,
            ]);

            // Las que ya no vienen se borran (solo pueden ser las que no recibieron nada).
            $conservadas = $enviadas->pluck('id')->filter()->all();
            $orden->items()->whereNotIn('id', $conservadas)->delete();

            foreach ($enviadas as $linea) {
                $existente = ($linea['id'] ?? null) ? $propias[$linea['id']] : null;

                if (! $existente) {
                    $this->crearLinea($orden, $linea);

                    continue;
                }

                $escrito = trim((string) ($linea['referencia_proveedor'] ?? ''));
                $recibida = (float) $existente->cantidad_recibida > 0;

                $existente->update($this->valoresDeLinea($orden, $linea) + [
                    // Lo que ya entró al inventario sigue siendo ese producto.
                    'item_id'              => $recibida ? $existente->item_id : ($linea['item_id'] ?? null),
                    'referencia_proveedor' => $escrito !== '' ? $escrito : $existente->referencia_proveedor,
                ]);
            }

            $orden->recalcularTotales();

            // Si lo recibido ya cubre lo que quedó pedido, la orden está completa.
            $orden->load('items');
            if ($orden->estado === 'recibida_parcial' && $orden->items->every(fn ($i) => (float) $i->cantidad_recibida >= (float) $i->cantidad)) {
                $orden->update(['estado' => 'recibida', 'fecha_recepcion' => now()]);
            }
        });

        // Una orden que ya salió al proveedor tiene precios acordados: lo editado los actualiza.
        if ($orden->estado !== 'borrador') {
            app(ProveedoresProductoService::class)->registrarPrecios($orden->fresh());
        }

        return redirect("/compras/ordenes/{$orden->id}")->with('success', 'Orden actualizada correctamente.');
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
        $orden->load(['proveedor', 'creadoPor:id,name,firma', 'items.item:id,nombre,referencia']);

        $pdf = Pdf::loadView('pdf.orden-compra', compact('orden'))
            ->setPaper('letter', 'portrait');

        return $pdf->download("OC-{$orden->numero}.pdf");
    }
}
