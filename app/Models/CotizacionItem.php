<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CotizacionItem extends Model
{
    protected $table = 'cotizacion_items';

    protected $fillable = [
        'cotizacion_id',
        'tipo',
        'producto_id',
        'configuracion_puerta_id',
        'ensamble_id',
        'variables_snapshot',
        'componentes_snapshot',
        'variables_instancia',
        'imagenes_instancia',
        'descripcion_larga',
        'orden',
        'descripcion',
        'cantidad',
        'precio_unitario',
        'precio_mayorista_base',
        'descuento_pct',
        'subtotal',
        'impuesto_pct',
        'impuesto_valor',
        'total_linea',
        'comision_pct_aplicada',
        'comision_valor',
    ];

    protected $casts = [
        'cantidad'             => 'decimal:3',
        'precio_unitario'      => 'decimal:2',
        'precio_mayorista_base'=> 'decimal:2',
        'descuento_pct'        => 'decimal:2',
        'subtotal'             => 'decimal:2',
        'impuesto_pct'         => 'decimal:2',
        'impuesto_valor'       => 'decimal:2',
        'total_linea'          => 'decimal:2',
        'variables_snapshot'   => 'array',
        'componentes_snapshot' => 'array',
        'variables_instancia'    => 'array',
        'imagenes_instancia'     => 'array',
        'comision_pct_aplicada'  => 'decimal:2',
        'comision_valor'         => 'decimal:2',
    ];

    public function cotizacion(): BelongsTo
    {
        return $this->belongsTo(Cotizacion::class);
    }

    public function producto(): BelongsTo
    {
        return $this->belongsTo(Producto::class);
    }

    public function configuracionPuerta(): BelongsTo
    {
        return $this->belongsTo(ConfiguracionPuerta::class, 'configuracion_puerta_id');
    }

    public function ensamble(): BelongsTo
    {
        return $this->belongsTo(Ensamble::class);
    }

    public function calcularTotales(): void
    {
        $base     = $this->cantidad * $this->precio_unitario;
        $desc     = $base * ($this->descuento_pct / 100);
        $baseDesc = $base - $desc;
        $impuesto = $baseDesc * ($this->impuesto_pct / 100);

        $this->subtotal       = $base;
        $this->impuesto_valor = $impuesto;
        $this->total_linea    = $baseDesc + $impuesto;
    }
}
