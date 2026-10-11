<?php

namespace App\Http\Controllers;

use App\Models\Op;
use App\Models\OpItem;
use App\Models\OpItemTrabajo;
use App\Models\OpItemTrabajoPaso;
use App\Models\TemplateTrabajo;
use App\Services\TrabajoAutoGeneratorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OpTrabajoController extends Controller
{
    /**
     * «Procesar en planta»: le asigna un flujo a un ítem y crea todas sus unidades.
     *
     * Antes creaba **una** unidad por clic, sin revisión de calidad ni paso final, y marcaba el
     * ítem «en proceso» por su cuenta, sin avisar a la orden. Ahora pasa por el mismo servicio
     * que crea las unidades de un ensamble, así que nacen iguales: QR, pasos, revisión.
     */
    public function iniciar(Request $request, Op $op, OpItem $item): RedirectResponse
    {
        abort_if($item->op_id !== $op->id, 404);

        $request->validate(['template_id' => 'required|exists:templates_trabajo,id']);

        $template = TemplateTrabajo::findOrFail($request->template_id);

        if ($motivo = app(TrabajoAutoGeneratorService::class)->procesarEnPlanta($item, $template)) {
            return back()->with('error', $motivo);
        }

        $unidades = $item->trabajos()->count();

        return back()->with('success', "Listo: {$unidades} unidad(es) en planta con el flujo «{$template->nombre}».");
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

    /**
     * Saca un ítem de planta: borra sus unidades, que todavía nadie tocó.
     *
     * Antes borraba todo sin mirar, con avance registrado y unidades ya remisionadas incluidas:
     * se perdían pasos, tiempos y puntos de los operarios, y una unidad que ya iba en el camión
     * dejaba de existir en el sistema.
     */
    public function eliminarTrabajos(Request $request, Op $op, OpItem $item): RedirectResponse
    {
        abort_if($item->op_id !== $op->id, 404);

        if ($motivo = app(TrabajoAutoGeneratorService::class)->sacarDePlanta($item)) {
            return back()->with('error', $motivo);
        }

        return back()->with('success', 'El ítem salió de planta. Puedes asignarle otro flujo, o alistarlo a mano.');
    }

    private function recalcularProgresoOp(Op $op): void
    {
        $trabajos = OpItemTrabajo::whereHas('opItem', fn ($q) => $q->where('op_id', $op->id))->get();

        if ($trabajos->isEmpty()) return;

        $promedio = $trabajos->avg('porcentaje_avance');
        $op->update(['porcentaje_avance' => round($promedio, 2)]);
    }
}
