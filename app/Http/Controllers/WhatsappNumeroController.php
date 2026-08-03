<?php

namespace App\Http\Controllers;

use App\Models\WhatsappNumero;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WhatsappNumeroController extends Controller
{
    public function index()
    {
        return Inertia::render('Configuracion/WhatsappNumeros', [
            'numeros' => WhatsappNumero::with('usuario:id,name')
                ->orderByDesc('rol')
                ->orderBy('nombre')
                ->get(),
            'usuarios' => \App\Models\User::orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:100',
            'numero_telefono' => 'required|string|max:30',
            'phone_number_id' => 'required|string|max:50|unique:whatsapp_numeros,phone_number_id',
            'rol' => 'required|in:central,asesor',
            'usuario_id' => 'nullable|exists:users,id',
            'activo' => 'boolean',
        ]);

        WhatsappNumero::create($data);

        return redirect()->back()->with('success', 'Número de WhatsApp creado.');
    }

    public function update(Request $request, WhatsappNumero $whatsappNumero)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:100',
            'numero_telefono' => 'required|string|max:30',
            'phone_number_id' => 'required|string|max:50|unique:whatsapp_numeros,phone_number_id,' . $whatsappNumero->id,
            'rol' => 'required|in:central,asesor',
            'usuario_id' => 'nullable|exists:users,id',
            'activo' => 'boolean',
        ]);

        $whatsappNumero->update($data);

        return redirect()->back()->with('success', 'Número de WhatsApp actualizado.');
    }

    public function destroy(WhatsappNumero $whatsappNumero)
    {
        if ($whatsappNumero->conversaciones()->exists()) {
            $whatsappNumero->update(['activo' => false]);
            return redirect()->back()->with('success', 'Número desactivado (tiene conversaciones asociadas).');
        }

        $whatsappNumero->delete();
        return redirect()->back()->with('success', 'Número eliminado.');
    }
}
