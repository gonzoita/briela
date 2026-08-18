<?php

namespace App\Http\Controllers;

use App\Models\OpItemTrabajoCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * La revisión de calidad de una unidad, punto por punto.
 *
 * Antes calidad era una decisión de una sola pieza sobre la orden entera. En una orden de diez
 * puertas eso no dice nada: no queda registro de qué se revisó, ni de cuál unidad salió mal, ni
 * de qué le faltaba. Aquí cada punto se marca por separado, con su foto y su observación, y
 * queda firmado por quien lo revisó.
 *
 * Una falla también se guarda: es justo lo que hace falta cuando el cliente reclama.
 */
class CalidadCheckController extends Controller
{
    public function actualizar(Request $request, OpItemTrabajoCheck $check): JsonResponse
    {
        $datos = $request->validate([
            'resultado'     => 'required|in:pendiente,cumple,falla',
            'observaciones' => 'nullable|string|max:2000',
        ]);

        // Un punto que exige foto no se puede dar por cumplido sin ella. Es el que después se
        // discute con el cliente, y una palabra contra otra no resuelve nada.
        if ($datos['resultado'] === 'cumple' && $check->exige_foto && empty($check->fotos)) {
            return response()->json([
                'message' => 'Este punto exige foto: súbela antes de marcarlo como cumplido.',
            ], 422);
        }

        $check->update([
            'resultado'     => $datos['resultado'],
            'observaciones' => $datos['observaciones'] ?? $check->observaciones,
            'revisado_por'  => $datos['resultado'] === 'pendiente' ? null : auth()->id(),
            'revisado_at'   => $datos['resultado'] === 'pendiente' ? null : now(),
        ]);

        return response()->json($this->fila($check->fresh('revisadoPor')));
    }

    public function fotos(Request $request, OpItemTrabajoCheck $check): JsonResponse
    {
        $request->validate([
            'fotos'   => 'required|array',
            'fotos.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:5120',
        ]);

        $fotos = $check->fotos ?? [];

        foreach ($request->file('fotos') as $file) {
            $fotos[] = $file->store("calidad/{$check->id}", 'public');
        }

        $check->update(['fotos' => $fotos]);

        return response()->json($this->fila($check->fresh('revisadoPor')));
    }

    /**
     * Lo que la pantalla necesita de un punto.
     *
     * Las fotos salen con su URL pública, no con la ruta guardada: mandarla cruda hace que el
     * navegador la resuelva contra la dirección de la pantalla y la imagen se vea rota.
     */
    public function fila(OpItemTrabajoCheck $check): array
    {
        return [
            'id'            => $check->id,
            'titulo'        => $check->titulo,
            'descripcion'   => $check->descripcion,
            'orden'         => $check->orden,
            'exige_foto'    => (bool) $check->exige_foto,
            'es_critico'    => (bool) $check->es_critico,
            'resultado'     => $check->resultado,
            'observaciones' => $check->observaciones,
            'fotos'         => collect($check->fotos ?? [])->map(fn ($f) => Storage::url($f))->all(),
            'revisado_por'  => $check->revisadoPor?->name,
            'revisado_at'   => $check->revisado_at?->format('d/m/Y H:i'),
        ];
    }
}
