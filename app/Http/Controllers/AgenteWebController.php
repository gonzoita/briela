<?php

namespace App\Http\Controllers;

use App\Services\IA\AgenteConversacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * El chat de la web, para quien todavía no es nadie para el sistema.
 *
 * Es público a propósito y por eso es el más expuesto del sistema: cada mensaje cuesta tokens y
 * no hay sesión que limite quién escribe. Va con `throttle`, con el historial acotado y con el
 * agente de perfil público, que no alcanza un solo dato de ningún cliente.
 */
class AgenteWebController extends Controller
{
    public function chat(Request $request, AgenteConversacionService $agentes): JsonResponse
    {
        $datos = $request->validate([
            'mensaje'            => 'required|string|max:900',
            'historial'          => 'nullable|array|max:20',
            'historial.*.rol'    => 'required_with:historial|in:cliente,agente',
            'historial.*.texto'  => 'required_with:historial|string|max:2000',
        ]);

        $respuesta = $agentes->responderWeb($datos['mensaje'], $datos['historial'] ?? []);

        // Sin agente activo para la web, el widget no debe fingir que hay alguien: dice que no
        // hay atención automática y deja el camino de siempre.
        return response()->json([
            'respuesta' => $respuesta,
            'atendido'  => $respuesta !== null,
        ]);
    }
}
