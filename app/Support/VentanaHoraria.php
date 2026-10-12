<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * «¿Le toca correr ahora?» para algo que se programa por hora: diaria o semanal.
 *
 * Devuelve la clave de la ventana en la que debe correr (la fecha), o null si ahora no le toca. Esa
 * clave es también la de idempotencia de la corrida: dos pasadas del cron dentro de la misma ventana
 * dan la misma y la segunda se descarta sola.
 *
 * Arranca dentro de las 3 horas siguientes a la hora fijada, una vez por día como máximo: si el
 * servidor estuvo caído a esa hora, corre cuando vuelve, pero no a las 11 de la noche.
 */
class VentanaHoraria
{
    /** @param array{tipo?: string, hora?: string, dias?: array<int, int|string>} $f */
    public static function actual(array $f, CarbonInterface $ahora, int $minutos = 180): ?string
    {
        $tipo = $f['tipo'] ?? 'manual';

        if (! in_array($tipo, ['diaria', 'semanal'], true) || empty($f['hora'])) {
            return null;
        }

        if ($tipo === 'semanal' && ! in_array($ahora->dayOfWeekIso, array_map('intval', $f['dias'] ?? []), true)) {
            return null;
        }

        [$h, $m] = array_pad(array_map('intval', explode(':', (string) $f['hora'])), 2, 0);

        $desde = $ahora->copy()->setTime($h, $m);

        if ($ahora->lt($desde) || $ahora->gt($desde->copy()->addMinutes($minutos))) {
            return null;
        }

        return $ahora->toDateString();
    }
}
