<?php

namespace App\Http\Controllers;

use App\Models\ComisionVendedor;
use App\Models\Cotizacion;
use App\Models\Ensamble;
use App\Models\Producto;
use App\Models\User;
use App\Services\ComisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ComisionController extends Controller
{
    public function __construct(private ComisionService $svc) {}

    public function index(Request $request)
    {
        $user = auth()->user();
        $mes = $request->get('mes', now()->format('Y-m'));

        // Asegurar formato correcto Y-m
        $mes = substr($mes, 0, 7);

        // Query base
        $query = ComisionVendedor::with([
            'cotizacion.cliente',
            'user',
        ])->where('periodo_mes', $mes);

        // Si es vendedor solo ve las suyas
        if ($user->rol === 'vendedor') {
            $query->where('user_id', $user->id);
        }

        // Si admin filtra por vendedor específico
        if ($request->get('vendedor_id')) {
            $query->where('user_id', $request->get('vendedor_id'));
        }

        $comisiones = $query->orderBy('created_at', 'desc')->get();

        // Calcular totales por estado
        $totales = [
            'proyectada' => (float) $comisiones->where('estado', 'proyectada')->sum('total_comision'),
            'confirmada' => (float) $comisiones->where('estado', 'confirmada')->sum('total_comision'),
            'ejecutada'  => (float) $comisiones->where('estado', 'ejecutada')->sum('total_comision'),
            'liquidada'  => (float) $comisiones->where('estado', 'liquidada')->sum('total_comision'),
        ];

        $totalMes = array_sum($totales);

        // Meses disponibles
        $mesesDisponibles = ComisionVendedor::query()
            ->when($user->rol === 'vendedor', fn ($q) =>
                $q->where('user_id', $user->id))
            ->orderBy('periodo_mes', 'desc')
            ->pluck('periodo_mes')
            ->unique()
            ->values();

        if ($mesesDisponibles->isEmpty()) {
            $mesesDisponibles = collect([$mes]);
        }

        // Vendedores para el filtro (solo admin)
        $vendedores = $user->rol === 'administrador'
            ? \App\Models\User::where('activo', true)
                              ->orderBy('name')
                              ->get(['id', 'name', 'rol'])
            : collect();

        // Log para verificar
        \Log::info('Comisiones index', [
            'mes'    => $mes,
            'user_id'=> $user->id,
            'rol'    => $user->rol,
            'count'  => $comisiones->count(),
            'total'  => $totalMes,
        ]);

        return Inertia::render('Comisiones/Index', [
            'comisiones'       => $comisiones,
            'totales'          => $totales,
            'totalMes'         => $totalMes,
            'mesActual'        => $mes,
            'mesesDisponibles' => $mesesDisponibles,
            'vendedores'       => $vendedores,
            'esAdmin'          => $user->rol === 'administrador',
        ]);
    }

    public function show(ComisionVendedor $comision): Response
    {
        $user = auth()->user();
        if ($user->rol === 'vendedor' && $comision->user_id !== $user->id) {
            abort(403);
        }

        $comision->load([
            'cotizacion.cliente',
            'cotizacion.items',
            'user',
        ]);

        $desglose = $comision->cotizacion->items->map(function ($item) {
            // Comisión sobre el excedente por encima del precio mayorista,
            // no sobre el precio de venta completo (ver CotizacionController).
            $comisionValor = max(0, (float) $item->precio_unitario - (float) $item->precio_mayorista_base)
                           * (float) $item->cantidad
                           * ((float) $item->comision_pct_aplicada / 100);
            return [
                'descripcion'     => $item->descripcion,
                'tipo'            => $item->tipo,
                'cantidad'        => (float) $item->cantidad,
                'precio_unitario' => (float) $item->precio_unitario,
                'descuento_pct'   => (float) $item->descuento_pct,
                'subtotal'        => (float) $item->subtotal,
                'comision_pct'    => (float) $item->comision_pct_aplicada,
                'comision_valor'  => $comisionValor,
            ];
        })->filter(fn ($i) => $i['comision_pct'] > 0)->values();

        return Inertia::render('Comisiones/Show', [
            'comision'  => $comision,
            'desglose'  => $desglose,
            'esAdmin'   => $user->rol === 'administrador',
            'bloqueada' => $comision->estado === 'liquidada',
        ]);
    }

    public function pdfDetalle(ComisionVendedor $comision)
    {
        $user = auth()->user();
        if ($user->rol === 'vendedor' && $comision->user_id !== $user->id) {
            abort(403);
        }

        $comision->load(['cotizacion.cliente', 'cotizacion.items', 'user']);

        $desglose = $comision->cotizacion->items
            ->filter(fn ($i) => (float) $i->comision_pct_aplicada > 0)
            ->map(fn ($item) => [
                'descripcion'     => $item->descripcion,
                'tipo'            => $item->tipo,
                'cantidad'        => (float) $item->cantidad,
                'precio_unitario' => (float) $item->precio_unitario,
                'descuento_pct'   => (float) $item->descuento_pct,
                'comision_pct'    => (float) $item->comision_pct_aplicada,
                'comision_valor'  => max(0, (float) $item->precio_unitario - (float) $item->precio_mayorista_base)
                                   * (float) $item->cantidad
                                   * ((float) $item->comision_pct_aplicada / 100),
            ])->values();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView(
            'comisiones.detalle-pdf',
            compact('comision', 'desglose')
        )->setPaper('letter', 'portrait');

        $numero = $comision->cotizacion->numero ?? $comision->id;
        return $pdf->stream("comision-{$numero}.pdf");
    }

    public function pdfResumenMes(Request $request)
    {
        $user = auth()->user();
        $mes  = $request->get('mes', now()->format('Y-m'));

        $query = ComisionVendedor::with([
            'cotizacion.cliente',
            'cotizacion.items',
            'user',
        ])->whereIn('estado', ['confirmada', 'ejecutada', 'liquidada'])
          ->where('periodo_mes', 'LIKE', substr($mes, 0, 7) . '%');

        if ($user->rol === 'vendedor') {
            $query->where('user_id', $user->id);
        } elseif ($request->filled('vendedor_id')) {
            $query->where('user_id', $request->get('vendedor_id'));
        }

        $comisiones = $query->orderBy('created_at')->get()->map(function ($com) {
            $com->desglose = $com->cotizacion->items
                ->filter(fn ($i) => (float) $i->comision_pct_aplicada > 0)
                ->map(fn ($item) => [
                    'descripcion'     => $item->descripcion,
                    'tipo'            => $item->tipo,
                    'cantidad'        => (float) $item->cantidad,
                    'precio_unitario' => (float) $item->precio_unitario,
                    'descuento_pct'   => (float) $item->descuento_pct,
                    'comision_pct'    => (float) $item->comision_pct_aplicada,
                    'comision_valor'  => max(0, (float) $item->precio_unitario - (float) $item->precio_mayorista_base)
                                       * (float) $item->cantidad
                                       * ((float) $item->comision_pct_aplicada / 100),
                ])->values();
            return $com;
        });

        $totalMes = $comisiones->sum('total_comision');
        $vendedor  = $comisiones->first()?->user;

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView(
            'comisiones.resumen-mes-pdf',
            compact('comisiones', 'totalMes', 'mes', 'vendedor')
        )->setPaper('letter', 'portrait');

        return $pdf->stream("resumen-comisiones-{$mes}.pdf");
    }

    public function liquidar(ComisionVendedor $comision): RedirectResponse
    {
        if ($comision->estado === 'liquidada') {
            return back()->with('error', 'Esta comisión ya fue liquidada y no puede modificarse.');
        }

        $comision->update([
            'estado'       => 'liquidada',
            'liquidada_at' => now(),
        ]);

        return back()->with('success', 'Comisión liquidada.');
    }

    public function calcular(Request $request): JsonResponse
    {
        $request->validate([
            'precio_lista'  => 'required|numeric|min:0',
            'precio_venta'  => 'required|numeric|min:0',
            'comision_min'  => 'required|numeric|min:0',
            'comision_max'  => 'required|numeric|min:0',
            'descuento_max' => 'nullable|numeric|min:0',
        ]);

        $resultado = $this->svc->calcularRango(
            (float) $request->precio_lista,
            (float) $request->precio_venta,
            (float) $request->comision_min,
            (float) $request->comision_max,
            (float) ($request->descuento_max ?? 5)
        );

        return response()->json($resultado);
    }

    public function sugerirTopes(Request $request): JsonResponse
    {
        $request->validate([
            'precio_lista'         => 'required|numeric|min:0',
            'costo'                => 'required|numeric|min:0',
            'utilidad_minima_pct'  => 'required|numeric|min:0',
            'descuento_max_pct'    => 'required|numeric|min:0',
        ]);

        $resultado = $this->svc->sugerirTopes(
            (float) $request->precio_lista,
            (float) $request->costo,
            (float) $request->utilidad_minima_pct,
            (float) $request->descuento_max_pct
        );

        return response()->json($resultado);
    }
}
