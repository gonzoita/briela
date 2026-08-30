<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Qué tan urgente es algo, medido contra su fecha de entrega.
 *
 * No hay un campo «prioridad» en la orden y no hace falta: un campo que alguien tiene que
 * acordarse de marcar termina vacío en todas las órdenes menos en tres. La fecha de entrega sí
 * se llena siempre —es lo que se le prometió al cliente— y dice lo mismo sin que nadie la
 * mantenga.
 *
 * Vive aquí y no en cada controlador porque la usan el tablero de Trabajos y el de Calidad, y
 * dos escalas distintas para la misma fecha harían que la misma orden se viera urgente en una
 * pantalla y tranquila en la otra.
 */
class Urgencia
{
    public static function de(?CarbonInterface $fechaEntrega): array
    {
        if (! $fechaEntrega) {
            return ['clave' => 'sin_fecha', 'etiqueta' => 'Sin fecha'];
        }

        $dias = (int) round(now()->startOfDay()->diffInDays($fechaEntrega->copy()->startOfDay(), false));

        if ($dias < 0)   return ['clave' => 'vencida', 'etiqueta' => 'Vencida por ' . abs($dias) . ' día(s)'];
        if ($dias === 0) return ['clave' => 'hoy',     'etiqueta' => 'Entrega hoy'];
        if ($dias <= 3)  return ['clave' => 'alta',    'etiqueta' => 'Faltan ' . $dias . ' día(s)'];

        return ['clave' => 'normal', 'etiqueta' => 'Faltan ' . $dias . ' días'];
    }
}
