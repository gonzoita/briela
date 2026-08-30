<?php

namespace App\Http\Controllers;

use App\Models\Operario;
use App\Models\OpItemTrabajo;
use App\Models\OpItemTrabajoPaso;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Storage;

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
                    // El paso final entrega la unidad: la pantalla le pide las dos bodegas.
                    'es_paso_final'        => (bool) $p->es_paso_final,
                    // Las fotos se guardan como ruta relativa —«pasos/2/foto.jpg»— y el
                    // navegador necesita la URL pública. Sin `Storage::url()` el `src` quedaba
                    // relativo a la dirección de la pantalla y resolvía a
                    // «/trabajos/pasos/2/foto.jpg», que no existe: la foto se veía rota al
                    // recargar, aunque al subirla se viera bien —el endpoint de subida sí
                    // devuelve la URL completa—.
                    'fotos'                => collect($p->fotos ?? [])->map(fn ($f) => Storage::url($f))->all(),
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
            // Las bodegas del paso final, con las de la orden ya elegidas.
            'bodegas'           => \App\Support\ContextoSede::bodegasParaElegir()
                ->map(fn ($b) => ['id' => $b->id, 'nombre' => $b->nombre])->values(),
            'bodegas_sugeridas' => app(\App\Services\CierrePasoService::class)->bodegasSugeridas($trabajo),
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

        $data = $request->validate([
            'operarios'                  => 'nullable|array',
            'operarios.*.operario_id'    => 'required|exists:operarios,id',
            'operarios.*.tiempo_minutos' => 'nullable|integer|min:0',
            'operarios.*.observaciones'  => 'nullable|string|max:500',
            // Las dos bodegas del paso final. Llegan precargadas de la orden y el operario
            // solo las corrige si la unidad quedó en otro estante del que se planeó.
            'bodega_entrega_id'          => 'nullable|exists:bodegas,id',
            'bodega_material_id'         => 'nullable|exists:bodegas,id',
        ]);

        // Cerrar un paso pasa por un solo sitio, venga del código QR, del panel de la orden o
        // del tablero: ahí se deciden los puntos, la entrega a bodega y sus dos bodegas.
        $bodega = app(\App\Services\CierrePasoService::class)->cerrar(
            $paso,
            $data['operarios'] ?? [],
            $data['bodega_entrega_id'] ?? null,
            $data['bodega_material_id'] ?? null,
        );

        $item = $trabajo->opItem;

        $item->update([
            'estado_item' => $trabajo->fresh()->pasos()->where('completado', false)->exists()
                ? 'en_proceso'
                : 'terminado',
        ]);

        $aviso = $bodega ? " La unidad entró a {$bodega->nombre}." : '';

        return back()->with('success', 'Paso completado.'.$aviso);
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

        // Reabrir también pasa por el servicio: ahí se devuelven los puntos que el paso
        // había otorgado, que si no se sumarían de nuevo al recompletarlo.
        app(\App\Services\CierrePasoService::class)->reabrir($paso);

        return back()->with('success', 'Paso desmarcado.');
    }
}
