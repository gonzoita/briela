<?php

namespace App\Http\Controllers;

use App\Models\WhatsappNumero;
use App\Services\WhatsappDiagnosticoService;
use App\Support\CredencialesRrss;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WhatsappNumeroController extends Controller
{
    public function __construct(private readonly WhatsappDiagnosticoService $diagnostico) {}

    public function index()
    {
        return Inertia::render('Configuracion/WhatsappNumeros', [
            'numeros' => WhatsappNumero::with('usuario:id,name,activo')
                ->orderByDesc('rol')
                ->orderBy('nombre')
                ->get(),
            'usuarios' => $this->usuariosAsignables(),
            // La pantalla pinta un semáforo con lo que falta, en el orden en
            // que hay que resolverlo. Ver WhatsappDiagnosticoService::estado().
            'conexion' => array_merge($this->diagnostico->estado(), [
                'tiene_secreto'  => CredencialesRrss::valor('whatsapp', 'secret') !== '',
                'verify_actual'  => CredencialesRrss::valor('whatsapp', 'redirect'),
                'token_sugerido' => WhatsappDiagnosticoService::tokenSugerido(),
            ]),
            'automatizacion' => \App\Services\WhatsappAutomatizacionService::config(),
            'agente'         => \App\Services\IA\AgentePublicoService::config(),
            'etapas'         => \App\Models\CrmEtapa::where('activa', true)
                ->orderBy('orden')->get(['id', 'nombre']),
        ]);
    }

    /**
     * A quién se le puede asignar un número: los usuarios activos, más los que
     * ya tienen un número asignado aunque estén inactivos.
     *
     * Sin ese segundo grupo, abrir un número de alguien que se fue mostraría el
     * selector vacío, y al guardar cualquier otro cambio se perdería la
     * asignación sin que nadie lo pidiera.
     */
    private function usuariosAsignables()
    {
        $asignados = WhatsappNumero::whereNotNull('usuario_id')->pluck('usuario_id');

        return \App\Models\User::where('activo', true)
            ->orWhereIn('id', $asignados)
            ->orderBy('name')
            ->get(['id', 'name', 'activo']);
    }

    /**
     * Guarda la automatización: aviso, respuestas y creación de leads.
     * Todo vive en `configuraciones`, así que no hace falta migración.
     */
    public function guardarAutomatizacion(Request $request)
    {
        $datos = $request->validate([
            'activo'                    => 'boolean',
            'avisar'                    => 'boolean',
            'responder'                 => 'boolean',
            'respuestas'                => 'nullable|array|max:20',
            'respuestas.*.palabra_clave'=> 'nullable|string|max:60',
            'respuestas.*.mensaje'      => 'nullable|string|max:1000',
            'crear_lead'                => 'boolean',
            'lead_etapa_id'             => 'nullable|integer|exists:crm_etapas,id',
            'asignacion'                => 'required|in:fijo,round_robin',
            'responsables'              => 'nullable|array',
            'responsables.*'            => 'integer|exists:users,id',
        ]);

        // Se descartan las respuestas vacías para que no queden filas fantasma
        // que el motor tendría que saltarse en cada mensaje.
        $respuestas = collect($datos['respuestas'] ?? [])
            ->filter(fn ($r) => filled($r['mensaje'] ?? null))
            ->map(fn ($r) => [
                'palabra_clave' => trim((string) ($r['palabra_clave'] ?? '')),
                'mensaje'       => trim((string) $r['mensaje']),
            ])
            ->values()
            ->all();

        \App\Models\Configuracion::set('whatsapp_auto_activo',       !empty($datos['activo']) ? '1' : '0');
        \App\Models\Configuracion::set('whatsapp_auto_avisar',       !empty($datos['avisar']) ? '1' : '0');
        \App\Models\Configuracion::set('whatsapp_auto_responder',    !empty($datos['responder']) ? '1' : '0');
        \App\Models\Configuracion::set('whatsapp_auto_crear_lead',   !empty($datos['crear_lead']) ? '1' : '0');
        \App\Models\Configuracion::set('whatsapp_auto_lead_etapa_id', (string) ($datos['lead_etapa_id'] ?? ''));
        \App\Models\Configuracion::set('whatsapp_auto_asignacion',   $datos['asignacion']);
        \App\Models\Configuracion::set('whatsapp_auto_respuestas',   json_encode($respuestas));
        \App\Models\Configuracion::set('whatsapp_auto_responsables', json_encode(array_values($datos['responsables'] ?? [])));

        return back()->with('success', 'Automatización guardada.');
    }

    /**
     * Guarda el agente de IA que atiende a los desconocidos: si está encendido,
     * su nombre y las indicaciones propias del negocio.
     */
    public function guardarAgente(Request $request)
    {
        $datos = $request->validate([
            'activo'       => 'boolean',
            'nombre'       => 'nullable|string|max:60',
            'indicaciones' => 'nullable|string|max:4000',
        ]);

        \App\Models\Configuracion::set('agente_publico_activo', !empty($datos['activo']) ? '1' : '0');
        \App\Models\Configuracion::set('agente_publico_nombre', trim((string) ($datos['nombre'] ?? '')));
        \App\Models\Configuracion::set('agente_publico_prompt', trim((string) ($datos['indicaciones'] ?? '')));

        return back()->with('success', 'Agente guardado.');
    }

    /**
     * Guarda las credenciales de WhatsApp desde la interfaz, sin tocar el .env.
     *
     * Solo se piden las dos que son de la **aplicación** de Meta: el token de
     * acceso y el token de verificación del webhook. El identificador del
     * número vive en cada número, que es donde de verdad se usa para enviar.
     * Se pedía también acá, y pegar el mismo dato en dos sitios con nombres
     * distintos hacía creer que la conexión estaba lista cuando el número que
     * enviaba era otro.
     *
     * El campo viejo se sigue leyendo (instalaciones que ya lo tenían), pero no
     * se toca desde acá: borrarlo al guardar rompería esas instalaciones.
     */
    public function guardarCredenciales(Request $request)
    {
        $datos = $request->validate([
            'secret'   => 'nullable|string|max:500',
            'redirect' => 'nullable|string|max:255',
        ]);

        CredencialesRrss::guardar('whatsapp', 'redirect', $datos['redirect'] ?? '');

        // El token solo se reemplaza si llega uno nuevo: la pantalla no lo
        // vuelve a mostrar, y dejar el campo vacío significa "consérvalo".
        if (filled($datos['secret'] ?? null)) {
            CredencialesRrss::guardar('whatsapp', 'secret', $datos['secret']);
        }

        return back()->with('success', 'Conexión de WhatsApp guardada.');
    }

    // ─── Los probadores ───────────────────────────────────────────────────────
    // Responden JSON y se pintan en la misma pantalla: una prueba que obliga a
    // recargar para leer el resultado se deja de usar.

    /** Le pregunta a Meta por ESE número. No le manda nada a nadie. */
    public function probarNumero(WhatsappNumero $whatsappNumero): JsonResponse
    {
        return response()->json($this->diagnostico->probarNumero($whatsappNumero));
    }

    /** Repite lo que hace Meta al suscribirse al webhook. */
    public function probarWebhook(): JsonResponse
    {
        return response()->json($this->diagnostico->probarWebhook());
    }

    /** Manda un mensaje de verdad al número que escriba quien está probando. */
    public function enviarPrueba(Request $request, WhatsappNumero $whatsappNumero): JsonResponse
    {
        $datos = $request->validate([
            'destino' => 'required|string|max:30',
            'texto'   => 'nullable|string|max:900',
        ]);

        $texto = trim((string) ($datos['texto'] ?? ''))
            ?: 'Mensaje de prueba enviado desde ' . \App\Support\Marca::nombreEmpresa() . '.';

        return response()->json(
            $this->diagnostico->enviarPrueba($whatsappNumero, $datos['destino'], $texto)
        );
    }

    /**
     * Qué contestaría el agente de IA. No manda nada, no crea leads y funciona
     * con el agente apagado: es para calibrarlo ANTES de encenderlo.
     */
    public function probarAgente(Request $request, \App\Services\IA\AgenteConversacionService $agentes): JsonResponse
    {
        $datos = $request->validate([
            'mensaje'      => 'required|string|max:900',
            'agente_id'    => 'nullable|exists:agentes_ia,id',
            'indicaciones' => 'nullable|string|max:4000',
        ]);

        // El agente que se está probando: el elegido, o el que hoy atendería por WhatsApp.
        $agente = $datos['agente_id']
            ? \App\Models\AgenteIa::find($datos['agente_id'])
            : \App\Models\AgenteIa::paraCanal('whatsapp', 'publico');

        if (! $agente) {
            return response()->json([
                'respuesta' => null,
                'motivo'    => 'No hay ningún agente configurado para WhatsApp. Créalo en Ajustes → Agentes.',
            ]);
        }

        // Funciona con el agente apagado a propósito: calibrar las indicaciones es justo lo que
        // uno hace ANTES de soltarlo a atender clientes. Y no manda nada a nadie ni crea leads.
        return response()->json($agentes->previsualizar($agente, $datos['mensaje'], $datos['indicaciones'] ?? null));
    }

    /**
     * Desconecta borrando las credenciales guardadas. Los números y sus
     * conversaciones NO se tocan: al volver a conectar, todo sigue ahí.
     */
    public function desconectar()
    {
        foreach (['id', 'secret', 'redirect'] as $campo) {
            CredencialesRrss::guardar('whatsapp', $campo, '');
        }

        return back()->with('success',
            'WhatsApp desconectado. Los números y el historial de conversaciones se conservan; '
            . 'al volver a cargar el token, la conexión queda activa otra vez.');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:100',
            'numero_telefono' => 'required|string|max:30',
            'phone_number_id' => 'required|string|max:50|unique:whatsapp_numeros,phone_number_id',
            'rol' => 'required|in:central,asesor',
            'usuario_id' => 'nullable|exists:users,id',
            'activo' => 'boolean',
        ]);

        WhatsappNumero::create($data);

        return redirect()->back()->with('success', 'Número de WhatsApp creado.');
    }

    public function update(Request $request, WhatsappNumero $whatsappNumero)
    {
        $data = $request->validate([
            'nombre' => 'required|string|max:100',
            'numero_telefono' => 'required|string|max:30',
            'phone_number_id' => 'required|string|max:50|unique:whatsapp_numeros,phone_number_id,' . $whatsappNumero->id,
            'rol' => 'required|in:central,asesor',
            'usuario_id' => 'nullable|exists:users,id',
            'activo' => 'boolean',
        ]);

        $whatsappNumero->update($data);

        return redirect()->back()->with('success', 'Número de WhatsApp actualizado.');
    }

    public function destroy(WhatsappNumero $whatsappNumero)
    {
        if ($whatsappNumero->conversaciones()->exists()) {
            $whatsappNumero->update(['activo' => false]);
            return redirect()->back()->with('success', 'Número desactivado (tiene conversaciones asociadas).');
        }

        $whatsappNumero->delete();
        return redirect()->back()->with('success', 'Número eliminado.');
    }
}
