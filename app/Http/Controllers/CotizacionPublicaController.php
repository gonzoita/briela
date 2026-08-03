<?php

namespace App\Http\Controllers;

use App\Models\Cotizacion;
use Illuminate\Http\Request;

class CotizacionPublicaController extends Controller
{
    public function show(string $token)
    {
        $cotizacion = Cotizacion::where('token_publico', $token)
            ->with([
                'cliente',
                'responsable',
                'items.producto.imagenes',
                'items.ensamble',
            ])
            ->firstOrFail();

        $items = $cotizacion->items->map(function ($item) {
            $imagenUrl = null;
            if ($item->ensamble?->imagen_principal) {
                $imagenUrl = asset('storage/' . $item->ensamble->imagen_principal);
            } elseif ($item->producto) {
                $img = $item->producto->imagenes->firstWhere('es_principal', true)
                    ?? $item->producto->imagenes->first();
                if ($img) $imagenUrl = asset('storage/' . $img->ruta);
            }
            return array_merge($item->toArray(), ['imagen_url' => $imagenUrl]);
        })->values()->toArray();

        return inertia('Cotizaciones/Publica/Show', [
            'cotizacion' => array_merge($cotizacion->toArray(), [
                'estado_badge' => $cotizacion->estadoBadge(),
                'items'        => $items,
            ]),
            'token' => $token,
        ]);
    }

    public function aprobar(string $token)
    {
        $cotizacion = Cotizacion::where('token_publico', $token)->firstOrFail();

        if ($cotizacion->estado !== 'enviada') {
            return back()->withErrors(['error' => 'Esta cotización no puede ser aprobada en su estado actual.']);
        }

        $cotizacion->update(['estado' => 'aprobada']);

        // Aviso interno al vendedor responsable.
        if ($cotizacion->responsable_id) {
            app(\App\Services\NotificacionService::class)->crear(
                $cotizacion->responsable_id,
                'cotizacion_aprobada',
                "Cotización {$cotizacion->numero} aprobada",
                'El cliente aprobó la cotización. Ya se puede generar la OP.',
                "/cotizaciones/{$cotizacion->id}",
            );
        }

        return redirect("/cotizaciones/{$token}/aprobar")
            ->with('success', '¡Cotización aprobada exitosamente!');
    }

    public function rechazar(Request $request, string $token)
    {
        $request->validate([
            'motivo' => 'nullable|string|max:500',
        ]);

        $cotizacion = Cotizacion::where('token_publico', $token)->firstOrFail();

        if ($cotizacion->estado !== 'enviada') {
            return back()->withErrors(['error' => 'Esta cotización no puede ser rechazada en su estado actual.']);
        }

        $cotizacion->update([
            'estado'          => 'rechazada',
            'motivo_rechazo'  => $request->motivo ?? null,
        ]);

        // Aviso interno al vendedor responsable.
        if ($cotizacion->responsable_id) {
            app(\App\Services\NotificacionService::class)->crear(
                $cotizacion->responsable_id,
                'cotizacion_rechazada',
                "Cotización {$cotizacion->numero} rechazada",
                trim('El cliente rechazó la cotización. ' . ($request->motivo ? "Motivo: {$request->motivo}" : '')),
                "/cotizaciones/{$cotizacion->id}",
            );
        }

        return redirect("/cotizaciones/{$token}/aprobar")
            ->with('success', 'Cotización rechazada.');
    }
}
