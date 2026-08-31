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

        // Marcar los puntos uno por uno también firma la unidad cuando ya no queda ninguno sin
        // resolver — si no, marcar los ocho a mano dejaría la unidad igual de bloqueada que
        // antes de empezar, y solo el atajo «Terminar» la abriría.
        $trabajo = $check->trabajo;

        if ($trabajo) {
            $trabajo->firmarCalidad(! $trabajo->calidadPendiente());
        }

        $this->sellarCalidadSiTerminó($check);

        return response()->json($this->fila($check->fresh('revisadoPor')));
    }

    /**
     * Cuando la última unidad de la OP queda revisada, la orden queda aprobada sola.
     *
     * Es el principio del sistema: cada acción real dispara el siguiente paso. Nadie debería
     * tener que entrar a la orden a apretar «aprobar» después de haber revisado punto por punto
     * cada unidad — y si tuviera que hacerlo, ese botón terminaría siendo un trámite que se
     * aprieta sin mirar.
     *
     * Si algo se marca como falla después, el sello se retira: la orden vuelve a no estar
     * aprobada, que es lo honesto.
     */
    private function sellarCalidadSiTerminó(OpItemTrabajoCheck $check): void
    {
        $op = $check->trabajo?->opItem?->op;

        if (! $op) {
            return;
        }

        $pendientes = OpItemTrabajoCheck::whereHas(
            'trabajo.opItem', fn ($q) => $q->where('op_id', $op->id)
        )->where(function ($q) {
            $q->where('resultado', 'pendiente')
              ->orWhere(fn ($q2) => $q2->where('resultado', 'falla')->where('es_critico', true));
        })->count();

        if ($pendientes === 0 && ! $op->calidad_aprobada_at) {
            $op->update(['calidad_aprobada_at' => now(), 'estado' => $op->estado === 'calidad' ? 'calidad' : $op->estado]);
        } elseif ($pendientes > 0 && $op->calidad_aprobada_at) {
            $op->update(['calidad_aprobada_at' => null]);
        }
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
