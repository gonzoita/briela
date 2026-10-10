<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WhatsappPlantilla;
use App\Services\Bandeja\BandejaService;
use App\Services\WhatsAppService;
use App\Support\Canales;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * La bandeja: donde se lee y se contesta lo que escriben los clientes.
 *
 * **Qué resolvió.** Los mensajes de WhatsApp se guardaban desde julio y no existía una sola
 * pantalla para verlos: la campanita avisaba «te escribieron» y llevaba a una ruta que no
 * existía. El cliente solo recibía la respuesta automática, y cualquier cosa que el agente de
 * IA no supiera contestar se quedaba ahí.
 *
 * **La lista entra por Inertia y el hilo por `fetch`.** Abrir una conversación no cambia de
 * pantalla: la lista se queda, y traer el hilo es una petición y nada más. Navegar con Inertia
 * en cada conversación volvería a construir el layout completo —que no es persistente— y a
 * pedir la lista otra vez para pintar lo mismo. Ver «Velocidad: nada cuesta una petición por
 * clic».
 */
class BandejaController extends Controller
{
    public function __construct(private readonly BandejaService $bandeja)
    {
    }

    public function index(Request $peticion)
    {
        $filtros = $peticion->only(['canal', 'buscar', 'estado', 'asignado']);
        $usuario = $peticion->user();

        return Inertia::render('Bandeja/Index', [
            'conversaciones' => $this->bandeja->lista($filtros, $usuario),
            'contadores'     => $this->bandeja->contadores($usuario),
            'canales'        => Canales::paraVista(),
            'filtros'        => $filtros,
            // Para el selector de «quién atiende». Solo los activos: asignarle una conversación
            // a alguien que ya no entra al sistema es dejarla con dueño y sin atender, que es el
            // mismo error que ya se corrigió en los números de WhatsApp.
            'usuarios'       => User::where('activo', true)->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn ($u) => ['id' => $u->id, 'nombre' => $u->name]),
            'puede' => [
                'responder' => $usuario->tienePermiso('bandeja.responder'),
                'asignar'   => $usuario->tienePermiso('bandeja.asignar'),
            ],
            // Cuál abrir de entrada, cuando se llega desde un aviso de la campanita.
            'abrir' => $peticion->query('conv'),
        ]);
    }

    /**
     * El hilo de una conversación.
     *
     * Marca leída al abrir, que es lo que de verdad significa abrirla: pedirle además que toque
     * un botón de «marcar como leído» es trabajo que nadie hace y un contador que nunca baja.
     */
    public function hilo(Request $peticion, string $clave): JsonResponse
    {
        $conversacion = $this->bandeja->resolver($clave);
        $adaptador    = $this->bandeja->canal($conversacion->canal());

        $this->bandeja->marcarLeida($conversacion);

        return response()->json([
            'clave'     => $conversacion->claveBandeja(),
            'canal'     => $conversacion->canal(),
            'canal_label' => Canales::label($conversacion->canal()),
            'contacto'  => $conversacion->comoSeLlama(),
            'mensajes'  => $adaptador->hilo($conversacion),
            'cabecera'  => $adaptador->cabecera($conversacion) + [
                'asignado_a' => $conversacion->asignado_a,
                'asignado'   => $conversacion->asignado?->name,
                'lead_id'    => $conversacion->crm_lead_id,
                'cliente_id' => $conversacion->cliente_id,
                'cliente'    => $conversacion->cliente?->nombre,
                'escalada'   => $conversacion->escalada_at !== null,
                'archivada'  => $conversacion->estaArchivada(),
                'agente'     => $conversacion->agente?->nombre,
            ],
            'ventana'    => $conversacion->estadoVentana(),
            // Las plantillas solo se ofrecen donde sirven: fuera de la ventana y en un canal
            // que las admita. Mostrar un selector vacío en Instagram sería prometer algo que
            // la API de Instagram no tiene.
            'plantillas' => $this->plantillasPara($conversacion),
        ]);
    }

    /**
     * Manda la respuesta.
     *
     * El texto y el adjunto llegan en la misma petición a propósito. El chat interno los manda
     * en dos —primero sube, después escribe—, y acá eso significaría que un mensaje con foto se
     * puede quedar a medias: el archivo subido y el mensaje sin salir.
     */
    public function responder(Request $peticion, string $clave): JsonResponse
    {
        $conversacion = $this->bandeja->resolver($clave);

        $datos = $peticion->validate([
            'texto'   => 'nullable|string|max:4000',
            // 16 MB es el techo de Meta para video, el más bajo de los suyos. Dejar subir más
            // sería aceptar un archivo que la API va a rechazar después de la espera.
            'archivo' => 'nullable|file|max:16384',
        ]);

        $texto   = trim((string) ($datos['texto'] ?? ''));
        $subido  = $peticion->file('archivo');

        if ($texto === '' && ! $subido) {
            return response()->json(['message' => 'Escribe un mensaje o adjunta un archivo.'], 422);
        }

        if ($subido && ! (Canales::de($conversacion->canal())['adjuntos'] ?? false)) {
            return response()->json([
                'message' => 'Por ' . Canales::label($conversacion->canal()) . ' no se pueden mandar archivos.',
            ], 422);
        }

        $archivo = $subido
            ? $this->bandeja->guardarAdjunto($conversacion, $subido, $peticion->user())
            : null;

        try {
            $mensaje = $this->bandeja->canal($conversacion->canal())
                ->responder($conversacion, $texto, $archivo, $peticion->user());
        } catch (\Throwable $e) {
            // El archivo ya quedó guardado y el mensaje no salió. Se devuelve el motivo tal
            // cual: los errores de Meta ya vienen traducidos a qué hacer (ver
            // `WhatsAppService::traducir()`), y esconderlos detrás de «no se pudo enviar» es
            // lo que hace que alguien reintente cinco veces.
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'mensaje' => $mensaje,
            'ventana' => $conversacion->fresh()->estadoVentana(),
        ]);
    }

    /**
     * Manda una plantilla aprobada: la única forma de escribir con la ventana cerrada.
     */
    public function enviarPlantilla(Request $peticion, string $clave): JsonResponse
    {
        $conversacion = $this->bandeja->resolver($clave);

        if ($conversacion->canal() !== 'whatsapp') {
            return response()->json([
                'message' => 'Solo WhatsApp tiene plantillas aprobadas. Los demás canales no permiten escribir primero.',
            ], 422);
        }

        $datos = $peticion->validate([
            'plantilla_id' => 'required|exists:whatsapp_plantillas,id',
            'variables'    => 'array',
            'variables.*'  => 'nullable|string|max:500',
        ]);

        $plantilla = WhatsappPlantilla::findOrFail($datos['plantilla_id']);
        $numero    = $conversacion->numero;

        if (! $numero || ! $numero->activo) {
            return response()->json(['message' => 'La línea de WhatsApp de esta conversación está desactivada.'], 422);
        }

        try {
            $mensaje = app(WhatsAppService::class)->enviarPlantilla(
                $numero,
                $conversacion->numero_contacto,
                $plantilla,
                $datos['variables'] ?? [],
                $peticion->user(),
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'mensaje' => $this->bandeja->canal('whatsapp')->hilo($conversacion->fresh(), 1)[0] ?? null,
            'ventana' => $conversacion->fresh()->estadoVentana(),
            'aviso'   => 'La plantilla salió. La conversación queda abierta cuando la persona responda.',
        ]);
    }

    public function asignar(Request $peticion, string $clave): JsonResponse
    {
        $conversacion = $this->bandeja->resolver($clave);

        $datos = $peticion->validate([
            'usuario_id' => 'nullable|exists:users,id',
        ]);

        $this->bandeja->asignar($conversacion, $datos['usuario_id'] ?? null);

        return response()->json([
            'asignado_a' => $conversacion->asignado_a,
            'asignado'   => $conversacion->fresh()->asignado?->name,
        ]);
    }

    public function archivar(Request $peticion, string $clave): JsonResponse
    {
        $conversacion = $this->bandeja->resolver($clave);
        $archivar     = $peticion->boolean('archivar', true);

        $this->bandeja->archivar($conversacion, $archivar);

        return response()->json(['archivada' => $archivar]);
    }

    /**
     * Las plantillas que se pueden usar en esta conversación.
     *
     * @return array<int, array<string, mixed>>
     */
    private function plantillasPara(Model $conversacion): array
    {
        if (! (Canales::de($conversacion->canal())['plantillas'] ?? false)) {
            return [];
        }

        return WhatsappPlantilla::aprobadas()
            ->orderBy('nombre')
            ->get()
            ->map(fn (WhatsappPlantilla $p) => [
                'id'        => $p->id,
                'nombre'    => $p->nombre,
                'idioma'    => $p->idioma,
                'categoria' => $p->categoria,
                'variables' => $p->variables,
                'texto'     => $p->previsualizar(),
            ])
            ->all();
    }
}
