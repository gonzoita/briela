<?php

namespace App\Observers;

use App\Models\Cotizacion;
use App\Services\ReservaStockService;

/**
 * Engancha el apartado de stock al ciclo de vida de una cotización.
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
