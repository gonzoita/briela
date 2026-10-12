<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Unas unidades de un producto apartadas para una cotización enviada, hasta por 24 horas.
 *
 * Es una marca: no mueve el inventario. Ver `ReservaStockService` y la migración.
 */
class ReservaStock extends Model
{
    protected $table = 'reservas_stock';

    protected $fillable = [
        'cotizacion_id', 'producto_id', 'cantidad', 'cantidad_cedida', 'estado',
        'expira_at', 'cerrada_at', 'cedida_a_cotizacion_id',
    ];

    protected function casts(): array
    {
        return [
            'cantidad'        => 'decimal:3',
            'cantidad_cedida' => 'decimal:3',
            'expira_at'       => 'datetime',
            'cerrada_at'      => 'datetime',
        ];
    }

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function cedidaA(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class, 'cedida_a_cotizacion_id');
    }

    /** Lo que de verdad aparta: lo apartado menos lo que una venta ya se llevó. */
    public function efectiva(): float
    {
        return max(0.0, (float) $this->cantidad - (float) $this->cantidad_cedida);
    }

    /**
     * Las que apartan AHORA: activas y no vencidas.
     *
     * Se mira `expira_at` además del estado: el comando que las marca como vencidas corre cada
     * quince minutos, y en ese rato una reserva ya vencida no debe seguir apartando.
     */
    public function scopeVigentes(Builder $query): Builder
    {
        return $query->where('estado', 'activa')->where('expira_at', '>', now());
    }
}
