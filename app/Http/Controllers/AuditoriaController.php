<?php

namespace App\Http\Controllers;

use App\Models\RegistroActividad;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditoriaController extends Controller
{
    public function index(Request $request): Response
    {
        $registros = RegistroActividad::with('usuario:id,name')
            ->when($request->filled('usuario_id'), fn ($q) => $q->where('user_id', $request->usuario_id))
            ->when($request->filled('modelo'), fn ($q) => $q->where('modelo', $request->modelo))
            ->when($request->filled('accion'), fn ($q) => $q->where('accion', $request->accion))
            ->when($request->filled('desde'), fn ($q) => $q->whereDate('created_at', '>=', $request->desde))
            ->when($request->filled('hasta'), fn ($q) => $q->whereDate('created_at', '<=', $request->hasta))
            ->when($request->filled('buscar'), fn ($q) => $q->where('descripcion', 'like', "%{$request->buscar}%"))
            ->latest('created_at')
            ->paginate(30)
            ->withQueryString();

        return Inertia::render('Auditoria/Index', [
            'registros' => $registros,
            'filtros'   => $request->only(['usuario_id', 'modelo', 'accion', 'desde', 'hasta', 'buscar']),
            'usuarios'  => User::orderBy('name')->get(['id', 'name']),
            'modelos'   => RegistroActividad::select('modelo')->distinct()->orderBy('modelo')->pluck('modelo'),
        ]);
    }
}
