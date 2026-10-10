<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una entrega de mercancía de una orden de compra, con el papel que trajo el proveedor.
 *
 * Una orden puede tener varias: llega en tres veces, cada una con su remisión.
 */
class OrdenCompraRecepcion extends Model
{
    protected $table = 'ordenes_compra_recepciones';

    protected $fillable = [
        'orden_compra_id', 'recibido_por', 'fecha_recepcion',
        'factura_numero', 'remision_numero', 'fecha_documento', 'observaciones',
    ];

    protected function casts(): array
    {
        return [
            'fecha_recepcion' => 'date:Y-m-d',
            'fecha_documento' => 'date:Y-m-d',
        ];
    }

    public function orden(): BelongsTo
    {
        return $this->belongsTo(OrdenCompra::class, 'orden_compra_id');
    }

    public function recibidoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recibido_por');
    }

    /** ¿Llegó con algún papel? Una entrega sin factura ni remisión no se puede comprobar. */
    public function traePapel(): bool
    {
        return filled($this->factura_numero) || filled($this->remision_numero);
    }
}
