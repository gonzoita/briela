<?php

namespace App\Http\Controllers;

use App\Models\PlantillaEnsamble;
use App\Models\TemplateTrabajo;
use App\Models\TemplateTrabajoPaso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TemplateTrabajoController extends Controller
{
    public function index(): Response
    {
        $templates = TemplateTrabajo::withCount('pasos')
            // Un flujo cuelga de una plantilla del cotizador o —si el ensamble es directo—
            // del ensamble mismo. Sin cargar las dos, el de un ensamble directo aparecía en
            // la lista sin decir a qué pertenece.
            ->with(['plantillaEnsamble', 'ensamble'])
            ->orderByDesc('id')
            ->get()
            ->map(fn ($t) => [
                'id'                => $t->id,
                'nombre'            => $t->nombre,
                'activo'            => $t->activo,
                'pasos_count'       => $t->pasos_count,
                'plantilla_nombre'  => $t->plantillaEnsamble?->nombre ?? $t->ensamble?->nombre,
                'suma_pesos'        => (float) $t->pasos()->sum('peso_porcentaje'),
            ]);

        return Inertia::render('Produccion/Templates/Index', [
            'templates' => $templates,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Produccion/Templates/Create', [
            'plantillas' => PlantillaEnsamble::where('activo', true)->get(['id', 'nombre']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'nombre'                      => 'required|string|max:200',
            'plantilla_ensamble_id'       => 'nullable|exists:plantillas_ensamble,id',
            'activo'                      => 'boolean',
            'pasos'                       => 'nullable|array',
            'pasos.*.nombre'              => 'required|string|max:200',
            'pasos.*.descripcion'         => 'nullable|string',
            'pasos.*.peso_porcentaje'     => 'required|numeric|min:0|max:100',
            'pasos.*.orden'               => 'nullable|integer',
            'pasos.*.nivel_dificultad'    => 'required|integer|min:1|max:5',
            'pasos.*.depende_de'          => 'nullable|array',
            'pasos.*.depende_de.*'        => 'nullable|integer|min:0',
            'pasos.*.es_paso_final'       => 'boolean',
        ]);

        $suma = array_sum(array_column($data['pasos'] ?? [], 'peso_porcentaje'));
        if ($suma > 100.05) {
            return back()->withErrors(['pasos' => 'La suma de pesos no puede exceder 100%']);
        }

        $template = TemplateTrabajo::create([
            'nombre'                => $data['nombre'],
            'plantilla_ensamble_id' => $data['plantilla_ensamble_id'] ?? null,
            'activo'                => $data['activo'] ?? true,
        ]);

        $template->sincronizarPasos($data['pasos'] ?? []);

        return redirect('/produccion/templates')
            ->with('success', "Template {$template->nombre} creado.");
    }

    public function show(TemplateTrabajo $template): Response
    {
        return Inertia::render('Produccion/Templates/Edit', [
            'template'   => $template->load('pasos', 'plantillaEnsamble'),
            'plantillas' => PlantillaEnsamble::where('activo', true)->get(['id', 'nombre']),
        ]);
    }

    public function edit(TemplateTrabajo $template): Response
    {
        return Inertia::render('Produccion/Templates/Edit', [
            'template'   => $template->load('pasos', 'plantillaEnsamble'),
            'plantillas' => PlantillaEnsamble::where('activo', true)->get(['id', 'nombre']),
        ]);
    }

    public function update(Request $request, TemplateTrabajo $template): RedirectResponse
    {
        $data = $request->validate([
            'nombre'                      => 'required|string|max:200',
            'plantilla_ensamble_id'       => 'nullable|exists:plantillas_ensamble,id',
            'activo'                      => 'boolean',
            'pasos'                       => 'nullable|array',
            'pasos.*.nombre'              => 'required|string|max:200',
            'pasos.*.descripcion'         => 'nullable|string',
            'pasos.*.peso_porcentaje'     => 'required|numeric|min:0|max:100',
            'pasos.*.orden'               => 'nullable|integer',
            'pasos.*.nivel_dificultad'    => 'required|integer|min:1|max:5',
            'pasos.*.depende_de'          => 'nullable|array',
            'pasos.*.depende_de.*'        => 'nullable|integer|min:0',
            'pasos.*.es_paso_final'       => 'boolean',
        ]);

        $suma = array_sum(array_column($data['pasos'] ?? [], 'peso_porcentaje'));
        if ($suma > 100.05) {
            return back()->withErrors(['pasos' => 'La suma de pesos no puede exceder 100%']);
        }

        $template->update([
            'nombre'                => $data['nombre'],
            'plantilla_ensamble_id' => $data['plantilla_ensamble_id'] ?? null,
            'activo'                => $data['activo'] ?? true,
        ]);

        $template->sincronizarPasos($data['pasos'] ?? []);

        return redirect('/produccion/templates')
            ->with('success', 'Template actualizado.');
    }

    public function destroy(TemplateTrabajo $template): RedirectResponse
    {
        $template->delete();
        return redirect('/produccion/templates')->with('success', 'Template eliminado.');
    }

    public function pasos(TemplateTrabajo $template): \Illuminate\Http\JsonResponse
    {
        return response()->json($template->pasos()->get());
    }
}
