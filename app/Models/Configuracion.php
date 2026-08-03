<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Configuracion extends Model
{
    protected $table = 'configuraciones';

    protected $fillable = ['clave', 'valor', 'tipo', 'grupo', 'etiqueta', 'descripcion'];

    public static function get(string $clave, mixed $default = null): mixed
    {
        $config = static::where('clave', $clave)->first();
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
