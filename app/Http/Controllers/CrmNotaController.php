<?php

namespace App\Http\Controllers;

use App\Models\CrmLead;
use App\Models\CrmNota;
use Illuminate\Http\Request;

class CrmNotaController extends Controller
{
    public function store(Request $request, CrmLead $lead)
    {
        $data  = $request->validate(['contenido' => 'required|string']);
        $nota  = CrmNota::create([...$data, 'lead_id' => $lead->id, 'user_id' => auth()->id()]);

        \App\Models\CrmActividad::registrar($lead->id, 'nota', 'Nota agregada');

        return response()->json(['nota' => $nota->load('user:id,name')]);
    }

    public function destroy(CrmNota $nota)
    {
        $nota->delete();
        return response()->json(['ok' => true]);
    }
}
