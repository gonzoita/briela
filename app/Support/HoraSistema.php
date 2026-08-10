<?php

namespace App\Support;

use App\Models\Sede;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * La hora del sistema, según las sedes.
 *
 * Dos ideas distintas que conviene no confundir:
 *
 *   - **La hora global**: la zona en la que la aplicación guarda las fechas. Es la
 *     de la sede principal, y es una sola. Tiene que serlo: si cada sede guardara
 *     en su propia zona, dos registros creados en el mismo instante quedarían con
 *     horas distintas y ningún informe cuadraría.
 *
 *   - **La hora local de cada sede**: la que se le muestra a quien está trabajando
 *     ahí. Es presentación, no almacenamiento.
 *
 * El resultado se guarda en memoria porque esto se consulta en cada petición y no
 * vale una consulta a la base para saber algo que no cambia.
 */
class HoraSistema
{
    private static ?string $zonaGlobal = null;

    public const POR_DEFECTO = 'America/Bogota';

    /**
     * Zona de la sede principal: la hora global del sistema.
     */
    public static function zonaGlobal(): string
    {
        if (self::$zonaGlobal !== null) {
            return self::$zonaGlobal;
        }

        return self::$zonaGlobal = self::zonaValida(
            self::consultar(fn () => Sede::where('es_principal', true)->value('zona_horaria'))
        );
    }

    /**
     * Zona de la sede en la que se está trabajando. Si no hay una activa, la global.
     */
    public static function zonaSedeActiva(): string
    {
        $id = ContextoSede::id();

        if ($id === null) {
            return self::zonaGlobal();
        }

        return self::zonaValida(
            self::consultar(fn () => Sede::whereKey($id)->value('zona_horaria'))
        );
    }

    /** La hora de ahora en la sede activa. */
    public static function ahoraEnSedeActiva(): Carbon
    {
        return Carbon::now(self::zonaSedeActiva());
    }

    /**
     * Aplica la hora global a la aplicación.
     *
     * Se llama al arrancar. Si la base todavía no existe —instalación nueva, o el
     * asistente a medio camino— se queda con la zona de la configuración y no
     * estorba.
     */
    public static function aplicar(): void
    {
        $zona = self::zonaGlobal();

        config(['app.timezone' => $zona]);
        date_default_timezone_set($zona);
    }

    /**
     * Zonas para ofrecer en la pantalla de sedes.
     *
     * La lista corta a propósito: son las de los países donde tiene sentido vender
     * esto. Una lista de las 400 zonas del mundo no ayuda a nadie a elegir.
     *
     * @return array<string, string>
     */
    public static function zonasDisponibles(): array
    {
        return [
            'America/Bogota'      => 'Colombia (Bogotá)',
            'America/Mexico_City' => 'México (Ciudad de México)',
            'America/Lima'        => 'Perú (Lima)',
            'America/Santiago'    => 'Chile (Santiago)',
            'America/Guayaquil'   => 'Ecuador (Guayaquil)',
            'America/Caracas'     => 'Venezuela (Caracas)',
            'America/Panama'      => 'Panamá',
            'America/Costa_Rica'  => 'Costa Rica',
            'America/Guatemala'   => 'Guatemala',
            'America/La_Paz'      => 'Bolivia (La Paz)',
            'America/Asuncion'    => 'Paraguay (Asunción)',
            'America/Montevideo'  => 'Uruguay (Montevideo)',
            'America/Argentina/Buenos_Aires' => 'Argentina (Buenos Aires)',
            'America/Sao_Paulo'   => 'Brasil (São Paulo)',
            'America/New_York'    => 'Estados Unidos (Nueva York)',
            'America/Chicago'     => 'Estados Unidos (Chicago)',
            'America/Los_Angeles' => 'Estados Unidos (Los Ángeles)',
            'Europe/Madrid'       => 'España (Madrid)',
            'UTC'                 => 'UTC',
        ];
    }

    /** Una zona que PHP no conozca dejaría la aplicación sin hora: cae a la de fábrica. */
    private static function zonaValida(?string $zona): string
    {
        $zona = trim((string) $zona);

        if ($zona === '' || ! in_array($zona, timezone_identifiers_list(), true)) {
            return self::POR_DEFECTO;
        }

        return $zona;
    }

    /** Consulta que no puede tumbar la aplicación si la base no está lista. */
    private static function consultar(callable $consulta): ?string
    {
        try {
            return $consulta();
        } catch (Throwable) {
            return null;
        }
    }
}
