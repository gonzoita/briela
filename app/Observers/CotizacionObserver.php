<?php

namespace App\Observers;

use App\Models\Cotizacion;
use App\Services\ReservaStockService;

/**
 * Engancha el apartado de stock —y la comisión del vendedor— al ciclo de vida de una cotización.
 *
 * El estado de una cotización lo cambian cuatro caminos distintos —la pantalla interna, la
 * aprobación del cliente por el enlace público, el comando que vence las viejas y el borrado—.
 * Escribir «y de paso, aparta el stock» en cada uno es dejar uno sin hacer el día que se agregue
 * un quinto; aquí se escucha el cambio una sola vez.
 *
 * **Nunca rompe lo que lo disparó.** Apartar stock es secundario: si falla, la cotización se
 * guarda igual. Se reporta para que quede rastro, en vez de tragarse el error.
 */
class CotizacionObserver
{
    public function __construct(private ReservaStockService $reservas) {}

    public function saved(Cotizacion $cotizacion): void
    {
        if (! $cotizacion->wasChanged('estado')) {
            return;
        }

        $this->sinRomper(fn () => $this->reservas->alCambiarEstado($cotizacion));

        // Aprobada, por el camino que sea, la comisión del vendedor deja de ser una proyección.
        // Antes solo lo hacían la pantalla interna y «Generar OP»: si el cliente aprobaba por el
        // enlace público, la comisión seguía «proyectada» hasta que alguien generara la orden.
        // El lead de donde salió se entera: aprobada → ganado; rechazada o vencida → perdido.
        if ($cotizacion->lead_id) {
            $this->sinRomper(fn () => $cotizacion->lead?->alCambiarCotizacion($cotizacion));
        }

        if ($cotizacion->estado === 'aprobada') {
            $this->sinRomper(fn () => \App\Models\ComisionVendedor::where('cotizacion_id', $cotizacion->id)
                ->where('estado', 'proyectada')
                ->update(['estado' => 'confirmada']));
        }

        $this->sinRomper(fn () => $this->avisarAlResponsable($cotizacion));
    }

    /**
     * El responsable se entera de todo cambio de estado que no hizo él: lo aprobó el cliente por
     * el enlace, otra persona la marcó, o se venció. Antes solo avisaba el enlace público, y un
     * cambio desde la pantalla o la guía no le llegaba a nadie.
     *
     * El aviso lleva a la cotización, y si el responsable la tiene abierta, la pantalla se
     * recarga sola (ver «Avisos en vivo» en AppLayout).
     */
    private function avisarAlResponsable(Cotizacion $cotizacion): void
    {
        $responsable = $cotizacion->responsable_id;

        if (! $responsable || $responsable === auth()->id() || $cotizacion->estado === 'borrador') {
            return;
        }

        $quien = auth()->user()?->name;
        $num   = $cotizacion->numero;

        [$tipo, $titulo, $mensaje] = match ($cotizacion->estado) {
            'aprobada'  => ['cotizacion_aprobada', "Cotización {$num} aprobada",
                $quien ? "{$quien} la marcó como aprobada. Ya se puede generar la orden." : 'El cliente la aprobó. Ya se puede generar la orden.'],
            'rechazada' => ['cotizacion_rechazada', "Cotización {$num} rechazada",
                trim(($quien ? "{$quien} la marcó como rechazada." : 'El cliente la rechazó.')
                    .($cotizacion->motivo_rechazo ? " Motivo: {$cotizacion->motivo_rechazo}" : ''))],
            'vencida'   => ['cotizacion_vencida', "Cotización {$num} vencida",
                'Pasó su fecha de validez sin respuesta del cliente.'],
            default     => ['cotizacion_estado', "Cotización {$num}: ".mb_strtolower($cotizacion->estadoBadge()['label']),
                $quien ? "{$quien} cambió su estado." : 'Cambió de estado.'],
        };

        app(\App\Services\NotificacionService::class)->crear(
            $responsable, $tipo, $titulo, $mensaje, "/cotizaciones/{$cotizacion->id}",
        );
    }

    /** Borrada (suave): sus unidades vuelven a estar libres. */
    public function deleted(Cotizacion $cotizacion): void
    {
        $this->sinRomper(fn () => $this->reservas->cerrar($cotizacion, 'liberada'));
    }

    private function sinRomper(callable $accion): void
    {
        try {
            $accion();
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
