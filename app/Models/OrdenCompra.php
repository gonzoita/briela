<?php

namespace App\Models;

use App\Services\SecuenciaService;
use App\Support\ContextoSede;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrdenCompra extends Model
{
    protected $table = 'ordenes_compra';

    protected $fillable = [
        'sede_id', 'numero', 'estado', 'proveedor_id', 'solicitud_id', 'creado_por',
        'fecha_entrega_esperada', 'fecha_recepcion',
        'subtotal', 'impuesto', 'total', 'condiciones', 'notas',
    ];

    protected $casts = [
        // `date:Y-m-d` y no `date`: sin el formato esto se serializa como
        // «2026-08-10T00:00:00.000000Z», y un `<input type="date"» exige «2026-08-10».
        // El navegador rechaza el valor y solo lo dice en la consola: el campo se ve
        // vacío y el usuario no puede leer ni corregir una fecha que sí está guardada.
        'fecha_entrega_esperada' => 'date:Y-m-d',
        'fecha_recepcion'        => 'date:Y-m-d',
        'subtotal'               => 'decimal:2',
        'impuesto'               => 'decimal:2',
        'total'                  => 'decimal:2',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            // Hereda la sede de la solicitud que la originó; si nace suelta,
            // toma la sede activa del usuario.
            $model->sede_id ??= $model->solicitud?->sede_id ?? ContextoSede::paraGuardar();
            $model->numero = app(SecuenciaService::class)->siguiente('orden_compra', $model->sede_id);
        });
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    public function proveedor(): BelongsTo
    {
        return $this->belongsTo(Proveedor::class);
    }

    public function solicitud(): BelongsTo
    {
        return $this->belongsTo(SolicitudCompra::class, 'solicitud_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrdenCompraItem::class, 'orden_id');
    }

    public function recalcularTotales(): void
    {
        $this->load('items');
        $subtotal = $this->items->sum('total_linea');
        $impuesto = $this->items->sum(fn ($i) =>
            $i->precio_unitario * $i->cantidad * ($i->impuesto_pct / 100)
        );
        $this->update([
            'subtotal' => $subtotal,
            'impuesto' => $impuesto,
            'total'    => $subtotal + $impuesto,
        ]);
    }

    public function recibir(array $itemsRecibidos, int $usuarioId): void
    {
        $bodegaPrincipal = Bodega::principal();

        foreach ($itemsRecibidos as $itemData) {
            $ocItem = OrdenCompraItem::find($itemData['id']);
            if (!$ocItem) continue;

            $cantidadRecibida = (float) $itemData['cantidad_recibida'];
            if ($cantidadRecibida <= 0) continue;

            $ocItem->update([
                'cantidad_recibida' => $ocItem->cantidad_recibida + $cantidadRecibida,
            ]);

            // El stock recibido entra al inventario real (productos), a la
            // bodega principal — antes iba a la tabla paralela
            // inventario_items que producción nunca miraba.
            if ($ocItem->item_id && $bodegaPrincipal) {
                $producto = Producto::find($ocItem->item_id);
                $producto?->registrarMovimiento(
                    tipo: 'entrada',
                    cantidad: $cantidadRecibida,
                    bodegaId: $bodegaPrincipal->id,
                    usuarioId: $usuarioId,
                    precioUnitario: (float) $ocItem->precio_unitario,
                    origenTipo: 'orden_compra',
                    origenId: $this->id,
                    notas: "Recepción OC {$this->numero}"
                );
            }
        }

        $this->load('items');
        $todosRecibidos  = $this->items->every(fn ($i) => (float) $i->cantidad_recibida >= (float) $i->cantidad);
        $algunoRecibido  = $this->items->some(fn ($i) => (float) $i->cantidad_recibida > 0);

        $this->update([
            'estado'          => $todosRecibidos ? 'recibida' : ($algunoRecibido ? 'recibida_parcial' : $this->estado),
            'fecha_recepcion' => $todosRecibidos ? now() : $this->fecha_recepcion,
        ]);
    }
}
