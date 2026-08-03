<?php

namespace App\Http\Controllers;

use App\Models\CrmEtapa;
use App\Models\CrmLead;
use App\Models\Cliente;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CrmPipelineController extends Controller
{
    public function index(Request $request)
    {
        $mes         = $request->input('mes');
        $responsable = $request->input('responsable');
        $fuente      = $request->input('fuente');
        $estado      = $request->input('estado', 'activo');
        $buscar      = $request->input('buscar');

        // El pipeline es de la sede de venta activa.
        $sedeActiva = \App\Support\ContextoSede::id();

        $etapas = CrmEtapa::where('activa', true)
            ->orderBy('orden')
            ->with(['leads' => function ($q) use ($mes, $responsable, $fuente, $estado, $buscar, $sedeActiva) {
                if ($sedeActiva) {
                    $q->where('crm_leads.sede_id', $sedeActiva);
                }

                if ($estado === 'todos') {
                    // sin filtro de estado
                } else {
                    $q->where('estado', $estado);
                }

                if ($mes) {
                    $q->whereYear('crm_leads.created_at', substr($mes, 0, 4))
                      ->whereMonth('crm_leads.created_at', substr($mes, 5, 2));
                }

                if ($responsable) {
                    $q->where('responsable_id', $responsable);
                }

                if ($fuente) {
                    $q->where('fuente', $fuente);
                }

                if ($buscar) {
                    $q->where(function ($q2) use ($buscar) {
                        $q2->where('titulo', 'like', "%{$buscar}%")
                           ->orWhere('nombre_contacto', 'like', "%{$buscar}%")
                           ->orWhere('empresa_contacto', 'like', "%{$buscar}%")
                           ->orWhere('email_contacto', 'like', "%{$buscar}%")
                           ->orWhere('telefono_contacto', 'like', "%{$buscar}%");
                    });
                }

                $q->with(['responsable:id,name', 'cliente:id,nombre', 'tareas'])
                  ->orderBy('orden_en_etapa');
            }])
            ->get()
            ->map(fn ($e) => [
                'id'                => $e->id,
                'nombre'            => $e->nombre,
                'color'             => $e->color,
                'orden'             => $e->orden,
                'accion_automatica' => $e->accion_automatica,
                'es_ganado'         => $e->es_ganado,
                'es_perdido'        => $e->es_perdido,
                'leads'             => $e->leads->map(fn ($l) => [
                    'id'                => $l->id,
                    'titulo'            => $l->titulo,
                    'nombre_contacto'   => $l->nombre_contacto,
                    'empresa_contacto'  => $l->empresa_contacto,
                    'telefono_contacto' => $l->telefono_contacto,
                    'fuente'            => $l->fuente,
                    'estado'            => $l->estado,
                    'responsable'       => $l->responsable?->name,
                    'cliente'           => $l->cliente?->nombre,
                    'tareas_pendientes' => $l->tareas->where('completada', false)->count(),
                    'tareas_vencidas'   => $l->tareas
                        ->where('completada', false)
                        ->filter(fn ($t) => $t->fecha_vencimiento && $t->fecha_vencimiento->lt(now()->startOfDay()))
                        ->count(),
                    'created_at'        => $l->created_at->format('d/m/Y'),
                ]),
            ]);

        $deSede = fn ($q) => $sedeActiva ? $q->where('sede_id', $sedeActiva) : $q;

        $fuentes = CrmLead::whereNotNull('fuente')
            ->where('fuente', '!=', '')
            ->tap($deSede)
            ->distinct()
            ->orderBy('fuente')
            ->pluck('fuente');

        $totalActivos  = CrmLead::where('estado', 'activo')->tap($deSede)->count();
        $totalGanados  = CrmLead::where('estado', 'ganado')->tap($deSede)->count();
        $totalPerdidos = CrmLead::where('estado', 'perdido')->tap($deSede)->count();

        $usuarios = User::orderBy('name')->get(['id', 'name']);
        $clientes = Cliente::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);

        return Inertia::render('Crm/Pipeline', [
            'etapas'   => $etapas,
            'usuarios' => $usuarios,
            'clientes' => $clientes,
            'fuentes'  => $fuentes,
            'filtros'  => [
                'mes'         => $mes,
                'responsable' => $responsable,
                'fuente'      => $fuente,
                'estado'      => $estado,
                'buscar'      => $buscar,
            ],
            'metricas' => [
                'activos'  => $totalActivos,
                'ganados'  => $totalGanados,
                'perdidos' => $totalPerdidos,
            ],
        ]);
    }

    public function storeLead(Request $request)
    {
        $data = $request->validate([
            'etapa_id'          => 'required|exists:crm_etapas,id',
            'titulo'            => 'required|string|max:200',
            'nombre_contacto'   => 'nullable|string|max:200',
            'email_contacto'    => 'nullable|email|max:150',
            'telefono_contacto' => 'nullable|string|max:30',
            'empresa_contacto'  => 'nullable|string|max:200',
            'descripcion'       => 'nullable|string',
            'fuente'            => 'nullable|string|max:100',
            'responsable_id'    => 'nullable|exists:users,id',
            'cliente_id'        => 'nullable|exists:clientes,id',
        ]);

        $orden = CrmLead::where('etapa_id', $data['etapa_id'])->max('orden_en_etapa') + 1;
        $lead  = CrmLead::create([...$data, 'orden_en_etapa' => $orden]);

        \App\Models\CrmActividad::registrar($lead->id, 'creacion', 'Lead creado');

        return response()->json(['lead' => $lead->load('responsable:id,name', 'cliente:id,nombre')]);
    }

    public function showLead(CrmLead $lead)
    {
        $lead->load([
            'etapa',
            'responsable:id,name',
            'cliente',
            'tareas.responsable:id,name',
            'notas.user:id,name',
            'actividades.user:id,name',
        ]);
        return response()->json(['lead' => $lead]);
    }

    public function updateLead(Request $request, CrmLead $lead)
    {
        $data = $request->validate([
            'titulo'            => 'sometimes|string|max:200',
            'nombre_contacto'   => 'nullable|string|max:200',
            'email_contacto'    => 'nullable|email|max:150',
            'telefono_contacto' => 'nullable|string|max:30',
            'empresa_contacto'  => 'nullable|string|max:200',
            'descripcion'       => 'nullable|string',
            'fuente'            => 'nullable|string|max:100',
            'responsable_id'    => 'nullable|exists:users,id',
            'cliente_id'        => 'nullable|exists:clientes,id',
        ]);

        $lead->update($data);
        return response()->json(['lead' => $lead->fresh()]);
    }

    public function moverLead(Request $request, CrmLead $lead)
    {
        $data = $request->validate([
            'etapa_id'       => 'required|exists:crm_etapas,id',
            'orden_en_etapa' => 'required|integer|min:0',
            'motivo_cierre'  => 'nullable|string',
        ]);

        $etapaNueva = CrmEtapa::find($data['etapa_id']);

        $estado = 'activo';
        if ($etapaNueva->es_ganado)  $estado = 'ganado';
        if ($etapaNueva->es_perdido) $estado = 'perdido';

        $etapaAnteriorId = $lead->etapa_id;

        $lead->update([
            'etapa_id'       => $data['etapa_id'],
            'orden_en_etapa' => $data['orden_en_etapa'],
            'estado'         => $estado,
            'fecha_cierre'   => in_array($estado, ['ganado', 'perdido']) ? now() : null,
            'motivo_cierre'  => $data['motivo_cierre'] ?? null,
        ]);

        \App\Models\CrmActividad::registrar(
            $lead->id,
            'etapa',
            "Movido a etapa: {$etapaNueva->nombre}",
            ['etapa_anterior' => $etapaAnteriorId, 'etapa_nueva' => $etapaNueva->id]
        );

        if (in_array($estado, ['ganado', 'perdido'])) {
            \App\Models\CrmActividad::registrar(
                $lead->id,
                'cierre',
                "Lead marcado como: {$estado}",
                ['estado' => $estado]
            );
        }

        $accion         = null;
        $cotizacionId   = null;

        if ($etapaNueva->accion_automatica === 'cotizacion' && $estado === 'activo') {
            $accion = 'cotizacion';

            // Si el lead ya tiene cliente asociado y no tiene una cotización
            // vinculada todavía, se crea automáticamente un borrador.
            if ($lead->cliente_id && ! $lead->cotizaciones()->exists()) {
                $cot = \App\Models\Cotizacion::create([
                    'lead_id'                 => $lead->id,
                    'cliente_id'               => $lead->cliente_id,
                    'moneda'                   => 'COP',
                    'tasa_cambio'               => 1,
                    'responsable_id'            => $lead->responsable_id ?? auth()->id(),
                    'estado'                    => 'borrador',
                    'condiciones_comerciales'   => 'Precios en pesos colombianos. Validez 30 días. Anticipo 50% para inicio de producción. Tiempo de entrega: 15 días hábiles.',
                    'notas_internas'            => "Generada automáticamente desde el lead \"{$lead->titulo}\".",
                ]);

                \App\Models\CrmActividad::registrar(
                    $lead->id,
                    'cotizacion',
                    "Cotización {$cot->numero} generada automáticamente",
                    ['cotizacion_id' => $cot->id]
                );

                $accion       = 'cotizacion_creada';
                $cotizacionId = $cot->id;
            } elseif (! $lead->cliente_id) {
                $accion = 'falta_cliente';
            }
        }

        return response()->json([
            'lead'          => $lead->fresh(),
            'accion'        => $accion,
            'cotizacion_id' => $cotizacionId,
        ]);
    }

    public function destroyLead(CrmLead $lead)
    {
        $lead->delete();
        return response()->json(['ok' => true]);
    }

    public function convertirCliente(Request $request, CrmLead $lead)
    {
        $data = $request->validate([
            'nombre'   => 'required|string|max:200',
            'email'    => 'nullable|email',
            'telefono' => 'nullable|string|max:20',
            'celular'  => 'nullable|string|max:20',
            'ciudad'   => 'nullable|string|max:100',
            'tipo'     => 'required|in:persona,empresa',
        ]);

        $cliente = Cliente::create([...$data, 'activo' => true]);

        \App\Models\CrmActividad::registrar(
            $lead->id,
            'conversion',
            "Convertido a cliente: {$cliente->nombre}",
            ['cliente_id' => $cliente->id]
        );

        $etapaDestino = CrmEtapa::where('accion_automatica', 'cotizacion')
            ->where('activa', true)
            ->orderBy('orden')
            ->first()
            ?? CrmEtapa::where('nombre', 'like', '%Cliente Nuevo%')
            ->where('activa', true)
            ->first();

        $updateData = ['cliente_id' => $cliente->id];
        if ($etapaDestino && $lead->etapa_id !== $etapaDestino->id) {
            $nuevoOrden = CrmLead::where('etapa_id', $etapaDestino->id)->max('orden_en_etapa') + 1;
            $updateData['etapa_id']       = $etapaDestino->id;
            $updateData['orden_en_etapa'] = $nuevoOrden;
        }
        $lead->update($updateData);

        $cotizacionId = null;
        if ($etapaDestino && $etapaDestino->accion_automatica === 'cotizacion' && ! $lead->cotizaciones()->exists()) {
            $cot = \App\Models\Cotizacion::create([
                'lead_id'                 => $lead->id,
                'cliente_id'              => $cliente->id,
                'moneda'                  => 'COP',
                'tasa_cambio'             => 1,
                'responsable_id'          => $lead->responsable_id ?? auth()->id(),
                'estado'                  => 'borrador',
                'condiciones_comerciales' => 'Precios en pesos colombianos. Validez 30 días. Anticipo 50% para inicio de producción. Tiempo de entrega: 15 días hábiles.',
                'notas_internas'          => "Generada automáticamente desde el lead \"{$lead->titulo}\".",
            ]);

            \App\Models\CrmActividad::registrar(
                $lead->id,
                'cotizacion',
                "Cotización {$cot->numero} generada automáticamente",
                ['cotizacion_id' => $cot->id]
            );

            $cotizacionId = $cot->id;
        }

        return response()->json([
            'cliente'       => $cliente,
            'lead'          => $lead->fresh()->load('etapa', 'cliente:id,nombre'),
            'etapa_id'      => $etapaDestino?->id,
            'cotizacion_id' => $cotizacionId,
        ]);
    }
}
