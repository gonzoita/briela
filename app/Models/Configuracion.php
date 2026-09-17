<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Configuracion extends Model
{
    protected $table = 'configuraciones';

    protected $fillable = ['clave', 'valor', 'tipo', 'grupo', 'etiqueta', 'descripcion'];

    /**
     * Todas las claves, leídas UNA vez por petición.
     *
     * Antes cada `get()` era su propia consulta. Se nota porque estos ajustes no se
     * leen de a uno: el bloque `marca` de HandleInertiaRequests pide nombre, color,
     * logo, logo oscuro, favicon, favicon oscuro, correo y web —y el asistente dos
     * más— en CADA navegación de Inertia, así que una pantalla costaba unas quince
     * consultas antes de mirar un solo dato del negocio.
     *
     * La tabla tiene 48 filas: traerlas todas de un golpe cuesta menos que la
     * segunda consulta suelta.
     *
     * Se guarda en el CONTENEDOR y no en una propiedad estática. Una estática vive lo
     * que vive el proceso de PHP, no la petición: en las pruebas, donde un mismo
     * proceso corre los 95 casos seguidos, el mapa de un test sobreviviría al
     * `RefreshDatabase` del siguiente y devolvería ajustes de una base que ya se
     * borró. El contenedor se reconstruye en cada petición y en cada test, así que
     * ahí el caché dura exactamente lo que tiene que durar.
     */
    private const CACHE = 'briela.configuraciones';

    protected static function booted(): void
    {
        // Cualquier escritura invalida el mapa, venga de `set()` o de un Eloquent
        // suelto: sin esto, guardar y volver a leer en la misma petición devolvería
        // el valor anterior.
        static::saved(fn () => static::olvidarCache());
        static::deleted(fn () => static::olvidarCache());
    }

    /** @return array<string, static> */
    private static function mapa(): array
    {
        if (app()->bound(self::CACHE)) {
            return app()->make(self::CACHE);
        }

        $mapa = static::query()->get()->keyBy('clave')->all();

        app()->instance(self::CACHE, $mapa);

        return $mapa;
    }

    /** Vacía el mapa. Hace falta si algo escribe la tabla sin pasar por el modelo. */
    public static function olvidarCache(): void
    {
        app()->forgetInstance(self::CACHE);
    }

    public static function get(string $clave, mixed $default = null): mixed
    {
        $config = static::mapa()[$clave] ?? null;
        if (!$config) return $default;

        return match($config->tipo) {
            'integer' => (int) $config->valor,
            'boolean' => (bool) $config->valor,
            'json'    => json_decode($config->valor, true),
            default   => $config->valor,
        };
    }

    public static function set(string $clave, mixed $valor): void
    {
        static::updateOrCreate(['clave' => $clave], ['valor' => $valor]);
    }

    public static function obtener(string $clave, mixed $default = null): mixed
    {
        return static::get($clave, $default);
    }
}
