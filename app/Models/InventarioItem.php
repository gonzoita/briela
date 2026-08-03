<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventarioItem extends Model
{
    protected $table = 'inventario_items';

    protected $fillable = [
        'codigo', 'nombre', 'descripcion', 'tipo', 'unidad',
        'stock_actual', 'stock_minimo', 'stock_maximo',
        'precio_promedio', 'precio_ultimo',
        'proveedor_id', 'ubicacion', 'activo',
    ];

    protected $casts = [
        'stock_actual'    => 'decimal:3',
        'stock_minimo'    => 'decimal:3',
        'stock_maximo'    => 'decimal:3',
        'precio_promedio' => 'decimal:2',
        'precio_ultimo'   => 'decimal:2',
        'activo'          => 'boolean',
    ];

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function movimientos(): HasMany
    {
        return $this->hasMany(InventarioMovimiento::class, 'item_id');
    }

    public function esBajoStock(): bool
    {
        return $this->stock_actual <= $this->stock_minimo;
    }

    public function registrarMovimiento(
        string $tipo,
        float $cantidad,
        int $usuarioId,
        ?float $precioUnitario = null,
        string $origenTipo = 'ajuste_manual',
        ?int $origenId = null,
        ?string $notas = null
    ): void {
        $stockAnterior = (float) $this->stock_actual;
        $stockNuevo = in_array($tipo, ['entrada', 'devolucion'])
            ? $stockAnterior + $cantidad
            : $stockAnterior - $cantidad;

        $this->update(['stock_actual' => $stockNuevo]);

        if ($precioUnitario && $tipo === 'entrada') {
            $totalUnidades = $stockAnterior + $cantidad;
            $precioPromedio = $totalUnidades > 0
                ? (($stockAnterior * (float) $this->precio_promedio) + ($cantidad * $precioUnitario)) / $totalUnidades
                : $precioUnitario;
            $this->update([
                'precio_promedio' => $precioPromedio,
                'precio_ultimo'   => $precioUnitario,
            ]);
        }

        InventarioMovimiento::create([
            'item_id'         => $this->id,
            'tipo'            => $tipo,
            'cantidad'        => $cantidad,
            'stock_anterior'  => $stockAnterior,
            'stock_nuevo'     => $stockNuevo,
            'precio_unitario' => $precioUnitario,
            'origen_tipo'     => $origenTipo,
            'origen_id'       => $origenId,
            'usuario_id'      => $usuarioId,
            'notas'           => $notas,
        ]);
    }
}
