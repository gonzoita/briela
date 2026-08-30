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
        $data = $request->validate([
            'operarios'          => 'nullable|array',
            'operarios.*'        => 'integer|exists:operarios,id',
            // Las dos bodegas del paso final: a dónde entra la unidad terminada y de dónde
            // salieron sus insumos. Llegan precargadas de la orden y aquí solo se corrigen.
            'bodega_entrega_id'  => 'nullable|exists:bodegas,id',
            'bodega_material_id' => 'nullable|exists:bodegas,id',
        ]);

        // Esta pantalla manda los operarios como una lista de ids; el servicio los espera con
        // su tiempo y su observación, igual que las demás.
        $operarios = collect($data['operarios'] ?? [])
            ->map(fn ($id) => ['operario_id' => $id])
            ->all();

        // Cerrar un paso pasa por un solo sitio, venga del código QR, de aquí o del tablero.
        $bodega = app(\App\Services\CierrePasoService::class)->cerrar(
            $paso,
            $operarios,
            $data['bodega_entrega_id'] ?? null,
            $data['bodega_material_id'] ?? null,
        );

        $item->update([
            'estado_item' => $paso->trabajo->fresh()->pasos()->where('completado', false)->exists()
                ? 'en_proceso'
                : 'terminado',
        ]);

        $this->recalcularProgresoOp($op);

        $aviso = $bodega ? " La unidad entró a {$bodega->nombre}." : '';

        return back()->with('success', 'Paso completado.'.$aviso);
    }

    public function desmarcarPaso(Request $request, Op $op, OpItem $item, OpItemTrabajoPaso $paso): RedirectResponse
    {
        app(\App\Services\CierrePasoService::class)->reabrir($paso);

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
