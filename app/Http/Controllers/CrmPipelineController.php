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
                    // Se filtra por canal de origen: un lead que llegó por
                    // WhatsApp aparece al filtrar WhatsApp aunque su primer
                    // contacto haya sido otro.
                    $q->whereHas('origenes', fn ($o) => $o->where('canal', $fuente));
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

                $q->with(['responsable:id,name', 'cliente:id,nombre', 'tareas', 'origenes'])
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
                    // Todos los canales por los que se acercó, para que la tarjeta
                    // los muestre como etiquetas. Un lead que llegó por tres lados
                    // vale más que uno que llegó por uno, y eso debe verse.
                    'origenes'          => $l->origenes->map(fn ($o) => $o->comoEtiqueta())->values(),
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

        // Los canales que de verdad tienen leads, con su etiqueta para el filtro.
        $canalesConLeads = \App\Models\CrmLeadOrigen::query()
            ->when($sedeActiva, fn ($q) => $q->whereHas('lead', fn ($l) => $l->where('sede_id', $sedeActiva)))
            ->distinct()
            ->pluck('canal');

        $catalogo = \App\Models\CrmLeadOrigen::canales();

        $fuentes = $canalesConLeads
            ->map(fn ($c) => [
                'valor'    => $c,
                'etiqueta' => $catalogo[$c]['etiqueta'] ?? $c,
            ])
            ->sortBy('etiqueta')
            ->values();

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

        // También la carga a mano pasa por la puerta única: si un vendedor escribe
        // un teléfono que ya está en el embudo, se le suma el contacto en vez de
        // crear el duplicado. Se le dice, para que sepa por qué no apareció una
        // tarjeta nueva.
        $resultado = app(\App\Services\LeadEntranteService::class)->registrar([
            'canal'          => 'manual',
            'detalle'        => $data['fuente'] ?? null,
            'nombre'         => $data['nombre_contacto'] ?? null,
            'email'          => $data['email_contacto'] ?? null,
            'telefono'       => $data['telefono_contacto'] ?? null,
            'empresa'        => $data['empresa_contacto'] ?? null,
            'mensaje'        => $data['descripcion'] ?? null,
            'etapa_id'       => $data['etapa_id'],
            'responsable_id' => $data['responsable_id'] ?? null,
            'avisar'         => false,
        ]);

        $lead = $resultado['lead'];

        // El título y el cliente los eligió una persona: mandan sobre lo que
        // hubiera armado el servicio.
        $lead->update(array_filter([
            'titulo'     => $data['titulo'] ?? null,
            'cliente_id' => $data['cliente_id'] ?? null,
        ], fn ($v) => $v !== null));

        \App\Models\CrmActividad::registrar(
            $lead->id,
            $resultado['nuevo'] ? 'creacion' : 'contacto_repetido',
            $resultado['nuevo'] ? 'Lead creado' : 'Se registró otro contacto de alguien que ya estaba'
        );

        return response()->json([
            'lead'  => $lead->load('responsable:id,name', 'cliente:id,nombre', 'origenes'),
            'nuevo' => $resultado['nuevo'],
        ]);
    }

    public function showLead(CrmLead $lead)
    {
        $this->deLaSede($lead);

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
        $this->deLaSede($lead);

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
        $this->deLaSede($lead);

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
                $cot          = $lead->crearCotizacion();
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
        $this->deLaSede($lead);

        $lead->delete();
        return response()->json(['ok' => true]);
    }

    public function convertirCliente(Request $request, CrmLead $lead)
    {
        $this->deLaSede($lead);

        $data = $request->validate([
            'nombre'                => 'required|string|max:200',
            'email'                 => 'nullable|email',
            'telefono'              => 'nullable|string|max:20',
            'celular'               => 'nullable|string|max:20',
            'ciudad'                => 'nullable|string|max:100',
            'tipo'                  => 'required|in:persona,empresa',
            'tipo_identificacion'   => 'nullable|string|max:20',
            'numero_identificacion' => 'nullable|string|max:30',
        ]);

        // Si ya existe —mismo documento, correo o celular—, se vincula en vez de crear otro.
        // Antes cada conversión creaba un cliente nuevo, y el mismo cliente quedaba repetido
        // con sus cotizaciones y su cartera repartidas entre las copias.
        $cliente = $this->clienteExistente($data);
        $yaExistia = (bool) $cliente;

        if (! $cliente) {
            $documento = $data['numero_identificacion'] ?? null;

            $cliente = Cliente::create([
                ...collect($data)->except(['tipo_identificacion', 'numero_identificacion'])->all(),
                'tipo_identificacion'   => $documento ? ($data['tipo_identificacion'] ?? ($data['tipo'] === 'empresa' ? 'NIT' : 'CC')) : null,
                'numero_identificacion' => $documento,
                'activo'                => true,
            ]);

            // Una empresa sin contacto queda sin a quién escribirle: el contacto es la persona
            // del lead.
            if ($cliente->tipo === 'empresa' && $lead->nombre_contacto) {
                \App\Models\ContactoCliente::create([
                    'cliente_id'   => $cliente->id,
                    'nombre'       => $lead->nombre_contacto,
                    'email'        => $lead->email_contacto,
                    'celular'      => $lead->telefono_contacto,
                    'es_principal' => true,
                ]);
            }
        }

        \App\Models\CrmActividad::registrar(
            $lead->id,
            'conversion',
            ($yaExistia ? 'Vinculado al cliente que ya existía: ' : 'Convertido a cliente: ') . $cliente->nombre,
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
            $cotizacionId = $lead->fresh()->crearCotizacion()->id;
        }

        return response()->json([
            'cliente'       => $cliente,
            'ya_existia'    => $yaExistia,
            'lead'          => $lead->fresh()->load('etapa', 'cliente:id,nombre'),
            'etapa_id'      => $etapaDestino?->id,
            'cotizacion_id' => $cotizacionId,
        ]);
    }

    /** Un cliente que ya está: mismo documento, mismo correo o mismo celular (últimos 10 dígitos). */
    private function clienteExistente(array $data): ?Cliente
    {
        $documento = preg_replace('/\D/', '', (string) ($data['numero_identificacion'] ?? ''));
        $email     = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $celular   = substr(preg_replace('/\D/', '', (string) ($data['celular'] ?? $data['telefono'] ?? '')), -10);

        if ($documento !== '') {
            $porDocumento = Cliente::whereRaw("REGEXP_REPLACE(COALESCE(numero_identificacion,''), '[^0-9]', '') = ?", [$documento])->first();
            if ($porDocumento) {
                return $porDocumento;
            }
        }

        if ($email !== '') {
            $porEmail = Cliente::whereRaw('LOWER(TRIM(email)) = ?', [$email])->first();
            if ($porEmail) {
                return $porEmail;
            }
        }

        if (strlen($celular) === 10) {
            return Cliente::where(function ($q) use ($celular) {
                $q->whereRaw("RIGHT(REGEXP_REPLACE(COALESCE(celular,''), '[^0-9]', ''), 10) = ?", [$celular])
                  ->orWhereRaw("RIGHT(REGEXP_REPLACE(COALESCE(telefono,''), '[^0-9]', ''), 10) = ?", [$celular]);
            })->first();
        }

        return null;
    }

    /**
     * Un lead de otra sede no se abre ni se toca por enlace directo. El tablero ya filtraba por la
     * sede activa, pero abrir, editar, mover o borrar un lead por su id no lo miraba.
     */
    private function deLaSede(CrmLead $lead): void
    {
        $sede = \App\Support\ContextoSede::id();

        abort_if($sede && $lead->sede_id && (int) $lead->sede_id !== (int) $sede, 404);
    }
}
