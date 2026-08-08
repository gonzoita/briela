<?php

namespace App\Http\Controllers;

use App\Models\ChatGrupo;
use App\Models\Comentario;
use App\Models\User;
use App\Services\NotificacionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Grupos de chat. Mismo motor que los mensajes directos y los hilos de
 * documento: tipos, tareas, adjuntos y avisos son los mismos.
 *
 * Regla de acceso: **solo los miembros** ven y escriben en un grupo. Se
 * comprueba en cada llamada, no solo al listar.
 */
class ChatGrupoController extends Controller
{
    public function __construct(private readonly NotificacionService $notificaciones) {}

    private function exigirMiembro(ChatGrupo $grupo): void
    {
        abort_unless($grupo->tieneMiembro(auth()->id()), 403, 'No perteneces a este grupo.');
    }

    /** Mis grupos, con lo que no he leído de cada uno. */
    public function index(): JsonResponse
    {
        $yo = auth()->id();

        $grupos = ChatGrupo::de($yo)->with('miembros:id,name')->orderBy('nombre')->get();

        return response()->json([
            'grupos' => $grupos->map(function (ChatGrupo $g) use ($yo) {
                $leidoHasta = $g->miembros->firstWhere('id', $yo)?->pivot?->leido_hasta;

                $ultimo = $g->mensajes()->latest()->first();

                return [
                    'id'        => $g->id,
                    'nombre'    => $g->nombre,
                    'miembros'  => $g->miembros->count(),
                    'ultimo'    => $ultimo ? mb_strimwidth($ultimo->contenido, 0, 60, '...') : null,
                    'sin_leer'  => $g->mensajes()
                        ->where('user_id', '!=', $yo)
                        ->when($leidoHasta, fn ($q) => $q->where('created_at', '>', $leidoHasta))
                        ->count(),
                ];
            }),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'nombre'      => 'required|string|max:120',
            'descripcion' => 'nullable|string|max:255',
            'miembros'    => 'required|array|min:1',
            'miembros.*'  => 'integer|exists:users,id',
        ]);

        $grupo = ChatGrupo::create([
            'nombre'      => $datos['nombre'],
            'descripcion' => $datos['descripcion'] ?? null,
            'creado_por'  => auth()->id(),
        ]);

        // Quien lo crea siempre queda dentro: si no, armaría un grupo al que
        // no puede entrar.
        $miembros = array_unique(array_merge($datos['miembros'], [auth()->id()]));
        $grupo->miembros()->sync($miembros);

        $autor = auth()->user()->name;

        foreach ($miembros as $id) {
            if ($id === auth()->id()) {
                continue;
            }

            $this->notificaciones->crear($id, 'chat_mensaje',
                "{$autor} te agregó al grupo «{$grupo->nombre}»", null, '/dashboard');
        }

        return response()->json(['grupo' => ['id' => $grupo->id, 'nombre' => $grupo->nombre]], 201);
    }

    public function hilo(ChatGrupo $grupo): JsonResponse
    {
        $this->exigirMiembro($grupo);

        $mensajes = $grupo->mensajes()
            ->with(['autor:id,name', 'archivos'])
            ->orderBy('created_at')
            ->get();

        // Marca hasta dónde leí. Es una fila por miembro, no por mensaje.
        $grupo->miembros()->updateExistingPivot(auth()->id(), ['leido_hasta' => now()]);

        return response()->json([
            'grupo'    => [
                'id'       => $grupo->id,
                'nombre'   => $grupo->nombre,
                'miembros' => $grupo->miembros()->get(['users.id', 'name'])->map->only(['id', 'name']),
            ],
            'mensajes' => $mensajes->map(fn (Comentario $m) => [
                'id'        => $m->id,
                'contenido' => $m->contenido,
                'tipo'      => $m->tipo,
                'estado'    => $m->estado,
                'autor'     => $m->autor?->only(['id', 'name']),
                'mio'       => $m->user_id === auth()->id(),
                'creado'    => $m->created_at->toIso8601String(),
                'referencia'=> $m->referencia_url ? [
                    'etiqueta' => $m->referencia_titulo,
                    'url'      => $m->referencia_url,
                ] : null,
                'archivos'  => $m->archivos->map(fn ($a) => [
                    'nombre'   => $a->nombre_original,
                    'url'      => \Illuminate\Support\Facades\Storage::disk('public')->url($a->ruta),
                    'esImagen' => (bool) preg_match('/\.(jpe?g|png|gif|webp|avif)$/i', $a->nombre_original),
                ])->all(),
            ]),
        ]);
    }

    public function enviar(Request $request, ChatGrupo $grupo): JsonResponse
    {
        $this->exigirMiembro($grupo);

        $datos = $request->validate([
            'contenido'  => 'required|string|max:5000',
            'tipo'       => 'required|in:comentario,solicitud,tarea',
            'ref_tipo'   => 'nullable|string|max:40',
            'ref_titulo' => 'nullable|string|max:200',
            'ref_url'    => 'nullable|string|max:300',
            'archivos'             => 'nullable|array|max:5',
            'archivos.*.nombre'    => 'required|string|max:255',
            'archivos.*.ruta'      => 'required|string|max:300',
            'archivos.*.mime'      => 'nullable|string|max:120',
            'archivos.*.extension' => 'nullable|string|max:10',
            'archivos.*.tamano'    => 'nullable|integer',
        ]);

        if (filled($datos['ref_url'] ?? null) && ! str_starts_with($datos['ref_url'], '/')) {
            abort(422, 'Solo se pueden adjuntar documentos del propio sistema.');
        }

        $mensaje = Comentario::create([
            'user_id'   => auth()->id(),
            'grupo_id'  => $grupo->id,
            'contenido' => $datos['contenido'],
            'tipo'      => $datos['tipo'],
            'estado'    => $datos['tipo'] === 'comentario' ? null : 'pendiente',
            'referencia_tipo'   => $datos['ref_tipo'] ?? null,
            'referencia_titulo' => $datos['ref_titulo'] ?? null,
            'referencia_url'    => $datos['ref_url'] ?? null,
        ]);

        foreach ($datos['archivos'] ?? [] as $a) {
            $mensaje->archivos()->create([
                'nombre_original' => $a['nombre'],
                'nombre_archivo'  => basename($a['ruta']),
                'ruta'            => $a['ruta'],
                'categoria'       => 'chat',
                'subido_por'      => auth()->id(),
                'storage'         => 'local',
                'tipo_mime'       => $a['mime'] ?? 'application/octet-stream',
                'extension'       => $a['extension'] ?? pathinfo($a['nombre'], PATHINFO_EXTENSION),
                'tamano'          => $a['tamano'] ?? 0,
            ]);
        }

        $autor = auth()->user()->name;

        foreach ($grupo->miembros()->where('users.id', '!=', auth()->id())->pluck('users.id') as $id) {
            $this->notificaciones->crear($id, 'chat_mensaje',
                "{$autor} escribió en «{$grupo->nombre}»",
                mb_strimwidth($datos['contenido'], 0, 120, '...'), '/dashboard');
        }

        return response()->json(['ok' => true, 'id' => $mensaje->id], 201);
    }
}
