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

    public function cantidadDisponible(): int
    {
        return $this->trabajos()->disponiblesParaRemision()->count();
    }

    public function estaRemisionado(): bool
    {
        $total = $this->trabajos()->count();
        if ($total === 0) return false;
        return $this->trabajos()->disponiblesParaRemision()->count() === 0
            && $this->trabajos()->where('porcentaje_avance', 100)->count() > 0;
    }

    public function unidadesCompletadas(): int
    {
        return $this->trabajos()->completados()->count();
    }

    public function unidadesRemisionadas(): int
    {
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
