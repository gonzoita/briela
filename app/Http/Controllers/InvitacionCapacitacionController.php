<?php

namespace App\Http\Controllers;

use App\Models\InvitacionCapacitacion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class InvitacionCapacitacionController extends Controller
{
    public function index(): Response
    {
        $invitaciones = InvitacionCapacitacion::with('cliente')
            ->latest()
            ->get();

        return Inertia::render('Capacitacion/Invitaciones/Index', [
            'invitaciones' => $invitaciones,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'email'           => 'required|email|max:255',
            'tipo'            => 'required|in:contratista,cliente',
            'cliente_id'      => 'nullable|exists:clientes,id',
            'nombre_sugerido' => 'nullable|string|max:150',
        ]);

        InvitacionCapacitacion::create([
            'token'           => Str::random(40),
            'email'           => $data['email'],
            'tipo'            => $data['tipo'],
            'cliente_id'      => $data['cliente_id'] ?? null,
            'nombre_sugerido' => $data['nombre_sugerido'] ?? null,
            'invitado_por'    => $request->user()->id,
            'expira_at'       => now()->addDays(7),
        ]);

        return back()->with('success', 'Invitación creada correctamente. Copia el link para compartirla.');
    }

    public function destroy(InvitacionCapacitacion $invitacion): RedirectResponse
    {
        abort_if($invitacion->usado_at !== null, 422, 'No se puede cancelar una invitación ya usada.');

        $invitacion->delete();

        return back()->with('success', 'Invitación cancelada.');
    }
}
