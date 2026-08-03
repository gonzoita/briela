<?php

namespace App\Http\Controllers;

use App\Models\CrmLead;
use App\Models\CrmTarea;
use Illuminate\Http\Request;

class CrmTareaController extends Controller
{
    public function store(Request $request, CrmLead $lead)
    {
        $data = $request->validate([
            'titulo'            => 'required|string|max:200',
            'descripcion'       => 'nullable|string',
            'tipo'              => 'required|in:llamada,email,reunion,seguimiento,otro',
            'fecha_vencimiento' => 'nullable|date',
            'responsable_id'    => 'nullable|exists:users,id',
        ]);

        $tarea = CrmTarea::create([...$data, 'lead_id' => $lead->id]);
        return response()->json(['tarea' => $tarea->load('responsable:id,name')]);
    }

    public function completar(CrmTarea $tarea)
    {
        $eraIncompleta = !$tarea->completada;

        $tarea->update([
            'completada'    => !$tarea->completada,
            'completada_at' => !$tarea->completada ? now() : null,
        ]);

        if ($eraIncompleta) {
            \App\Models\CrmActividad::registrar(
                $tarea->lead_id,
                'tarea',
                "Tarea completada: {$tarea->titulo}"
            );
        }

        return response()->json(['tarea' => $tarea->fresh()]);
    }

    public function destroy(CrmTarea $tarea)
    {
        $tarea->delete();
        return response()->json(['ok' => true]);
    }
}
