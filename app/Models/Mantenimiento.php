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
        // `date:Y-m-d` y no `date`: sin el formato esto se serializa como
        // «2026-08-10T00:00:00.000000Z», y un `<input type="date"» exige «2026-08-10».
        // El navegador rechaza el valor y solo lo dice en la consola: el campo se ve
        // vacío y el usuario no puede leer ni corregir una fecha que sí está guardada.
        'fecha_programada' => 'date:Y-m-d',
        'fecha_inicio'     => 'date:Y-m-d',
        'fecha_fin'        => 'date:Y-m-d',
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
