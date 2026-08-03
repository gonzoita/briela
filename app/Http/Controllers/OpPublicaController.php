<?php

namespace App\Http\Controllers;

use App\Models\Op;
use Inertia\Inertia;
use Inertia\Response;

class OpPublicaController extends Controller
{
    public function show(string $token): Response
    {
        $op = Op::where('token_publico', $token)
            ->with(['cliente', 'responsable', 'items.trabajos'])
            ->firstOrFail();

        return Inertia::render('OpPublica/Show', [
            'op' => $op->datosSeguimientoPublico(),
        ]);
    }
}
