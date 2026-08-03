<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MantenimientoRepuesto extends Model
{
    protected $table = 'mantenimiento_repuestos';

    protected $fillable = [
        'mantenimiento_id', 'nombre', 'referencia', 'unidad',
        'cantidad', 'precio_unitario', 'subtotal',
    ];

    public function mantenimiento()
    {
        return $this->belongsTo(Mantenimiento::class, 'mantenimiento_id');
    }
}
