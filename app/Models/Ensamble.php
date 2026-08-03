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
}
