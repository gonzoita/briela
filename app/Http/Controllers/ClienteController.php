<?php

namespace App\Http\Controllers;

use App\Models\Archivo;
use App\Models\Cliente;
use App\Models\ContactoCliente;
use App\Models\SegmentacionOpcion;
use App\Services\ConsultaNitService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ClienteController extends Controller
{
    public function index(Request $request): Response
    {
        $query = \App\Support\ContextoSede::aplicar(Cliente::query())
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->tipo))
            ->when($request->filled('buscar'), fn ($q) => $q->where(function ($q) use ($request) {
                $q->where('nombre', 'like', "%{$request->buscar}%")
                  ->orWhere('apellido', 'like', "%{$request->buscar}%")
                  ->orWhere('numero_identificacion', 'like', "%{$request->buscar}%")
                  ->orWhere('email', 'like', "%{$request->buscar}%");
            }))
            ->when($request->filled('industria'), fn ($q) => $q->whereJsonContains('industrias', $request->industria))
            ->when($request->filled('fuente_contacto'), fn ($q) => $q->whereJsonContains('fuentes_contacto', $request->fuente_contacto))
            ->when($request->filled('proceso_seguimiento'), fn ($q) => $q->whereJsonContains('proceso_seguimiento', $request->proceso_seguimiento))
;

        // El orden lo pide la pantalla. `Orden::aplicar` valida el campo contra esta
        // lista: lo que llegue por `?orden=` y no esté aquí se ignora, así que el
        // parámetro nunca toca el SQL.
        $orden = \App\Support\Orden::aplicar($query, $request, [
            'nombre'     => ['nombre', 'apellido'],
            'ciudad'     => 'ciudad',
            'created_at' => 'created_at',
        ]);

        $clientes = $query->paginate(15)->withQueryString();

        return Inertia::render('Clientes/Index', [
            'clientes'           => $clientes,
            'orden'      => $orden,
            'filters'            => $request->only(['buscar', 'tipo', 'industria', 'fuente_contacto', 'proceso_seguimiento']),
            'segmentacion_opciones' => $this->getSegmentacionOpciones(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Clientes/Create', [
            'segmentacion_opciones' => $this->getSegmentacionOpciones(),
            'sedes'                 => $this->sedesDisponibles(),
        ]);
    }

    /**
     * Sedes a las que el usuario puede mandar un cliente. Si solo tiene una,
     * el selector ni se muestra.
     */
    private function sedesDisponibles()
    {
        return auth()->user()?->sedesAccesibles()->map(fn ($s) => [
            'id'     => $s->id,
            'nombre' => $s->nombre,
        ])->values() ?? collect();
    }

    /**
     * Mueve un cliente a otra sede. Pensado para repartir la cartera de
     * clientes que quedó toda en la sede principal al activar el multi-sede.
     */
    public function cambiarSede(Request $request, Cliente $cliente): RedirectResponse
    {
        $request->validate(['sede_id' => 'required|exists:sedes,id']);

        if (! auth()->user()->puedeAccederASede((int) $request->sede_id)) {
            return back()->with('error', 'No tienes acceso a esa sede.');
        }

        $cliente->update(['sede_id' => $request->sede_id]);

        return back()->with('success', 'Cliente movido de sede.');
    }

    /**
     * Revisa una identificación mientras el usuario la escribe: valida el
     * dígito de verificación, avisa si el cliente ya existe y, si es NIT,
     * intenta traer la razón social del RUES.
     *
     * Nunca falla: si el RUES no responde, devuelve lo que sí pudo averiguar.
     */
    public function consultarIdentificacion(Request $request, ConsultaNitService $consulta)
    {
        $data = $request->validate([
            'numero'              => 'required|string|max:30',
            'tipo_identificacion' => 'required|in:CC,NIT,CE,PA,RUT',
            'ignorar_id'          => 'nullable|integer',
        ]);

        return response()->json($consulta->consultar(
            $data['numero'],
            $data['tipo_identificacion'],
            $data['ignorar_id'] ?? null,
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate($this->reglas());

        $this->validarContactos($request);

        $cliente = Cliente::create($data);

        $this->syncContactos($cliente, $request->input('contactos', []), $request);

        return redirect("/clientes/{$cliente->id}")
            ->with('success', 'Cliente creado correctamente.');
    }

    /**
     * Crea un cliente y lo devuelve en JSON, para el modal de la cotización.
     *
     * Existe porque salirse de una cotización a medio armar para crear el cliente es
     * perder el trabajo: los ítems, los precios calculados y las medidas del ensamble se
     * quedan en la pantalla que se abandonó.
     *
     * Valida con las mismas reglas que la pantalla completa —no una versión recortada, que
     * es como se cuelan clientes a medio llenar— y devuelve lo que la cotización necesita
     * para seleccionarlo de una vez, incluida su segmentación: sin tipo de contacto, la
     * cotización no muestra precios y el usuario se quedaría mirando una pantalla vacía sin
     * entender por qué.
     */
    public function storeApi(Request $request): JsonResponse
    {
        $data = $request->validate($this->reglas());

        $this->validarContactos($request);

        $cliente = Cliente::create($data);

        $this->syncContactos($cliente, $request->input('contactos', []), $request);

        return response()->json([
            'ok'      => true,
            'cliente' => array_merge($cliente->fresh()->toArray(), [
                'contactos' => $cliente->contactos()->orderByDesc('es_principal')->orderBy('nombre')->get(),
            ]),
        ], 201);
    }

    public function show(Cliente $cliente): Response
    {
        $usuario = auth()->user();

        return Inertia::render('Clientes/Show', [
            'cliente'   => $cliente,
            'contactos' => $cliente->contactos()->orderByDesc('es_principal')->orderBy('nombre')->get(),
            'archivos'  => $cliente->archivos()->get()->map(fn ($a) => array_merge($a->toArray(), ['url' => $a->url, 'tamano_formateado' => $a->tamano_formateado])),
            // Todo lo que este cliente tiene en el sistema, en un solo lugar.
            // Cada bloque solo se carga si el usuario puede ver ese módulo:
            // un vendedor sin acceso a logística no ve las remisiones.
            'historial' => $this->historial($cliente, $usuario),
        ]);
    }

    /**
     * Cotizaciones, OPs, remisiones y leads del cliente.
     *
     * Se limita a los 10 más recientes de cada tipo: la ficha es para tener
     * el panorama, no para reemplazar el listado de cada módulo. Cada bloque
     * lleva su enlace "ver todos" con el filtro ya aplicado.
     */
    private function historial(Cliente $cliente, $usuario): array
    {
        $historial = [];

        if ($usuario?->tienePermiso('cotizaciones.ver')) {
            $historial['cotizaciones'] = $cliente->cotizaciones()->limit(10)->get()
                ->map(fn ($c) => [
                    'id'     => $c->id,
                    'numero' => $c->numero,
                    'estado' => $c->estado,
                    'fecha'  => optional($c->fecha_creacion)->format('d/m/Y'),
                    'total'  => $c->total,
                    'url'    => "/cotizaciones/{$c->id}",
                ])->all();
        }

        if ($usuario?->tienePermiso('ops.ver')) {
            $historial['ops'] = $cliente->ops()->limit(10)->get()
                ->map(fn ($o) => [
                    'id'     => $o->id,
                    'numero' => $o->numero,
                    'estado' => $o->estado,
                    'fecha'  => optional($o->fecha_creacion)->format('d/m/Y'),
                    'total'  => $o->total,
                    'avance' => $o->porcentaje_avance,
                    'url'    => "/produccion/ops/{$o->id}",
                ])->all();
        }

        if ($usuario?->tienePermiso('remisiones.ver')) {
            $historial['remisiones'] = $cliente->remisiones()->limit(10)->get()
                ->map(fn ($r) => [
                    'id'     => $r->id,
                    'numero' => $r->numero,
                    'estado' => $r->estado,
                    'fecha'  => optional($r->fecha_remision)->format('d/m/Y'),
                    'url'    => "/logistica/remisiones/{$r->id}",
                ])->all();
        }

        if ($usuario?->tienePermiso('crm.ver')) {
            $historial['leads'] = $cliente->leads()->limit(10)->get()
                ->map(fn ($l) => [
                    'id'     => $l->id,
                    'titulo' => $l->titulo,
                    'estado' => $l->estado,
                    'url'    => "/crm/leads/{$l->id}",
                ])->all();
        }

        return $historial;
    }

    public function edit(Cliente $cliente): Response
    {
        return Inertia::render('Clientes/Edit', [
            'cliente'               => $cliente,
            'contactos'             => $cliente->contactos()->orderByDesc('es_principal')->orderBy('nombre')->get(),
            'archivos'              => $cliente->archivos()->get()->map(fn ($a) => array_merge($a->toArray(), ['url' => $a->url, 'tamano_formateado' => $a->tamano_formateado])),
            'segmentacion_opciones' => $this->getSegmentacionOpciones(),
            'sedes'                 => $this->sedesDisponibles(),
        ]);
    }

    public function storeArchivo(Request $request, Cliente $cliente): RedirectResponse
    {
        $request->validate([
            'archivo'   => 'required|file|max:10240',
            'categoria' => 'nullable|string|max:60',
        ]);

        $archivo = $request->file('archivo');
        $ruta    = $archivo->store("clientes/{$cliente->id}", 'public');

        Archivo::create([
            'nombre_original'  => $archivo->getClientOriginalName(),
            'nombre_archivo'   => basename($ruta),
            'ruta'             => $ruta,
            'tipo_mime'        => $archivo->getMimeType(),
            'extension'        => $archivo->getClientOriginalExtension(),
            'tamano'           => $archivo->getSize(),
            'categoria'        => $request->input('categoria', 'documento'),
            'archivable_type'  => Cliente::class,
            'archivable_id'    => $cliente->id,
            'subido_por'       => auth()->id(),
        ]);

        return back()->with('success', 'Documento subido correctamente.');
    }

    public function destroyArchivo(Archivo $archivo): RedirectResponse
    {
        Storage::disk('public')->delete($archivo->ruta);
        $archivo->delete();

        return back()->with('success', 'Documento eliminado.');
    }

    public function update(Request $request, Cliente $cliente): RedirectResponse
    {
        $data = $request->validate($this->reglas($cliente->id));

        $this->validarContactos($request);

        $cliente->update($data);

        $this->syncContactos($cliente, $request->input('contactos', []), $request);

        return redirect("/clientes/{$cliente->id}")
            ->with('success', 'Cliente actualizado.');
    }

    public function destroy(Cliente $cliente): RedirectResponse
    {
        $cliente->delete();

        return redirect('/clientes')
            ->with('success', 'Cliente eliminado.');
    }

    private function validarContactos(Request $request): void
    {
        if ($request->input('tipo') !== 'empresa') {
            return;
        }

        $contactos = $request->input('contactos', []);
        $validos   = array_filter($contactos, fn ($c) => ! empty($c['nombre']));

        if (count($validos) === 0) {
            throw ValidationException::withMessages([
                'contactos' => 'Debe agregar al menos un contacto para clientes de tipo empresa.',
            ]);
        }
    }

    private function syncContactos(Cliente $cliente, array $contactos, Request $request): void
    {
        if ($cliente->tipo === 'persona') {
            // Auto-sincronizar contacto principal con datos del cliente
            $contacto = $cliente->contactos()->first();
            $datosContacto = [
                'cliente_id'  => $cliente->id,
                'nombre'      => $cliente->nombre,
                'apellido'    => $cliente->apellido,
                'email'       => $cliente->email,
                'telefono'    => $cliente->telefono,
                'celular'     => $cliente->celular,
                'es_principal'=> true,
            ];
            if ($contacto) {
                $contacto->update($datosContacto);
            } else {
                ContactoCliente::create($datosContacto);
            }
            return;
        }

        // Para empresas: sincronizar lista de contactos
        $idsEnviados = [];
        $tienePrincipal = false;

        foreach ($contactos as $i => $datos) {
            if (empty($datos['nombre'])) {
                continue;
            }

            $esPrincipal = (bool) ($datos['es_principal'] ?? false);
            if ($esPrincipal) {
                $tienePrincipal = true;
            }

            $fill = [
                'cliente_id'  => $cliente->id,
                'nombre'      => $datos['nombre'],
                'apellido'    => $datos['apellido'] ?? null,
                'cargo'       => $datos['cargo'] ?? null,
                'email'       => $datos['email'] ?? null,
                'telefono'    => $datos['telefono'] ?? null,
                'celular'     => $datos['celular'] ?? null,
                'es_principal'=> $esPrincipal,
                'notas'       => $datos['notas'] ?? null,
            ];

            if (! empty($datos['id'])) {
                $contacto = ContactoCliente::find($datos['id']);
                if ($contacto && $contacto->cliente_id === $cliente->id) {
                    $contacto->update($fill);
                    $idsEnviados[] = $contacto->id;
                    continue;
                }
            }

            $nuevo = ContactoCliente::create($fill);
            $idsEnviados[] = $nuevo->id;
        }

        // Eliminar contactos que ya no están en el payload
        $cliente->contactos()->whereNotIn('id', $idsEnviados)->delete();

        // Garantizar que exactamente uno sea principal
        if (! $tienePrincipal && $cliente->contactos()->count() > 0) {
            $cliente->contactos()->first()->update(['es_principal' => true]);
        }
    }

    private function getSegmentacionOpciones(): array
    {
        return SegmentacionOpcion::orderBy('tipo')->orderBy('orden')
            ->get()
            ->groupBy('tipo')
            ->toArray();
    }

    private function reglas(?int $ignoreId = null): array
    {
        return [
            'sede_id'               => 'nullable|exists:sedes,id',
            'tipo'                  => 'required|in:persona,empresa',
            'tipo_identificacion'   => 'required|in:CC,NIT,CE,PA,RUT',
            'numero_identificacion' => 'nullable|string|max:30',
            'digito_verificacion'   => 'nullable|string|max:1',
            'datos_rues'            => 'nullable|array',
            'nombre'                => 'required|string|max:200',
            'apellido'              => 'nullable|string|max:100',
            'email'                 => 'nullable|email|max:150',
            'telefono'              => 'nullable|string|max:20',
            'celular'               => 'nullable|string|max:20',
            'ciudad'                => 'nullable|string|max:100',
            'direccion'             => 'nullable|string|max:200',
            'notas'               => 'nullable|string',
            'tipos_contacto'      => 'nullable|array',
            'industrias'          => 'nullable|array',
            'intereses'           => 'nullable|string',
            'proceso_seguimiento' => 'nullable|array',
            'fuentes_contacto'    => 'nullable|array',
            'contactos'             => 'nullable|array',
            'contactos.*.nombre'    => 'nullable|string|max:100',
            'contactos.*.apellido'  => 'nullable|string|max:100',
            'contactos.*.cargo'     => 'nullable|string|max:100',
            'contactos.*.email'     => 'nullable|email|max:150',
            'contactos.*.telefono'  => 'nullable|string|max:20',
            'contactos.*.celular'   => 'nullable|string|max:20',
            'contactos.*.notas'     => 'nullable|string',
            'contactos.*.es_principal' => 'nullable|boolean',
        ];
    }
}
