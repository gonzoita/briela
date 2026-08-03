<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecuenciaDocumento extends Model
{
    protected $table = 'secuencias_documento';

    protected $fillable = [
        'sede_id', 'tipo_documento', 'prefijo', 'incluir_anio',
        'siguiente_numero', 'padding',
    ];

    protected $casts = [
        'incluir_anio'     => 'boolean',
        'siguiente_numero' => 'integer',
        'padding'          => 'integer',
    ];

    /**
     * Catálogo de documentos que llevan consecutivo. Fuente única para la
     * pantalla de configuración de numeración.
     */
    public static function catalogo(): array
    {
        return [
            'op'               => 'Órdenes de Producción',
            'cotizacion'       => 'Cotizaciones',
            'remision'         => 'Remisiones',
            'solicitud_compra' => 'Solicitudes de Compra',
            'orden_compra'     => 'Órdenes de Compra',
        ];
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    /**
     * Cómo se vería el próximo código, para la vista previa en pantalla.
     */
    public function getEjemploAttribute(): string
    {
        $anio = $this->incluir_anio ? date('Y') . '-' : '';

        return $this->prefijo . $anio . str_pad((string) $this->siguiente_numero, $this->padding, '0', STR_PAD_LEFT);
    }
}
