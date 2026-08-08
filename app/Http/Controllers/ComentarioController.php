<?php

namespace App\Http\Controllers;

use App\Models\Comentario;
use App\Models\User;
use App\Services\NotificacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hilos internos pegados a un documento (una OP, una cotización, un cliente).
 *
 * Solo se aceptan los tipos de documento de la lista blanca: el tipo llega
 * desde el navegador, y sin esa lista alguien podría colgar comentarios de
 * cualquier modelo del sistema.
 */
class ComentarioController extends Controller
{
    private const DOCUMENTOS = [
        'op'         => \App\Models\Op::class,
        'cotizacion' => \App\Models\Cotizacion::class,
        'cliente'    => \App\Models\Cliente::class,
        'orden_compra' => \App\Models\OrdenCompra::class,
    ];

    public function __construct(private readonly NotificacionService $notificaciones) {}

    private function claseDe(string $documento): string
    {
        abort_unless(isset(self::DOCUMENTOS[$documento]), 404, 'Tipo de documento no soportado.');

        return self::DOCUMENTOS[$documento];
    }

    /**
     * Lo que tengo pendiente: solicitudes y tareas abiertas que me asignaron.
     * Es lo que hace útil el botón de chat fuera de un documento — si no, en
     * el dashboard no tendría nada que mostrar.
     */
    public function pendientes(): JsonResponse
    {
        $comentarios = Comentario::with('autor:id,name')
            ->abiertos()
            ->where('asignado_a', auth()->id())
            ->latest()
            ->limit(30)
            ->get();

        return response()->json([
            'pendientes' => $comentarios->map(fn (Comentario $c) => [
                'id'          => $c->id,
                'tipo'        => $c->tipo,
                'contenido'   => $c->contenido,
                'autor'       => $c->autor?->name,
                'fecha_limite'=> $c->fecha_limite?->format('Y-m-d'),
                'creado'      => $c->created_at->toIso8601String(),
                'url'         => $this->urlDocumento($c),
                'documento'   => class_basename($c->comentable_type),
            ]),
        ]);
    }

    public function index(string $documento, int $id): JsonResponse
    {
        $clase = $this->claseDe($documento);

        $comentarios = Comentario::with(['autor:id,name', 'asignado:id,name', 'resueltoPor:id,name'])
            ->where('comentable_type', $clase)
            ->where('comentable_id', $id)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'comentarios' => $comentarios,
            'usuarios'    => User::where('activo', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, string $documento, int $id): JsonResponse
    {
        $clase = $this->claseDe($documento);

        // Que el documento exista de verdad: si no, quedarían hilos huérfanos
        // apuntando a nada.
        abort_unless($clase::whereKey($id)->exists(), 404, 'El documento no existe.');

        $datos = $request->validate([
            'contenido'    => 'required|string|max:5000',
            'tipo'         => 'required|in:comentario,solicitud,tarea',
            'asignado_a'   => 'nullable|exists:users,id',
            'fecha_limite' => 'nullable|date',
        ]);

        $mencionados = $this->detectarMenciones($datos['contenido']);

        $comentario = Comentario::create([
            'comentable_type' => $clase,
            'comentable_id'   => $id,
            'user_id'         => auth()->id(),
            'contenido'       => $datos['contenido'],
            'tipo'            => $datos['tipo'],
            // Solo lo accionable nace pendiente; un comentario suelto no tiene estado.
            'estado'          => $datos['tipo'] === 'comentario' ? null : 'pendiente',
            'asignado_a'      => $datos['asignado_a'] ?? null,
            'fecha_limite'    => $datos['fecha_limite'] ?? null,
            'mencionados'     => $mencionados,
        ]);

        $this->avisar($comentario, $documento, $id);

        return response()->json([
            'comentario' => $comentario->load(['autor:id,name', 'asignado:id,name']),
        ], 201);
    }

    /** Marca una solicitud o tarea como resuelta o rechazada. */
    public function resolver(Request $request, Comentario $comentario): JsonResponse
    {
        abort_unless($comentario->esAccionable(), 422, 'Solo las solicitudes y tareas se pueden cerrar.');

        $datos = $request->validate(['estado' => 'required|in:resuelta,rechazada']);

        $comentario->update([
            'estado'       => $datos['estado'],
            'resuelto_at'  => now(),
            'resuelto_por' => auth()->id(),
        ]);

        // Se le avisa a quien lo pidió, que es el que está esperando respuesta.
        if ($comentario->user_id !== auth()->id()) {
            $this->notificaciones->crear(
                $comentario->user_id,
                'comentario_resuelto',
                $datos['estado'] === 'resuelta' ? 'Tu solicitud fue resuelta' : 'Tu solicitud fue rechazada',
                mb_strimwidth($comentario->contenido, 0, 120, '...'),
                $this->urlDocumento($comentario)
            );
        }

        return response()->json(['comentario' => $comentario->fresh(['autor:id,name', 'asignado:id,name', 'resueltoPor:id,name'])]);
    }

    /**
     * Los mensajes NO se borran.
     *
     * Un hilo es evidencia: sirve para responder "¿quién pidió este cambio?"
     * o "¿esto se avisó a tiempo?". Si cualquiera puede quitar lo que dijo,
     * el rastro deja de servir justo cuando más se necesita — que es cuando
     * alguien quiere que no aparezca.
     *
     * Se deja el método respondiendo explícitamente en vez de quitar la ruta
     * para que quede claro que es una decisión, no un olvido.
     */
    public function destroy(Comentario $comentario): JsonResponse
    {
        return response()->json([
            'message' => 'Los mensajes del chat no se pueden borrar: quedan como evidencia de lo que se dijo y cuándo.',
        ], 403);
    }

    // ─── Avisos ───────────────────────────────────────────────────────────────

    private function avisar(Comentario $comentario, string $documento, int $id): void
    {
        $autor = auth()->user()->name;
        $url   = $this->urlDocumento($comentario);
        $extracto = mb_strimwidth($comentario->contenido, 0, 120, '...');

        // A quien se le asignó la tarea o solicitud.
        if ($comentario->asignado_a && $comentario->asignado_a !== auth()->id()) {
            $this->notificaciones->crear(
                $comentario->asignado_a,
                'comentario_asignado',
                $comentario->tipo === 'tarea' ? "{$autor} te asignó una tarea" : "{$autor} te hizo una solicitud",
                $extracto,
                $url
            );
        }

        // A los mencionados con @, salvo el propio autor y el ya avisado arriba.
        foreach ($comentario->mencionados ?? [] as $userId) {
            if ($userId === auth()->id() || $userId === $comentario->asignado_a) {
                continue;
            }

            $this->notificaciones->crear(
                $userId,
                'comentario_mencion',
                "{$autor} te mencionó",
                $extracto,
                $url
            );
        }
    }

    /**
     * Busca @Nombre en el texto y devuelve los ids. Se comparan nombres
     * completos y primeros nombres, porque en la práctica la gente escribe
     * "@Diego" y no "@Diego González".
     */
    private function detectarMenciones(string $texto): array
    {
        if (! str_contains($texto, '@')) {
            return [];
        }

        $ids = [];

        foreach (User::where('activo', true)->get(['id', 'name']) as $u) {
            $completo = mb_strtolower($u->name);
            $primero  = mb_strtolower(explode(' ', trim($u->name))[0]);
            $enTexto  = mb_strtolower($texto);

            if (str_contains($enTexto, '@' . $completo) || str_contains($enTexto, '@' . $primero)) {
                $ids[] = $u->id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function urlDocumento(Comentario $comentario): string
    {
        return match ($comentario->comentable_type) {
            \App\Models\Op::class          => "/produccion/ops/{$comentario->comentable_id}",
            \App\Models\Cotizacion::class  => "/cotizaciones/{$comentario->comentable_id}",
            \App\Models\Cliente::class     => "/clientes/{$comentario->comentable_id}",
            \App\Models\OrdenCompra::class => "/compras/ordenes/{$comentario->comentable_id}",
            default                        => '/dashboard',
        };
    }
}
