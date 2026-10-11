<?php

namespace App\Services\Informes;

use Illuminate\Support\Carbon;

/**
 * Los periodos de un informe, por nombre: «el mes pasado», «los últimos 30 días».
 *
 * Se guardan con su nombre y no con fechas fijas para que un informe se pueda volver a correr el
 * mes siguiente con un clic y traiga ESE mes, no el que estaba cuando se armó. «Personalizado»
 * es el único que guarda fechas.
 */
class Periodo
{
    public const OPCIONES = [
        'hoy'              => 'Hoy',
        'ultimos_7'        => 'Últimos 7 días',
        'ultimos_30'       => 'Últimos 30 días',
        'ultimos_90'       => 'Últimos 90 días',
        'este_mes'         => 'Este mes',
        'mes_anterior'     => 'El mes pasado',
        'este_trimestre'   => 'Este trimestre',
        'trimestre_anterior' => 'El trimestre pasado',
        'este_ano'         => 'Este año',
        'ano_anterior'     => 'El año pasado',
        'todo'             => 'Todo el historial',
        'personalizado'    => 'Entre dos fechas',
    ];

    /**
     * @return array{desde: ?Carbon, hasta: ?Carbon, etiqueta: string}
     */
    public static function resolver(?string $clave, ?string $desde = null, ?string $hasta = null, ?Carbon $hoy = null): array
    {
        $hoy   = ($hoy ?? now())->copy();
        $clave = array_key_exists((string) $clave, self::OPCIONES) ? $clave : null;

        // Sin periodo pero con fechas sueltas: es personalizado.
        if (! $clave && ($desde || $hasta)) {
            $clave = 'personalizado';
        }

        [$d, $h] = match ($clave) {
            'hoy'              => [$hoy->copy()->startOfDay(), $hoy->copy()->endOfDay()],
            'ultimos_7'        => [$hoy->copy()->subDays(6)->startOfDay(), $hoy->copy()->endOfDay()],
            'ultimos_30'       => [$hoy->copy()->subDays(29)->startOfDay(), $hoy->copy()->endOfDay()],
            'ultimos_90'       => [$hoy->copy()->subDays(89)->startOfDay(), $hoy->copy()->endOfDay()],
            'este_mes'         => [$hoy->copy()->startOfMonth(), $hoy->copy()->endOfDay()],
            'mes_anterior'     => [$hoy->copy()->subMonthNoOverflow()->startOfMonth(), $hoy->copy()->subMonthNoOverflow()->endOfMonth()],
            'este_trimestre'   => [$hoy->copy()->startOfQuarter(), $hoy->copy()->endOfDay()],
            'trimestre_anterior' => [$hoy->copy()->subQuarterNoOverflow()->startOfQuarter(), $hoy->copy()->subQuarterNoOverflow()->endOfQuarter()],
            'este_ano'         => [$hoy->copy()->startOfYear(), $hoy->copy()->endOfDay()],
            'ano_anterior'     => [$hoy->copy()->subYearNoOverflow()->startOfYear(), $hoy->copy()->subYearNoOverflow()->endOfYear()],
            'personalizado'    => [
                $desde ? Carbon::parse($desde)->startOfDay() : null,
                $hasta ? Carbon::parse($hasta)->endOfDay() : null,
            ],
            default            => [null, null],
        };

        return ['desde' => $d, 'hasta' => $h, 'etiqueta' => self::etiqueta($clave, $d, $h)];
    }

    private static function etiqueta(?string $clave, ?Carbon $d, ?Carbon $h): string
    {
        if (! $d && ! $h) {
            return 'Todo el historial';
        }

        $rango = trim(($d ? $d->format('d/m/Y') : '…').' a '.($h ? $h->format('d/m/Y') : '…'));

        return $clave && $clave !== 'personalizado'
            ? self::OPCIONES[$clave]." ({$rango})"
            : $rango;
    }
}
