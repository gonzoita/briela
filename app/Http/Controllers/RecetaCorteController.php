<?php

namespace App\Http\Controllers;

use App\Models\Bodega;
use App\Models\Producto;
use App\Models\RecetaCorte;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RecetaCorteController extends Controller
{
    public function index(Request $request): Response
    {
        $recetas = RecetaCorte::with([
                'insumo:id,nombre,referencia,unidad_medida',
                'resultado:id,nombre,referencia,unidad_medida,valor_variante,producto_padre_id',
                'resultado.padre:id,nombre',
            ])
            ->orderByDesc('id')
            ->get()
            ->map(fn (RecetaCorte $r) => [
                ...$r->toArray(),
                'resultado_nombre_completo' => $r->resultado?->nombre_completo,
                'stock_insumo'    => $r->insumo?->stockTotal(),
                'stock_resultado' => $r->resultado?->stockTotal(),
            ]);

        return Inertia::render('Compras/Inventario/RecetasCorte/Index', [
            'recetas'    => $recetas,
            'insumos'    => Producto::insumos()->where('activo', true)->orderBy('nombre')
                ->get(['id', 'nombre', 'referencia', 'unidad_medida']),
            'resultados' => Producto::seleccionables()->where('activo', true)->with('padre:id,nombre')->orderBy('nombre')
                ->get(['id', 'nombre', 'referencia', 'unidad_medida', 'valor_variante', 'producto_padre_id'])
                ->map(fn (Producto $p) => [
                    'id'              => $p->id,
                    'nombre_completo' => $p->nombre_completo,
                    'referencia'      => $p->referencia,
                    'unidad_medida'   => $p->unidad_medida,
                ]),
            'bodegas'    => Bodega::where('activa', true)->orderByDesc('es_principal')->orderBy('nombre')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validarReceta($request);

        RecetaCorte::create($data);

        return back()->with('success', 'Receta de corte creada correctamente.');
    }

    public function update(Request $request, RecetaCorte $receta): RedirectResponse
    {
        $data = $this->validarReceta($request);

        $receta->update($data);

        return back()->with('success', 'Receta de corte actualizada.');
    }

    public function destroy(RecetaCorte $receta): RedirectResponse
    {
        $receta->delete();

        return back()->with('success', 'Receta de corte eliminada.');
    }

    public function construir(Request $request, RecetaCorte $receta): RedirectResponse
    {
        $data = $request->validate([
            'bodega_id' => 'required|exists:bodegas,id',
            'cantidad'  => 'required|numeric|min:1',
            'notas'     => 'nullable|string|max:255',
        ]);

        $receta->load(['insumo', 'resultado']);
        $bodegaId          = (int) $data['bodega_id'];
        $cantidadAProducir = (float) $data['cantidad'];
        $cantidadInsumo    = (float) $receta->cantidad_insumo * $cantidadAProducir;

        $stockDisponible = $receta->insumo->stockEnBodega($bodegaId);
        if ($stockDisponible < $cantidadInsumo) {
            return back()->withErrors([
                'cantidad' => "Stock insuficiente de {$receta->insumo->nombre} en esa bodega: "
                    . "disponible {$stockDisponible} {$receta->insumo->unidad_medida}, "
                    . "se requieren {$cantidadInsumo}.",
            ]);
        }

        DB::transaction(function () use ($receta, $bodegaId, $cantidadAProducir, $cantidadInsumo, $data) {
            $receta->insumo->registrarMovimiento(
                tipo: 'salida',
                cantidad: $cantidadInsumo,
                bodegaId: $bodegaId,
                usuarioId: auth()->id(),
                origenTipo: 'corte',
                origenId: $receta->id,
                notas: $data['notas'] ?? "Corte para producir {$cantidadAProducir} x {$receta->resultado->nombre_completo}"
            );

            $receta->resultado->registrarMovimiento(
                tipo: 'entrada',
                cantidad: $cantidadAProducir,
                bodegaId: $bodegaId,
                usuarioId: auth()->id(),
                origenTipo: 'corte',
                origenId: $receta->id,
                notas: $data['notas'] ?? "Corte de {$receta->insumo->nombre}"
            );
        });

        return back()->with('success', "Se produjeron {$cantidadAProducir} unidades de {$receta->resultado->nombre_completo}.");
    }

    private function validarReceta(Request $request): array
    {
        return $request->validate([
            'nombre'                 => 'nullable|string|max:150',
            'producto_insumo_id'     => 'required|exists:productos,id',
            'producto_resultado_id'  => [
                'required',
                'different:producto_insumo_id',
                'exists:productos,id',
                function ($attribute, $value, $fail) {
                    if (Producto::where('id', $value)->where('es_padre', true)->exists()) {
                        $fail('El producto resultado no puede ser un producto padre (selecciona una variante).');
                    }
                },
            ],
            'cantidad_insumo'        => 'required|numeric|min:0.001',
            'activo'                 => 'boolean',
        ]);
    }
}
