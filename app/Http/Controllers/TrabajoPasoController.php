<?php

namespace App\Http\Controllers;

use App\Models\OpItemTrabajoPaso;
use App\Services\CierrePasoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Marcar un paso desde la hoja del trabajo y desde el tablero.
 *
 * Cerrar y reabrir no viven aquí: viven en `CierrePasoService`, que es el único sitio donde se
 * decide qué pasa al cerrar un paso —los puntos, la entrega a bodega, las dos bodegas—. Aquí
 * queda lo que sí es propio de esta pantalla: corregir a mano las horas y los tiempos por
 * operario de un paso que ya está cerrado.
 */
class TrabajoPasoController extends Controller
{
    public function update(Request $request, OpItemTrabajoPaso $paso, CierrePasoService $cierre): JsonResponse
    {
        $data = $request->validate([
            'completado'                 => 'sometimes|boolean',
            'iniciado'                   => 'sometimes|boolean',
            'iniciado_at_manual'         => 'sometimes|nullable|date',
            'completado_at_manual'       => 'sometimes|nullable|date',
            'tiempo_minutos'             => 'sometimes|nullable|integer|min:0',
            'es_extra'                   => 'sometimes|boolean',
            'operarios'                  => 'sometimes|nullable|array',
            'operarios.*.operario_id'    => 'required|exists:operarios,id',
            'operarios.*.tiempo_minutos' => 'nullable|integer|min:0',
            'operarios.*.observaciones'  => 'nullable|string|max:500',
            // Las dos bodegas del paso final: a dónde entra la unidad y de dónde salió su
            // material. En los demás pasos no significan nada y se ignoran.
            'bodega_entrega_id'          => 'sometimes|nullable|exists:bodegas,id',
            'bodega_material_id'         => 'sometimes|nullable|exists:bodegas,id',
        ]);

        $operarios = collect($data['operarios'] ?? [])
            ->filter(fn ($o) => ! empty($o['operario_id']))
            ->values()
            ->all();

        $entregadaEn = null;

        // ─── Cerrar o reabrir ────────────────────────────────────────────────────
        if (array_key_exists('completado', $data)) {
            try {
                if ($data['completado']) {
                    $entregadaEn = $cierre->cerrar(
                        $paso,
                        $operarios,
                        $data['bodega_entrega_id']  ?? null,
                        $data['bodega_material_id'] ?? null,
                    )?->nombre;
                } else {
                    $cierre->reabrir($paso);
                }
            } catch (ValidationException $e) {
                // La pantalla espera un mensaje que pueda mostrar tal cual, no el formato de
                // errores de un formulario: aquí no hay campos donde pintarlos.
                return response()->json([
                    'success' => false,
                    'message' => collect($e->errors())->flatten()->implode(' '),
                    'errores' => $e->errors(),
                ], 422);
            }

            $paso->refresh();
        }

        // ─── Iniciar / quitar el inicio ──────────────────────────────────────────
        if (array_key_exists('iniciado', $data)) {
            if ($data['iniciado'] && ! $paso->iniciado_at) {
                $paso->update(['iniciado_at' => now()]);
            } elseif (! $data['iniciado'] && ! $paso->completado) {
                $paso->update(['iniciado_at' => null]);
            }
        }

        // ─── Corrección manual de horas ──────────────────────────────────────────
        //
        // Por si el arranque o el cierre automático no coinciden con lo que pasó de verdad en
        // planta. Es lo único de esta pantalla que no puede vivir en el servicio: el servicio
        // cierra un paso ahora, no lo reescribe.
        $correccion = [];

        if (array_key_exists('iniciado_at_manual', $data)) {
            $correccion['iniciado_at'] = $data['iniciado_at_manual']
                ? \Carbon\Carbon::parse($data['iniciado_at_manual'])
                : null;
        }

        if (array_key_exists('completado_at_manual', $data)) {
            $correccion['completado_at'] = $data['completado_at_manual']
                ? \Carbon\Carbon::parse($data['completado_at_manual'])
                : null;
        }

        if (array_key_exists('es_extra', $data)) {
            $correccion['es_extra'] = $data['es_extra'];
        }

        if ($correccion !== []) {
            $paso->update($correccion);
            $paso->refresh();
        }

        // El tiempo real (fin − inicio) es el valor por omisión del tiempo de cada operario
        // cuando nadie escribió uno a mano.
        $duracionAuto = $paso->duracionRealMinutos();

        // ─── Operarios de un paso ya cerrado ─────────────────────────────────────
        //
        // Corregir un tiempo sin tener que desmarcar y volver a marcar. Solo entra aquí cuando
        // el cierre no pasó ya por el servicio, que sincroniza lo mismo.
        if ($request->has('operarios') && ! array_key_exists('completado', $data)) {
            $paso->operarios()->delete();

            foreach ($operarios as $quien) {
                $paso->operarios()->create([
                    'operario_id'    => $quien['operario_id'],
                    'tiempo_minutos' => $quien['tiempo_minutos'] ?? $duracionAuto,
                    'observaciones'  => $quien['observaciones'] ?? null,
                ]);
            }

            $paso->update([
                'operario_id'    => $operarios[0]['operario_id']    ?? null,
                'tiempo_minutos' => $operarios[0]['tiempo_minutos'] ?? $duracionAuto,
            ]);
        } elseif ($duracionAuto !== null && ! $request->has('operarios') && $paso->operarios()->exists()) {
            // Solo se corrigieron las horas: el tiempo individual se resincroniza con la nueva
            // duración real en vez de quedarse con la vieja.
            $paso->operarios()->update(['tiempo_minutos' => $duracionAuto]);
            $paso->update(['tiempo_minutos' => $duracionAuto]);
        }

        if (array_key_exists('tiempo_minutos', $data)) {
            $paso->update(['tiempo_minutos' => $data['tiempo_minutos']]);
        }

        $paso->load('operario', 'operarios.operario');
        $trabajo = $paso->trabajo->fresh();
        $trabajo->recalcularAvance();
        $trabajo->refresh();

        return response()->json([
            'success'           => true,
            'entregada_en'      => $entregadaEn,
            'porcentaje_avance' => (float) $trabajo->porcentaje_avance,
            'paso'              => [
                'id'                    => $paso->id,
                'completado'            => (bool) $paso->completado,
                'completado_at'         => $paso->completado_at?->format('d/m/Y H:i'),
                'completado_at_iso'     => $paso->completado_at?->toIso8601String(),
                'iniciado_at'           => $paso->iniciado_at?->format('d/m/Y H:i'),
                'iniciado_at_iso'       => $paso->iniciado_at?->toIso8601String(),
                'duracion_real_minutos' => $paso->duracionRealMinutos(),
                'operario_id'           => $paso->operario_id,
                'operario_nombre'       => $paso->operario?->nombre,
                'tiempo_minutos'        => $paso->tiempo_minutos,
                'es_extra'              => (bool) $paso->es_extra,
                'operarios_pivot'       => $paso->operarios->map(fn ($o) => [
                    'operario_id'    => $o->operario_id,
                    'nombre'         => $o->operario?->nombre,
                    'tiempo_minutos' => $o->tiempo_minutos,
                    'observaciones'  => $o->observaciones,
                ])->values(),
            ],
        ]);
    }
}
