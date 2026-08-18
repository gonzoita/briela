<?php

namespace App\Http\Controllers;

use App\Models\OpItemTrabajoPaso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrabajoPasoController extends Controller
{
    public function update(Request $request, OpItemTrabajoPaso $paso): JsonResponse
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
            // A que bodega entra la unidad. Solo significa algo en el paso final.
            'bodega_destino_id'          => 'sometimes|nullable|exists:bodegas,id',
        ]);

        // ─── El paso final entrega la unidad ─────────────────────────────────────
        //
        // Esta es la pantalla que de verdad usa la planta, y aqui no pasaba nada al cerrar
        // el ultimo paso: el avance llegaba a 100 y la unidad no entraba a ninguna bodega ni
        // se descontaba un solo material. La entrega estaba escrita en OTRO endpoint, el de
        // la orden de produccion, que esta pantalla no llama.
        //
        // Se pide la bodega aqui porque es quien cierra el paso el que fisicamente deja la
        // unidad en el estante, y es el unico que sabe en cual.
        $cerrandoFinal = ($data['completado'] ?? false) && $paso->es_paso_final && ! $paso->completado;

        if ($cerrandoFinal) {
            $bodega = $data['bodega_destino_id'] ?? $paso->bodega_destino_id;

            if (! $bodega) {
                return response()->json([
            'entregada_en' => $entregadaEn ?? null,
                    'success' => false,
                    'message' => 'Elige a que bodega entra la unidad: este es el paso que la entrega.',
                ], 422);
            }

            $data['bodega_destino_id'] = $bodega;
        }

        // Fecha/hora de inicio y fin siempre las pone el servidor con now() —
        // nadie las escribe a mano. "iniciado" marca el arranque; si se marca
        // completado sin haber pasado por "iniciado" antes, se rellena solo
        // para que la línea de tiempo del paso nunca quede incompleta.
        if (array_key_exists('iniciado', $data)) {
            if ($data['iniciado'] && ! $paso->iniciado_at) {
                $data['iniciado_at'] = now();
            } elseif (! $data['iniciado'] && ! $paso->completado) {
                $data['iniciado_at'] = null;
            }
            unset($data['iniciado']);
        }

        if (array_key_exists('completado', $data)) {
            if ($data['completado'] && ! $paso->completado_at) {
                $data['completado_at'] = now();
                if (! $paso->iniciado_at && ! ($data['iniciado_at'] ?? null)) {
                    $data['iniciado_at'] = $data['completado_at'];
                }
            } elseif (! $data['completado']) {
                $data['completado_at'] = null;
            }
        }

        // Corrección manual — por si el arranque/cierre automático no coincide
        // con lo que pasó de verdad en planta.
        if (array_key_exists('iniciado_at_manual', $data)) {
            $data['iniciado_at'] = $data['iniciado_at_manual'] ? \Carbon\Carbon::parse($data['iniciado_at_manual']) : null;
            unset($data['iniciado_at_manual']);
        }
        if (array_key_exists('completado_at_manual', $data)) {
            $data['completado_at'] = $data['completado_at_manual'] ? \Carbon\Carbon::parse($data['completado_at_manual']) : null;
            unset($data['completado_at_manual']);
        }

        // Tiempo real (fin - inicio) — se usa como valor del tiempo por
        // operario cuando no se escribió uno a mano. diffInMinutes() puede
        // devolver float en Carbon reciente, por eso el round().
        $inicioEfectivo = $data['iniciado_at']   ?? $paso->iniciado_at;
        $finEfectivo    = $data['completado_at'] ?? $paso->completado_at;
        $duracionAuto   = ($inicioEfectivo && $finEfectivo)
            ? (int) round(\Carbon\Carbon::parse($inicioEfectivo)->diffInMinutes(\Carbon\Carbon::parse($finEfectivo)))
            : null;

        // Guardar operario principal (primero de la lista) para compatibilidad
        if (!empty($data['operarios'])) {
            $data['operario_id']    = $data['operarios'][0]['operario_id'];
            $data['tiempo_minutos'] = $data['operarios'][0]['tiempo_minutos'] ?? $duracionAuto;
        } elseif ($duracionAuto !== null && !$request->has('operarios')) {
            // No se tocó el formulario de operarios en este guardado (ej. solo
            // se corrigieron las horas de inicio/fin) — el tiempo se
            // resincroniza solo con la nueva duración real.
            $data['tiempo_minutos'] = $duracionAuto;
        }

        unset($data['operarios']);
        $paso->update($data);

        // Sincronizar pivot de múltiples operarios
        if ($request->has('operarios')) {
            $paso->operarios()->delete();
            foreach ($request->operarios ?? [] as $op_data) {
                $paso->operarios()->create([
                    'operario_id'    => $op_data['operario_id'],
                    'tiempo_minutos' => $op_data['tiempo_minutos'] ?? $duracionAuto,
                    'observaciones'  => $op_data['observaciones'] ?? null,
                ]);
            }
        } elseif ($duracionAuto !== null && $paso->operarios()->exists()) {
            // Mismo caso: si ya había operarios asignados y solo se corrigieron
            // las horas, su tiempo individual también se resincroniza.
            $paso->operarios()->update(['tiempo_minutos' => $duracionAuto]);
        }

        // Si se desmarca, limpiar operarios pivot y devolver los puntos que
        // el paso hubiera otorgado (mismo criterio que al desmarcar desde el
        // portal del operario, para no dejar puntos por un paso incompleto).
        if (isset($data['completado']) && ! $data['completado']) {
            app(\App\Services\PuntosColaboradorService::class)->revertirPuntosPorPaso($paso->id);
            $paso->operarios()->delete();
        }

        $paso->load('operario', 'operarios.operario');
        $trabajo = $paso->trabajo;
        $trabajo->recalcularAvance();

        // Cerrado el ultimo paso: se descuentan los materiales de ESTA unidad y entra como
        // producto terminado. `entregado_at` es el candado contra la doble entrega.
        $entregadaEn = null;

        if ($cerrandoFinal) {
            $entregadaEn = app(\App\Services\EntregaAlmacenService::class)->entregar($trabajo->fresh())?->nombre;
        }
        $trabajo->refresh();

        return response()->json([
            'success'           => true,
            'porcentaje_avance' => (float) $trabajo->porcentaje_avance,
            'paso'              => [
                'id'                    => $paso->id,
                'completado'            => (bool) $paso->completado,
                'completado_at'         => $paso->completado_at?->format('d/m/Y H:i'),
                'completado_at_iso'     => $paso->completado_at?->toIso8601String(),
                'iniciado_at'           => $paso->iniciado_at?->format('d/m/Y H:i'),
                'iniciado_at_iso'       => $paso->iniciado_at?->toIso8601String(),
                'duracion_real_minutos' => $paso->duracionRealMinutos(),
                'operario_id'     => $paso->operario_id,
                'operario_nombre' => $paso->operario?->nombre,
                'tiempo_minutos'  => $paso->tiempo_minutos,
                'es_extra'        => (bool) $paso->es_extra,
                'operarios_pivot' => $paso->operarios->map(fn ($o) => [
                    'operario_id'    => $o->operario_id,
                    'nombre'         => $o->operario?->nombre,
                    'tiempo_minutos' => $o->tiempo_minutos,
                    'observaciones'  => $o->observaciones,
                ])->values(),
            ],
        ]);
    }
}
