<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OpItem extends Model
{
    protected $table = 'op_items';

    protected $fillable = [
        'op_id',
        'tipo',
        'producto_id',
        'ensamble_id',
        'orden',
        'descripcion',
        'descripcion_larga',
        'cantidad',
        'precio_unitario',
        'descuento_pct',
        'subtotal',
        'impuesto_pct',
        'impuesto_valor',
        'total_linea',
        'variables_instancia',
        'imagenes_instancia',
        'componentes_snapshot',
        'numero_serie',
        'operario_id',
        'estado_item',
        'notas_item',
        'remisionado',
        'remision_id',
        'cantidad_remisionada',
    ];

    protected $casts = [
        'cantidad'             => 'decimal:2',
        'precio_unitario'      => 'decimal:2',
        'descuento_pct'        => 'decimal:2',
        'subtotal'             => 'decimal:2',
        'impuesto_pct'         => 'decimal:2',
        'impuesto_valor'       => 'decimal:2',
        'total_linea'          => 'decimal:2',
        'variables_instancia'  => 'array',
        'imagenes_instancia'   => 'array',
        'componentes_snapshot' => 'array',
        'remisionado'          => 'boolean',
        'cantidad_remisionada' => 'decimal:3',
    ];

    /**
     * Un ítem que nadie fabrica: un producto de bodega o un concepto libre.
     *
     * Los trabajos se generan solo para los ensambles —uno por unidad física, con sus pasos—,
     * así que un producto suelto de la OP no tiene ninguno. Eso no significa que no se pueda
     * despachar: significa que su estado lo lleva el ítem, no sus trabajos.
     */
    public function sinTrabajos(): bool
    {
        return $this->trabajos()->count() === 0;
    }

    /**
     * Cuántas unidades se pueden meter hoy en una remisión.
     *
     * Un ensamble las cuenta por sus trabajos: terminados y todavía sin remisionar. Un
     * producto no tiene trabajos, así que lo que manda es que esté marcado como alistado en la
     * OP, y lo que quede por remisionar.
     *
     * Hasta el 18 ago 2026 esto solo miraba los trabajos, y por eso **un producto nunca
     * aparecía en el remisionador**: la OP lo listaba y la remisión no lo dejaba escoger.
     */
    public function cantidadDisponible(): int
    {
        if ($this->sinTrabajos()) {
            return $this->estado_item === 'terminado'
                ? max(0, (int) $this->cantidad - (int) $this->cantidad_remisionada)
                : 0;
        }

        return $this->trabajos()->disponiblesParaRemision()->count();
    }

    public function estaRemisionado(): bool
    {
        if ($this->sinTrabajos()) {
            return (int) $this->cantidad_remisionada >= (int) $this->cantidad && (int) $this->cantidad > 0;
        }

        $total = $this->trabajos()->count();
        if ($total === 0) return false;
        return $this->trabajos()->disponiblesParaRemision()->count() === 0
            && $this->trabajos()->where('porcentaje_avance', 100)->count() > 0;
    }

    public function unidadesCompletadas(): int
    {
        if ($this->sinTrabajos()) {
            return $this->estado_item === 'terminado' ? (int) $this->cantidad : 0;
        }

        return $this->trabajos()->completados()->count();
    }

    public function unidadesRemisionadas(): int
    {
        if ($this->sinTrabajos()) {
            return (int) $this->cantidad_remisionada;
        }

        return $this->trabajos()->where('remisionado', true)->count();
    }

    public function op(): BelongsTo
    {
        return $this->belongsTo(Op::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function ensamble(): BelongsTo
    {
        return $this->belongsTo(Ensamble::class);
    }

    public function operario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operario_id');
    }

    public function componentes(): HasMany
    {
        return $this->hasMany(OpItemComponente::class);
    }

    public function trabajo(): HasOne
    {
        return $this->hasOne(OpItemTrabajo::class);
    }

    public function trabajos(): HasMany
    {
        return $this->hasMany(OpItemTrabajo::class);
    }

    public function remision(): BelongsTo
    {
        return $this->belongsTo(Remision::class);
    }
}
