<?php

namespace App\Http\Controllers;

use App\Models\Op;
use App\Models\OpItem;
use App\Models\OpItemTrabajo;
use App\Models\OpItemTrabajoPaso;
use App\Models\TemplateTrabajo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OpTrabajoController extends Controller
{
    public function iniciar(Request $request, Op $op, OpItem $item): RedirectResponse
    {
        $request->validate(['template_id' => 'required|exists:templates_trabajo,id']);

        $template  = TemplateTrabajo::with('pasos')->findOrFail($request->template_id);
        $variables = $item->variables_instancia ?? [];
        $cantidad  = (int) max(1, floor((float) $item->cantidad));

        // Contar trabajos existentes
        $trabajosExistentes = OpItemTrabajo::where('op_item_id', $item->id)->count();

        if ($trabajosExistentes >= $cantidad) {
            return back()->with('error',
                "Ya existen {$trabajosExistentes} trabajo(s) para este ítem. Máximo permitido: {$cantidad}."
            );
        }

        // Crear el siguiente trabajo en secuencia
        $siguienteUnidad = $trabajosExistentes + 1;

        $trabajo = OpItemTrabajo::create([
            'op_item_id'        => $item->id,
            'template_id'       => $template->id,
            'porcentaje_avance' => 0,
            'numero_unidad'     => $siguienteUnidad,
            'total_unidades'    => $cantidad,
        ]);

        $serie = now()->format('Ymd') . str_pad($item->id, 4, '0', STR_PAD_LEFT) . str_pad($siguienteUnidad, 3, '0', STR_PAD_LEFT);
        if (!$item->numero_serie) {
            $item->update(['numero_serie' => $serie]);
        }

        foreach ($template->pasos as $paso) {
            $desc = preg_replace_callback('/\{(\w+)\}/', function ($m) use ($variables) {
                return $variables[$m[1]] ?? $m[0];
            }, $paso->descripcion);

            OpItemTrabajoPaso::create([
                'op_item_trabajo_id'   => $trabajo->id,
                'template_paso_id'     => $paso->id,
                'nombre'               => $paso->nombre,
                'descripcion_resuelta' => $desc,
                'peso_porcentaje'      => $paso->peso_porcentaje,
                'orden'                => $paso->orden,
            ]);
        }

        // Actualizar estado del ítem
        $item->update(['estado_item' => 'en_proceso']);
        $this->recalcularProgresoOp($op);

        $quedan = $cantidad - $siguienteUnidad;
        $msg = "Trabajo {$siguienteUnidad}/{$cantidad} creado con template \"{$template->nombre}\".";
        if ($quedan > 0) $msg .= " Faltan {$quedan} trabajo(s) por iniciar.";

        return back()->with('success', $msg);
    }

    public function completarPaso(Request $request, Op $op, OpItem $item, OpItemTrabajoPaso $paso): RedirectResponse
    {
        $request->validate([
            'operarios'         => 'nullable|array',
            'operarios.*'       => 'integer|exists:operarios,id',
            'bodega_destino_id' => 'nullable|exists:bodegas,id',
        ]);

        // El paso final entrega la unidad a una bodega: al cerrarlo se descuentan los
        // materiales y entra el producto terminado. Antes, si nadie la había declarado, el
        // sistema la mandaba a la principal — y en una empresa con varias bodegas eso es
        // inventario que aparece donde no es, sin que nadie lo haya decidido.
        if ($paso->es_paso_final) {
            // La OP manda: si ya declaró su bodega, el operario no tiene nada que elegir.
            // Solo se le pregunta a quien cierra el paso de una OP vieja, nacida antes de que
            // el campo existiera.
            $bodega = $op->bodega_entrega_id ?: ($request->bodega_destino_id ?: $paso->bodega_destino_id);

            if (! $bodega) {
                return back()->withErrors([
                    'bodega_destino_id' => 'Elige a qué bodega entra la unidad: este es el paso que la entrega. '
                        . 'Lo normal es que lo declare la orden de producción; esta no lo hace porque '
                        . 'se creó antes de que ese campo existiera.',
                ]);
            }

            $paso->update(['bodega_destino_id' => $bodega]);
        }

        $paso->update([
            'completado'    => true,
            'completado_at' => now(),
        ]);

        if (!empty($request->operarios)) {
            $paso->operarios()->delete();
            foreach ($request->operarios as $operarioId) {
                $paso->operarios()->create(['operario_id' => $operarioId]);
            }
        }

        $trabajo = $paso->trabajo;
        $trabajo->recalcularAvance();

        $aviso = '';

        if ($trabajo->pasos()->where('completado', false)->count() === 0) {
            $item->update(['estado_item' => 'terminado']);

            // La unidad terminó: entra a bodega y sus materiales se descuentan ahí. Antes el
            // trabajo terminaba y la unidad no existía en ninguna parte del sistema hasta que
            // alguien la despachaba.
            $bodega = app(\App\Services\EntregaAlmacenService::class)->entregar($trabajo);

            if ($bodega) {
                $aviso = " La unidad entró a {$bodega->nombre}.";
            }
        }

        $this->recalcularProgresoOp($op);

        return back()->with('success', 'Paso completado.'.$aviso);
    }

    public function desmarcarPaso(Request $request, Op $op, OpItem $item, OpItemTrabajoPaso $paso): RedirectResponse
    {
        $paso->operarios()->delete();
        $paso->update([
            'completado'    => false,
            'completado_at' => null,
        ]);

        $trabajo = $paso->trabajo;
        $trabajo->recalcularAvance();

        if ($item->estado_item === 'terminado') {
            $item->update(['estado_item' => 'en_proceso']);
        }

        $this->recalcularProgresoOp($op);

        return back()->with('success', 'Paso desmarcado.');
    }

    public function agregarPasoExtra(Request $request, Op $op, OpItem $item): RedirectResponse
    {
        if (!in_array(auth()->user()->rol, ['administrador', 'jefe_produccion'])) {
            return back()->with('error', 'No autorizado.');
        }

        $request->validate([
            'nombre'              => 'required|string|max:200',
            'descripcion_resuelta'=> 'required|string',
            'peso_porcentaje'     => 'required|numeric|min:0|max:100',
        ]);

        $trabajo = OpItemTrabajo::where('op_item_id', $item->id)->firstOrFail();
        $maxOrden = $trabajo->pasos()->max('orden') ?? 0;

        OpItemTrabajoPaso::create([
            'op_item_trabajo_id'  => $trabajo->id,
            'nombre'              => $request->nombre,
            'descripcion_resuelta'=> $request->descripcion_resuelta,
            'peso_porcentaje'     => $request->peso_porcentaje,
            'es_extra'            => true,
            'orden'               => $maxOrden + 1,
        ]);

        return back()->with('success', 'Paso extra agregado.');
    }

    public function registrarTiempo(Request $request, Op $op, OpItem $item, OpItemTrabajoPaso $paso): RedirectResponse
    {
        if (!in_array(auth()->user()->rol, ['administrador', 'jefe_produccion'])) {
            return back()->with('error', 'No autorizado.');
        }

        $request->validate(['tiempo_minutos' => 'required|integer|min:1']);
        $paso->update(['tiempo_minutos' => $request->tiempo_minutos]);

        return back()->with('success', 'Tiempo registrado.');
    }

    public function eliminarTrabajos(Request $request, Op $op, OpItem $item): RedirectResponse
    {
        OpItemTrabajo::where('op_item_id', $item->id)->each(function ($t) {
            $t->pasos()->each(fn ($p) => $p->operarios()->delete());
            $t->pasos()->delete();
            $t->delete();
        });
        $item->update(['estado_item' => 'pendiente', 'numero_serie' => null]);
        $this->recalcularProgresoOp($op);

        return back()->with('success', 'Trabajos eliminados. Puedes asignar un nuevo template.');
    }

    private function recalcularProgresoOp(Op $op): void
    {
        $trabajos = OpItemTrabajo::whereHas('opItem', fn ($q) => $q->where('op_id', $op->id))->get();

        if ($trabajos->isEmpty()) return;

        $promedio = $trabajos->avg('porcentaje_avance');
        $op->update(['porcentaje_avance' => round($promedio, 2)]);
    }
}
