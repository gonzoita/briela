<?php

namespace App\Http\Controllers;

use App\Services\BuscadorGlobalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BuscadorController extends Controller
{
    /**
     * El buscador nunca debe responder con un error 500.
     *
     * Un buscador que revienta se ve igual que uno que no encuentra nada, y
     * eso hace perder horas: uno termina dudando de sus datos cuando el
     * problema es del código. Si algo falla, se responde 200 con el motivo
     * escrito, y la interfaz lo muestra tal cual.
     */
    public function buscar(Request $request, BuscadorGlobalService $buscador): JsonResponse
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:100',
        ]);

        try {
            $grupos = $buscador->buscar($data['q'] ?? '');

            return response()->json([
                'grupos' => $grupos,
                'total'  => array_sum(array_map(fn ($g) => count($g['resultados']), $grupos)),
            ]);
        } catch (\Throwable $e) {
            Log::error('Buscador global: ' . $e->getMessage(), [
                'archivo' => $e->getFile(),
                'linea'   => $e->getLine(),
            ]);

            return response()->json([
                'grupos' => [],
                'total'  => 0,
                'error'  => $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')',
            ]);
        }
    }
}
