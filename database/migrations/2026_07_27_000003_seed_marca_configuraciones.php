<?php

use App\Models\Configuracion;
use Illuminate\Database\Migrations\Migration;

/**
 * Ajustes de identidad visual: color de marca, favicon y título de la pestaña.
 *
 * Van en el grupo 'marca' y no en 'empresa' porque la pantalla de Marca los
 * edita con controles propios (selector de color, subida de imagen, vista
 * previa) y no con los campos de texto genéricos de Configuración.
 */
return new class extends Migration
{
    public function up(): void
    {
        $claves = [
            [
                'clave'       => 'marca_color',
                'valor'       => '#0A4283',
                'tipo'        => 'string',
                'grupo'       => 'marca',
                'etiqueta'    => 'Color principal',
                'descripcion' => 'De este color se derivan el hover, los fondos suaves y el color del texto.',
            ],
            [
                'clave'       => 'marca_titulo',
                'valor'       => 'SGI — {empresa}',
                'tipo'        => 'string',
                'grupo'       => 'marca',
                'etiqueta'    => 'Título de la pestaña',
                'descripcion' => 'Admite {pagina} y {empresa}.',
            ],
            [
                'clave'       => 'marca_favicon_url',
                'valor'       => '',
                'tipo'        => 'string',
                'grupo'       => 'marca',
                'etiqueta'    => 'Favicon',
                'descripcion' => 'Ícono que sale en la pestaña del navegador.',
            ],
        ];

        foreach ($claves as $c) {
            Configuracion::firstOrCreate(['clave' => $c['clave']], $c);
        }
    }

    public function down(): void
    {
        Configuracion::whereIn('clave', ['marca_color', 'marca_titulo', 'marca_favicon_url'])->delete();
    }
};
