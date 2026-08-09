<?php

use App\Models\Configuracion;
use Illuminate\Database\Migrations\Migration;

/**
 * Tipografía de la empresa, elegible desde Ajustes → Identidad visual.
 *
 * Hasta ahora la interfaz usaba una fuente traída de un CDN externo, igual para
 * todas las instalaciones. Pasa a ser parte de la identidad de cada empresa, como
 * el color y el logo.
 *
 * Nace con la del sistema: San Francisco en Mac y iPhone, Segoe UI en Windows,
 * Roboto en Android. No descarga ningún archivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Configuracion::firstOrCreate(
            ['clave' => 'marca_fuente'],
            [
                'valor'       => 'sistema',
                'tipo'        => 'string',
                'grupo'       => 'marca',
                'etiqueta'    => 'Tipografía',
                'descripcion' => 'La familia tipográfica de toda la interfaz y de los documentos.',
            ]
        );
    }

    public function down(): void
    {
        // Migración hacia adelante: quitar el ajuste dejaría la interfaz sin
        // tipografía definida. Ver docs/BRIELA-PLAN.md, reglas del producto.
    }
};
