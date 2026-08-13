<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ensamble extends Model
{
    use SoftDeletes;

    protected $table = 'ensambles';

    protected $fillable = [
        'plantilla_id',
        'nombre',
        'categoria_id',
        'descripcion_corta',
        'descripcion_larga',
        'imagen_principal',
        'imagen_principal_drive_id',
        'imagenes_secundarias',
        'variables',
        'componentes_resultado',
        'precio_costo',
        'precio_mayorista',
        'precio_distribuidor',
        'precio_cliente_final',
        'margen_aplicado',
        'comision_pct_minima',
        'comision_pct_maxima',
        'comision_min_distribuidor',
        'comision_max_distribuidor',
        'comision_min_cliente_final',
        'comision_max_cliente_final',
        'utilidad_minima_empresa_pct',
        'descuento_max_cliente_final',
        'descuento_max_distribuidor',
        'descuento_max_mayorista',
        'creado_por',
        // Si sale al sitio web del cliente. Lo lee el plugin Briela Connect.
        'publicado_web',
        'publicado_web_at',
    ];

    protected $casts = [
        'variables'                  => 'array',
        'componentes_resultado'      => 'array',
        'imagenes_secundarias'       => 'array',
        'precio_costo'               => 'decimal:2',
        'precio_mayorista'           => 'decimal:2',
        'precio_distribuidor'        => 'decimal:2',
        'precio_cliente_final'       => 'decimal:2',
        'margen_aplicado'            => 'decimal:2',
        'comision_pct_minima'         => 'decimal:2',
        'comision_pct_maxima'         => 'decimal:2',
        'comision_min_distribuidor'   => 'decimal:2',
        'comision_max_distribuidor'   => 'decimal:2',
        'comision_min_cliente_final'  => 'decimal:2',
        'comision_max_cliente_final'  => 'decimal:2',
        'utilidad_minima_empresa_pct' => 'decimal:2',
        'descuento_max_cliente_final'=> 'decimal:2',
        'descuento_max_distribuidor' => 'decimal:2',
        'descuento_max_mayorista'    => 'decimal:2',
        'publicado_web'              => 'boolean',
        'publicado_web_at'           => 'datetime',
    ];

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(PlantillaEnsamble::class, 'plantilla_id');
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaProducto::class, 'categoria_id');
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function calcularPrecios(float $margen = null): void
    {
        $m     = $margen ?? (float) $this->margen_aplicado;
        $costo = (float) $this->precio_costo;

        $this->precio_mayorista     = round($costo * (1 + $m / 100), 0);
        $this->precio_distribuidor  = round($costo * (1 + ($m + 2.5) / 100), 0);
        $this->precio_cliente_final = round($costo * (1 + ($m + 5) / 100), 0);
    }

    /**
     * Los precios por canal. Reemplazan a las columnas fijas por canal, que siguen
     * existiendo durante el período de compatibilidad de la regla 2.
     */
    public function preciosPorCanal(): \Illuminate\Database\Eloquent\Relations\MorphMany
    {
        return $this->morphMany(\App\Models\CanalPrecio::class, "precionable");
    }

    /** El precio de un canal concreto, o null si ese canal no tiene precio cargado. */
    public function precioDeCanal(?\App\Models\SegmentacionOpcion $canal): ?\App\Models\CanalPrecio
    {
        return $canal
            ? $this->preciosPorCanal->firstWhere("segmentacion_opcion_id", $canal->id)
            : null;
    }
}
