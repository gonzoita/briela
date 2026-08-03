<?php

namespace App\Http\Controllers;

use App\Models\Notificacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificacionController extends Controller
{
    // Devuelve las últimas notificaciones del usuario + cuántas sin leer,
    // para la campanita del layout.
    public function index(): JsonResponse
    {
        $userId = auth()->id();

        $notificaciones = Notificacion::where('user_id', $userId)
            ->latest()
            ->limit(20)
            ->get(['id', 'tipo', 'titulo', 'mensaje', 'url', 'icono', 'color', 'leida', 'created_at'])
            ->map(fn ($n) => [
                'id'      => $n->id,
                'tipo'    => $n->tipo,
                'titulo'  => $n->titulo,
                'mensaje' => $n->mensaje,
                'url'     => $n->url,
                'icono'   => $n->icono,
                'color'   => $n->color,
                'leida'   => $n->leida,
                'hace'    => $n->created_at->diffForHumans(),
            ]);

        return response()->json([
            'notificaciones' => $notificaciones,
            'no_leidas'      => Notificacion::where('user_id', $userId)->where('leida', false)->count(),
        ]);
    }

    public function marcarLeida(Notificacion $notificacion): JsonResponse
    {
        abort_if($notificacion->user_id !== auth()->id(), 403);

        if (! $notificacion->leida) {
            $notificacion->update(['leida' => true, 'leida_at' => now()]);
        }

        return response()->json(['ok' => true]);
    }

    public function marcarTodasLeidas(): JsonResponse
    {
        Notificacion::where('user_id', auth()->id())
            ->where('leida', false)
            ->update(['leida' => true, 'leida_at' => now()]);

        return response()->json(['ok' => true]);
    }
}
