<?php

namespace App\Contracts;

interface PdfExportable
{
    // Variables calculadas adicionales a las columnas de BD (imagen_url, nombre_completo, etc.)
    public function pdfVariablesExtra(): array;

    // Colecciones relacionadas para bloques {{#nombre}} (['items' => [...], ...])
    public function pdfColecciones(): array;

    // Nombre del módulo PDF al que pertenece este modelo
    public static function pdfModulo(): string;
}
