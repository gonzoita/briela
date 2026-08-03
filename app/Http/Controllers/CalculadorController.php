<?php

namespace App\Http\Controllers;

use App\Models\ConfiguracionPuerta;
use App\Models\FormulaComponente;
use App\Models\Insumo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CalculadorController extends Controller
{
    public function index(): Response
    {
        // Cargar insumos con sus fórmulas para el cálculo en el frontend
        $insumos = Insumo::where('activo', true)
            ->with('formulas')
            ->orderBy('categoria')
            ->orderBy('orden')
            ->get()
            ->map(fn ($insumo) => [
                'id'           => $insumo->id,
                'nombre'       => $insumo->nombre,
                'categoria'    => $insumo->categoria,
                'unidad'       => $insumo->unidad,
                'precio_costo' => (float) $insumo->precio_costo,
                'formulas'     => $insumo->formulas->mapWithKeys(fn ($f) => [
                    $f->tipo_puerta => [
                        'cantidad'             => (float) $f->cantidad,
                        'es_lamina'            => $f->es_lamina,
                        'escala_con_dimension' => $f->escala_con_dimension,
                    ],
                ]),
            ]);

        $configuraciones = ConfiguracionPuerta::where('creado_por', auth()->id())
            ->latest()
            ->take(20)
            ->get(['id', 'nombre', 'tipo_puerta', 'ancho_vano', 'alto_vano', 'created_at']);

        return Inertia::render('Cotizadores/Calculador/Index', [
            'insumos'         => $insumos,
            'configuraciones' => $configuraciones,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nombre'            => 'required|string|max:150',
            'tipo_puerta'       => 'required|string|max:50',
            'tipo_sello'        => 'nullable|string|max:50',
            'temperatura'       => 'required|in:MEDIA,BAJA',
            'ancho_vano'        => 'required|numeric|min:0.3|max:3',
            'alto_vano'         => 'required|numeric|min:0.5|max:3',
            'tipo_corredera'    => 'nullable|in:SE12,SM20,480',
            'sin_llave'         => 'boolean',
            'palanca'           => 'boolean',
            'visor'             => 'boolean',
            'desglose'          => 'required|array',
            'precios_resultado' => 'required|array',
        ]);

        $config = ConfiguracionPuerta::create([
            ...$data,
            'creado_por' => auth()->id(),
        ]);

        return back()->with('success', 'Configuración guardada: ' . $config->nombre);
    }
}
