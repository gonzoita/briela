<?php

namespace App\Http\Controllers;

use App\Models\FormulaComponente;
use App\Models\Insumo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConfiguradorController extends Controller
{
    public function index(): Response
    {
        $insumos = Insumo::orderBy('categoria')->orderBy('orden')->orderBy('nombre')->get();

        $tiposPuerta = [
            'M_SUELO','M_TOPE','M_SUELO_D','M_TOPE_D',
            'VAIVEN','VAIVEN_D','INST','INST_D',
            'SE12','SM20','P480',
        ];

        $formulas = FormulaComponente::all()->mapWithKeys(fn ($f) => [
            "{$f->insumo_id}_{$f->tipo_puerta}" => [
                'id'                   => $f->id,
                'cantidad'             => (float) $f->cantidad,
                'es_lamina'            => $f->es_lamina,
                'escala_con_dimension' => $f->escala_con_dimension,
            ],
        ]);

        return Inertia::render('Cotizadores/Configurador/Index', [
            'insumos'      => $insumos,
            'tiposPuerta'  => $tiposPuerta,
            'formulas'     => $formulas,
        ]);
    }

    public function update(Request $request, Insumo $insumo): RedirectResponse
    {
        $data = $request->validate([
            'formulas'                         => 'required|array',
            'formulas.*.tipo_puerta'           => 'required|string',
            'formulas.*.cantidad'              => 'required|numeric|min:0',
            'formulas.*.es_lamina'             => 'boolean',
            'formulas.*.escala_con_dimension'  => 'boolean',
        ]);

        foreach ($data['formulas'] as $item) {
            FormulaComponente::updateOrCreate(
                ['insumo_id' => $insumo->id, 'tipo_puerta' => $item['tipo_puerta']],
                [
                    'cantidad'             => $item['cantidad'],
                    'es_lamina'            => $item['es_lamina'] ?? false,
                    'escala_con_dimension' => $item['escala_con_dimension'] ?? false,
                ]
            );
        }

        return back()->with('success', "Fórmulas de {$insumo->nombre} actualizadas.");
    }
}
