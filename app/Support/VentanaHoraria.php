<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * «¿Le toca correr ahora?» para algo que se programa por hora.
 *
 * Devuelve la clave de la ventana en la que debe correr, o null si ahora no le toca. Esa clave es
 * también la de idempotencia de la corrida: dos pasadas del cron dentro de la misma ventana dan la
 * misma y la segunda se descarta sola.
 *
 * - `diaria`, `semanal` y `mensual` arrancan dentro de las 3 horas siguientes a la hora fijada, una
 *   vez por día como máximo: si el servidor estuvo caído a esa hora, corre cuando vuelve, pero no a
 *   las 11 de la noche. La clave es la fecha.
 * - `cada_horas` corre una vez en cada bloque de N horas entre «desde» y «hasta» (8:00, 10:00,
 *   12:00…). La clave es la fecha con la hora del bloque.
 */
class VentanaHoraria
{
    /** @param array{tipo?: string, hora?: string, dias?: array<int, int|string>, dia_mes?: int|string, intervalo?: int|string, desde?: string, hasta?: string} $f */
    public static function actual(array $f, CarbonInterface $ahora, int $minutos = 180): ?string
    {
        $tipo = $f['tipo'] ?? 'manual';

        if ($tipo === 'cada_horas') {
            return self::cadaHoras($f, $ahora);
        }

        if (! in_array($tipo, ['diaria', 'semanal', 'mensual'], true) || empty($f['hora'])) {
            return null;
        }

        if ($tipo === 'semanal' && ! in_array($ahora->dayOfWeekIso, array_map('intval', $f['dias'] ?? []), true)) {
            return null;
        }

        if ($tipo === 'mensual' && $ahora->day !== (int) ($f['dia_mes'] ?? 0)) {
            return null;
        }

        [$h, $m] = self::hora((string) $f['hora']);

        $desde = $ahora->copy()->setTime($h, $m);

        if ($ahora->lt($desde) || $ahora->gt($desde->copy()->addMinutes($minutos))) {
            return null;
        }

        return $ahora->toDateString();
    }

    private static function cadaHoras(array $f, CarbonInterface $ahora): ?string
    {
        $intervalo = max(1, (int) ($f['intervalo'] ?? 0));

        if (empty($f['desde']) || empty($f['hasta'])) {
            return null;
        }

        if (! empty($f['dias']) && ! in_array($ahora->dayOfWeekIso, array_map('intval', $f['dias']), true)) {
            return null;
        }

        [$hd, $md] = self::hora((string) $f['desde']);
        [$hh, $mh] = self::hora((string) $f['hasta']);

        $inicio = $ahora->copy()->setTime($hd, $md);
        $fin    = $ahora->copy()->setTime($hh, $mh);

        if ($ahora->lt($inicio) || $ahora->gt($fin)) {
            return null;
        }

        $bloque = intdiv((int) $inicio->diffInMinutes($ahora), $intervalo * 60);

        return $ahora->toDateString() . '-' . $inicio->copy()->addHours($bloque * $intervalo)->format('H');
    }

    /** @return array{0: int, 1: int} */
    private static function hora(string $hora): array
    {
        return array_pad(array_map('intval', explode(':', $hora)), 2, 0);
    }
}
