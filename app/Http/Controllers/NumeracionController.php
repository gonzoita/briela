<?php

namespace App\Http\Controllers;

use App\Models\SecuenciaDocumento;
use App\Models\Sede;
use Illuminate\Http\Request;
use Inertia\Inertia;

class NumeracionController extends Controller
{
    public function index()
    {
        $sedes = Sede::orderByDesc('es_principal')->orderBy('nombre')->get(['id', 'nombre', 'codigo', 'activa']);

        $secuencias = SecuenciaDocumento::with('sede:id,nombre')
            ->whereIn('tipo_documento', array_keys(SecuenciaDocumento::catalogo()))
            ->orderBy('sede_id')
            ->orderBy('tipo_documento')
            ->get()
            ->map(fn (SecuenciaDocumento $s) => [
                'id'               => $s->id,
                'sede_id'          => $s->sede_id,
                'tipo_documento'   => $s->tipo_documento,
                'tipo_label'       => SecuenciaDocumento::catalogo()[$s->tipo_documento] ?? $s->tipo_documento,
                'prefijo'          => $s->prefijo,
                'incluir_anio'     => $s->incluir_anio,
                'siguiente_numero' => $s->siguiente_numero,
                'padding'          => $s->padding,
                'ejemplo'          => $s->ejemplo,
            ]);

        return Inertia::render('Configuracion/Numeracion', [
            'sedes'      => $sedes,
            'secuencias' => $secuencias,
            'catalogo'   => SecuenciaDocumento::catalogo(),
        ]);
    }

    public function update(Request $request, SecuenciaDocumento $secuencia)
    {
        $data = $request->validate([
            'prefijo'          => 'nullable|string|max:30',
            'incluir_anio'     => 'boolean',
            'siguiente_numero' => 'required|integer|min:1',
            'padding'          => 'required|integer|min:1|max:10',
        ]);

        $data['prefijo'] = $data['prefijo'] ?? '';

        $secuencia->update($data);

        return back()->with('success', 'Numeración actualizada.');
    }
}
