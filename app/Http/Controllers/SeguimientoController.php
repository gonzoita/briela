<?php

namespace App\Http\Controllers;

use App\Models\Op;
use App\Models\OpItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

// Portal público de seguimiento por búsqueda (/seguimiento). Hasta el 23 de
// julio de 2026 esto buscaba contra OrdenProduccion/ItemOP, un sistema
// abandonado desde que se migró a Op/OpItem — nunca encontraba nada real.
// Reconstruido para buscar contra el modelo Op real, ya sea por número de
// OP (ej. "OP-0017") o por número de serie de un ítem
// (ej. "IF-2026-045-P-001"). Reutiliza la misma vista y los mismos datos
// que el seguimiento por QR (/op/{token}) — ver Op::datosSeguimientoPublico().
class SeguimientoController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Seguimiento/Index');
    }

    public function show(Request $request, string $codigo): Response|RedirectResponse
    {
        $codigo = trim($codigo);

        // Buscar por número de serie de un ítem (ej. IF-2026-045-P-001) —
        // se resuelve al Op al que pertenece.
        $item = OpItem::where('numero_serie', $codigo)->first();
        $op   = $item?->op;

        // Si no fue por serie, buscar directo por número de OP.
        if (! $op) {
            $op = Op::where('numero', $codigo)->first();
        }

        if (! $op) {
            return redirect()->route('seguimiento.index')
                ->with('error', "No encontramos resultados para «{$codigo}».");
        }

        // Candado de privacidad: como el número de OP es consecutivo (y
        // adivinable), se exige además un dato del cliente (apellido o
        // documento) para que nadie pueda espiar pedidos ajenos probando
        // números. El link por QR (/op/{token}) no pide esto porque el token
        // ya es imposible de adivinar.
        $op->load(['cliente', 'responsable', 'items.trabajos']);

        $verificacion = mb_strtolower(trim($request->query('v', '')));
        $cliente      = $op->cliente;
        $coincide = $verificacion !== '' && $cliente && (
            mb_strtolower(trim($cliente->apellido ?? ''))              === $verificacion ||
            mb_strtolower(trim($cliente->numero_identificacion ?? '')) === $verificacion ||
            mb_strtolower(trim($cliente->nombre ?? ''))               === $verificacion
        );

        if (! $coincide) {
            return redirect()->route('seguimiento.index')
                ->with('error', 'Para ver el pedido, ingresa también el apellido o el documento del cliente tal como figura en la orden.');
        }

        return Inertia::render('OpPublica/Show', [
            'op' => $op->datosSeguimientoPublico(),
        ]);
    }
}
