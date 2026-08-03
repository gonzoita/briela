<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfTemplate extends Model
{
    protected $table = 'pdf_templates';

    protected $fillable = [
        'modulo', 'nombre', 'color_primario', 'color_secundario',
        'color_texto', 'fuente', 'tamanio_fuente', 'logo_url',
        'mostrar_logo', 'logo_posicion', 'logo_ancho',
        'mostrar_encabezado', 'encabezado_titulo', 'encabezado_subtitulo',
        'encabezado_html', 'mostrar_pie', 'pie_html', 'pie_texto',
        'secciones_visibles', 'config_avanzada', 'activa',
    ];

    protected $casts = [
        'mostrar_logo'       => 'boolean',
        'mostrar_encabezado' => 'boolean',
        'mostrar_pie'        => 'boolean',
        'activa'             => 'boolean',
        'secciones_visibles' => 'array',
        'config_avanzada'    => 'array',
    ];

    public static function obtenerParaModulo(string $modulo): self
    {
        return static::firstOrCreate(
            ['modulo' => $modulo],
            ['nombre' => 'Plantilla ' . ucfirst($modulo)]
        );
    }
}
