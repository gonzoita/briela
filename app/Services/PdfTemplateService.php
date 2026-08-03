<?php

namespace App\Services;

use App\Models\PdfTemplate;

class PdfTemplateService
{
    public static function getEstilos(string $modulo): array
    {
        $t = PdfTemplate::obtenerParaModulo($modulo);

        return [
            'template'             => $t,
            'color_primario'       => $t->color_primario,
            'color_secundario'     => $t->color_secundario,
            'color_texto'          => $t->color_texto,
            'fuente'               => $t->fuente,
            'tamanio_fuente'       => $t->tamanio_fuente,
            'logo_url'             => $t->logo_url,
            'mostrar_logo'         => $t->mostrar_logo,
            'logo_posicion'        => $t->logo_posicion,
            'logo_ancho'           => $t->logo_ancho,
            'mostrar_encabezado'   => $t->mostrar_encabezado,
            'encabezado_titulo'    => $t->encabezado_titulo,
            'encabezado_subtitulo' => $t->encabezado_subtitulo,
            'encabezado_html'      => $t->encabezado_html,
            'mostrar_pie'          => $t->mostrar_pie,
            'pie_html'             => $t->pie_html,
            'pie_texto'            => $t->pie_texto,
            'secciones_visibles'   => $t->secciones_visibles ?? [],
        ];
    }
}
