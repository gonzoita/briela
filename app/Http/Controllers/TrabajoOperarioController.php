<?php

namespace App\Http\Controllers;

use App\Models\Operario;
use App\Models\OpItemTrabajo;
use App\Models\OpItemTrabajoPaso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TrabajoOperarioController extends Controller
{
    public function show(string $token): Response
    {
        $trabajo = OpItemTrabajo::where('token_trabajo', $token)
            ->with([
                'opItem.op.cliente',
                'opItem.op.items',
                'opItem.ensamble.plantilla.campos',
                'opItem.componentes.producto',
                'template',
                'pasos' => fn ($q) => $q->orderBy('orden'),
                'pasos.operarios.operario',
            ])
            ->firstOrFail();

        $user     = auth()->user();
        $operario = Operario::where('user_id', $user->id)->first();

        if ($user->rol === 'operario') {
            if (!$operario) abort(403, 'No tienes un perfil de operario asociado.');
            $asignado = $trabajo->pasos()
                ->whereHas('operarios', fn ($q) => $q->where('operario_id', $operario->id))
                ->exists();
            if (!$asignado) {
                $asignado = $trabajo->pasos()
                    ->where('operario_id', $operario->id)
                    ->exists();
            }
            if (!$asignado && $trabajo->pasos()->count() > 0) {
                abort(403, 'No tienes acceso a este trabajo.');
            }
        }

        $item = $trabajo->opItem;
        $op   = $item->op;

        $itemIdx   = $op->items->search(fn ($i) => $i->id === $item->id);
        $itemCodigo = $op->numero . '-' . str_pad(($itemIdx !== false ? $itemIdx : 0) + 1, 2, '0', STR_PAD_LEFT);

        $camposPlantilla = $item->ensamble?->plantilla?->campos
            ->where('tipo_campo', 'entrada')
            ->sortBy('orden')
            ->map(fn ($c) => [
                'nombre'            => $c->nombre,
                'etiqueta'          => $c->etiqueta ?? $c->nombre,
                'imagen_referencia' => $c->imagen_referencia
                    ? '/storage/' . $c->imagen_referencia
                    : null,
            ])->values() ?? collect();

        return Inertia::render('Trabajo/Show', [
            'trabajo' => [
                'id'                 => $trabajo->id,
                'token'              => $trabajo->token_trabajo,
                'numero_unidad'      => $trabajo->numero_unidad,
                'total_unidades'     => $trabajo->total_unidades,
                'porcentaje_avance'  => (float) $trabajo->porcentaje_avance,
                'template_nombre'    => $trabajo->template?->nombre,
                'op_numero'          => $op->numero,
                'op_id'              => $op->id,
                'op_estado'          => $op->estado,
                'cliente_nombre'     => $op->cliente
                    ? trim($op->cliente->nombre . ' ' . $op->cliente->apellido)
                    : null,
                'item_descripcion'   => $item->descripcion,
                'item_codigo'        => $itemCodigo,
                'variables_instancia' => $item->variables_instancia ?? [],
                'imagenes_instancia'  => $item->imagenes_instancia ?? [],
                'campos_plantilla'    => $camposPlantilla,
                'pasos' => $trabajo->pasos->map(fn ($p) => [
                    'id'                   => $p->id,
                    'nombre'               => $p->nombre,
                    'descripcion_resuelta' => $p->descripcion_resuelta,
                    'peso_porcentaje'      => (float) $p->peso_porcentaje,
                    'completado'           => (bool) $p->completado,
                    'completado_at'        => $p->completado_at?->format('d/m/Y H:i'),
                    'es_extra'             => (bool) $p->es_extra,
                    'fotos'                => $p->fotos ?? [],
                    'operarios_pivot'      => $p->operarios->map(fn ($o) => [
                        'operario_id'    => $o->operario_id,
                        'nombre'         => $o->operario?->nombre,
                        'tiempo_minutos' => $o->tiempo_minutos,
                        'observaciones'  => $o->observaciones,
                    ])->values(),
                ])->values(),
            ],
            'operario_id'     => $operario?->id,
            'operario_nombre' => $operario?->nombre ?? $user->name,
            'operarios'       => Operario::where('estado', 'activo')->get(['id', 'nombre']),
        ]);
    }

    public function completarPaso(Request $request, string $token, OpItemTrabajoPaso $paso): RedirectResponse
    {
        $trabajo = OpItemTrabajo::where('token_trabajo', $token)->firstOrFail();
        abort_if($paso->op_item_trabajo_id !== $trabajo->id, 403);

        $user = auth()->user();
        if ($user->rol === 'operario') {
            $operario = Operario::where('user_id', $user->id)->first();
            if (!$operario) abort(403);
        }

        $request->validate([
            'operarios'                  => 'nullable|array',
            'operarios.*.operario_id'    => 'required|exists:operarios,id',
            'operarios.*.tiempo_minutos' => 'nullable|integer|min:0',
            'operarios.*.observaciones'  => 'nullable|string|max:500',
        ]);

        $paso->update([
            'completado'     => true,
            'completado_at'  => now(),
            'operario_id'    => $request->operarios[0]['operario_id'] ?? null,
            'tiempo_minutos' => $request->operarios[0]['tiempo_minutos'] ?? null,
        ]);

        $paso->operarios()->delete();
        foreach ($request->operarios ?? [] as $op_data) {
            $paso->operarios()->create([
                'operario_id'    => $op_data['operario_id'],
                'tiempo_minutos' => $op_data['tiempo_minutos'] ?? null,
                'observaciones'  => $op_data['observaciones'] ?? null,
            ]);
        }

        $trabajo->recalcularAvance();

        $item = $trabajo->opItem;
        if ($trabajo->pasos()->where('completado', false)->count() === 0) {
            $item->update(['estado_item' => 'terminado']);
        } else {
            $item->update(['estado_item' => 'en_proceso']);
        }

        $op = $item->op;
        $trabajosOp = \App\Models\OpItemTrabajo::whereHas('opItem', fn ($q) => $q->where('op_id', $op->id))->get();
        if ($trabajosOp->isNotEmpty()) {
            $op->update(['porcentaje_avance' => round($trabajosOp->avg('porcentaje_avance'), 2)]);
        }

        // Otorgar puntos a cada operario asignado al paso
        $svc = app(\App\Services\PuntosColaboradorService::class);
        foreach ($paso->operarios as $opData) {
            if ($opData->operario_id) {
                $svc->otorgarPuntosPorPaso($paso, $opData->operario_id);
            }
        }

        return back()->with('success', 'Paso completado.');
    }

    public function desmarcarPaso(Request $request, string $token, OpItemTrabajoPaso $paso): RedirectResponse
    {
        $trabajo = OpItemTrabajo::where('token_trabajo', $token)->firstOrFail();
        abort_if($paso->op_item_trabajo_id !== $trabajo->id, 403);

        $user = auth()->user();
        if ($user->rol === 'operario') {
            $operario = Operario::where('user_id', $user->id)->first();
            if (!$operario) abort(403);
        }

        // Devolver los puntos que este paso había otorgado — si no, al
        // recompletarlo se sumarían de nuevo y quedaría doble conteo.
        app(\App\Services\PuntosColaboradorService::class)->revertirPuntosPorPaso($paso->id);

        $paso->operarios()->delete();
        $paso->update(['completado' => false, 'completado_at' => null]);
        $trabajo->recalcularAvance();
        $trabajo->opItem->update(['estado_item' => 'en_proceso']);

        return back()->with('success', 'Paso desmarcado.');
    }
}
