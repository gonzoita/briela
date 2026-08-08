<?php

namespace App\Http\Controllers;

use App\Models\Comentario;
use App\Models\User;
use App\Services\NotificacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Mensajes directos entre usuarios del sistema.
 *
 * Comparte tabla y lógica con los hilos de documento (ver ComentarioController):
 * un mensaje directo también puede ser una **solicitud** o una **tarea** con
 * responsable y fecha, y también avisa por la campanita. Lo único distinto es
 * que cuelga de una persona y no de un documento.
 */
class ChatDirectoController extends Controller
{
    /** Documentos que se pueden adjuntar a un mensaje. */
    private const COMPARTIBLES = [
        'cotizacion'   => \App\Models\Cotizacion::class,
        'orden_compra' => \App\Models\OrdenCompra::class,
        'op'           => \App\Models\Op::class,
        'cliente'      => \App\Models\Cliente::class,
    ];

    public function __construct(private readonly NotificacionService $notificaciones) {}

    /** Buscar a quién escribirle. */
    public function usuarios(Request $request): JsonResponse
    {
        $buscar = trim((string) $request->get('buscar', ''));

        $usuarios = User::where('activo', true)
            ->where('id', '!=', auth()->id())
            ->when($buscar !== '', fn ($q) => $q->where(function ($s) use ($buscar) {
                $s->where('name', 'like', "%{$buscar}%")
                  ->orWhere('email', 'like', "%{$buscar}%");
            }))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'email', 'rol']);

        return response()->json(['usuarios' => $usuarios]);
    }

    /**
     * Mis conversaciones: con quién he hablado, el último mensaje y cuántos
     * tengo sin leer de cada uno.
     */
    public function conversaciones(): JsonResponse
    {
        $yo = auth()->id();

        // Se traen los mensajes y se agrupan en PHP: la consulta SQL para
        // "el último mensaje por cada interlocutor" se vuelve ilegible, y aquí
        // el volumen es de un equipo, no de una red social.
        $mensajes = Comentario::whereNotNull('destinatario_id')
            ->where(fn ($q) => $q->where('user_id', $yo)->orWhere('destinatario_id', $yo))
            ->with(['autor:id,name', 'destinatario:id,name'])
            ->latest()
            ->limit(300)
            ->get();

        $conversaciones = [];

        foreach ($mensajes as $m) {
            $otro = $m->user_id === $yo ? $m->destinatario : $m->autor;

            if (! $otro) {
                continue;
            }

            if (! isset($conversaciones[$otro->id])) {
                $conversaciones[$otro->id] = [
                    'usuario_id' => $otro->id,
                    'nombre'     => $otro->name,
                    'ultimo'     => mb_strimwidth($m->contenido, 0, 80, '...'),
                    'cuando'     => $m->created_at->toIso8601String(),
                    'mio'        => $m->user_id === $yo,
                    'sin_leer'   => 0,
                ];
            }

            if ($m->destinatario_id === $yo && $m->leido_at === null) {
                $conversaciones[$otro->id]['sin_leer']++;
            }
        }

        return response()->json(['conversaciones' => array_values($conversaciones)]);
    }

    /** La conversación con una persona. Al abrirla se marca como leída. */
    public function hilo(User $usuario): JsonResponse
    {
        $yo = auth()->id();

        $mensajes = Comentario::entre($yo, $usuario->id)
            ->with(['autor:id,name', 'asignado:id,name', 'resueltoPor:id,name', 'referencia'])
            ->orderBy('created_at')
            ->get();

        Comentario::entre($yo, $usuario->id)
            ->where('destinatario_id', $yo)
            ->whereNull('leido_at')
            ->update(['leido_at' => now()]);

        return response()->json([
            'usuario'  => $usuario->only(['id', 'name', 'rol']),
            'mensajes' => $mensajes->map(fn (Comentario $m) => $this->serializar($m)),
        ]);
    }

    public function enviar(Request $request, User $usuario): JsonResponse
    {
        abort_if($usuario->id === auth()->id(), 422, 'No puedes escribirte a ti mismo.');

        $datos = $request->validate([
            'contenido'    => 'required|string|max:5000',
            'tipo'         => 'required|in:comentario,solicitud,tarea',
            'fecha_limite' => 'nullable|date',
            'referencia'   => 'nullable|string',   // 'cotizacion' | 'orden_compra' | ...
            'referencia_id'=> 'nullable|integer',
        ]);

        $refClase = null;

        if (filled($datos['referencia'] ?? null) && filled($datos['referencia_id'] ?? null)) {
            abort_unless(isset(self::COMPARTIBLES[$datos['referencia']]), 422, 'Ese documento no se puede compartir.');
            $refClase = self::COMPARTIBLES[$datos['referencia']];
            abort_unless($refClase::whereKey($datos['referencia_id'])->exists(), 404, 'El documento compartido no existe.');
        }

        $mensaje = Comentario::create([
            'user_id'         => auth()->id(),
            'destinatario_id' => $usuario->id,
            'contenido'       => $datos['contenido'],
            'tipo'            => $datos['tipo'],
            // Una solicitud o tarea directa queda a cargo de quien la recibe.
            'estado'          => $datos['tipo'] === 'comentario' ? null : 'pendiente',
            'asignado_a'      => $datos['tipo'] === 'comentario' ? null : $usuario->id,
            'fecha_limite'    => $datos['fecha_limite'] ?? null,
            'referencia_type' => $refClase,
            'referencia_id'   => $refClase ? $datos['referencia_id'] : null,
        ]);

        $autor = auth()->user()->name;

        $this->notificaciones->crear(
            $usuario->id,
            $datos['tipo'] === 'comentario' ? 'chat_mensaje' : 'comentario_asignado',
            match ($datos['tipo']) {
                'tarea'     => "{$autor} te asignó una tarea",
                'solicitud' => "{$autor} te hizo una solicitud",
                default     => "{$autor} te escribió",
            },
            mb_strimwidth($datos['contenido'], 0, 120, '...'),
            '/chat/' . auth()->id()
        );

        return response()->json(['mensaje' => $this->serializar($mensaje->fresh(['autor:id,name', 'asignado:id,name', 'referencia']))], 201);
    }

    /** Documentos recientes que se pueden adjuntar, para el selector. */
    public function compartibles(Request $request): JsonResponse
    {
        $tipo = $request->get('tipo', 'cotizacion');

        abort_unless(isset(self::COMPARTIBLES[$tipo]), 422);

        $clase   = self::COMPARTIBLES[$tipo];
        $columna = match ($tipo) {
            'cliente' => 'nombre',
            default   => 'numero',
        };

        $items = $clase::query()
            ->latest('id')
            ->limit(20)
            ->get(['id', $columna])
            ->map(fn ($d) => ['id' => $d->id, 'etiqueta' => (string) $d->{$columna}]);

        return response()->json(['items' => $items]);
    }

    private function serializar(Comentario $m): array
    {
        return [
            'id'          => $m->id,
            'contenido'   => $m->contenido,
            'tipo'        => $m->tipo,
            'estado'      => $m->estado,
            'autor'       => $m->autor?->only(['id', 'name']),
            'mio'         => $m->user_id === auth()->id(),
            'fecha_limite'=> $m->fecha_limite?->format('Y-m-d'),
            'creado'      => $m->created_at->toIso8601String(),
            'leido'       => $m->leido_at !== null,
            'referencia'  => $m->referencia_id ? [
                'etiqueta' => class_basename($m->referencia_type) . ' ' .
                              ($m->referencia->numero ?? $m->referencia->nombre ?? "#{$m->referencia_id}"),
                'url'      => $this->urlReferencia($m),
            ] : null,
        ];
    }

    private function urlReferencia(Comentario $m): string
    {
        return match ($m->referencia_type) {
            \App\Models\Cotizacion::class  => "/cotizaciones/{$m->referencia_id}",
            \App\Models\OrdenCompra::class => "/compras/ordenes/{$m->referencia_id}",
            \App\Models\Op::class          => "/produccion/ops/{$m->referencia_id}",
            \App\Models\Cliente::class     => "/clientes/{$m->referencia_id}",
            default                        => '/dashboard',
        };
    }
}
