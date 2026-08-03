<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Mantenimiento extends Model
{
    protected $fillable = [
        'equipo_id', 'tipo', 'estado', 'fecha_programada', 'fecha_inicio', 'fecha_fin',
        'ejecutor_tipo', 'ejecutor_nombre', 'descripcion', 'hallazgos', 'acciones',
        'costo_mano_obra', 'costo_repuestos', 'costo_total', 'tiempo_horas', 'registrado_por',
    ];

    protected $casts = [
        'fecha_programada' => 'date',
        'fecha_inicio'     => 'date',
        'fecha_fin'        => 'date',
    ];

    public function equipo()
    {
        return $this->belongsTo(EquipoMantenimiento::class, 'equipo_id');
    }

    public function repuestos()
    {
        return $this->hasMany(MantenimientoRepuesto::class, 'mantenimiento_id');
    }

    public function registradoPor()
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
