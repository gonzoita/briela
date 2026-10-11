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
        $usuario = auth()->user();
        $userId  = $usuario?->id;

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
            // El contador de la bandeja viaja acá, pegado a la campanita, y no en su propia
            // ruta: `useAvisos` ya hace esta petición cada minuto, y una conversación sin leer
            // no es un aviso pero se mira en el mismo sitio. Darle ruta propia habría sido una
            // quinta petición por ronda, que es justo lo que costó trabajo quitar. Ver
            // «Velocidad: nada cuesta una petición por clic».
            'bandeja_sin_leer' => $usuario?->tienePermiso('bandeja.ver')
                ? app(\App\Services\Bandeja\BandejaService::class)->sinLeerPara($usuario)
                : 0,
        ]);
    }

    /**
     * El pulso: ¿cambió algo desde la última vez? Una firma corta, no los datos.
     *
     * La campana y el chat se ven «en vivo» preguntando esto cada pocos segundos: son cuatro
     * consultas de un número cada una, sin traer filas. Solo cuando la firma cambia, la pantalla
     * pide las listas completas (`/notificaciones` y compañía). Así un cambio de estado —una
     * cotización aprobada por el cliente— aparece sin recargar, sin pagar cuatro peticiones
     * grandes cada vez.
     */
    public function pulso(): JsonResponse
    {
        $yo = auth()->id();

        $notif = Notificacion::where('user_id', $yo)
            ->selectRaw('MAX(id) as ultima, SUM(CASE WHEN leida = 0 THEN 1 ELSE 0 END) as no_leidas')
            ->first();

        $directos = \App\Models\Comentario::where('destinatario_id', $yo)
            ->selectRaw('MAX(id) as ultimo, SUM(CASE WHEN leido_at IS NULL THEN 1 ELSE 0 END) as sin_leer')
            ->first();

        $enGrupos = \App\Models\Comentario::whereIn('grupo_id',
            \Illuminate\Support\Facades\DB::table('chat_grupo_usuario')->where('user_id', $yo)->select('chat_grupo_id')
        )->max('id');

        $asignados = \App\Models\Comentario::where('asignado_a', $yo)->abiertos()->count();

        return response()->json([
            'firma' => implode('.', [
                (int) $notif?->ultima, (int) $notif?->no_leidas,
                (int) $directos?->ultimo, (int) $directos?->sin_leer,
                (int) $enGrupos, $asignados,
            ]),
            'ultima_notificacion' => (int) $notif?->ultima,
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
